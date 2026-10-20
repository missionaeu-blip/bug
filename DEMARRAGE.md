# Missiona — Guide de démarrage

Le site est livré **vide**, prêt pour une utilisation réelle.
Aucun projet, aucun message, aucune facture fictive : tous les compteurs
démarrent à zéro et se remplissent avec votre activité réelle.

## 1. Avant la mise en ligne

Ouvrez `config.php` et changez **au minimum** le mot de passe administrateur :

```php
define('ADMIN_EMAIL',    'votre@email.fr');
define('ADMIN_PASSWORD', 'un-mot-de-passe-solide');
```

> Ces valeurs ne servent qu'à créer le compte au tout premier chargement.
> Si vous les modifiez ensuite, supprimez `data/missiona.sqlite`
> pour repartir d'une base neuve.

## 1 bis. Comptes de test

Trois comptes sont créés à la première ouverture :

| Rôle | Identifiant | Mot de passe |
|---|---|---|
| Administrateur | `admin@missiona.eu` | `admin123` |
| Entreprise | `entreprise@test.fr` | `test123` |
| Freelance | `freelance@test.fr` | `test123` |

Ils servent à parcourir la plateforme dans les trois rôles sans rien saisir.
Aucun projet, message ni facture fictif : les tableaux de bord restent à zéro.

**Avant la mise en ligne**, désactivez les deux comptes de test dans
`config.php` :

```php
define('COMPTES_TEST', false);
```

puis supprimez `data/missiona.sqlite` pour repartir d'une base propre.
Seul le compte administrateur sera recréé.

## 2. Installation sur l'hébergeur

1. Envoyez tous les fichiers dans le dossier public (`public_html`).
2. Donnez les droits d'écriture **755** aux dossiers `data/` et `uploads/`.
3. Ouvrez le site : la base de données se crée automatiquement.

Prérequis : PHP 8.0 ou supérieur avec l'extension SQLite (standard partout).

## 3. Premiers pas

1. Connectez-vous avec le compte administrateur défini dans `config.php`.
2. Réglez vos coefficients de matching et votre commission dans
   **Back-office → Paramètres**.
3. Les entreprises et freelances s'inscrivent eux-mêmes depuis le site.

## 4. Le cycle d'un projet

```
Entreprise dépose un projet   →  statut « nouveau »
  ↓
Vous l'étudiez au back-office →  « analyse »
  ↓
Vous attribuez un freelance   →  « attribué »   (score de matching à l'appui)
  ↓
Production                    →  « en cours »   (avancement en %)
  ↓
Livraison                     →  « terminé »    + facture et évaluation
```

L'entreprise ne cherche jamais de freelance elle-même : vous pilotez
l'attribution depuis le back-office.

## 5. Remettre le site à zéro

Supprimez `data/missiona.sqlite`. Au prochain chargement, une base
neuve est recréée avec le seul compte administrateur.

## 6. Sauvegarde

Deux éléments à sauvegarder régulièrement :
- `data/missiona.sqlite` — toutes les données
- `uploads/` — les fichiers livrés et les portfolios


## 7. Modèle de paiement

**La part Missiona est incluse dans le prix affiché au client.**

Un freelance qui annonce 2 500 € touche 2 500 €. Le client voit 3 000 €
dès le départ (avec un taux à 20 %) et ne voit jamais apparaître de
ligne de commission. Le détail n'existe que dans le back-office.

### Les deux échéances

| Étape | Quand | Montant | Effet |
|---|---|---|---|
| 1. Validation | À l'attribution de l'expert | 2 € de frais de dossier | Lance la mission |
| 2. Règlement | **À la livraison du livrable** | Prix total (part WC incluse) | Clôture le projet |

Le règlement de l'étape 2 est **verrouillé** tant que le projet n'est
pas passé au statut « Terminé ».

Les 2 € restent **en suspens** pendant toute la durée du projet :

- projet livré → statut `libere`, les frais sont acquis
- projet annulé → statut `rembourse`, les 2 € sont rendus au client

### Régler les montants

- Taux Missiona : Back-office → Paramètres → Commission
- Frais de dossier : `FRAIS_DOSSIER` dans `config.php` (2 € par défaut)

### Raccordement à un prestataire de paiement

Les règlements sont enregistrés en base mais aucun flux bancaire n'est
déclenché. Pour encaisser réellement, brancher le prestataire sur la
fonction `regler_paiement()` dans `includes/functions.php`.


## 8. Annulations et remboursements

### Côté client

Sur la page d'un projet, encadré **Annulation** : le client dépose une
demande (annulation ou remboursement) avec un motif obligatoire d'au
moins 10 caractères. Une seule demande peut être en cours à la fois, et
aucune n'est possible sur un projet déjà terminé ou annulé.

### Côté back-office

Onglet **Demandes** (badge rouge = nombre en attente) :

- **Accepter** → le projet passe en « annulé », les sommes versées sont
  remboursées, le client et le freelance sont notifiés
- **Refuser** → le projet continue, le client est notifié du motif

