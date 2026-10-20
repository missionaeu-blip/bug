<?php
/**
 * Missiona — socle des documents légaux
 *
 * Contenus rédactionnels préparatoires pour un service B2B en bêta.
 * Ils ne remplacent pas la validation d'un avocat, d'un DPO ou de tout
 * conseil compétent avant l'ouverture commerciale.
 */

function legal_version() { return '1.0-beta'; }
function legal_date_mise_a_jour() { return '18 octobre 2026'; }
function legal_placeholder() { return '[À COMPLÉTER PAR L’ÉDITEUR]'; }
function legal_email_support() {
    $email = defined('SITE_EMAIL') ? trim((string) SITE_EMAIL) : '';
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : legal_placeholder();
}
function legal_email_link($label = null) {
    $email = legal_email_support();
    if ($email === legal_placeholder()) { return e($label ?: $email); }
    return '<a href="mailto:' . e($email) . '">' . e($label ?: $email) . '</a>';
}
function legal_lien($url, $libelle) {
    return '<a href="' . e($url) . '">' . e($libelle) . '</a>';
}
function legal_document_debut($titre, $intro, array $sommaire = [], $identifiant_document = '') {
    $identifiant_document = preg_replace('/[^a-z0-9-]/', '', strtolower((string) $identifiant_document));
    $classe_document = $identifiant_document !== '' ? ' legal-document--' . $identifiant_document : '';
    echo '<main class="legal-page' . e($classe_document) . '" data-legal-document="' . e($identifiant_document) . '"><div class="container-sm legal-container">';
    echo '<nav class="breadcrumb" aria-label="Fil d’Ariane"><a href="/">Accueil</a><span aria-hidden="true">/</span><span aria-current="page">' . e($titre) . '</span></nav>';
    echo '<header class="legal-head"><p class="legal-kicker">Missiona · information légale</p><h1>' . e($titre) . '</h1><p class="legal-intro">' . e($intro) . '</p>';
    echo '<p class="legal-meta">Dernière mise à jour : <strong>' . e(legal_date_mise_a_jour()) . '</strong> · Version <strong>' . e(legal_version()) . '</strong></p>';
    echo '<p class="legal-history"><strong>Historique :</strong> version 1.0-beta — ' . e(legal_date_mise_a_jour()) . ' : première publication préparatoire.</p></header>';
    echo '<aside class="legal-warning" role="note"><strong>Document préparatoire.</strong> Ce document est adapté au projet Missiona, à son statut de bêta et à son activité B2B. Il doit être validé et complété par un juriste avant l’ouverture commerciale annoncée le 11 novembre 2026.</aside>';
    if ($sommaire) {
        echo '<nav class="legal-toc" aria-label="Sommaire"><strong>Sommaire</strong><ol>';
        foreach ($sommaire as $id => $libelle) { echo '<li><a href="#' . e($id) . '">' . e($libelle) . '</a></li>'; }
        echo '</ol></nav>';
    }
    echo '<article class="legal-content">';
}
function legal_document_fin() {
    echo '<section class="legal-validation" aria-label="Validation juridique"><h2>Validation avant ouverture commerciale</h2><p>Les champs signalés <strong>[À COMPLÉTER PAR L’ÉDITEUR]</strong>, les prix, la qualité des parties, les responsabilités, les sous-traitants et les durées de conservation doivent être revus avec un professionnel du droit avant toute commercialisation.</p></section>';
    echo '</article></div></main>';
}
function legal_h2($id, $titre) { echo '<section id="' . e($id) . '"><h2>' . e($titre) . '</h2>'; }
function legal_fin_section() { echo '</section>'; }
