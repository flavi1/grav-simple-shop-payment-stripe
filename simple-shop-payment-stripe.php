<?php
namespace Grav\Plugin;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\Plugin;
use RocketTheme\Toolbox\Event\Event;
use Grav\Plugin\SimpleShopPaymentStripe\Controllers\WebhookController;
use Grav\Plugin\SimpleShopPaymentStripe\Gateways\DocumentPaymentGateway;
use Grav\Plugin\SimpleShopPaymentStripe\Gateways\PaymentGateway;

class SimpleShopPaymentStripePlugin extends Plugin
{
    /**
     * L'autoloader PSR-4 doit exister dès la construction : le plugin `mvc`
     * déclenche `onMvcRegisterRoutes` pendant SON `onPluginsInitialized`
     * (priorité 1000), avant le nôtre, et `RouteManager::add()` vérifie alors
     * que la classe du contrôleur existe.
     */
    public function __construct($name, Grav $grav, ?Config $config = null)
    {
        parent::__construct($name, $grav, $config);
        $grav['loader']->addPsr4('Grav\\Plugin\\SimpleShopPaymentStripe\\', __DIR__ . '/classes/');
    }

    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
            // Abonnement statique : le plugin mvc déclenche cet événement pendant
            // SON onPluginsInitialized (priorité 1000), avant le nôtre.
            'onMvcRegisterRoutes'  => ['onMvcRegisterRoutes', 0],
        ];
    }

    public function onPluginsInitialized()
    {
        if ($this->isAdmin()) {
            return;
        }

        // Autoloader pour la bibliothèque officielle Stripe (si placée dans /vendor/stripe/stripe-php/init.php)
        $stripeInit = __DIR__ . '/stripe-php/init.php';
        if (file_exists($stripeInit)) {
            require_once $stripeInit;
        }

        $this->enable([
            'onSimpleShopRegisterGateways' => ['onSimpleShopRegisterGateways', 0],
            'onTwigTemplatePaths'          => ['onTwigTemplatePaths', 0],
        ]);
    }

    /**
     * Route du webhook Stripe (`/stripe/webhook` par défaut, modifiable via
     * `webhook_route`).
     */
    public function onMvcRegisterRoutes()
    {
        $router = $this->grav['mvc_router'] ?? null;
        if (!$router) {
            return;
        }

        $route = (string) $this->config->get('plugins.simple-shop-payment-stripe.webhook_route', '/stripe/webhook');

        // Jamais bloquant pour le reste du site : une route de webhook
        // défaillante ne doit pas faire tomber toutes les pages.
        try {
            $router->add($route, [WebhookController::class, 'handle']);
        } catch (\Throwable $e) {
            $this->grav['log']->error('[SimpleShopPaymentStripe] Route du webhook non enregistrée : ' . $e->getMessage());
        }
    }

    /**
     * Rend disponibles les partiels du plugin (templates/partials/simple-shop-payment-stripe/).
     */
    public function onTwigTemplatePaths()
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    public function onSimpleShopRegisterGateways(Event $event)
    {
        $gateways = $event['gateways'] ?? [];
        // simple-shop récent : la même passerelle sait aussi régler les documents (factures, demandes de paiement).
        $g = interface_exists(\Grav\Plugin\SimpleShop\Gateways\RedirectDocumentPaymentGatewayInterface::class)
            ? new DocumentPaymentGateway()
            : new PaymentGateway();
        $gateways[$g->getId()] = $g;
        $event['gateways'] = $gateways;
    }
}
