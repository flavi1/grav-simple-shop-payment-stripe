# Simple Shop Payment Stripe

Passerelle de paiement [Stripe](https://stripe.com) pour le plugin Grav [Simple Shop](https://github.com/flavi1/grav-simple-shop).

- **Formulaire intégré** (`use_javascript: true`, Stripe Elements / PaymentIntent) avec repli sans JavaScript vers la page hébergée ;
- ou **page hébergée Stripe** (`use_javascript: false`, Checkout Session) : aucun JavaScript sur le site ;
- retour de paiement vérifié (statut et montant en centimes), commande créée une seule fois ;
- **webhook** `/stripe/webhook` (`payment_intent.succeeded`, signature vérifiée) : la commande est créée même si le client ferme
  son navigateur avant le retour sur le site.

Bibliothèque officielle `stripe-php` embarquée (`stripe-php/`).

## Installation

1. Dossier `user/plugins/simple-shop-payment-stripe/` (hébergement mutualisé : `/shared/grav-2/user/plugins/…`, déployé par
   GitHub Actions (rsync/ssh), voir `AGENTS.md`). Requiert `simple-shop` et `mvc`.
2. Activer le plugin dans la configuration du site (**désactivé par défaut**).
3. Saisir les clés dans Plugins > Simple Shop Payment Stripe (ou `user/config/plugins/simple-shop-payment-stripe.yaml`) :

```yaml
enabled: true
use_javascript: true   # false = page hébergée Stripe, sans JavaScript
stripe_mode: test      # test | live
test_secret_key: ''    # sk_test_…
test_publishable_key: ''
live_secret_key: ''    # sk_live_… (ou clé restreinte rk_live_…)
live_publishable_key: ''
webhook_secret: ''     # whsec_… (Stripe > Développeurs > Webhooks)
```

Ne jamais versionner ces clés. Branchement du webhook pas à pas : `AGENTS.md`, « Configuration du webhook Stripe ».
