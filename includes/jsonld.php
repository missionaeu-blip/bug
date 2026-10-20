<?php
/**
 * Données structurées JSON-LD.
 *
 * Règle absolue : on ne déclare que des informations réellement présentes
 * sur la page et vérifiables. Aucune note, aucun avis, aucun prix inventé.
 */

function jsonld_bloc($data) {
    return '<script type="application/ld+json">'
         . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
         . '</script>';
}

/** Organisation éditrice — page d'accueil. */
function jsonld_organisation() {
    return [
        '@context'    => 'https://schema.org',
        '@type'       => 'Organization',
        '@id'         => SITE_URL . '/#organization',
        'name'        => 'Missiona',
        'url'         => SITE_URL . '/',
        'logo'        => [
            '@type' => 'ImageObject',
            'url'   => SITE_URL . '/assets/img/logo.svg',
        ],
        'description' => "Plateforme de délégation de projets digitaux : Missiona sélectionne et pilote les freelances pour le compte des entreprises.",
        'email'       => defined('SITE_EMAIL') ? SITE_EMAIL : 'entreprise@missiona.eu',
        'contactPoint' => [
            '@type'             => 'ContactPoint',
            'contactType'       => 'customer support',
            'email'             => defined('SITE_EMAIL') ? SITE_EMAIL : 'entreprise@missiona.eu',
            'availableLanguage' => ['French'],
        ],
    ];
}

/** Page publique indexable, reliée au site et à son éditeur. */
function jsonld_page($meta) {
    return [
        '@context'    => 'https://schema.org',
        '@type'       => 'WebPage',
        '@id'         => seo_url($meta['path']) . '#webpage',
        'url'         => seo_url($meta['path']),
        'name'        => $meta['title'],
        'description' => $meta['description'],
        'inLanguage'  => 'fr-FR',
        'isPartOf'    => ['@id' => SITE_URL . '/#website'],
        'publisher'   => ['@id' => SITE_URL . '/#organization'],
    ];
}

/** Site web + action de recherche interne non déclarée (il n'y en a pas). */
function jsonld_website() {
    return [
        '@context'      => 'https://schema.org',
        '@type'         => 'WebSite',
        '@id'           => SITE_URL . '/#website',
        'url'           => SITE_URL . '/',
        'name'          => 'Missiona',
        'inLanguage'    => 'fr-FR',
        'publisher'     => ['@id' => SITE_URL . '/#organization'],
    ];
}

/** Service proposé, décrit sans prix fixe (le devis est sur mesure). */
function jsonld_service() {
    return [
        '@context'    => 'https://schema.org',
        '@type'       => 'Service',
        'name'        => "Délégation de projets digitaux",
        'serviceType' => "Gestion de projet et mise en oeuvre par des freelances sélectionnés",
        'provider'    => ['@id' => SITE_URL . '/#organization'],
        'areaServed'  => ['@type' => 'Country', 'name' => 'France'],
        'description' => "Vous décrivez votre projet, Missiona sélectionne l'expert, pilote la réalisation et livre le résultat.",
    ];
}

/** Fil d'Ariane. $items = [['nom' => ..., 'path' => ...], ...] sans l'accueil. */
function jsonld_fil($items) {
    $liste = [[
        '@type'    => 'ListItem',
        'position' => 1,
        'name'     => 'Accueil',
        'item'     => SITE_URL . '/',
    ]];
    $i = 2;
    foreach ($items as $it) {
        $entree = [
            '@type'    => 'ListItem',
            'position' => $i++,
            'name'     => $it['nom'],
        ];
        if (!empty($it['path'])) { $entree['item'] = seo_url($it['path']); }
        $liste[] = $entree;
    }
    return [
        '@context'        => 'https://schema.org',
        '@type'           => 'BreadcrumbList',
        'itemListElement' => $liste,
    ];
}

/** FAQ — uniquement des questions réellement affichées sur la page. */
function jsonld_faq($faq) {
    $q = [];
    foreach ($faq as $f) {
        $q[] = [
            '@type'          => 'Question',
            'name'           => $f['q'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['r']],
        ];
    }
    return [
        '@context'   => 'https://schema.org',
        '@type'      => 'FAQPage',
        'mainEntity' => $q,
    ];
}
