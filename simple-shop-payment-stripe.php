<?php
namespace Grav\Plugin;

use Grav\Common\Plugin;
use RocketTheme\Toolbox\Event\Event;
use Grav\Plugin\SimpleShopPaymentStripe\Controllers\WebhookController;
use Grav\Plugin\SimpleShopPaymentStripe\Gateways\PaymentGateway;

class SimpleShopPaymentStripePlugin extends Plugin
{
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

        // Autoloader PSR-4 pour nos classes internes
        $loader = $this->grav['loader'];
        $loader->addPsr4('Grav\\Plugin\\SimpleShopPaymentStripe\\', __DIR__ . '/classes/');

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
        $router->add($route, [WebhookController::class, 'handle']);
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
        $g = new PaymentGateway();
        $gateways[$g->getId()] = $g;
        $event['gateways'] = $gateways;
    }
}
