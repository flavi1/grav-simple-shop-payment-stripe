<?php
namespace Grav\Plugin\SimpleShopPaymentStripe\Gateways;

use Grav\Common\Grav;
use Grav\Common\Utils;
use Grav\Plugin\SimpleShop\Gateways\RedirectPaymentGatewayInterface;
use Grav\Plugin\SimpleShop\Orders\PendingPayment;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\PaymentIntent;
use Stripe\Stripe;

/**
 * Passerelle Stripe.
 *
 * Deux modes d'affichage, au choix dans la configuration du plugin
 * (`use_javascript`) :
 *
 *  - JavaScript (par défaut) : formulaire Stripe Elements intégré à la page
 *    (`partials/simple-shop-payment-stripe/js.html.twig`) ; le bloc
 *    `<noscript>` propose le paiement hébergé aux navigateurs sans JavaScript ;
 *  - sans JavaScript : simple bouton qui envoie le client vers la page de
 *    paiement hébergée Stripe (Checkout), puis le ramène sur le site
 *    (`partials/simple-shop-payment-stripe/nojs.html.twig`).
 *
 * Quel que soit le mode, la commande n'est créée qu'après vérification du
 * paiement auprès de Stripe (statut ET montant), et la référence de paiement
 * (identifiant PaymentIntent) rend cette création idempotente.
 */
class PaymentGateway implements RedirectPaymentGatewayInterface
{
    /** Montant minimal accepté par Stripe, en centimes (0,50 €). */
    private const MIN_AMOUNT_CENTS = 50;

    private function config(): array
    {
        return (array) Grav::instance()['config']->get('plugins.simple-shop-payment-stripe', []);
    }

    protected function initStripe(): void
    {
        $config = $this->config();
        $mode = $config['stripe_mode'] ?? 'test';
        $secretKey = ($mode === 'live') ? ($config['live_secret_key'] ?? '') : ($config['test_secret_key'] ?? '');

        Stripe::setApiKey((string) $secretKey);
    }

    private function publishableKey(): string
    {
        $config = $this->config();
        $mode = $config['stripe_mode'] ?? 'test';

        return (string) (($mode === 'live') ? ($config['live_publishable_key'] ?? '') : ($config['test_publishable_key'] ?? ''));
    }

    /** Vrai si le formulaire intégré (JavaScript) est activé. */
    private function useJavascript(): bool
    {
        return (bool) ($this->config()['use_javascript'] ?? true);
    }

    /** Montant à régler en centimes, avec le plancher imposé par Stripe. */
    private function amountInCents(array $orderData): int
    {
        // Montant exact calculé par simple-shop en centimes ; ancien total décimal en repli.
        $cents = isset($orderData['billing_total_cents'])
            ? (int) $orderData['billing_total_cents']
            : (int) round(((float) ($orderData['billing_total'] ?? 0)) * 100);

        return max($cents, self::MIN_AMOUNT_CENTS);
    }

    /** Devise de la commande (ISO 4217 en minuscules pour Stripe), EUR par défaut. */
    private function currency(array $orderData): string
    {
        $c = strtolower((string) ($orderData['billing_currency'] ?? 'EUR'));

        return preg_match('/^[a-z]{3}$/', $c) ? $c : 'eur';
    }

    /**
     * Enregistre (ou met à jour) l'instantané de la commande en cours et
     * retourne son identifiant, à confier à Stripe dans les métadonnées : le
     * webhook s'en sert pour créer la commande sans session.
     */
    private function snapshotId(array $orderData): string
    {
        $session = Grav::instance()['session'];
        $existing = $session->shop_order->stripe_snapshot_id ?? null;
        $id = PendingPayment::save($orderData, $this->amountInCents($orderData), is_string($existing) ? $existing : null);

        if (isset($session->shop_order)) {
            $session->shop_order->stripe_snapshot_id = $id;
        }

        return $id;
    }

    public function getId(): string
    {
        return 'stripe';
    }

    public function getLabel(): string
    {
        return 'Paiement par Stripe';
    }

    // ------------------------------------------------------------------
    // Affichage du formulaire (Temps 2)
    // ------------------------------------------------------------------

