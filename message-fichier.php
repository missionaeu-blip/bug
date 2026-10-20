<?php
/**
 * Missiona — Pièce jointe d'une conversation
 * ------------------------------------------------------------------
 * Seuls l'expéditeur et le destinataire du message y accèdent. Un
 * administrateur n'y accède que si le message concerne un compte de
 * son périmètre : sans cela, il lui suffirait de faire défiler les
 * identifiants pour lire les pièces jointes de toute la plateforme.
 */
require_once __DIR__ . '/includes/functions.php';

if (!is_logged()) { header('Location: ' . u('/connexion')); exit; }
$u  = user();
$id = (int) ($_GET['id'] ?? 0);

$st = db()->prepare("SELECT * FROM messages WHERE id = ? AND fichier_nom <> ''");
$st->execute([$id]);
$m = $st->fetch();
if (!$m) { http_response_code(404); exit('Pièce jointe introuvable.'); }

$moi  = (int) $u['id'];
$exp  = (int) $m['expediteur_id'];
$dest = (int) $m['destinataire_id'];

$autorise = ($moi === $exp) || ($moi === $dest);
if (!$autorise && $u['role'] === 'admin') {
    // L'autre partie doit relever du périmètre de cet administrateur
    $autre    = ($exp === $moi) ? $dest : $exp;
    $autorise = msg_peut_ecrire($u, $exp) || msg_peut_ecrire($u, $dest) || msg_peut_ecrire($u, $autre);
}
if (!$autorise) { http_response_code(403); exit('Accès refusé.'); }

$chemin = realpath(__DIR__ . '/uploads/' . $m['fichier_chemin']);
$racine = realpath(__DIR__ . '/uploads');
if (!$chemin || !$racine || strpos($chemin, $racine) !== 0 || !is_file($chemin)) {
    http_response_code(404); exit('Fichier absent du serveur.');
}

// Intégrité : le document servi doit être celui qui a été envoyé
if (!empty($m['fichier_empreinte'])) {
    $actuelle = @hash_file('sha256', $chemin);
    if ($actuelle && !hash_equals((string) $m['fichier_empreinte'], $actuelle)) {
        @error_log('[integrite] piece jointe message #' . $id . ' altérée');
        http_response_code(409);
        exit('Ce document ne correspond plus à la version envoyée. Signalez-le à notre équipe.');
    }
}

/* Pièce jointe protégée remise à une entreprise : consultation seule.
   Le destinataire client la consulte dans la boîte d'aperçu (filigrane
   nominatif) mais ne la télécharge pas depuis le site ; l'expéditeur et
   l'équipe conservent le téléchargement. */
$recip_client = !empty($m['fichier_protege'])
             && ($moi === $dest)
             && est_client($u['role']);
if ($recip_client && !isset($_GET['vue'])) {
    http_response_code(403);
    $titre = 'Document en consultation seule';
    include __DIR__ . '/includes/header.php';
    echo '<div class="container" style="padding:60px 0;max-width:640px">'
       . '<h1>Document en consultation seule</h1>'
       . '<p class="muted">Ce document confidentiel vous est présenté en consultation '
       . 'protégée : ouvrez-le avec le bouton Aperçu dans la conversation. Il n\'est pas '
       . 'téléchargeable depuis le site.</p>'
       . '<p><a class="btn btn-primary" href="' . u('/messages') . '">Retour à la messagerie</a></p></div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

/* Affichage dans la boîte d'aperçu : images, PDF, pages HTML rendues
   isolées et code en lecture seule. */
if (isset($_GET['vue']) && servir_apercu($chemin, $m['fichier_nom']) !== false) { exit; }

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $m['fichier_nom']) . '"');
header('Content-Length: ' . filesize($chemin));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');
readfile($chemin);
exit;
