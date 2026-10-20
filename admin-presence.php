<?php
/**
 * Missiona — Présence de l'équipe, en direct
 * ------------------------------------------------------------------
 * Interrogé toutes les quelques secondes par la page Suivi équipe.
 * Renvoie qui est connecté et sur quelle page.
 */
require_once __DIR__ . '/includes/functions.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (!est_admin_principal()) {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}

$out = [];
foreach (equipe_admins() as $a) {
    $out[] = [
        'id'      => (int) $a['id'],
        'ligne'   => $a['en_ligne'] ? 1 : 0,
        'page'    => $a['en_ligne'] ? equipe_nom_page($a['page_actuelle'] ?? '') : '',
        'chemin'  => $a['en_ligne'] ? (string) ($a['page_actuelle'] ?? '') : '',
        'depuis'  => equipe_depuis($a['derniere_vue']),
    ];
}

echo json_encode(['ok' => true, 'heure' => date('H:i:s'), 'admins' => $out]);
