# Guide du dépôt — plugin Grav `simple-shop-payment-stripe`

> Ce fichier est écrit pour l'agent (Claude) et pour le propriétaire. Il doit permettre de travailler sur ce plugin
> **sans accès immédiat aux autres dépôts** : contexte, décisions, historique et travail restant y sont recopiés.
> Mettre à jour les sections « À faire » et « Historique » à chaque lot de travail.

## Communication
- S'adresser au propriétaire en **français** ; code, commentaires et messages du plugin en français.
- Ne jamais reproduire de secret (clés Stripe, identifiants Colissimo, mots de passe SMTP, sel Grav). Ce dépôt est **public** :
  aucune clé, même de test, ne doit y entrer (configuration réelle : `user/config/plugins/simple-shop-payment-stripe.yaml` du site, jamais versionnée).
- Ne pas supprimer de fichiers à la place du propriétaire : les lister dans « À supprimer par le propriétaire » ci-dessous.

## Rôle du plugin

Passerelle de paiement Stripe pour `simple-shop` (événement `onSimpleShopRegisterGateways`, interfaces
`Gateways\PaymentGatewayInterface` et `RedirectPaymentGatewayInterface` de simple-shop). Bibliothèque officielle embarquée
(`stripe-php/`, chargée par `init.php`).

- **Deux modes** (`use_javascript`) :
  - `true` : formulaire intégré (Stripe Elements, `PaymentIntent`, `js/stripe-payment.js`, partial `js.html.twig`) avec repli
    `<noscript>` vers la page hébergée — **seule exception** au principe « sans JavaScript » du site ;
  - `false` : **Checkout Session** sur la page hébergée Stripe, sans JavaScript (partial `nojs.html.twig`).
- Retour client sur `/checkout/payment-return` (simple-shop) : statut et **montant en centimes** vérifiés auprès de Stripe
  (`billing_total_cents` + devise) ; commande idempotente (référence de paiement unique, table `order_payment`).
- **Webhook** `/stripe/webhook` (`classes/Controllers/WebhookController.php`, route modifiable par `webhook_route`) : événement
  `payment_intent.succeeded`, signature vérifiée avec `webhook_secret` ; crée la commande si le client a fermé l'onglet avant le
  retour (instantané `pending_payment` enregistré au démarrage du paiement, `OrderFinalizer` partagé de simple-shop, e-mails une seule
  fois). Réponses : 400 signature absente/invalide, 503 secret non configuré, 500 erreur temporaire (Stripe réessaie), 200 sinon
  (y compris les échecs définitifs, journalisés pour rapprochement manuel).
