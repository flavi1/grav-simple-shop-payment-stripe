# Sécurité

## Signaler une vulnérabilité

Merci de **ne pas ouvrir de ticket public**. Utilisez le signalement privé de GitHub : onglet *Security* → *Report a
vulnerability* de ce dépôt. Réponse sous quelques jours ; un correctif est publié sur `main` puis déployé.

## Principes
- Aucun secret dans ce dépôt (clés Stripe, identifiants Colissimo, SMTP) : ils vivent dans la configuration de chaque site
  (`user/config/plugins/<plugin>.yaml`), jamais versionnée.
- Le plugin est **désactivé par défaut** ; chaque site l'active explicitement.
- Paiement vérifié auprès de Stripe (statut, montant en centimes, identifiant lié à la session) ; webhook à signature vérifiée
  (`webhook_secret`) ; création de commande idempotente.
