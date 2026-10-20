<?php
/**
 * Missiona — Réglages par défaut
 * ==================================================================
 *  NE MODIFIEZ PAS CE FICHIER.
 *
 *  Il est remplacé à chaque mise à jour : vos changements seraient
 *  perdus. Placez plutôt « config.local.php » HORS de public_html,
 *  dans le dossier frère recommandé :
 *
 *      ../missiona-config/config.local.php
 *
 *  Exemple d'arborescence :
 *      /home/votre-compte/public_html/       ← le site remplaçable
 *      /home/votre-compte/missiona-config/   ← votre configuration privée
 *
 *  Un ancien config.local.php encore dans public_html reste accepté
 *  provisoirement pour ne casser aucune installation existante, mais
 *  l'emplacement externe est toujours prioritaire. Un modèle est fourni :
 *      config.local.exemple.php
 * ==================================================================
 */

/* Réglages propres à cette installation. Chargés avant les valeurs par
   défaut. Le chemin externe évite de recréer les clés et réglages après
   chaque livraison de public_html. */
$_missiona_config_paths = [];
$_missiona_config_env = getenv('MISSIONA_CONFIG_FILE');
if (is_string($_missiona_config_env) && preg_match('#^/#', $_missiona_config_env)) {
    $_missiona_config_paths[] = $_missiona_config_env;
}
$_missiona_config_paths[] = dirname(__DIR__) . '/missiona-config/config.local.php';
// Variante admise si votre hébergeur ne permet pas de créer un sous-dossier.
$_missiona_config_paths[] = dirname(__DIR__) . '/config.local.php';
// Compatibilité avec les installations déjà déployées avant cette version.
$_missiona_config_paths[] = __DIR__ . '/config.local.php';
foreach (array_unique($_missiona_config_paths) as $_missiona_config_path) {
    if (is_file($_missiona_config_path) && is_readable($_missiona_config_path)) {
        require_once $_missiona_config_path;
        break;
    }
}
unset($_missiona_config_paths, $_missiona_config_env, $_missiona_config_path);

/* ==================================================================
 *  1. IDENTITÉ DU SITE
 * ================================================================== */
defined('SITE_NOM')   || define('SITE_NOM',   'Missiona');
defined('SITE_EMAIL') || define('SITE_EMAIL', 'entreprise@missiona.eu');
defined('SITE_TEL')   || define('SITE_TEL',   '');

/* Adresse publique, sans barre oblique finale. Sert aux liens absolus,
   au plan du site et aux données de référencement. À corriger si votre
   domaine change, sinon Google indexera de mauvaises adresses. */
defined('SITE_URL')   || define('SITE_URL',   'https://missiona.eu');

/* ==================================================================
 *  2. COMPTE ADMINISTRATEUR
 * ------------------------------------------------------------------
 *  Ce compte est créé au TOUT PREMIER chargement du site, et
 *  uniquement à ce moment-là. Le modifier ensuite ici ne change rien :
 *  passez par le back-office, ou par secours-admin.php.
 * ================================================================== */
defined('ADMIN_EMAIL')    || define('ADMIN_EMAIL',    'admin@missiona.eu');
defined('ADMIN_PASSWORD') || define('ADMIN_PASSWORD', 'admin123');

/* ==================================================================
 *  3. COMPTES DE DÉMONSTRATION
 * ------------------------------------------------------------------
 *  true  : crée aussi une entreprise et un expert de test au premier
 *          chargement, pour parcourir la plateforme sans rien saisir.
 *  false : seul l'administrateur est créé. Valeur à conserver en ligne.
 * ================================================================== */
defined('COMPTES_TEST') || define('COMPTES_TEST', false);