    public function renderPaymentForm(array $orderData): string
    {
        $grav = Grav::instance();
        $this->initStripe();

        $vars = [
            'total'          => number_format((float) ($orderData['billing_total'] ?? 0), 2, ',', ' '),
            'nonce'          => Utils::getNonce('shop-payment-step'),
            'action_url'     => $grav['uri']->route(),
            'error'          => null,
        ];

        if (!$this->useJavascript()) {
            return $grav['twig']->processTemplate('partials/simple-shop-payment-stripe/nojs.html.twig', $vars);
        }

        try {
            $paymentIntent = $this->ensurePaymentIntent($orderData);
        } catch (\Throwable $e) {
            $grav['log']->error('[SimpleShopPaymentStripe] Initialisation du PaymentIntent impossible : ' . $e->getMessage());
            $vars['error'] = "Erreur d'initialisation du paiement. Veuillez réessayer ou nous contacter.";

            return $grav['twig']->processTemplate('partials/simple-shop-payment-stripe/js.html.twig', $vars);
        }

        $vars['publishable_key'] = $this->publishableKey();
        $vars['client_secret']   = $paymentIntent->client_secret;
        // Retour des paiements à redirection (3-D Secure) : route de retour du site.
        $vars['return_url']      = $grav['base_url_absolute']
            . ($grav['config']->get('plugins.simple-shop.routes.payment_return') ?: '/checkout/payment-return');

        return $grav['twig']->processTemplate('partials/simple-shop-payment-stripe/js.html.twig', $vars);
    }

    /**
     * Réutilise (ou crée) le PaymentIntent de la commande en cours et aligne
     * son montant sur le total courant.
     */
    private function ensurePaymentIntent(array $orderData): PaymentIntent
    {
        $session = Grav::instance()['session'];
        $amountInCents = $this->amountInCents($orderData);
        $snapshotId = $this->snapshotId($orderData);
        $paymentIntentId = $session->shop_order->stripe_payment_intent_id ?? null;
        $paymentIntent = null;

        if ($paymentIntentId) {
            try {
                $existing = PaymentIntent::retrieve($paymentIntentId);
                if ($existing && $existing->status === 'requires_payment_method') {
                    $paymentIntent = ($existing->amount === $amountInCents)
                        ? $existing
                        : PaymentIntent::update($paymentIntentId, ['amount' => $amountInCents]);
                }
            } catch (\Throwable $e) {
                $paymentIntent = null; // on en recrée un
            }
        }

        if (!$paymentIntent) {
            $paymentIntent = PaymentIntent::create([
                'amount' => $amountInCents,
                'currency' => $this->currency($orderData),
                'automatic_payment_methods' => ['enabled' => true],
                'metadata' => [
                    'cart_id' => $orderData['cart_id'] ?? 'unknown',
                    'customer_email' => $orderData['customer']['email'] ?? '',
                    'shop_snapshot' => $snapshotId,
                ],
            ]);
        }

        if (isset($session->shop_order)) {
            $session->shop_order->stripe_payment_intent_id = $paymentIntent->id;
        }

        return $paymentIntent;
    }

    // ------------------------------------------------------------------
    // Mode JavaScript : finalisation après confirmPayment() côté navigateur
    // ------------------------------------------------------------------

    public function processPayment(array $postData): array
    {
        $this->initStripe();

        $order = Grav::instance()['session']->shop_order ?? null;
        if (!$order) {
            return ['success' => false, 'error' => 'Session expirée, veuillez recommencer votre commande.'];
        }

        $paymentIntentId = $order->stripe_payment_intent_id ?? null;
        if (!$paymentIntentId) {
            return ['success' => false, 'error' => "Identifiant d'intention de paiement introuvable dans la session."];
        }

        return $this->verifyPaymentIntent((string) $paymentIntentId, (array) $order);
    }

    /**
     * Vérifie auprès de Stripe qu'un PaymentIntent est payé ET que le montant
     * correspond à la commande en cours.
     */
    private function verifyPaymentIntent(string $paymentIntentId, array $orderData): array
    {
        try {
            $paymentIntent = PaymentIntent::retrieve($paymentIntentId);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        if ($paymentIntent->status !== 'succeeded') {
            return [
                'success' => false,
                'error' => "Le paiement n'a pas abouti (statut : " . $paymentIntent->status . ').',
            ];
        }

        if ((int) $paymentIntent->amount_received !== $this->amountInCents($orderData)) {
            Grav::instance()['log']->error("[SimpleShopPaymentStripe] Montant payé ({$paymentIntent->amount_received}) différent du total attendu pour {$paymentIntent->id}.");

            return ['success' => false, 'error' => 'Le montant payé ne correspond pas au total de la commande.'];
        }

        return ['success' => true, 'error' => null, 'payment_reference' => (string) $paymentIntent->id];
    }

    // ------------------------------------------------------------------
    // Paiement hébergé (mode sans JavaScript) et retours à redirection
    // ------------------------------------------------------------------

    public function startHostedPayment(array $orderData, string $returnUrl): array
    {
        $this->initStripe();

        try {
            $currency = $this->currency($orderData);
            $lines = [];
            $linesTotal = 0;

            foreach (\Grav\Plugin\SimpleShop\Inventory\CartStorage::getCart() as $item) {
                $unit = isset($item['unit_ttc_cents']) ? (int) $item['unit_ttc_cents'] : (int) round(((float) ($item['price'] ?? 0)) * 100);
                $qty  = (int) ($item['quantity'] ?? 0);
                if ($unit <= 0 || $qty <= 0) {
                    continue;
                }
                $lines[] = [
                    'quantity' => $qty,
                    'price_data' => [
                        'currency' => $currency,
                        'unit_amount' => $unit,
                        'product_data' => ['name' => (string) ($item['title'] ?? 'Article')],
                    ],
                ];
                $linesTotal += $unit * $qty;
            }

            $shipping = isset($orderData['shipping']['price_cents'])
                ? (int) $orderData['shipping']['price_cents']
                : (int) round(((float) ($orderData['shipping']['price'] ?? 0)) * 100);
            if ($shipping > 0) {
                $lines[] = [
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => $currency,
                        'unit_amount' => $shipping,
                        'product_data' => ['name' => 'Frais de livraison'],
                    ],
                ];
                $linesTotal += $shipping;
            }

            // Garde-fou : le détail ne doit jamais s'écarter du total attendu.
            if ($lines === [] || $linesTotal !== $this->amountInCents($orderData)) {
                $lines = [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => $currency,
                        'unit_amount' => $this->amountInCents($orderData),
                        'product_data' => ['name' => 'Commande'],
                    ],
                ]];
            }

