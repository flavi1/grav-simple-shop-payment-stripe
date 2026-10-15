# Historique — ce qui a été délibérément fait (simple-shop-payment-stripe)

> Repris le 15/10/2026 de `private/prompts/DONE.md` du site d'origine (senonevero.flavieng.com), en ne gardant que ce qui concerne
> ce plugin. « Preuve » = fichier, test ou procédure qui le montre. Les numéros (A1, T4, O6, Q9…) sont ceux des anciens fichiers de
> suivi du site ; ils ne renvoient à rien dans ce dépôt mais sont gardés pour pouvoir retrouver une discussion.

| Point | État | Preuve |
|---|---|---|
| E7 Callbacks Stripe | Fait (option a) | plus de mapping `handleStripeSuccess/Cancel` ; paiement finalisé en ligne à l'étape paiement |
| E8 Stripe : nettoyage | Fait | `echo`/`console.log` supprimés ; idempotence du `PaymentIntent` (réutilisation + mise à jour du montant) |
| T9 + T18 Paiement | Oui | `use_javascript` (case à cocher du plugin Stripe) : partial JS + `<noscript>`, ou page hébergée Stripe sans JS ; route de retour `/checkout/payment-return` ; vérification statut + montant ; commande idempotente (table `order_payment`) |
| Webhook Stripe | Oui (à brancher) | Route `/stripe/webhook`, événement `payment_intent.succeeded`, signature vérifiée, secret `webhook_secret` ; instantané de commande `pending_payment` enregistré au démarrage du paiement ; `OrderFinalizer` partagé avec le retour client (idempotent, e-mails une seule fois) ; le plugin `mvc` expose `Request::getRawBody()` |
| Bug sur tout le site (webhook) | Oui | autoloader du plugin Stripe déclaré dans le constructeur, abonnement statique à `onMvcRegisterRoutes`, `try/catch` autour de `add()` (déjà sur `main`, 4f37d8e) |
| 4. Montants en centimes jusqu'à la commande : panier, livraison (`price_cents`), montant à encaisser (`billing_total_cents`, lu par Stripe avec la devise), contrôle du webhook, commande | Fait | `Orders/OrderAmounts.php`, `CartStorage::toOrder()`, `CartController::computeBillingTotal()`, `OrderFinalizer`, `simple-shop-payment-stripe` |
