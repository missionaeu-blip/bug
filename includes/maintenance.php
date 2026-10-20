<?php
/**
 * Mode maintenance.
 *
 * Quand il est actif, tout le site public renvoie une page d'attente en HTTP 503.
 * Seul l'administrateur principal peut continuer à naviguer normalement : il se
 * connecte depuis un lien discret en bas de la page de maintenance.
 *
 * Le 503 + Retry-After est important pour le SEO : il indique à Google une
 * indisponibilité temporaire, ce qui évite la désindexation des pages.
 */

/** La maintenance est-elle activée ? */
function maintenance_active() {
    return (int) param('maintenance', 0) === 1;
}

/** Message affiché aux visiteurs (paramétrable depuis le back-office). */
function maintenance_message() {
    $m = trim((string) param('maintenance_message', ''));
    return $m !== ''
        ? $m
        : "Nous effectuons actuellement une mise à jour de la plateforme. "
        . "Le service sera rétabli dans quelques instants.";
}

/**
 * Chemins toujours accessibles, même en maintenance.
 * Sans cela, l'administrateur ne pourrait plus se connecter pour la désactiver.
 */
function maintenance_chemins_libres() {
    return [
        '/connexion', '/connexion.php',
        '/deconnexion', '/deconnexion.php',
        '/maintenance', '/maintenance.php',
    ];
}

/**
 * Verrou appliqué au tout début de chaque page.
 * Ne fait rien si la maintenance est inactive ou si l'utilisateur est le principal.
 */
function maintenance_verrou() {
    if (!maintenance_active()) { return; }

    // L'administrateur principal garde un accès complet au site
    if (function_exists('est_admin_principal') && est_admin_principal()) { return; }

    $chemin = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $chemin = rtrim($chemin, '/');
    if ($chemin === '') { $chemin = '/'; }

    // La page de connexion reste joignable pour que l'admin puisse entrer
    if (in_array($chemin, maintenance_chemins_libres(), true)) { return; }

    // Les ressources statiques doivent rester servies (rendu de la page d'attente)
    if (preg_match('#^/assets/#', $chemin)) { return; }

    // Un compte non principal déjà connecté est déconnecté proprement
    require __DIR__ . '/../maintenance.php';
    exit;
}