### Actions directes de l'administrateur

Sur la page d'un projet, encadré **Actions administrateur** :

- **Annuler le projet** — avec ou sans remboursement, sans demande préalable
- **Supprimer définitivement** — efface le projet, ses messages, fichiers,
  paiements et factures. Exige de saisir `SUPPRIMER` en majuscules.

L'historique des demandes traitées reste consultable sur la page du
projet et dans l'onglet Demandes.


## 9. Activer les paiements Stripe

Aucune bibliothèque à installer : le site dialogue directement avec l'API
Stripe en PHP pur.

### Étape 1 — Récupérer les clés

Créez un compte sur stripe.com, puis
Dashboard → Développeurs → Clés API.

**Commencez par les clés de test** (`sk_test_…` / `pk_test_…`).

### Étape 2 — Renseigner config.php

```php
define('STRIPE_SECRET_KEY', 'sk_test_...');
define('STRIPE_PUBLIC_KEY', 'pk_test_...');
```

### Étape 3 — Vérifier

Back-office → Paramètres affiche l'état : *Désactivé*, *Mode test* ou
*En production*.

Testez un paiement avec la carte `4242 4242 4242 4242`, une date future
et n'importe quel CVC. Aucun argent réel n'est débité.

### Étape 4 — Passer en production

Remplacez par les clés `sk_live_…` / `pk_live_…`.
Stripe exige que le site soit en **HTTPS** : activez le SSL avant.

### Fonctionnement

- Le client clique sur « Régler » → redirection vers la page sécurisée Stripe
- Après paiement → retour sur `paiement-retour.php`
- **Le paiement est revérifié auprès de Stripe** avant tout enregistrement :
  un retour falsifié dans l'URL ne valide rien
- À l'annulation d'un projet, les sommes encaissées sont **remboursées
  automatiquement** via Stripe

### Sans clés

Le site reste pleinement utilisable : les règlements sont enregistrés en
base sans encaissement réel. Pratique pour faire des démonstrations.


## 10. Forfaits des experts

Un expert peut proposer **autant de formules qu'il le souhaite** plutôt
qu'un tarif unique.

### Côté expert

Profil → onglet **Mes forfaits** → nom, prix, délai, contenu de la formule.
Exemple : Essentiel 800 € / Standard 1 500 € / Premium 3 000 €.

Le prix saisi est **ce que perçoit l'expert** : la part Missiona s'ajoute
ensuite au montant facturé au client.

### Effet sur le reste du site

- **Matching** : le score budgétaire utilise le forfait le moins cher
- **Fiche expert (admin)** : toutes les formules sont listées à l'attribution
- **Attribution** : l'échéance initiale est bâtie sur le forfait le moins cher
- L'équipe reste libre d'ajuster le montant final depuis le suivi du projet

### Sans forfait

Le champ « Tarif de base par projet » de l'onglet Informations prend le
relais. Rien n'est cassé pour les comptes existants.


## 11. Barre de progression

Dans le suivi d'un projet, la barre se règle **toute seule** d'après le statut :

| Statut | Avancement |
|---|---|
| Nouveau | 0 % |
| En analyse | 10 % |
| Attribué | 15 % |
| En cours | 50 % |
| En livraison | 85 % |
| Terminé | 100 % |

La case **« Suivre automatiquement le statut »** est cochée par défaut :
changez le statut, le pourcentage suit.

Pour fixer une valeur précise (par exemple 37 %), décochez la case ou tapez
directement dans le champ — la case se décoche alors d'elle-même et votre
valeur est conservée, y compris aux visites suivantes.


## 12. Équipe Missiona

Back-office → **Équipe**.

#### Répartition automatique des projets

Par défaut, chaque nouveau client est confié automatiquement au gestionnaire qui suit
le **moins de projets en cours**. La charge s'équilibre donc toute seule au fil des inscriptions,
sans intervention de votre part.

Depuis la page **Équipe** (administrateur principal uniquement) :

- un tableau montre, pour chaque gestionnaire, son nombre de clients, son nombre de projets
  en cours et une barre de charge comparative ;
- la case **« Attribuer automatiquement les nouveaux clients »** active ou coupe le mécanisme ;
- la case **« Inclure l'administrateur principal »** permet de le sortir de la rotation,
  s'il supervise plutôt qu'il ne gère des dossiers ;
- le bouton **« Lancer la répartition »** rééquilibre à la demande, au choix :
  *seulement les clients non assignés* (rattrapage sans rien casser) ou
  *tous les clients* (redistribution complète, qui écrase les portefeuilles manuels).

Seuls les projets **actifs** comptent : un dossier terminé ou annulé ne pèse plus dans la charge,
si bien qu'un gestionnaire qui clôture ses missions redevient prioritaire pour les suivantes.

L'attribution manuelle du panneau **Attribution manuelle** reste disponible à tout moment et
prime sur l'automatique : un client assigné à la main n'est jamais déplacé, sauf redistribution complète.

### Réglages réservés à l'administrateur principal

