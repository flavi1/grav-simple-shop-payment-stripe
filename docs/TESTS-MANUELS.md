# MANUAL_TESTS — À tester à la main (non testable depuis l'environnement de développement)

> Seuls `php -l`, `node --check` et des scripts en ligne de commande ont été exécutés. Rien
> de ce qui suit n'a été essayé dans un vrai Grav, un vrai Admin2 ni chez Stripe.
> Notez tout écart dans `TODO.md`. **Ne figure ici que ce qui reste à tester** : un point validé est retiré
> et consigné dans `DONE.md` (passes des 13/10/2026 dans Admin2 réel via Claude in Chrome).

> Extrait du site d'origine : seules les sections utiles à ce plugin sont gardées (numérotation d'origine).

## 3. Paiement Stripe (mode TEST uniquement)
Cartes de test Stripe : `4242 4242 4242 4242` (succès), `4000 0025 0000 3155` (authentification 3-D Secure),
`4000 0000 0000 9995` (refus). Date future quelconque, CVC quelconque.

**A. Mode avec JavaScript** (`use_javascript: true`)
1. Paiement avec `4242…` : redirection vers la confirmation, **une seule** commande créée, e-mails client et vendeur reçus, facture PDF jointe.
2. Stock : le produit commandé en 2 exemplaires avec stock 5 passe à `product_quantity: 3` dans sa page ; un produit à `-1` reste à `-1`.
3. Carte `4000 0025 0000 3155` : valider l'authentification, retour sur le site, commande créée une seule fois.
4. Carte refusée `4000 0000 0000 9995` : message d'erreur, aucune commande.
5. Désactivez JavaScript sur la page de paiement : le bouton de repli `<noscript>` doit mener au paiement hébergé Stripe.

**B. Mode sans JavaScript** (`use_javascript: false`, vider le cache)
1. Étape paiement : bouton qui redirige vers la page Stripe hébergée ; payer avec `4242…` ; retour sur le site ; confirmation ; **une seule** commande.
2. Sur la page Stripe, cliquez « Retour » (annulation) : retour au site sans commande, panier intact.
3. Rechargez (F5) la page de retour après un paiement réussi : toujours **une seule** commande, pas d'e-mail en double.
4. Ouvrez `/checkout/confirmation` sans avoir commandé : aucune commande affichée ; ajouter `?order_id=…` dans l'URL ne montre rien d'une autre commande.

**C. Webhook** (après la configuration décrite dans `TODO.md`, « Configuration du webhook Stripe »)
1. Mode sans JavaScript : payez avec `4242…` et **fermez l'onglet sur la page Stripe**, juste avant le retour. Attendu (quelques
   secondes plus tard) : une commande dans la page Boutique, e-mails client et vendeur reçus, stock décrémenté.
   Dans Stripe : événement `payment_intent.succeeded`, réponse `200`.
2. Même scénario en mode JavaScript : payer puis fermer l'onglet avant la page de confirmation.
3. Payer normalement (sans fermer) : **une seule** commande et **un seul** jeu d'e-mails, que le webhook ou le retour arrive le premier.
4. Dans Stripe, *Renvoyer* l'événement : réponse `200`, aucune seconde commande.
5. Secret volontairement faux : réponse `400` dans Stripe et aucune commande créée par ce chemin.
6. Dans `logs/grav.log`, aucune ligne « Webhook : paiement … encaissé mais commande non créée » après ces essais.
