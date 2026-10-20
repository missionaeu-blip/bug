<?php
/**
 * Missiona — Réception des actions mesurées côté navigateur
 * ------------------------------------------------------------------
 * Appelé par assets/js/app.js à chaque clic suivi. Répond toujours
 * « 204 sans contenu » : la mesure ne doit rien changer à la navigation.
 */
require_once __DIR__ . '/includes/functions.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // Garde-fou : on n'accepte que des actions venant de nos propres pages.
    $ref  = parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_HOST);
    $hote = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? '');
    if ($ref === null || $ref === $hote) {
        // Limite simple : pas plus de 40 actions par visiteur et par minute.
        $cle = 'suivi_' . date('YmdHi');
        $n   = (int) ($_SESSION[$cle] ?? 0);
        if ($n < 40) {
            $type = (string) ($_POST['type'] ?? 'clic');
            $cible = (string) ($_POST['cible'] ?? '');
            $detail = (string) ($_POST['detail'] ?? '');
            // Une durée ne peut être qu'un segment public, borné et numérique.
            // Cela protège les moyennes contre les envois accidentels ou forgés.
            if ($type === 'duree') {
                $secondes = filter_var($detail, FILTER_VALIDATE_INT, ['options' => ['min_range' => 3, 'max_range' => 1800]]);
                if ($secondes === false || !preg_match('#^/[A-Za-z0-9_./-]{0,119}$#', $cible)) {
                    http_response_code(204); exit;
                }
                $detail = (string) $secondes;
            }
            $_SESSION[$cle] = $n + 1;
            stats_action($type, $cible, $detail);
        }
    }
}

http_response_code(204);
