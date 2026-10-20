<?php
/**
 * Missiona — Réglages de VOTRE installation
 * ==================================================================
 *  MODE D'EMPLOI
 *   1. Copiez ce modèle sous le nom « config.local.php ».
 *   2. Sur l'hébergement, créez le dossier frère de public_html :
 *          ../missiona-config/
 *      puis placez-y le fichier :
 *          ../missiona-config/config.local.php
 *   3. Retirez les // devant les lignes qui vous concernent.
 *   4. Laissez les autres commentées : les valeurs par défaut de
 *      config.php s'appliqueront.
 *   5. Gardez une copie sur votre ordinateur.
 *
 *  Ce dossier est hors de public_html : une nouvelle livraison du site
 *  ne peut donc ni l'écraser ni l'exposer sur le Web. Tout ce qu'il
 *  définit l'emporte sur config.php.
 *
 *  Aucun espace ni ligne vide avant le <?php ci-dessus, sous peine
 *  d'un message « headers already sent ».
 * ==================================================================
 */

/* ==================================================================
 *  À RENSEIGNER EN PREMIER
 * ================================================================== */

/* Adresse publique du site, SANS barre oblique finale.
   Indispensable si votre domaine diffère de missiona.eu, sinon les
   liens partagés et le référencement pointeront au mauvais endroit. */
// define('SITE_URL',   'https://missiona.eu');

/* Identité affichée sur le site et dans les factures. */
// define('SITE_NOM',   'Missiona');
// define('SITE_EMAIL', 'entreprise@missiona.eu');
// define('SITE_TEL',   '01 23 45 67 89');

/* ------------------------------------------------------------------
 *  Compte administrateur
 * ------------------------------------------------------------------
 *  ATTENTION : ces deux lignes ne servent qu'au TOUT PREMIER
 *  chargement du site, quand la base est encore vide. Ensuite, le
 *  mot de passe se change dans le back-office.
 *
 *  Renseignez-les AVANT d'ouvrir le site pour la première fois.
 *  Le mot de passe par défaut (admin123) ne doit jamais rester.
 * ------------------------------------------------------------------ */
// define('ADMIN_EMAIL',    'vous@votre-domaine.eu');
// define('ADMIN_PASSWORD', 'un-mot-de-passe-long-et-unique');

/* ==================================================================
 *  EMPLACEMENT DES DONNÉES
 * ------------------------------------------------------------------
 *  Par défaut, la base est rangée toute seule dans un dossier situé
 *  À CÔTÉ du site (…/missiona-data/), pour qu'une mise à jour des
 *  fichiers ne puisse jamais l'effacer.
 *
 *  N'imposez un chemin que si vous savez pourquoi. Il doit se trouver
 *  HORS du dossier public et ne plus jamais changer : le modifier
 *  revient à repartir d'une base vide.
 * ================================================================== */
// define('DB_FILE', '/home/votre-compte/prive/missiona.sqlite');

/* ==================================================================
 *  E-MAILS — SMTP DE VOTRE HÉBERGEMENT
 * ------------------------------------------------------------------
 *  Réglages du compte e-mail de votre hébergement (ex. Hostinger :
 *  « E-mails » → votre adresse → « Configuration » → SMTP).
 *
 *  Port 465 = connexion chiffrée SSL ; port 587 = STARTTLS.
 *
 *  Tant que ces lignes restent commentées, le site essaie d'envoyer
 *  les e-mails par la fonction mail() de l'hébergement (résultat
 *  variable selon l'hébergeur ; le SMTP est recommandé).
 * ================================================================== */
// define('SMTP_HOST', 'smtp.hostinger.com');
// define('SMTP_PORT', 465);
// define('SMTP_USER', 'contact@votre-domaine.eu');
// define('SMTP_PASS', 'le-mot-de-passe-de-la-boite-mail');

