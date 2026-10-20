# Stripe Payment Element — suivi d’intégration

Ce document est la source de vérité pour les éléments restants de l’intégration de paiement Missiona.

## Valeurs à remplacer

Les secrets ne sont volontairement pas présents dans le dépôt ni dans l’archive.

**Fichier modèle contenant les placeholders :**
- [config.local.exemple.php](config.local.exemple.php)

| Champ | Valeur actuelle | À renseigner |
|---|---|---|
| `STRIPE_SECRET_KEY` | `sk_test_...` | La clé secrète Stripe de **test** de la plateforme. |
| `STRIPE_PUBLIC_KEY` | `pk_test_...` | La clé publique Stripe de **test** de la plateforme, utilisée uniquement par Stripe.js. |
| `STRIPE_WEBHOOK_SECRET` | `whsec_...` | Le secret de signature de la destination webhook Stripe de test. |
| `STRIPE_ACCOUNTS_V2_VERSION` | `2026-09-30.endive` | Version requise pour les appels JSON Accounts v2 / Account Links v2 (déjà définie dans `config.php`). |

Ne placez jamais ces valeurs dans `config.php`, dans Git, dans une archive, ni dans une conversation.

## Paramètres configurés

**Fichiers concernés :**
- [includes/stripe.php](includes/stripe.php)
- [paiement.php](paiement.php)

| Paramètre | Valeur |
|---|---|
| Interface | Stripe Payment Element embarqué dans Missiona |
| Création serveur | `PaymentIntent` Stripe Connect direct charge |
| Compte de charge | Compte connecté de l’expert, via `Stripe-Account` |
| Moyens de paiement | Automatiques (`automatic_payment_methods[enabled]=true`) |
| Commission Missiona | `application_fee_amount`, calculé depuis le total du projet |
| Confirmation navigateur | `stripe.confirmPayment()` avec `elements.submit()` |
| Retour | `/paiement-retour?projet=…` |
| Confirmation définitive | Webhook Stripe signé et idempotent |

## Connect — Accounts v2 et onboarding hébergé

Les comptes experts ne sont **pas** créés avec l’ancienne API Accounts v1. `includes/stripe-connect.php` utilise exclusivement :

- `POST /v2/core/account_tokens`, pour encapsuler l’identité initiale requise par une plateforme établie en France ;
- `POST /v2/core/accounts`, en JSON, avec `account_token` et `dashboard: full` ;
- la configuration `merchant` et la capacité `card_payments` demandée, afin de recevoir les paiements directs ;
- `POST /v2/core/account_links`, avec `use_case.type: account_onboarding` et `collection_options.fields: eventually_due`. La configuration `merchant` est déjà portée par le compte v2 et ne doit pas être renvoyée dans l’Account Link avec la version Stripe `2026-09-30.endive`.

La France impose un **Account Token** pour les informations d’identité d’un compte marchand. Cette livraison est strictement limitée au mode test : Stripe y autorise la création serveur temporaire du token avec la clé de test, qui reste exclusivement côté serveur. Le token opaque retourné par Stripe est à usage unique, n’est jamais exposé au navigateur, est mémorisé au plus neuf minutes dans la session de l’expert pour rendre un éventuel retry idempotent, puis est supprimé. Il porte `contact_email`, `display_name` et `identity.entity_type`. L’API Account Token v2 ne reconnaît pas `identity.country` : ce pays, obligatoire et non tokenisé par Stripe, est donc envoyé seul dans la requête Accounts v2 avec `account_token`.

Stripe héberge le KYC complémentaire, les coordonnées de versement et le parcours d’onboarding. Missiona ne conserve que l’identifiant `acct_…`. Le retour navigateur est contrôlé par un jeton de session puis l’état est relu à l’API ; il ne valide jamais à lui seul un compte ou un paiement.

Le `PaymentIntent` reste sur l’API Stripe v1 mais est créé avec l’en-tête `Stripe-Account: acct_…`, ce qui est le flux de **paiement direct** compatible avec les comptes Accounts v2. La commission reste `application_fee_amount`.

## Configuration et étapes restantes

