<?php
namespace Grav\Plugin\SimpleShopPaymentStripe\Gateways;

use Grav\Common\Grav;
use Grav\Common\Utils;
use Grav\Plugin\SimpleShop\Gateways\PaymentContext;
use Grav\Plugin\SimpleShop\Gateways\RedirectDocumentPaymentGatewayInterface;
use Stripe\Checkout\Session as CheckoutSession;

/**
 * Stripe pour les documents envoyés depuis l'administration de simple-shop (factures à régler, factures d'acompte,
 * demandes de paiement) : paiement sur la page hébergée de Stripe (Checkout), sans JavaScript sur le site.
 *
 * Parcours : bouton « Payer par carte » (formulaire de la page du document) → Checkout → retour sur la page du document
 * (`$ctx->returnUrl`), où le paiement est vérifié auprès de Stripe (session, jeton du document, statut, montant) avant
 * d'être enregistré par simple-shop. Le webhook (`payment_intent.succeeded`, métadonnée `shop_document_token`) enregistre
 * le même paiement si le client ne revient pas sur le site ; la référence du PaymentIntent rend l'enregistrement
 * idempotent.
 *
 * Hérite de la passerelle de la boutique (tunnel d'achat) : même identifiant (`stripe`), mêmes clés.
 */
class DocumentPaymentGateway extends PaymentGateway implements RedirectDocumentPaymentGatewayInterface
{
    /** Montant minimal accepté par Stripe, en centimes (0,50 €). */
    private const MIN_DOCUMENT_CENTS = 50;

    public function isOffline(): bool
    {
        return false;
    }

    public function renderDocumentPayment(PaymentContext $ctx): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        if ($ctx->amountCents < self::MIN_DOCUMENT_CENTS) {
            return '<p>' . $e('Montant trop faible pour un paiement par carte : choisissez un autre moyen de paiement.') . '</p>';
        }

        return '<form method="post" action="' . $e($ctx->pageUrl) . '" class="stripe-document-payment">'
            . '<input type="hidden" name="action" value="start_hosted_document_payment">'
            . '<input type="hidden" name="method" value="' . $e($this->getId()) . '">'
            . '<input type="hidden" name="nonce" value="' . $e(Utils::getNonce(PaymentContext::NONCE_ACTION)) . '">'
            . '<p>' . $e('Vous allez être dirigé vers la page de paiement sécurisée de Stripe, puis ramené ici.') . '</p>'
            . '<button type="submit" class="btn">' . $e('Payer ' . $ctx->amountText() . ' ' . $ctx->currency . ' par carte') . '</button>'
            . '</form>';
    }

    /** Pas de formulaire à traiter sur le site : tout passe par la page hébergée. */
    public function processDocumentPayment(PaymentContext $ctx, array $postData): array
    {
        return ['success' => false, 'error' => 'utilisez le bouton de paiement par carte.'];
    }

    public function startHostedDocumentPayment(PaymentContext $ctx): array
    {
        if ($ctx->amountCents < self::MIN_DOCUMENT_CENTS) {
            return ['url' => null, 'error' => 'montant trop faible pour un paiement par carte.'];
        }
        $this->initStripe();
        $currency = strtolower($ctx->currency);
        $metadata = $ctx->metadata();

        try {
            $sep = str_contains($ctx->returnUrl, '?') ? '&' : '?';
            $params = [
                'mode'                => 'payment',
                'line_items'          => [[
                    'quantity'   => 1,
                    'price_data' => [
                        'currency'     => preg_match('/^[a-z]{3}$/', $currency) ? $currency : 'eur',
                        'unit_amount'  => $ctx->amountCents,
                        'product_data' => ['name' => $ctx->description],
                    ],
                ]],
                'success_url'         => $ctx->returnUrl . $sep . 'result=success&session_id={CHECKOUT_SESSION_ID}',
                'cancel_url'          => $ctx->returnUrl . $sep . 'result=cancel',
                'client_reference_id' => $ctx->documentNumber,
                'metadata'            => $metadata,
                'payment_intent_data' => ['metadata' => $metadata, 'description' => $ctx->description],
            ];
            if ($ctx->customerEmail !== '') {
                $params['customer_email'] = $ctx->customerEmail;
            }

            return ['url' => (string) CheckoutSession::create($params)->url, 'error' => null];
        } catch (\Throwable $e) {
            Grav::instance()['log']->error('[SimpleShopPaymentStripe] Session Checkout impossible pour ' . $ctx->documentNumber . ' : ' . $e->getMessage());

            return ['url' => null, 'error' => 'service de paiement indisponible.'];
        }
    }

    public function confirmDocumentReturn(PaymentContext $ctx, array $query): array
    {
        if (($query['result'] ?? '') === 'cancel') {
            return ['success' => false, 'cancelled' => true, 'error' => null];
        }
        $sessionId = (string) ($query['session_id'] ?? '');
        if (!preg_match('/^cs_[A-Za-z0-9_]+$/', $sessionId)) {
            return ['success' => false, 'cancelled' => false, 'error' => 'retour de paiement incomplet.'];
        }
        $this->initStripe();

        try {
            $checkout = CheckoutSession::retrieve($sessionId);
        } catch (\Throwable $e) {
            Grav::instance()['log']->error('[SimpleShopPaymentStripe] Session ' . $sessionId . ' illisible : ' . $e->getMessage());

            return ['success' => false, 'cancelled' => false, 'error' => 'vérification du paiement impossible, réessayez dans un instant.'];
        }

        // La session doit appartenir à CE document (jeton secret de la page) : une session d'un autre paiement est refusée.
        $token = (string) ($checkout->metadata['shop_document_token'] ?? '');
        if ($token === '' || !hash_equals($ctx->token, $token)) {
            return ['success' => false, 'cancelled' => false, 'error' => 'session de paiement inconnue pour ce document.'];
        }
        if ($checkout->payment_status !== 'paid') {
            return ['success' => false, 'cancelled' => false, 'error' => 'le paiement n\'est pas confirmé (statut : ' . $checkout->payment_status . ').'];
        }
        $reference = is_string($checkout->payment_intent) ? $checkout->payment_intent : ($checkout->payment_intent->id ?? (string) $checkout->id);

        // Montant réellement encaissé : simple-shop l'enregistre tel quel (au-delà du reste dû : trop-perçu signalé).
        return ['success' => true, 'cancelled' => false, 'error' => null, 'payment_reference' => (string) $reference,
            'amount_cents' => (int) $checkout->amount_total];
    }
}
