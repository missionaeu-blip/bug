<?php
/**
 * Mode bêta — préinscription.
 * ------------------------------------------------------------------
 * Le site reste ouvert et référencé, mais réduit à sa vitrine :
 * pages publiques, inscription et connexion. Tout ce qui engage de
 * l'argent ou une prestation est fermé — création de projet, factures,
 * versements, tarification.
 *
 * L'objectif est de constituer une base d'inscrits avant l'ouverture
 * réelle, sans promettre un service qui n'est pas encore rendu.
 *
 * Différence avec la maintenance : la maintenance ferme tout et renvoie
 * un 503. Le mode bêta laisse le site vivant et indexable.
 *
 * L'administrateur principal n'est jamais bridé : il doit pouvoir
 * préparer et vérifier la plateforme pendant la bêta.
 */

/** Le mode bêta est-il actif ? */
function beta_actif() {
    return (int) param('beta', 0) === 1;
}

/** Message du bandeau, paramétrable depuis le back-office. */
function beta_message() {
    $m = trim((string) param('beta_message', ''));
    return $m !== ''
        ? $m
        : "Missiona ouvre le 11 novembre 2026. Créez votre compte dès maintenant : "
        . "vous serez prévenu en priorité, et les premiers projets démarreront dès l'ouverture.";
}

/** L'utilisateur échappe-t-il aux restrictions ? */
function beta_exempt() {
    return function_exists('est_admin_principal') && est_admin_principal();
}

/**
 * Pages fermées pendant la bêta, avec la raison affichée au visiteur.
 * La clé est le nom du script, sans extension.
 */
function beta_pages_fermees() {
    return [
        'tarifs'             => "Nos tarifs seront publiés à l'ouverture du service.",
        'factures'           => "La facturation sera disponible à l'ouverture du service.",
        'facture-telecharger'=> "La facturation sera disponible à l'ouverture du service.",
        'paiement-retour'    => "Les paiements ne sont pas encore activés.",
    ];
}

/** Cette page est-elle fermée pour l'utilisateur courant ? */
function beta_page_fermee($script = null) {
    if (!beta_actif() || beta_exempt()) { return false; }
    $s = $script ?? basename($_SERVER['SCRIPT_NAME'] ?? '', '.php');
    $s = preg_replace('/\.php$/', '', $s);
    return array_key_exists($s, beta_pages_fermees());
}

/**
 * Un lien doit-il être masqué dans les menus ?
 * Évite de proposer des pages qui afficheront un refus.
 */
function beta_lien_masque($href) {
    if (!beta_actif() || beta_exempt()) { return false; }
    $h = trim(parse_url((string) $href, PHP_URL_PATH) ?: '', '/');
    $h = preg_replace('/\.php$/', '', $h);
    return $h !== '' && array_key_exists($h, beta_pages_fermees());
}

/**
 * Bandeau bleu apposé sur un projet déposé pendant la préinscription.
 * Le client peut décrire son besoin dès maintenant, mais l'équipe ne
 * le prendra en charge qu'à l'ouverture : il faut le dire clairement
 * plutôt que de laisser croire à un traitement immédiat.
 */
function beta_projet_en_attente($projet) {
    return beta_actif() && !empty($projet['cree_en_beta']);
}

/** Petite étiquette bleue, pour les listes. */
function beta_etiquette($projet) {
    if (!beta_projet_en_attente($projet)) { return ''; }
    return '<span class="badge badge-bleu" title="Ce projet sera étudié dès la fin de la préinscription">'
         . 'Pris en charge à l\'ouverture</span>';
}

/**
 * Verrou appliqué au chargement de chaque page.
 * Contrairement à la maintenance, on ne coupe pas le site : on renvoie
 * l'utilisateur vers l'accueil avec une explication.
 */
function beta_verrou() {
    if (!beta_page_fermee()) { return; }

    $s      = preg_replace('/\.php$/', '', basename($_SERVER['SCRIPT_NAME'] ?? ''));
    $raison = beta_pages_fermees()[$s] ?? "Cette page ouvrira à la fin de la préinscription.";

    if (function_exists('flash')) { flash($raison, 'info'); }

    $vers = '/';
    if (function_exists('user') && ($u = user())) {
        $vers = '/' . dashboard_url($u['role']);
    }
    header('Location: ' . (function_exists('u') ? u($vers) : $vers));
    exit;
}
