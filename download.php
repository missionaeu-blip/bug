<?php
/**
 * Téléchargement sécurisé des livrables.
 * Le fichier n'est jamais accessible directement : on vérifie d'abord
 * que l'utilisateur connecté a le droit de voir le projet concerné.
 */
require_once __DIR__ . '/includes/functions.php';

if (!is_logged()) { header('Location: ' . u('/connexion')); exit; }
$u  = user();
$id = (int)($_GET['id'] ?? 0);

$st = db()->prepare("SELECT f.*, p.entreprise_id, p.freelance_id
                     FROM fichiers f JOIN projets p ON p.id = f.projet_id
                     WHERE f.id = ?");
$st->execute([$id]);
$f = $st->fetch();

if (!$f) { http_response_code(404); exit('Fichier introuvable.'); }

// Contrôle d'accès : client du projet, expert affecté, ou administrateur
$autorise = ($u['role'] === 'admin')
         || ((int)$f['entreprise_id'] === (int)$u['id'])
         || ((int)$f['freelance_id']  === (int)$u['id']);
if (!$autorise) { http_response_code(403); exit('Accès refusé.'); }

/* ------------------------------------------------------------------
 *  Remise au client : uniquement après vérification
 * ------------------------------------------------------------------
 *  Un livrable déposé par un expert reste retenu tant que l'équipe ne
 *  l'a pas contrôlé. L'expert qui l'a déposé et l'équipe y accèdent
 *  toujours — c'est le client, et lui seul, qui attend le feu vert.
 * ------------------------------------------------------------------ */
$statut     = (string) ($f['statut'] ?? 'valide');
$est_client = ((int) $f['entreprise_id'] === (int) $u['id']) && $u['role'] !== 'admin';

if ($est_client && $statut !== 'valide') {
    http_response_code(403);
    $titre = 'Document en cours de vérification';
    include __DIR__ . '/includes/header.php';
    echo '<div class="container" style="padding:60px 0;max-width:640px">'
       . '<h1>Document en cours de vérification</h1>'
       . '<p class="muted">Ce livrable a bien été déposé, mais notre équipe ne l\'a pas '
       . 'encore contrôlé. Il vous sera transmis dès qu\'il sera validé, et vous en '
       . 'serez averti.</p>'
       . '<p><a class="btn btn-primary" href="' . u('/projet.php?id=' . (int) $f['projet_id']) . '">'
       . 'Retour au projet</a></p></div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

// Empêche toute remontée de répertoire
$chemin = realpath(__DIR__ . '/uploads/' . $f['chemin']);
$racine = realpath(__DIR__ . '/uploads');
if (!$chemin || !$racine || strpos($chemin, $racine) !== 0 || !is_file($chemin)) {
    http_response_code(404); exit('Fichier absent du serveur.');
}

/* Intégrité : on compare le fichier servi à l'empreinte enregistrée.
   Si elle ne correspond plus, le document a été remplacé sur le disque
   depuis sa validation : mieux vaut refuser que livrer un faux. */
if (!empty($f['empreinte'])) {
    $actuelle = @hash_file('sha256', $chemin);
    if ($actuelle && !hash_equals((string) $f['empreinte'], $actuelle)) {
        @error_log('[integrite] fichier #' . (int) $f['id'] . ' altéré : ' . $f['chemin']);
        http_response_code(409);
        exit('Ce document ne correspond plus à la version vérifiée. Signalez-le à notre équipe.');
    }
}

/* ------------------------------------------------------------------
 *  Livrables remis au client : consultation seule
 * ------------------------------------------------------------------
 *  Un document déposé par l'expert ou l'équipe à l'attention du
 *  client se consulte dans la boîte d'aperçu (filigrane nominatif),
 *  jamais en téléchargement : le client ne reçoit le fichier brut
 *  que hors plateforme, selon les modalités convenues. Le client
 *  reste libre de retélécharger SES propres dépôts.
 * ------------------------------------------------------------------ */
$depose_par_client = ((int) $f['uploader_id'] === (int) $u['id']);
if ($est_client && !$depose_par_client && !isset($_GET['vue'])) {
    http_response_code(403);
    $titre = 'Document en consultation seule';
    include __DIR__ . '/includes/header.php';
    echo '<div class="container" style="padding:60px 0;max-width:640px">'
       . '<h1>Document en consultation seule</h1>'
       . '<p class="muted">Ce document vous est présenté en consultation protégée : '
       . 'ouvrez-le avec le bouton Aperçu. Il n\'est pas téléchargeable depuis le site.</p>'
       . '<p><a class="btn btn-primary" href="' . u('/projet.php?id=' . (int) $f['projet_id']) . '">'
       . 'Retour au projet</a></p></div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

/* ------------------------------------------------------------------
 *  Affichage dans la fenêtre d'aperçu
 * ------------------------------------------------------------------
 *  Images, PDF, pages HTML (rendues isolées) et code en lecture
 *  seule. Les formats exécutables sur notre domaine (SVG, scripts
 *  servis comme tels) restent exclus.
 * ------------------------------------------------------------------ */
if (isset($_GET['vue']) && servir_apercu($chemin, $f['nom']) !== false) { exit; }

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $f['nom']) . '"');
header('Content-Length: ' . filesize($chemin));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');
readfile($chemin);
exit;
