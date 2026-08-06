<?php
namespace Grav\Plugin\SimpleShopPaymentStripe\Gateways;

use Grav\Common\Grav;
use Grav\Plugin\SimpleShop\Gateways\PaymentGatewayInterface;
use Grav\Plugin\SimpleShop\Inventory\CartStorage;
use Grav\Common\Utils;
use Stripe\Stripe;
use Stripe\PaymentIntent;

class PaymentGateway implements PaymentGatewayInterface
{
    protected function initStripe()
    {
        $grav = Grav::instance();
        $config = $grav['config']->get('plugins.simple-shop-payment-stripe');
        
        $mode = $config['stripe_mode'] ?? 'test';
        $secretKey = ($mode === 'live') ? ($config['live_secret_key'] ?? '') : ($config['test_secret_key'] ?? '');
        
        Stripe::setApiKey($secretKey);
    }

    public function getId(): string
    {
        return 'stripe';
    }

    public function getLabel(): string
    {
        return 'Paiement par Stripe';
    }

    public function renderPaymentForm(array $orderData): string
    {
        $this->initStripe();
        
        $grav = Grav::instance();
        $config = $grav['config']->get('plugins.simple-shop-payment-stripe');
        $mode = $config['stripe_mode'] ?? 'test';
        $publishableKey = ($mode === 'live') ? ($config['live_publishable_key'] ?? '') : ($config['test_publishable_key'] ?? '');
        
        $nonce = Utils::getNonce('shop-payment-step');
        $actionUrl = $grav['uri']->route();
        
        $amountInCents = (int)round(($orderData['billing_total'] ?? 0) * 100);
        $amountInCents = max($amountInCents, 50); // Minimum de sécurité Stripe (0.50 €)

        $clientSecret = '';
        $session = $grav['session'];
        $shopOrder = $session->shop_order ?? null;
        $paymentIntentId = $shopOrder->stripe_payment_intent_id ?? null;

        try {
            $paymentIntent = null;

            // Idempotence : tenter de réutiliser l'intention de paiement existante si le montant correspond
            if ($paymentIntentId) {
                try {
                    $existingIntent = PaymentIntent::retrieve($paymentIntentId);
                    if ($existingIntent && $existingIntent->status === 'requires_payment_method') {
                        if ($existingIntent->amount === $amountInCents) {
                            $paymentIntent = $existingIntent;
                        } else {
                            // Mettre à jour le montant si celui-ci a changé
                            $paymentIntent = PaymentIntent::update($paymentIntentId, [
                                'amount' => $amountInCents
                            ]);
                        }
                    }
                } catch (\Exception $ex) {
                    // En cas d'erreur de récupération, on ignorera et on en recréera un
                    $paymentIntent = null;
                }
            }

            // Si aucune intention valide n'a pu être réutilisée, on en crée une nouvelle
            if (!$paymentIntent) {
                $paymentIntent = PaymentIntent::create([
                    'amount' => $amountInCents,
                    'currency' => 'eur',
                    'automatic_payment_methods' => [
                        'enabled' => true,
                    ],
                    'metadata' => [
                        'cart_id' => $orderData['cart_id'] ?? 'unknown',
                        'customer_email' => $orderData['customer']['email'] ?? ''
                    ]
                ]);
            }

            $clientSecret = $paymentIntent->client_secret;
            
            // Stockage de l'ID en session
            if (isset($session->shop_order)) {
                $session->shop_order->stripe_payment_intent_id = $paymentIntent->id;
            }

        } catch (\Exception $e) {
            return "<div class='alert error'>Erreur d'initialisation Stripe : " . htmlspecialchars($e->getMessage()) . "</div>";
        }

        $html = "
        <div class='payment-gateway-stripe-box' style='background: #fff; border: 1px solid #e2e8f0; padding: 1.5rem; border-radius: 8px; margin: 1rem 0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);'>
            <h4 style='margin-top:0; color: #1a202c;'>💳 Paiement Sécurisé par Carte Bancaire</h4>
            <p>Montant total à régler : <strong>" . number_format($orderData['billing_total'] ?? 0, 2, ',', ' ') . " €</strong></p>
            
            <form id='payment-form' method='POST' action='" . htmlspecialchars($actionUrl, ENT_QUOTES) . "' style='margin-top: 1.5rem;'>
                <input type='hidden' name='action' value='execute_payment' />
                <input type='hidden' name='nonce' value='{$nonce}' />
                
                <!-- Conteneur Stripe Elements -->
                <div id='payment-element' style='margin-bottom: 1.5rem;'></div>
                
                <button type='submit' id='submit-button' class='btn' style='background: #635bff; color: #fff; border:none; padding: 0.75rem 1.5rem; cursor:pointer; border-radius:4px; width: 100%; font-weight: bold;'>
                    <span id='button-text'>Payer " . number_format($orderData['billing_total'] ?? 0, 2, ',', ' ') . " €</span>
                    <span id='spinner' style='display: none;'>Traitement en cours...</span>
                </button>
                <div id='payment-message' style='color: #e53e3e; margin-top: 1rem; font-size: 0.9rem;'></div>
            </form>
        </div>

        <script src='https://js.stripe.com/v3/'></script>
        <script>
            document.addEventListener('DOMContentLoaded', async () => {
                const stripe = Stripe('" . $publishableKey . "');
                const clientSecret = '" . $clientSecret . "';
                
                const elements = stripe.elements({ clientSecret });
                const paymentElement = elements.create('payment');
                paymentElement.mount('#payment-element');

                const form = document.getElementById('payment-form');
                form.addEventListener('submit', async (e) => {
                    e.preventDefault();
                    
                    setLoading(true);
                    const messageContainer = document.querySelector('#payment-message');
                    messageContainer.textContent = '';

                    const { error } = await stripe.confirmPayment({
                        elements,
                        confirmParams: {
                            return_url: window.location.href,
                        },
                        redirect: 'if_required'
                    });

                    if (error) {
                        messageContainer.textContent = error.message;
                        setLoading(false);
                    } else {
                        form.submit();
                    }
                });

                function setLoading(isLoading) {
                    const submitButton = document.querySelector('#submit-button');
                    const spinner = document.querySelector('#spinner');
                    const buttonText = document.querySelector('#button-text');
                    
                    if (isLoading) {
                        submitButton.disabled = true;
                        spinner.style.display = 'inline';
                        buttonText.style.display = 'none';
                    } else {
                        submitButton.disabled = false;
                        spinner.style.display = 'none';
                        buttonText.style.display = 'inline';
                    }
                }
            });
        </script>
        ";

        return $html;
    }

    public function processPayment(array $postData): array
    {
        $this->initStripe();

        $session = Grav::instance()['session'];
        if (!isset($session->shop_order)) {
            return [
                'success' => false,
                'error' => 'Session expirée, veuillez recommencer votre commande.'
            ];
        }
        
        $order = $session->shop_order;
        $paymentIntentId = $order->stripe_payment_intent_id ?? null;

        if (!$paymentIntentId) {
            return [
                'success' => false,
                'error' => 'Identifiant d\'intention de paiement introuvable dans la session.'
            ];
        }

        try {
            $paymentIntent = PaymentIntent::retrieve($paymentIntentId);

            if ($paymentIntent->status === 'succeeded') {
                unset($order->stripe_payment_intent_id);

                return [
                    'success' => true,
                    'error' => null
                ];
            }

            return [
                'success' => false,
                'error' => 'Le paiement n\'a pas abouti (Statut actuel : ' . $paymentIntent->status . ').'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
