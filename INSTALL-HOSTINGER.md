# PANNACOTTACLUB — installation sur Hostinger

Site de livraison de glaces, boissons et encas **sur la plage de Cabourg**.
Fonctionne avec **HTML + CSS + PHP + JavaScript + MySQL** : aucun Node.js, aucune
installation de programme, aucun serveur à maintenir.

Temps d'installation : **10 à 15 minutes.**

---

## Ce qu'il vous faut

- Un hébergement Hostinger avec **PHP 8.0 ou plus** et **MySQL** (toutes les offres en ont).
- Un domaine ou sous-domaine, idéalement avec le **SSL gratuit** activé (obligatoire pour le GPS).

---

## Étape 1 — Envoyer les fichiers

Le paquet contient déjà un dossier **`pannacotaclub/`** : il s'installe donc à côté
d'un site existant, sans rien écraser.

1. Ouvrez **hPanel → Fichiers → Gestionnaire de fichiers**.
2. Allez dans le dossier **`public_html`** (la racine de votre domaine).
3. Téléversez **`pannacottaclub-hostinger.zip`**, puis clic droit → **Extraire**.
4. Vous obtenez `public_html/pannacotaclub/` avec dedans : `index.php`, `api.php`,
   `install.php`, `config.php`, `includes/`, `assets/`, `.htaccess`.
   *Activez « Afficher les fichiers cachés » dans les réglages du gestionnaire de
   fichiers pour voir `.htaccess`.*
5. Le site répond alors sur **`https://votre-domaine.fr/pannacotaclub/`**.

> Pour l'installer **à la racine** du domaine : extrayez le ZIP puis déplacez le
> *contenu* de `pannacotaclub/` directement dans `public_html`. Tout fonctionne
> dans les deux cas, sans modifier un seul fichier.

---

## Étape 2 — Créer la base de données

Dans **hPanel → Bases de données → Bases de données MySQL** :

1. **Créer une base** : par exemple `pannacotta` → Hostinger la nomme
   `u123456789_pannacotta`. **Notez ce nom complet.**
2. **Créer un utilisateur** : par exemple `panna` avec un mot de passe solide →
   Hostinger le nomme `u123456789_panna`. **Notez-le.**
3. Cliquez sur **Attribuer** pour associer l'utilisateur à la base, avec
   **tous les privilèges**.

Vous avez maintenant vos 4 valeurs : hôte (`localhost`), nom de base, utilisateur, mot de passe.

---

## Étape 3 — Renseigner `config.php`

Ouvrez `config.php` (Gestionnaire de fichiers → clic droit → Modifier) et remplissez :

```php
'db' => [
    'host'    => 'localhost',
    'name'    => 'u123456789_pannacotta',   // ← nom complet de la base
    'user'    => 'u123456789_panna',        // ← nom complet de l'utilisateur
    'pass'    => 'VotreMotDePasse',         // ← mot de passe MySQL
    'port'    => 3306,
],

'seed' => [
    'admin'   => ['email' => 'vous@exemple.fr', 'password' => 'ChoisissezUnMotDePasseFort', 'name' => 'Direction'],
    'courier' => ['email' => 'livreur@exemple.fr', 'password' => 'AutreMotDePasseFort', 'name' => 'Léo'],
],
```

Profitez-en pour vérifier :

- **`zone`** : le centre et le rayon de livraison (déjà réglés sur la plage de
  Cabourg, postes de secours 1 à 2 : `49.29155 / -0.12125`, 900 m).
- **`defaults`** : nom du club, téléphone, horaires, frais de livraison (0 € = offerte),
  message d'accueil, etc.

Enregistrez.

---

## Étape 4 — Activer le HTTPS (indispensable)

**hPanel → Sécurité → SSL → Installer le SSL gratuit.** Attendez quelques minutes.

Ensuite, dans `.htaccess`, décommentez les deux lignes de la section **HTTPS** :