defined('TEST_ENTREPRISE_EMAIL')    || define('TEST_ENTREPRISE_EMAIL',    'entreprise@test.fr');
defined('TEST_ENTREPRISE_PASSWORD') || define('TEST_ENTREPRISE_PASSWORD', 'test123');
defined('TEST_FREELANCE_EMAIL')     || define('TEST_FREELANCE_EMAIL',     'freelance@test.fr');
defined('TEST_FREELANCE_PASSWORD')  || define('TEST_FREELANCE_PASSWORD',  'test123');

/* ==================================================================
 *  4. MODÈLE ÉCONOMIQUE
 * ------------------------------------------------------------------
 *  Valeurs de départ seulement : une fois le site installé, elles se
 *  règlent dans le back-office (Paramètres), qui prime sur celles-ci.
 * ================================================================== */
defined('COMMISSION_DEFAUT')  || define('COMMISSION_DEFAUT',  20);   // part Missiona, en %
defined('FRAIS_DOSSIER')      || define('FRAIS_DOSSIER',       2);   // (historique) frais de validation, en €
defined('BUDGET_PLAFOND_CFG') || define('BUDGET_PLAFOND_CFG', 50000); // plafond du curseur de budget, en €

/* ==================================================================
 *  5. PAIEMENTS — STRIPE CONNECT (TEST UNIQUEMENT)
 * ------------------------------------------------------------------
 *  Architecture : Stripe Accounts v2 (Account Token en test pour les
 *  plateformes françaises, dashboard: full), onboarding Stripe
 *  hébergé via Account Links v2 et paiements directs sur le compte de
 *  l'expert. Missiona reçoit sa commission comme application fee.
 *
 *  Les clés restent dans config.local.php (jamais dans une archive).
 *  Cette livraison est volontairement verrouillée en mode test : seules
 *  les clés sk_test_… activent le parcours. Ne renseignez aucune clé live.
 *
 *  Dans Stripe TEST : créez le webhook
 *       https://votre-domaine/stripe-webhook
 *  pour les événements de comptes connectés : account.updated,
 *  payment_intent.succeeded, payment_intent.processing,
 *  payment_intent.payment_failed et charge.refunded.
 * ================================================================== */
defined('STRIPE_MODE')           || define('STRIPE_MODE',           'test');
defined('STRIPE_SECRET_KEY')     || define('STRIPE_SECRET_KEY',     '');
defined('STRIPE_PUBLIC_KEY')     || define('STRIPE_PUBLIC_KEY',     '');
defined('STRIPE_WEBHOOK_SECRET') || define('STRIPE_WEBHOOK_SECRET', '');
// Version exigée par les appels JSON Stripe Accounts v2 / Account Links v2.
defined('STRIPE_ACCOUNTS_V2_VERSION') || define('STRIPE_ACCOUNTS_V2_VERSION', '2026-09-30.endive');

/* ==================================================================
 *  6. VERSION
 * ------------------------------------------------------------------
 *  Repère de version. La sauvegarde automatique ne s'appuie pas
 *  dessus : elle se déclenche sur l'empreinte du schéma de la base.
 * ================================================================== */
defined('APP_VERSION') || define('APP_VERSION', '2026.09.27');

/* ==================================================================
 *  7. ERREURS
 * ------------------------------------------------------------------
 *  false (à garder en ligne) : les erreurs partent dans un journal.
 *          Les afficher révélerait les chemins du serveur.
 *  true  : erreurs visibles à l'écran, pour du développement local.
 * ================================================================== */
defined('MODE_DEBUG') || define('MODE_DEBUG', false);

if (MODE_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');

    // Le dossier doit exister, sinon PHP ne peut rien écrire et les
    // erreurs disparaissent en silence — le pire cas pour dépanner.
    $_journal = __DIR__ . '/data';
    if (!is_dir($_journal)) { @mkdir($_journal, 0755, true); }
    if (is_dir($_journal) && is_writable($_journal)) {
        if (!is_file($_journal . '/.htaccess')) {
            @file_put_contents($_journal . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        ini_set('error_log', $_journal . '/php-errors.log');
    }
    unset($_journal);

    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}