/* ==================================================================
 *  PAIEMENTS — STRIPE CONNECT (TEST)
 * ------------------------------------------------------------------
 *  Parcours choisi : Stripe Accounts v2 et Account Token de test, onboarding
 *  Stripe hébergé avec
 *  Account Links v2 et tableau de bord Stripe complet, puis paiement direct par carte
 *  sur le compte de l'expert. Missiona reçoit sa commission via
 *  application_fee_amount. Aucun IBAN n'est enregistré par Missiona.
 *
 *  1. Gardez STRIPE_MODE sur test et utilisez exclusivement sk_test_…
 *  2. Dans Stripe TEST, créez un webhook vers :
 *        https://votre-domaine/stripe-webhook
 *     Événements de comptes connectés : account.updated,
 *     payment_intent.succeeded, payment_intent.processing,
 *     payment_intent.payment_failed, charge.refunded.
 *  3. Collez ci-dessous le secret de signature de CE webhook de test.
 *
 *  Ne copiez jamais ces valeurs dans config.php, Git ou une archive.
 * ================================================================== */
// define('STRIPE_MODE',           'test');
// define('STRIPE_SECRET_KEY',     'sk_test_...');
// define('STRIPE_PUBLIC_KEY',     'pk_test_...'); // requis par Stripe.js / Payment Element
// define('STRIPE_WEBHOOK_SECRET', 'whsec_...');
// Laissez la valeur publiée par Stripe sauf indication contraire de leur support.
// define('STRIPE_ACCOUNTS_V2_VERSION', '2026-09-30.endive');

/* ==================================================================
 *  CONNEXION AVEC GOOGLE
 * ------------------------------------------------------------------
 *  Une seule fois, sur console.cloud.google.com :
 *   1. Créez un projet.
 *   2. « API et services » → « Écran de consentement OAuth » :
 *      type Externe, nom du site et votre adresse.
 *   3. « Identifiants » → « Créer » → « ID client OAuth » →
 *      type « Application Web ».
 *   4. Dans « URI de redirection autorisés », collez EXACTEMENT :
 *          https://votre-domaine/google-callback.php
 *      La moindre différence (http au lieu de https, www en trop)
 *      provoque l'erreur redirect_uri_mismatch.
 *
 *  Tant que ces lignes restent commentées, le bouton Google ne
 *  s'affiche pas et le site fonctionne normalement sans lui.
 * ================================================================== */
// define('GOOGLE_CLIENT_ID',     '123456789-xxxx.apps.googleusercontent.com');
// define('GOOGLE_CLIENT_SECRET', 'GOCSPX-xxxxxxxxxxxxxxxx');

/* Seulement si la détection automatique de l'adresse de retour échoue
   (site derrière un proxy, installation dans un sous-dossier). */
// define('GOOGLE_REDIRECT_URI', 'https://votre-domaine/google-callback.php');

/* ==================================================================
 *  MODÈLE ÉCONOMIQUE
 * ------------------------------------------------------------------
 *  Valeurs de départ uniquement. Une fois le site installé, réglez-les
 *  dans le back-office : ce que vous y saisissez prime sur ces lignes.
 * ================================================================== */
// define('COMMISSION_DEFAUT',  20);   // part Missiona, en %
// define('FRAIS_DOSSIER',       2);   // (historique) frais de validation, en €
// define('BUDGET_PLAFOND_CFG', 50000); // plafond du curseur de budget, en €

/* ==================================================================
 *  DÉPANNAGE
 * ================================================================== */

/* Affiche les erreurs à l'écran. À n'activer qu'en local, jamais en
   ligne : un message d'erreur révèle les chemins de votre serveur. */
// define('MODE_DEBUG', true);

/* ------------------------------------------------------------------
 *  Je ne peux plus me connecter
 * ------------------------------------------------------------------
 *  Décommentez la ligne suivante avec une phrase longue connue de
 *  vous seul, puis ouvrez :
 *
 *      https://votre-domaine/secours-admin.php
 *
 *  Cet écran recherche toutes les bases présentes sur l'hébergement,
 *  indique combien de comptes contient chacune, permet de remettre la
 *  bonne en service, de changer le mot de passe administrateur ou
 *  d'en créer un nouveau.
 *
 *  RECOMMENTEZ cette ligne dès que l'accès est rétabli : tant qu'elle
 *  est active, qui devine la phrase peut reprendre la main.
 * ------------------------------------------------------------------ */
// define('CLE_SECOURS', 'une-phrase-longue-et-unique');
