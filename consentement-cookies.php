<?php
/**
 * Trace minimale des préférences cookies.
 * Aucun identifiant public, aucune adresse IP, aucune donnée de navigation :
 * seul le condensé HMAC d'un identifiant local aléatoire est conservé.
 */
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false]);
    exit;
}
if (!csrf_valide($_POST['csrf'] ?? '')) {
    http_response_code(400);
    echo json_encode(['ok' => false]);
    exit;
}

$id = (string) ($_POST['consent_id'] ?? '');
$decision = (string) ($_POST['decision'] ?? '');
$version = trim((string) ($_POST['version'] ?? ''));
$functional = !empty($_POST['functional']) ? 1 : 0;
$analytics = !empty($_POST['analytics']) ? 1 : 0;

if (!preg_match('/^[A-Za-z0-9-]{16,160}$/', $id)
    || !in_array($decision, ['accept_all', 'refuse_all', 'custom'], true)
    || !preg_match('/^[A-Za-z0-9._-]{1,60}$/', $version)) {
    http_response_code(422);
    echo json_encode(['ok' => false]);
    exit;
}

$hash = hash_hmac('sha256', $id, csrf_secret());
try {
    db()->prepare("INSERT INTO cookie_consentements
        (token_hash, decision, fonctionnalites, audience, version, updated_at)
        VALUES (?,?,?,?,?,CURRENT_TIMESTAMP)
        ON CONFLICT(token_hash) DO UPDATE SET
          decision=excluded.decision,
          fonctionnalites=excluded.fonctionnalites,
          audience=excluded.audience,
          version=excluded.version,
          updated_at=CURRENT_TIMESTAMP")
       ->execute([$hash, $decision, $functional, $analytics, $version]);
    http_response_code(204);
} catch (Throwable $e) {
    // Le bandeau ne doit jamais empêcher l'accès si le stockage de preuve est
    // momentanément indisponible. L'incident reste visible dans le journal PHP.
    @error_log('[consentement-cookies] ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['ok' => false]);
}
exit;