```apache
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}/$1 [R=301,L]
```

Sans HTTPS, les navigateurs **refusent de donner la position GPS** du client — or
c'est elle qui permet de livrer sur la plage.

---

## Étape 5 — Installer

1. Ouvrez `https://votre-domaine.fr/install.php` dans votre navigateur.
2. Cliquez sur **Installer maintenant**.
3. La page affiche ce qui a été créé : les 9 tables, les 3 catégories, les
   **23 produits**, les réglages, votre compte **administrateur** et votre compte **livreur**.
4. **Supprimez `install.php`** (clic droit → Supprimer). C'est la meilleure
   protection : personne ne pourra relancer l'installation.
5. Changez vos mots de passe depuis **Admin → Livreurs** dès la première connexion.

---

## Étape 6 — Vérifier que tout fonctionne

Ouvrez ces adresses :

| Adresse | Ce que vous devez voir |
|---|---|
| `https://votre-domaine.fr/` | Page d'accueil bleue et blanche, bouton **COMMANDER** |
| `/commander` | La carte avec glaces, boissons, encas |
| `/livreur` | L'écran de connexion du livreur |
| `/admin` | L'écran de connexion du propriétaire |

**Test complet (5 minutes) :** depuis votre téléphone, passez une commande (la
position GPS vous sera demandée — autorisez-la) ; sur un autre appareil, connectez-vous
en livreur, mettez l'interrupteur **En service**, puis « Je pars en livraison ».
L'écran du client doit se mettre à jour **tout seul**, sans jamais recharger la page.

### La carte du livreur en direct (page d'accueil)

La page d'accueil affiche une carte « En direct » (le plan du club, voir la
section suivante) : dès qu'un livreur part en livraison, sa position apparaît et
se déplace en temps réel, sous les yeux des visiteurs. Elle se remet à zéro dès la
livraison terminée.

- **Vie privée respectée** : la position (au mètre près) n'apparaît que
  pendant une course, aucune donnée client (nom, téléphone, adresse) n'est publiée,
  et elle s'arrête dès que la course est finie.
- **Vous gardez la main** : Admin → Réglages → décochez « Afficher le livreur en
  direct sur la page d'accueil » pour que la position redevienne privée. La carte
  disparaît alors de l'accueil ; le suivi de commande, lui, continue de fonctionner.
- Réglage par défaut, dans `config.php` : `'live_map_public' => true`.

### La carte de suivi — photo fournie + positions GPS

Le suivi client utilise **la photo transmise pour le projet, conservée exactement
comme reçue** (`assets/map/plage-littoral-originale.jpg`). Elle est affichée telle
quelle dans Leaflet : pas de tuiles, de filtre, de dessin ni d'autre fond de carte.
Seuls les repères « Vous » et « Livreur » sont superposés ; le point du livreur
s'actualise pendant la course. La carte reste déplaçable et zoomable.

Le placement GPS est calibré à partir des deux coordonnées indiquées par le client
pour les extrémités de la ligne bleue : ouest `49.2903, -0.1381`, est
`49.2905, -0.1299`. Ces coordonnées sont des ancres de l'image ; elles ne modifient
pas les coordonnées des postes de secours ni les règles de livraison.

La photo est stockée localement dans le site, donc le fond reste disponible hors
connexion. La position précise du client reste visible uniquement dans le suivi de
sa commande ; elle n'est jamais exposée sur la carte publique de l'accueil.

### La localisation du client — comment c'est fait

C'est le point le plus délicat du site, et il est traité sérieusement :

1. **Elle est demandée automatiquement** à l'ouverture du formulaire de commande,
   sans que le client ait à chercher un bouton.
2. **Elle est mesurée plusieurs fois** pendant 20 secondes et le site garde la
   **meilleure mesure** : une seule lecture GPS peut se tromper de 500 m, deux ou
   trois lectures ramènent souvent l'erreur sous 20 m. La précision est affichée en
   direct (« Recherche en cours… précision actuelle ± 32 m »).
