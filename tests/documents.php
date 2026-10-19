<?php
// Paiement Stripe des documents de simple-shop (factures à régler, demandes de paiement), sans réseau : le client HTTP de
// stripe-php est remplacé par une doublure qui répond comme l'API de Stripe.
// Lancement (plugins voisins : simple-shop, mvc, simple-shop-payment-test) :
//   YAML=<autoload symfony/yaml> php -d zend.assertions=1 -d assert.exception=1 tests/documents.php
require __DIR__ . '/../../simple-shop/tests/support/bootstrap.php';
require __DIR__ . '/../stripe-php/init.php';
spl_autoload_register(function ($c) {
    $p = 'Grav\\Plugin\\SimpleShopPaymentStripe\\';
    if (strpos($c, $p) === 0 && is_file($f = __DIR__ . '/../classes/' . str_replace('\\', '/', substr($c, strlen($p))) . '.php')) {
        require $f;
    }
});

use Grav\Plugin\SimpleShop\Gateways\GatewayRegistry;
use Grav\Plugin\SimpleShop\Gateways\PaymentContext;
use Grav\Plugin\SimpleShop\Invoicing\DocumentManager as D;
use Grav\Plugin\SimpleShop\Payments\DocumentPayments;
use Grav\Plugin\SimpleShopPaymentStripe\Gateways\DocumentPaymentGateway;

/** Doublure de l'API Stripe : enregistre les requêtes, répond avec la session Checkout du scénario. */
final class FakeStripe implements \Stripe\HttpClient\ClientInterface
{
    public array $calls = [];
    public array $session = [];

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->calls[] = [$method, $absUrl, $params];
        if ($method === 'post' && str_ends_with($absUrl, '/v1/checkout/sessions')) {
            $this->session = ['id' => 'cs_test_A1', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_A1',
                'metadata' => $params['metadata'], 'payment_status' => 'unpaid', 'amount_total' => $params['line_items'][0]['price_data']['unit_amount'],
                'payment_intent' => null];
        }

        return [json_encode($this->session), 200, []];
    }
}

$grav = \SimpleShopTest\boot([
    'invoicing' => ['manual' => true],
    'seller' => ['name' => 'Flavien G', 'address_line1' => '1 rue du Code', 'postal_code' => '75011', 'city' => 'Paris', 'country' => 'FR', 'siret' => '12345678900011'],
    'taxes' => ['small_business' => ['enabled' => true]],
    'payments' => ['methods' => ['manual' => ['stripe', 'test_gateway']]],
], [], null);
$grav['config']->set('site.title', 'Mon site');
$grav['config']->set('plugins.simple-shop-payment-stripe', ['stripe_mode' => 'test', 'test_secret_key' => 'sk_test_factice']);
$grav->listeners['onSimpleShopRegisterGateways'][] = function ($event) {
    $g = $event['gateways'] ?? [];
    $g['stripe'] = new DocumentPaymentGateway();
    $event['gateways'] = $g;
};
$fake = new FakeStripe();
\Stripe\ApiRequestor::setHttpClient($fake);

// Demande de paiement de 120,00 € à un particulier.
$r = D::saveDraft(['customer' => ['type' => 'b2c', 'email' => 'jean@exemple.fr', 'firstname' => 'Jean', 'lastname' => 'Test', 'street' => '1 rue',
    'postal-code' => '75001', 'city' => 'Paris'], 'price_basis' => 'ttc', 'lines' => [['title' => 'Cours', 'quantity' => 2, 'unit_price' => '60']]], 'admin');
assert($r['errors'] === [], json_encode($r['errors']));
$dp = D::issue($r['reference'], 'payment_request', ['issued_by' => 'admin'])['document'];
$doc = D::find((int) $dp['id']);
$gateways = GatewayRegistry::forDocuments((array) $doc['payload']['methods']);
assert(isset($gateways['stripe']) && $gateways['stripe'] instanceof DocumentPaymentGateway, 'Stripe proposé pour les documents');
$page = 'https://shop.test/document?t=' . $doc['access_token'];
$ctx = new PaymentContext((int) $doc['id'], $doc['invoice_number'], $doc['kind'], $doc['order_reference'], 12000, 'EUR', 'jean@exemple.fr',
    'Demande de paiement ' . $doc['invoice_number'], $page, $page . '&moyen=stripe&retour=1', $doc['access_token']);
$g = $gateways['stripe'];

