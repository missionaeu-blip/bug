<?php
/**
 * Missiona — référencement centralisé
 * ------------------------------------------------------------------
 * Une seule source pour les titres, descriptions, URL canoniques,
 * directives robots et le sitemap. Les espaces connectés et pages d'action
 * sont volontairement exclus de l'indexation.
 */

// sitemap.php charge ce fichier directement : il doit donc aussi récupérer
// SITE_URL depuis la configuration, y compris une éventuelle config.local.php.
if (!defined('SITE_URL') && is_file(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config.php';
}
if (!defined('SITE_URL')) { define('SITE_URL', 'https://missiona.eu'); }

/**
 * Pages déclarées explicitement. Seules les entrées indexable=true peuvent
 * être canoniques, recevoir les balises enrichies et entrer dans sitemap.xml.
 *
 * La meta de l'accueil est conservée telle qu'approuvée.
 */
function seo_pages() {
    return [
        'accueil' => [
            'title'       => "Déléguer vos projets digitaux sans les gérer",
            'description' => "Vous décrivez le projet, on livre le résultat. Nous sélectionnons et pilotons les freelances à votre place, avec un budget fixé à l'avance.",
            'path'        => '/',
            'indexable'   => true,
        ],
        'entreprises' => [
            'title'       => "Sous-traiter vos projets web sereinement",
            'description' => "Confiez vos projets digitaux à une équipe qui recrute et pilote les experts pour vous. Devis sous 48 h, budget validé avant démarrage.",
            'path'        => '/entreprises',
            'indexable'   => true,
        ],
        'freelances' => [
            'title'       => "Missions freelance sans prospection",
            'description' => "Recevez des missions cadrées et payées au juste prix. Missiona gère la prospection, le cadrage et la facturation : vous vous concentrez sur votre métier.",
            'path'        => '/freelances',
            'indexable'   => true,
        ],
        'services' => [
            'title'       => "Nos expertises : web, mobile, design et data",
            'description' => "Développement web et mobile, design, data et marketing digital : les domaines couverts par notre réseau d'experts sélectionnés.",
            'path'        => '/services',
            'indexable'   => true,
        ],
        'faq' => [
            'title'       => "Questions fréquentes",
            'description' => "Délais, budgets, propriété du code, confidentialité : les réponses aux questions que posent nos clients avant de lancer un projet.",
            'path'        => '/faq',
            'indexable'   => true,
        ],
        'a-propos' => [
            'title'       => "Qui sommes-nous",
            'description' => "Notre méthode de sélection des freelances, nos engagements et notre façon de piloter les projets. Découvrez comment nous travaillons.",
            'path'        => '/a-propos',
            'indexable'   => true,
        ],
        'contact' => [
            'title'       => "Contact : parlons de votre projet",
            'description' => "Décrivez votre besoin en quelques lignes : notre équipe revient vers vous sous 48 heures avec une estimation de budget et de délai, sans engagement.",
            'path'        => '/contact',
            'indexable'   => true,
        ],

        // Documents légaux publics : indexables pour être accessibles et
        // vérifiables, sans masquer leur caractère préparatoire de bêta.
        'mentions-legales' => [
            'title'       => "Mentions légales",
            'description' => "Informations légales, statut bêta et prestataires de Missiona.",
            'path'        => '/mentions-legales',
            'indexable'   => true,
        ],
        'cgu' => [
            'title'       => "Conditions générales d’utilisation",
            'description' => "Conditions d’utilisation de la plateforme Missiona.",
            'path'        => '/cgu',
            'indexable'   => true,
        ],
        'cgv' => [
            'title'       => "Conditions générales de vente",
            'description' => "Conditions commerciales B2B de Missiona.",
            'path'        => '/cgv',
            'indexable'   => true,
        ],
        'politique-confidentialite' => [
            'title'       => "Politique de confidentialité",
            'description' => "Traitement des données personnelles sur Missiona.",
            'path'        => '/politique-confidentialite',
            'indexable'   => true,
        ],
        'politique-cookies' => [
            'title'       => "Politique cookies",
            'description' => "Informations et gestion des cookies sur Missiona.",
            'path'        => '/politique-cookies',
            'indexable'   => true,
        ],
        'dpa' => [
            'title'       => "Accord de sous-traitance",
            'description' => "Modèle d’accord de sous-traitance de données B2B Missiona.",
            'path'        => '/dpa',
            'indexable'   => true,
        ],
        'securite' => [
            'title'       => "Sécurité",
            'description' => "Mesures et bonnes pratiques de sécurité Missiona.",
            'path'        => '/securite',
            'indexable'   => true,
        ],
        'moderation-signalement' => [
            'title'       => "Modération et signalement",
            'description' => "Procédure de signalement et de modération Missiona.",
            'path'        => '/moderation-signalement',
            'indexable'   => true,
        ],
        'accessibilite' => [
            'title'       => "Accessibilité",
            'description' => "Engagement et améliorations d’accessibilité de Missiona.",
            'path'        => '/accessibilite',
            'indexable'   => true,
        ],
        'conditions-beta' => [
            'title'       => "Conditions de la bêta",
            'description' => "Conditions spécifiques au pré-lancement de Missiona.",
            'path'        => '/conditions-beta',
            'indexable'   => true,
        ],
        'retractation' => [
            'title'       => "Rétractation et professionnels",
            'description' => "Information B2B sur la rétractation et ses situations particulières.",
            'path'        => '/retractation',
            'indexable'   => true,
        ],
        'contact-donnees-personnelles' => [
            'title'       => "Contacts légal et données personnelles",
            'description' => "Contacts Missiona pour le légal, les données, la sécurité et la modération.",
            'path'        => '/contact-donnees-personnelles',
            'indexable'   => true,
        ],

        // Pages existantes mais volontairement absentes de Google : elles
        // servent à une action, sont privées, légales ou temporairement fermées.
        'tarifs' => [
            'title'       => "Tarifs Missiona",
            'description' => "Informations tarifaires Missiona.",
            'path'        => '/tarifs',
            'indexable'   => false,
        ],
        'mentions' => [
            'title'       => "Mentions légales",
            'description' => "Informations légales de Missiona.",
            'path'        => '/mentions',
            'indexable'   => false,
        ],
        'connexion' => [
            'title'       => "Connexion à votre espace Missiona",
            'description' => "Connexion sécurisée à votre espace Missiona.",
            'path'        => '/connexion',
            'indexable'   => false,
        ],
        'inscription' => [
            'title'       => "Créer un compte Missiona",
            'description' => "Création sécurisée de compte Missiona.",
            'path'        => '/inscription',
            'indexable'   => false,
        ],
    ];
}

/** Métadonnées de la page courante, avec repli systématiquement noindex. */
function seo_meta($page) {
    $pages = seo_pages();
    if (isset($pages[$page])) { return $pages[$page]; }
    return [
        'title'       => '',
        'description' => "Missiona : vous décrivez le projet, on livre le résultat.",
        'path'        => null,
        'indexable'   => false,
    ];
}

/** Cette page est-elle autorisée dans les résultats de recherche ? */
function seo_est_indexable($page) {
    $meta = seo_meta($page);
    return !empty($meta['indexable']) && !empty($meta['path']);
}

/** Compatibilité avec les appels existants : true = à protéger de l'index. */
function seo_est_privee($page) {
    return !seo_est_indexable($page);
}

/** Directive unique utilisée dans meta robots et l'en-tête HTTP X-Robots-Tag. */
function seo_directive_robots($page) {
    return seo_est_indexable($page)
        ? 'index, follow, max-image-preview:large, max-snippet:-1'
        : 'noindex, nofollow, noarchive, nosnippet';
}

/** URL absolue à partir d'un chemin canonique. */
function seo_url($path) {
    return rtrim(SITE_URL, '/') . ($path === '/' ? '/' : '/' . ltrim(rtrim($path, '/'), '/'));
}
