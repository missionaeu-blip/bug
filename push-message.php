<?php
/**
 * Missiona — Message à afficher sur le téléphone
 * ------------------------------------------------------------------
 * Interrogé par le service worker à la réception d'une notification.
 *
 * L'impulsion envoyée est vide : le contenu ne transite donc jamais
 * par les serveurs de Google ou Mozilla. Le téléphone s'identifie
 * avec son « endpoint », une URL secrète que le navigateur est seul
 * à connaître — aucune session n'est nécessaire, ce qui permet à la
 * notification de fonctionner même navigateur fermé.
 */
require_once __DIR__ . '/includes/functions.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$defaut = ['titre' => SITE_NOM, 'texte' => 'Nouvelle activité sur votre plateforme.', 'lien' => '/admin'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode($defaut);
    exit;
}

$endpoint = trim((string) ($_POST['endpoint'] ?? ''));
if ($endpoint === '') { echo json_encode($defaut); exit; }

$st = db()->prepare("SELECT user_id FROM push_abonnes WHERE endpoint = ?");
$st->execute([$endpoint]);
$uid = (int) $st->fetchColumn();

if ($uid <= 0) {
    // Abonnement inconnu : on n'en dit pas plus
    echo json_encode($defaut);
    exit;
}

$st = db()->prepare("SELECT texte, lien FROM notifications
                     WHERE user_id = ? AND lu = 0 ORDER BY id DESC LIMIT 1");
$st->execute([$uid]);
$n = $st->fetch();

$nb = (int) db()->query("SELECT COUNT(*) FROM notifications WHERE user_id = " . $uid . " AND lu = 0")
                ->fetchColumn();

echo json_encode([
    'titre' => SITE_NOM,
    'texte' => $n ? $n['texte'] : $defaut['texte'],
    'lien'  => $n && $n['lien'] !== '' ? '/' . ltrim($n['lien'], '/') : '/admin',
    'nb'    => $nb,
]);