// --- 1. Formulaire : bouton vers la page hébergée, nonce, échappement
$html = $g->renderDocumentPayment($ctx);
assert(str_contains($html, 'name="action" value="start_hosted_document_payment"') && str_contains($html, 'name="method" value="stripe"')
    && str_contains($html, 'value="nonce-' . PaymentContext::NONCE_ACTION . '"') && str_contains($html, 'Payer 120,00'), $html);
assert(str_contains($html, 'action="' . htmlspecialchars($page, ENT_QUOTES) . '"'), 'formulaire posté sur la page du document');

// --- 2. Démarrage : session Checkout au montant du reste dû, métadonnées du document, adresses de retour
$start = $g->startHostedDocumentPayment($ctx);
assert($start['url'] === 'https://checkout.stripe.com/c/pay/cs_test_A1' && $start['error'] === null, json_encode($start));
[$m, $url, $p] = $fake->calls[0];
assert($p['line_items'][0]['price_data']['unit_amount'] === 12000 && $p['line_items'][0]['price_data']['currency'] === 'eur');
assert($p['metadata']['shop_document_token'] === $doc['access_token'] && $p['payment_intent_data']['metadata']['shop_document'] === $doc['invoice_number']);
assert($p['success_url'] === $page . '&moyen=stripe&retour=1&result=success&session_id={CHECKOUT_SESSION_ID}' && $p['customer_email'] === 'jean@exemple.fr');
$small = new PaymentContext(1, 'X', 'invoice', 'R', 30, 'EUR', '', 'X', $page, $page, $doc['access_token']);
assert($g->startHostedDocumentPayment($small)['url'] === null && str_contains($g->renderDocumentPayment($small), 'trop faible'), 'plancher Stripe 0,50 €');

// --- 3. Retour : annulation, session inconnue, non payée, puis payée
assert($g->confirmDocumentReturn($ctx, ['result' => 'cancel'])['cancelled'] === true);
assert($g->confirmDocumentReturn($ctx, ['session_id' => '../x'])['success'] === false);
$fake->session['payment_status'] = 'unpaid';
$res = $g->confirmDocumentReturn($ctx, ['result' => 'success', 'session_id' => 'cs_test_A1']);
assert($res['success'] === false && str_contains($res['error'], 'unpaid'), json_encode($res));
$other = new PaymentContext((int) $doc['id'], 'X', 'invoice', 'R', 12000, 'EUR', '', 'X', $page, $page, str_repeat('a', 32));
$fake->session['payment_status'] = 'paid';
$fake->session['payment_intent'] = 'pi_test_B2';
assert($g->confirmDocumentReturn($other, ['session_id' => 'cs_test_A1'])['success'] === false, 'session d\'un autre document refusée');
$res = $g->confirmDocumentReturn($ctx, ['result' => 'success', 'session_id' => 'cs_test_A1']);
assert($res === ['success' => true, 'cancelled' => false, 'error' => null, 'payment_reference' => 'pi_test_B2', 'amount_cents' => 12000], json_encode($res));

// --- 4. Enregistrement (retour du client puis webhook : idempotent) ; la demande réglée donne la facture
$a = DocumentPayments::recordGatewayPayment($doc, 'stripe', 'pi_test_B2', 12000);
$b = DocumentPayments::recordGatewayPayment(D::find((int) $doc['id']), 'stripe', 'pi_test_B2', 12000, 'stripe');
assert($a['ok'] && $a['created'] && $b['ok'] && !$b['created'], 'un seul paiement pour une même référence');
D::afterPayment((int) $doc['id']);
$docs = D::documentsForOrder($r['reference']);
$inv = array_values(array_filter($docs, fn ($d) => $d['kind'] === 'invoice'));
assert(count($inv) === 1 && D::settlement($inv[0])['state'] === 'paid', 'facture émise au règlement, réglée');

// --- 5. Double paiement encaissé : enregistré quand même (trop-perçu à rembourser), jamais perdu
$c = DocumentPayments::recordGatewayPayment(D::find((int) $doc['id']), 'stripe', 'pi_test_C3', 12000);
assert($c['ok'] && $c['created'] && D::settlement(D::find((int) $doc['id']))['state'] === 'overpaid', json_encode(D::settlement(D::find((int) $doc['id']))));
assert(DocumentPayments::recordGatewayPayment(D::find((int) $doc['id']), 'stripe', 'pi_test_D4', 0)['ok'] === false, 'montant nul refusé');

echo "OK\n";
