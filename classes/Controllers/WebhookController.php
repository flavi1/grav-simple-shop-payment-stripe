<?php
namespace Grav\Plugin\SimpleShopPaymentStripe\Controllers;

use Grav\Plugin\Mvc\AbstractController;
use Grav\Plugin\Mvc\Response;
use Grav\Plugin\SimpleShop\Invoicing\DocumentManager;
use Grav\Plugin\SimpleShop\Payments\DocumentPayments;
use Grav\Plugin\SimpleShop\Services\OrderFinalizer;
use Stripe\Stripe;
use Stripe\Webhook;

/**
 * Réception des webhooks Stripe.
 *
 * Filet de sécurité : si le client ferme son navigateur après avoir payé et
 * avant d'être revenu sur le site, Stripe prévient quand même le serveur et la
 * commande est créée à partir de l'instantané enregistré au démarrage du
 * paiement ({@see \Grav\Plugin\SimpleShop\Orders\PendingPayment}).
 *
 * Un seul événement est traité : `payment_intent.succeeded`. Il couvre les deux
 * modes (le paiement hébergé crée lui aussi un PaymentIntent). Les autres
 * sont acquittés (200) et ignorés. Un PaymentIntent portant la métadonnée
 * `shop_document_token` règle un document de simple-shop (facture, acompte,
 * demande de paiement) au lieu de créer une commande.
 *
 * Codes de réponse : 400 signature absente ou invalide ; 503 secret non
 * configuré ; 500 erreur transitoire (Stripe renverra l'événement) ; 200 dans
 * tous les autres cas, y compris les échecs définitifs (journalisés pour
 * reprise manuelle : rejouer ne les réparerait pas).
 */
class WebhookController extends AbstractController
{
    public function handle(): Response
    {
        if (!$this->isPost()) {
            return $this->json(['error' => 'POST requis.'], 405);
        }

        $config = $this->pluginConfig('simple-shop-payment-stripe');
        $secret = (string) ($config['webhook_secret'] ?? '');
        if ($secret === '') {
            $this->grav['log']->error('[SimpleShopPaymentStripe] Webhook reçu mais webhook_secret n\'est pas configuré.');

            return $this->json(['error' => 'Webhook non configuré.'], 503);
        }

        $signature = (string) $this->request()->getServerParam('HTTP_STRIPE_SIGNATURE', '');
        try {
            $event = Webhook::constructEvent($this->request()->getRawBody(), $signature, $secret);
        } catch (\Throwable $e) {
            // Signature invalide, corps altéré ou horodatage trop ancien.
            return $this->json(['error' => 'Signature invalide.'], 400);
        }

        if ($event->type !== 'payment_intent.succeeded') {
            return $this->json(['received' => true, 'ignored' => $event->type]);
        }

        $intent = $event->data->object;

        // Paiement d'un document envoyé depuis l'administration (facture, acompte, demande de paiement).
        $documentToken = (string) ($intent->metadata['shop_document_token'] ?? '');
        if ($documentToken !== '') {
            return $this->documentPayment($intent, $documentToken);
        }

        $snapshotId = (string) ($intent->metadata['shop_snapshot'] ?? '');
        if ($snapshotId === '') {
            // Paiement sans rapport avec la boutique (autre usage du compte Stripe).
            return $this->json(['received' => true, 'ignored' => 'sans instantané']);
        }

        $result = OrderFinalizer::finalizeFromSnapshot(
            (string) $intent->id,
            $snapshotId,
            (int) ($intent->amount_received ?? 0)
        );

        if (!$result['ok'] && $result['retry']) {
            return $this->json(['error' => 'Erreur temporaire.'], 500);
        }

        return $this->json(['received' => true, 'order' => $result['order'], 'created' => $result['created']]);
    }

    /**
     * Filet de sécurité des documents : enregistre le paiement si le client n'est pas revenu sur la page du document
     * (idempotent : la référence est le PaymentIntent, comme au retour du client).
     */
    private function documentPayment(object $intent, string $token): Response
    {
        if (!class_exists(DocumentManager::class)) {
            return $this->json(['received' => true, 'ignored' => 'documents non pris en charge']);
        }
        try {
            $doc = DocumentManager::findByToken($token);
            if ($doc === null) {
                $this->grav['log']->error('[SimpleShopPaymentStripe] Paiement ' . $intent->id . ' : document introuvable (jeton inconnu). À rapprocher à la main.');

                return $this->json(['received' => true, 'ignored' => 'document inconnu']);
            }
            $r = DocumentPayments::recordGatewayPayment($doc, 'stripe', (string) $intent->id, (int) ($intent->amount_received ?? 0), 'stripe');
        } catch (\Throwable $e) {
            $this->grav['log']->error('[SimpleShopPaymentStripe] Paiement ' . $intent->id . ' non enregistré : ' . $e->getMessage());

            return $this->json(['error' => 'Erreur temporaire.'], 500);
        }

        return $this->json(['received' => true, 'document' => $doc['invoice_number'], 'recorded' => $r['ok'], 'created' => $r['created']]);
    }
}
