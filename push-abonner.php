<?php
/**
 * Missiona — Abonnement d'un téléphone aux notifications
 * ------------------------------------------------------------------
 * Appelé par assets/js/app.js après acceptation par l'utilisateur.
 * Réservé aux administrateurs : ce sont eux qui reçoivent les alertes.
 */
require_once __DIR__ . '/includes/functions.php';
header('Content-Type: application/json; charset=UTF-8');

$u = user();
if (!$u || ($u['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erreur' => 'Réservé à l\'administration.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}
csrf_check();

$endpoint = trim((string) ($_POST['endpoint'] ?? ''));
$action   = (string) ($_POST['action'] ?? 'abonner');

// L'endpoint doit être une vraie URL HTTPS fournie par le navigateur
if (!filter_var($endpoint, FILTER_VALIDATE_URL) || stripos($endpoint, 'https://') !== 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'erreur' => 'Adresse invalide.']);
    exit;
}

if ($action === 'desabonner') {
    db()->prepare("DELETE FROM push_abonnes WHERE endpoint = ?")->execute([$endpoint]);
    echo json_encode(['ok' => true, 'etat' => 'desabonne']);
    exit;
}

$appareil = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120);
db()->prepare(
    "INSERT INTO push_abonnes (user_id, endpoint, appareil, created_at)
     VALUES (?,?,?,datetime('now'))
     ON CONFLICT(endpoint) DO UPDATE SET user_id=excluded.user_id, echecs=0"
)->execute([(int) $u['id'], $endpoint, $appareil]);

echo json_encode(['ok' => true, 'etat' => 'abonne']);