La page **Paramètres** est entièrement réservée à l'administrateur principal. Les gestionnaires
n'y accèdent pas et n'en voient aucun lien (barre latérale, tableau de bord, page de matching).
Elle regroupe les réglages structurants :

| Réglage | Pourquoi il est protégé |
|---|---|
| Coefficients du moteur de matching | Modifier l'algorithme change l'expert proposé sur tous les projets |
| Taux de commission | C'est le modèle économique de l'entreprise |
| Clés de paiement Stripe | Accès aux encaissements réels |
| Mode maintenance | Coupe l'accès public au site |

La protection est appliquée **côté serveur** (`require_admin_principal()`), pas seulement par
masquage des liens : un gestionnaire qui tenterait un POST direct sur l'URL est rejeté sans
qu'aucune valeur ne bouge en base.

Les gestionnaires conservent l'accès au **matching lui-même** (attribuer un expert à un projet) :
ils utilisent l'algorithme, ils n'en règlent pas les coefficients.

### Mode maintenance

L'**administrateur principal** peut fermer le site le temps d'une mise à jour, depuis
**Paramètres → Mode maintenance**. Les autres membres de l'équipe ne voient même pas ce panneau.

Pendant la maintenance :

- les visiteurs et tous les autres comptes (clients, experts, gestionnaires) reçoivent une
  page d'attente avec votre message personnalisé ;
- la réponse HTTP est un **503 avec `Retry-After`**, ce qui indique à Google une interruption
  temporaire : vos pages ne sont pas déréférencées (une page blanche ou un 200 le seraient) ;
- l'administrateur principal, lui, continue de naviguer normalement sur tout le site ;
- les fichiers CSS, images et scripts restent servis pour que la page d'attente s'affiche
  correctement.

**Si vous vous déconnectez pendant la maintenance**, un lien discret **« Accès administrateur »**
en bas de la page d'attente mène à la connexion. Une fois identifié, le lien devient
**« Désactiver la maintenance »** et vous ramène directement aux paramètres. Vous ne pouvez donc
jamais rester enfermé dehors.

Pour remettre le site en ligne : décochez la case et enregistrez.

### Qui peut modifier quoi

Tous les gestionnaires **voient l'intégralité des projets et des clients** : rien n'est caché au sein de l'équipe.
En revanche, **seul le gestionnaire assigné à un client peut modifier ses dossiers**.

| Action | Administrateur principal | Gestionnaire assigné au client | Autre gestionnaire |
|---|---|---|---|
| Consulter un projet, un client, les statistiques | oui | oui | oui |
| Changer le statut / l'avancement / le montant | oui | oui | non |
| Attribuer une mission à un expert | oui | oui | non |
| Traiter une demande d'annulation | oui | oui | non |
| Activer / désactiver un compte client | oui | oui | non |
| Gérer l'équipe (page Équipe) | oui | non | non |

Les clients **non assignés** (« Suivi par l'équipe ») restent modifiables par n'importe quel gestionnaire :
c'est le mode par défaut tant que vous n'attribuez pas de portefeuille.

Sur un dossier qu'il ne gère pas, le gestionnaire voit le panneau de back-office en **lecture seule**,
avec un bandeau indiquant le nom du collègue responsable. Les champs sont désactivés et toute tentative
d'envoi est refusée côté serveur.

## Nom du chargé de compte

Par défaut le site affiche « Sophie Dupont — Chargée de compte » aux
entreprises et aux freelances. Ce nom se change depuis cette page : il est
repris partout (messagerie, tableaux de bord) et les initiales de l'avatar
suivent automatiquement.

C'est une **identité d'affichage**, pas un compte de connexion.

### Administrateur principal

Le compte défini par `ADMIN_EMAIL` dans `config.php`
(par défaut `admin@missiona.eu`) est l'**administrateur principal**.

Lui seul voit l'onglet **Équipe** et peut :

- créer et supprimer des comptes administrateurs,
- assigner les entreprises à un gestionnaire,
- modifier le nom générique du chargé de compte.

Il ne peut **jamais** être supprimé, ni par lui-même ni par un autre compte.

### Gestionnaires de projet

Les comptes créés depuis cette page sont des gestionnaires : ils accèdent
au back-office (projets, utilisateurs, matching, messagerie) mais **pas** à
la gestion de l'équipe.

Assignez-leur des entreprises depuis le tableau « Gestionnaires de projet ».
Le gestionnaire devient alors l'interlocuteur affiché au client : son nom
apparaît dans la messagerie et sur le tableau de bord, et les messages du
client lui sont adressés directement.

Une entreprise sans gestionnaire voit le nom générique (« Sophie Dupont »
par défaut, modifiable plus haut).

### Garde-fous

- L'administrateur principal est protégé contre toute suppression
- Impossible de supprimer son propre compte
- À la suppression d'un gestionnaire, ses clients reviennent à l'équipe et
  l'historique de ses échanges est transféré au principal
- Les entreprises et freelances n'ont aucun accès à cette page