1. Copier `config.local.exemple.php` vers `config.local.php`, puis placer ce fichier dans `../missiona-config/` (dossier frère de `public_html`, hors du Web) et renseigner uniquement les clés de test :

   ```php
   define('STRIPE_MODE', 'test');
   define('STRIPE_SECRET_KEY', 'sk_test_...');
   define('STRIPE_PUBLIC_KEY', 'pk_test_...');
   define('STRIPE_WEBHOOK_SECRET', 'whsec_...');
   // Optionnel : la valeur par défaut est déjà définie dans config.php.
   define('STRIPE_ACCOUNTS_V2_VERSION', '2026-09-30.endive');
   ```

2. Dans Stripe en **mode test**, créer une destination dans **Workbench → Webhooks** :
   - source : **Events on connected accounts** ;
   - format : **Snapshot** ;
   - destination : **Webhook endpoint** ;
   - URL :

     ```text
     https://missiona.eu/stripe-webhook
     ```

   Événements à sélectionner :
   - `account.updated`
   - `payment_intent.succeeded`
   - `payment_intent.processing`
   - `payment_intent.payment_failed`
   - `charge.refunded`

3. Déployer l’archive, puis tester tout le parcours :
   - l’expert termine son onboarding Stripe ;
   - la capacité `merchant.card_payments` du compte expert devient `active` (le snapshot Connect expose aussi `charges_enabled`) ;
   - l’équipe attribue un **nouveau** projet après l’activation de Stripe de test ;
   - le client ouvre le Payment Element depuis le projet et paie.

4. Cartes de test utiles :

   | Numéro | Résultat attendu |
   |---|---|
   | `4242 4242 4242 4242` | Paiement réussi sans authentification. |
   | `4000 0025 0000 3155` | Paiement avec authentification 3D Secure. |
   | `4000 0000 0000 9995` | Paiement refusé. |

   Utilisez une date future, un CVC quelconque et un code postal quelconque.

5. Avant toute production, faire une revue dédiée des responsabilités Connect : frais Stripe, remboursements, litiges, conditions contractuelles et passage explicite du verrou de test vers le mode live. Cette livraison refuse volontairement les clés `sk_live_…`.

## Structure et fonctionnement

Nouveaux ou principaux fichiers du flux :

- [paiement.php](paiement.php) : page protégée qui crée/récupère le PaymentIntent et affiche Stripe Payment Element.
- [includes/stripe-connect.php](includes/stripe-connect.php) : création Accounts v2, Account Link v2 hébergé et relecture des capacités/requêtes Stripe.
- [includes/stripe.php](includes/stripe.php) : client JSON v2 limité à Connect, création idempotente du PaymentIntent direct v1, `application_fee_amount`, relecture serveur et remboursement.
- [paiement-retour.php](paiement-retour.php) : vérifie le PaymentIntent côté serveur après un éventuel retour 3D Secure.
- [stripe-webhook.php](stripe-webhook.php) : finalise la mission à réception de `payment_intent.succeeded`.
- [projet.php](projet.php) : entrée client vers la page de paiement embarquée.

Flux :

1. Le client choisit « Continuer vers le paiement sécurisé » depuis son projet.
2. Missiona crée ou reprend un PaymentIntent sur le compte Stripe de l’expert, avec une clé d’idempotence persistée.
3. Stripe.js monte le Payment Element : les données de carte restent dans l’iframe Stripe.
4. Stripe confirme le paiement ; les moyens nécessitant une authentification reviennent vers Missiona.
5. Le webhook signé `payment_intent.succeeded` met le paiement à jour et démarre la mission. Le retour navigateur est une seconde vérification, jamais la source de vérité.

## Ressources

- [Stripe Elements](https://docs.stripe.com/payments/elements)
- [Payment Element avec Connect direct charges](https://docs.stripe.com/connect/direct-charges?platform=web&ui=elements)
- [Stripe Accounts v2](https://docs.stripe.com/connect/accounts-v2/account-creation)
- [Stripe Account Tokens v2](https://docs.stripe.com/api/v2/core/account-tokens)
- [Stripe Account Links v2](https://docs.stripe.com/api/v2/core/account-links)
- [Support Stripe](https://support.stripe.com)
- [Documentation Stripe MCP](https://docs.stripe.com/mcp)