            $sep = (strpos($returnUrl, '?') === false) ? '?' : '&';
            $params = [
                'mode' => 'payment',
                'line_items' => $lines,
                'success_url' => $returnUrl . $sep . 'result=success&session_id={CHECKOUT_SESSION_ID}',
                'cancel_url'  => $returnUrl . $sep . 'result=cancel',
                'payment_intent_data' => [
                    'metadata' => [
                        'cart_id' => $orderData['cart_id'] ?? 'unknown',
                        'customer_email' => $orderData['customer']['email'] ?? '',
                        'shop_snapshot' => $this->snapshotId($orderData),
                    ],
                ],
            ];
            $email = (string) ($orderData['customer']['email'] ?? '');
            if ($email !== '') {
                $params['customer_email'] = $email;
            }

            $checkout = CheckoutSession::create($params);

            $session = Grav::instance()['session'];
            if (isset($session->shop_order)) {
                $session->shop_order->stripe_checkout_session_id = $checkout->id;
            }

            return ['url' => $checkout->url, 'error' => null];
        } catch (\Throwable $e) {
            Grav::instance()['log']->error('[SimpleShopPaymentStripe] Session Checkout impossible : ' . $e->getMessage());

            return ['url' => null, 'error' => 'service de paiement indisponible.'];
        }
    }

    public function confirmReturn(array $query, array $orderData): array
    {
        $this->initStripe();

        if (($query['result'] ?? '') === 'cancel') {
            return ['success' => false, 'cancelled' => true, 'error' => null];
        }

        // Retour d'un paiement du mode JavaScript (3-D Secure, redirection).
        if (!empty($query['payment_intent'])) {
            if ((string) $query['payment_intent'] !== (string) ($orderData['stripe_payment_intent_id'] ?? '')) {
                return ['success' => false, 'cancelled' => false, 'error' => 'paiement inconnu pour cette commande.'];
            }

            return $this->verifyPaymentIntent((string) $query['payment_intent'], $orderData) + ['cancelled' => false];
        }

        // Retour de la page hébergée (mode sans JavaScript).
        if (!empty($query['session_id'])) {
            if ((string) $query['session_id'] !== (string) ($orderData['stripe_checkout_session_id'] ?? '')) {
                return ['success' => false, 'cancelled' => false, 'error' => 'session de paiement inconnue pour cette commande.'];
            }

            try {
                $checkout = CheckoutSession::retrieve((string) $query['session_id']);
            } catch (\Throwable $e) {
                return ['success' => false, 'cancelled' => false, 'error' => $e->getMessage()];
            }

            if ($checkout->payment_status !== 'paid') {
                return ['success' => false, 'cancelled' => false, 'error' => 'le paiement n\'est pas confirmé (statut : ' . $checkout->payment_status . ').'];
            }

            if ((int) $checkout->amount_total !== $this->amountInCents($orderData)) {
                Grav::instance()['log']->error("[SimpleShopPaymentStripe] Montant payé ({$checkout->amount_total}) différent du total attendu pour la session {$checkout->id}.");

                return ['success' => false, 'cancelled' => false, 'error' => 'le montant payé ne correspond pas au total de la commande.'];
            }

            // La référence de paiement est le PaymentIntent : la même clé que
            // dans le mode JavaScript, donc une idempotence commune.
            $reference = is_string($checkout->payment_intent) ? $checkout->payment_intent : ($checkout->payment_intent->id ?? (string) $checkout->id);

            return ['success' => true, 'cancelled' => false, 'error' => null, 'payment_reference' => (string) $reference];
        }

        return ['success' => false, 'cancelled' => false, 'error' => 'retour de paiement incomplet.'];
    }
}
