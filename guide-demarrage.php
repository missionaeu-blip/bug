<?php
/** Marque le guide de première connexion comme lu. */
require_once __DIR__ . '/includes/functions.php';
if (!is_logged()) {
    header('Location: ' . u('/connexion'));
    exit;
}

$u = user();
$retour = dashboard_url($u['role'], true);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    db()->prepare("UPDATE users SET guide_premiere_connexion_vu = 1 WHERE id = ?")
        ->execute([(int) $u['id']]);
    unset($_SESSION['guide_premiere_connexion']);

    // Le guide revient à la page qui l'a ouvert (notamment le récapitulatif
    // d'un projet) sans accepter de redirection externe.
    $demande = trim((string) ($_POST['retour'] ?? ''));
    if (str_starts_with($demande, '/') && !str_starts_with($demande, '//')
        && !preg_match('#^[a-z][a-z0-9+.-]*:#i', $demande)) {
        $retour = u($demande);
    }
}

header('Location: ' . $retour);
exit;