3. **Elle s'améliore en continu** : tant que la fenêtre est ouverte, le site affine,
   et le client peut toucher **AFFINER** quand il veut.
4. **Position refusée ou impossible ?** Le site explique quoi faire (autoriser la
   localisation, sortir le téléphone de la poche, s'écarter du parasol), et le client
   peut **commander quand même** en écrivant une indication (« devant le poste 1,
   parasol bleu »). Dans ce cas le livreur voit **POSITION NON TRANSMISE** en rouge
   sur sa fiche, avec le repère écrit et le bouton d'appel.
5. **Une mesure très imprécise ne bloque jamais une commande** : au-delà de ±150 m,
   le site accepte et signale la commande comme « hors zone » pour que le livreur
   appelle avant de partir.
6. **HTTPS obligatoire** : sans lui, les navigateurs refusent de donner la position.
   Le site le dit explicitement au client si le site n'est pas en HTTPS.

### Imprimer les panneaux de plage

**Admin → Panneaux QR** :

1. Vérifiez l'adresse affichée (elle doit correspondre à votre domaine, ex.
   `https://votre-domaine.fr/commander`).
2. **Imprimer le panneau** (A4 ou A3, c'est encore plus lisible en A3), puis
   plastifiez-le.
3. Téléchargez aussi le QR au format PNG si vous voulez le faire figurer sur un
   menu, un tee-shirt ou une voile de parasol.
4. Placez un panneau à chaque accès de plage entre les postes de secours 1 et 2.

> Le QR code est fabriqué **dans le navigateur** : aucune image ne part sur Internet.

---

## Étape 7 — Au quotidien

**Le livreur** : ouvre `/livreur` sur son téléphone, se connecte une seule fois
(case « Rester connecté sur cet appareil » cochée), passe **En service**.
Son navigateur partage alors sa position pendant les livraisons. Sur chaque
commande il voit **À ENCAISSER : X €**, puis **PAIEMENT REÇU** et **LIVRÉE**.

**Le propriétaire** : `/admin` donne le tableau de bord (commandes du jour,
chiffre d'affaires, commandes à assigner), la carte temps réel, la gestion du
catalogue (prix, descriptions, photos, activer/désactiver) et des livreurs.

**Astuce** : depuis le téléphone, « Ajouter à l'écran d'accueil » installe
l'application (icône, plein écran). Le site fonctionne comme une appli.

---

## Comment ça marche (pour comprendre)

- **Temps réel sans rechargement** : comme l'hébergement mutualisé ne supporte pas
  les connexions permanentes, le site interroge le serveur à intervalle court —
  suivi client toutes les 4 s, espace livreur 5 s, administration 6 s, carte du
  livreur sur l'accueil 10 s, carte des prix 25 s. L'écran se met à jour tout seul.
- **Aucun paiement en ligne** : le client règle en espèces ou sans contact au
  livreur. Aucun compte client n'est nécessaire.
- **Sessions** : stockées dans la base MySQL, cookie sécurisé valable **90 jours**
  (ou **12 heures** si « rester connecté » est décoché, pratique pour le téléphone
  d'un livreur qui tourne).
- **Sécurité** : mots de passe hachés, 8 tentatives ratées → verrouillage 60 s,
  vérification de chaque donnée côté serveur, un client ne peut voir que **sa**
  commande (jeton secret dans le lien de suivi), les positions GPS ne sont jamais
  publiques, et **le partage de position s'arrête à la livraison**.
- **Autonomie totale** : tout est hébergé chez vous — le plan est dessiné par le
  site, le QR code est généré sur place. **Aucune requête vers un autre domaine**,
  donc le suivi fonctionne même quand le réseau de la plage faiblit.
- **Mise en cache** : un service worker garde l'interface en mémoire pour
  l'ouverture rapide, mais **ne met jamais en cache les données de commandes**.

---

## Remplacer une version précédente (dossier déjà en place)

Si un dossier `pannacotaclub/` existe déjà (ancienne version « sans JavaScript ») :
les fichiers du paquet portent **les mêmes noms**, l'extraction les remplace donc
directement. Deux précautions :

1. **Vos commandes.** L'ancienne version enregistrait tout dans un fichier
   `data/php-db.json` ; la nouvelle utilise **MySQL**. Les commandes déjà passées ne
   sont donc pas reprises automatiquement. Si vous en avez besoin, téléchargez ce
   fichier avant l'extraction (il reste intact), et demandez la reprise des données.
2. **`config.php`.** Écrasez-le aussi, puis renseignez vos identifiants MySQL
   (étape 3) : la nouvelle version lit une base MySQL, pas un fichier JSON.

Ensuite, ouvrez `https://votre-domaine.fr/pannacotaclub/install.php` une fois.
Le catalogue et les comptes sont recréés ; les commandes repartent de zéro.

## Sauvegardes

- **Base de données** : hPanel → **phpMyAdmin → Exporter**. Faites-le une fois par
  semaine en saison (la base contient les commandes, le catalogue et les comptes).
- **Fichiers** : seul `config.php` vous est propre. Le reste peut être remplacé par
  une version plus récente du paquet.

---

## Problèmes fréquents

| Symptôme | Solution |
|---|---|
| Page blanche / « Erreur serveur » | Les identifiants MySQL de `config.php` sont faux, ou la base n'est pas *attribuée* à l'utilisateur. Vérifiez, puis rechargez. |
| « Le site n'est pas encore installé » | Ouvrez `install.php` et cliquez sur **Installer maintenant**. |
| Le GPS est refusé ou imprécis | Vérifiez que le **HTTPS** est actif. Le client peut aussi écrire une indication libre (« devant le poste 1 ») ; dans tous les cas, la commande précise que le livreur appellera. |
| Aucune commande n'arrive au livreur | Le livreur doit être **En service** (interrupteur) et actif dans **Admin → Livreurs**. Vérifiez aussi que l'assignation automatique est activée dans **Admin → Réglages**. |
| « Trop de tentatives » à la connexion | Sécurité anti-force brute : attendez 60 secondes. |
| Un prix modifié n'apparaît pas | Il se répercute en quelques secondes sur la carte. Sinon, rechargez une fois. |
| Le QR code mène au mauvais site | **Admin → Panneaux QR** : l'adresse se règle là (elle est détectée automatiquement depuis votre domaine). |
| Le site est en sous-dossier | Rien à faire, tout est en chemins relatifs. |
| Passer à une version plus récente | Remplacez les fichiers sauf `config.php` et le dossier `data/`, puis rechargez la page. La base et les commandes sont conservées. |

---

## Contenu du paquet

```
index.php                 La page unique (client, suivi, livreur, admin)
api.php                   L'interface de programmation (toutes les actions)
install.php               Création des tables + catalogue + comptes  (à supprimer après usage)
config.php                VOS réglages : base de données, comptes, zone
.htaccess                 Adresses propres (/api/…), sécurité, cache
includes/
  bootstrap.php           Connexion MySQL + utilitaires + réglages
  auth.php                Connexion, sessions 90 jours, mots de passe hachés
  orders.php              Commandes, statuts, encaissement, positions
assets/
  css/style.css           La charte bleu #060CD4 et blanc
  js/app.js, core.js      L'application et les appels au serveur
  js/views/*.js           Écrans client, livreur, administration
  js/map.js               Le plan du club, dessiné par le site (SVG, à l'échelle)
  js/qrcode-generator.js  Générateur de QR code (licence MIT)
  img/                    Logo et illustrations produits
sw.js, manifest.webmanifest   Installation sur l'écran d'accueil
```