- Autoloader PSR-4 déclaré **dans le constructeur** et abonnement **statique** à `onMvcRegisterRoutes` (sinon erreur sur tout le site :
  mvc déclenche l'événement avant le `onPluginsInitialized` de ce plugin).
- Clés (`stripe_mode`, `test_*`, `live_*`, `webhook_secret`) : configuration du site uniquement, **jamais dans ce dépôt public**
  (d'anciennes clés de test présentes dans l'historique du site ont été remplacées par `CLE-STRIPE-RETIREE` lors de l'extraction).

## Famille de plugins

Extraits le 15/10/2026 du dépôt du site `senonevero.flavieng.com` (`private/grav/plugins/<nom>`), historique Git conservé
(commits ne touchant que ce dossier ; clés Stripe de test présentes dans d'anciennes versions remplacées par `CLE-STRIPE-RETIREE`).

| Dossier du plugin | Dépôt | Rôle |
|---|---|---|
| `mvc` | [flavi1/grav-mvc](https://github.com/flavi1/grav-mvc) | routes → contrôleurs (`Request`, `Response`, `AbstractController`) ; requis par simple-shop |
| `simple-shop` | [flavi1/grav-simple-shop](https://github.com/flavi1/grav-simple-shop) | boutique : panier, tunnel sans JavaScript, OTP, livraison, taxes, commandes, factures Factur-X, API Admin2 |
| `simple-shop-carrier-colissimo` | [flavi1/grav-simple-shop-carrier-colissimo](https://github.com/flavi1/grav-simple-shop-carrier-colissimo) | transporteur Colissimo (grille Facilité, points de retrait) |
| `simple-shop-payment-stripe` **(ce dépôt)** | [flavi1/grav-simple-shop-payment-stripe](https://github.com/flavi1/grav-simple-shop-payment-stripe) | paiement Stripe (formulaire intégré ou page hébergée), webhook |
| `simple-shop-payment-test` | [flavi1/grav-simple-shop-payment-test](https://github.com/flavi1/grav-simple-shop-payment-test) | passerelle de paiement simulée (sites de test) |
| — | [flavi1/senonevero.flavieng.com](https://github.com/flavi1/senonevero.flavieng.com) (privé) | site d'origine : thème `bookstore` (type de produit `book`), configuration, pages |

## Installation sur le serveur (hébergement mutualisé multi-domaines)

- Grav 2.0 est installé **une fois** dans `/shared/grav-2/` et sert plusieurs domaines (`/domains/<domaine>/private/grav` = dossier
  `user://` de chaque site ; routeurs hors de ces dépôts).
- Ce plugin est déployé dans **`/shared/grav-2/user/plugins/simple-shop-payment-stripe/`** (chemin absolu chez OVH : `/home/<identifiant>/shared/…`), partagé par tous les domaines.
- Grav cherche un plugin d'abord dans le dossier du site (`user://plugins`, soit `/domains/<domaine>/private/grav/plugins/simple-shop-payment-stripe`),
  **puis** dans le dossier partagé. Une copie restée dans un site masque donc la version partagée : la supprimer de ce site
  pour qu'il utilise la version partagée.
- **Désactivé par défaut**, comme tous les plugins `mvc` et `simple-shop-*` (`enabled: false` dans `simple-shop-payment-stripe.yaml`), puisque le dossier est commun à tous les domaines : chaque site
  l'active dans sa propre configuration (`user/config/plugins/simple-shop-payment-stripe.yaml` : `enabled: true`, ou Admin2 > Plugins).
- Fichiers publics : le routeur de domaine ne sert depuis `/user/plugins/…` que les ressources statiques (CSS, JS, images, polices) ;
  `.php`, `.yaml`, `.md`, `.json`, `.lock`… répondent 403.

## Déploiement (GitHub Actions → rsync sur SSH)

Chaque push sur `main` lance `.github/workflows/deploy.yml` (même principe que « Deploy to OVH via rsync/ssh » du site) :
1. **Contrôle** : `php -l` sur tous les fichiers PHP (hors bibliothèques tierces).
2. **Envoi** : `rsync --delete` sur SSH (mot de passe via `sshpass`) vers `/home/<identifiant>/shared/grav-2/user/plugins/simple-shop-payment-stripe/`.
   Le dossier du plugin devient l'image exacte du dépôt (un fichier supprimé du dépôt disparaît du serveur), sauf `.git*`,
   `.github/`, `tests/`, `docs/`, `AGENTS.md`, jamais envoyés. Rien n'est touché en dehors de `…/plugins/simple-shop-payment-stripe/`.
3. Relance à la main : onglet *Actions* → *Déploiement (rsync/ssh)* → *Run workflow* (ou *Re-run all jobs* d'une exécution).

**Mise en place (une fois par dépôt)** — GitHub → dépôt `flavi1/grav-simple-shop-payment-stripe` → *Settings* → *Secrets and variables* → *Actions*.
Les noms gardent le préfixe `FTP_` (premier essai en FTP) mais ce sont les identifiants de l'hébergement, valables aussi en SSH :
- *Secrets* : `FTP_SERVER` (hôte, ex. `ftp.clusterNNN.hosting.ovh.net` ; le workflow essaie aussi `ssh.clusterNNN.hosting.ovh.net`),
  `FTP_USERNAME` (identifiant de l'hébergement), `FTP_PASSWORD`.
- *Variables* (facultatives) :
  - `FTP_PLUGINS_DIR` : chemin **absolu** du dossier des plugins partagés, avec ou sans `/` final ; par défaut
    `/home/<FTP_USERNAME>/shared/grav-2/user/plugins` (chemin d'un hébergement OVH) ;
  - `SSH_HOST` : hôte SSH s'il diffère des deux ci-dessus ; `SSH_PORT` (`22`).
- SSH doit être ouvert sur l'hébergement (offre OVH Pro ou supérieure : c'est déjà le cas pour le déploiement du site).

Vérification : le journal de l'étape « Envoi » liste les fichiers transmis ; `simple-shop-payment-stripe.php` doit être présent dans le dossier.

## Conventions (reprises du site d'origine)
- Fonctionnement **sans JavaScript** côté client public (hors option Stripe « formulaire intégré ») ; JavaScript admis côté Admin2.
- Un contrôleur retourne toujours une `Response` ; pas de `exit`, pas d'accès direct à `$_POST`/`$_GET`/`$_SERVER` (passer par `Request`).
- Écriture de fichiers partagés : verrou + fichier temporaire + `rename`.
- Pas de `innerHTML` avec des données dans le JavaScript.
- Tester en ligne de commande ce qui peut l'être (`php -l`, scripts de `tests/`) ; signaler explicitement ce qui n'est testable
  qu'après déploiement (Admin2, Stripe réel, service Colissimo réel).
- Commits distincts par étape, poussés directement sur `main` (le déploiement suit).

## À faire
- [ ] **Clés de production** : créer les clés live et le secret de webhook live au moment de la mise en production (jamais dans ce dépôt).
- [ ] **Flux réels à tester** (clés de test) : mode JS, mode sans JS, annulation, rechargement de la page de retour (une seule
  commande), montant incohérent refusé, webhook — `docs/TESTS-MANUELS.md` §3.
- [ ] **Brancher le webhook chez Stripe** (une fois par mode, test puis live) — procédure ci-dessous.
- [ ] **Documents de simple-shop** (19/10/2026, `classes/Gateways/DocumentPaymentGateway.php`) : tester en mode test le paiement
  d'une facture envoyée depuis Admin2 > Ventes (simple-shop `docs/TESTS-MANUELS.md` §25, points 5 à 7, dont le webhook). Le même
  point de terminaison et le même événement servent : rien à ajouter chez Stripe.

## Configuration du webhook Stripe (procédure du site, à refaire en « live » le jour de la mise en production)
1. **Sur le serveur**, `user/config/plugins/simple-shop-payment-stripe.yaml` : `enabled: true`, clés du mode utilisé renseignées.
   La route répond en `https://VOTRE-SITE/stripe/webhook` ; elle doit être accessible en HTTPS public (sans authentification ni
   redirection vers `www`).
2. **Dans Stripe** (bascule « Mode test ») : *Développeurs* → *Webhooks* → *Ajouter un point de terminaison* (interface 2026 :
   *Ajouter une destination* → type *Compte* → coller l'URL).
3. **Un seul événement** : `payment_intent.succeeded` (il couvre le formulaire intégré et la page hébergée).
4. Enregistrer, ouvrir le point de terminaison et **révéler le « Secret de signature »** (`whsec_…`).
5. Le coller dans `webhook_secret` (fichier du serveur ou écran du plugin). Vider le cache Grav. Ne jamais le commiter.
6. Vérifier : paiement de test (`4242 4242 4242 4242`) puis fermer l'onglet **avant** le retour sur le site ; dans Stripe, la
   livraison de l'événement affiche *200* et la commande apparaît dans Admin2 > Ventes avec ses e-mails.
7. En cas de problème : *Webhooks* → point de terminaison → *Événements* → réponse (`400` secret incorrect ou corps modifié par un
   proxy ; `503` secret vide ; `500` erreur temporaire). Échecs définitifs (montant différent, panier modifié) : `200` + ligne dans
   `logs/grav.log` avec la référence du paiement.
8. Renvoyer un événement depuis Stripe est sans risque : la création de commande est idempotente.

## Historique
- `git log` : commits d'origine (messages en français, un par étape).
- `docs/HISTORIQUE.md` : ce qui a été **délibérément** fait pour ce plugin (repris de `DONE.md` du site), avec les preuves.
- `docs/TESTS-MANUELS.md` : §3 du site (paiement Stripe en mode test).
