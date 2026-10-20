<?php
/**
 * Missiona — Téléchargement d'une facture au format PDF
 * ------------------------------------------------------------------
 * /facture-telecharger.php?id=12            (téléchargement)
 * /facture-telecharger.php?id=12&vue=1      (affichage navigateur)
 *
 * Document unique du modèle direct : la facture de rémunération que
 * Missiona adresse à l'expert (commission d'apport d'affaires uniquement,
 * sans aucun frais fixe). L'entreprise cliente n'a aucune facture Missiona.
 *
 * Accès strictement contrôlé : l'expert concerné et l'administration
 * uniquement. Tout autre compte reçoit une page « introuvable ».
 */
require_once __DIR__ . '/includes/functions.php';
if (!is_logged()) { header('Location: ' . u('/connexion')); exit; }

$u   = user();
$id  = (int) ($_GET['id'] ?? 0);
$vue = isset($_GET['vue']);   // affichage dans le navigateur plutôt que téléchargement

$f = facture_complete($id);

if (!$f || !facture_accessible($f, $u)) {
    http_response_code(404);
    $titre = 'Document introuvable';
    include __DIR__ . '/includes/header.php';
    echo '<div class="container" style="padding:60px 0">'
       . '<h1>Document introuvable</h1>'
       . '<p class="muted">Cette facture n\'existe pas ou ne vous est pas destinée.</p>'
       . '<p><a class="btn btn-primary" href="' . u('/factures') . '">Retour à mes factures</a></p>'
       . '</div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

$contenu = facture_remuneration_pdf($f);
$nom     = 'facture-' . preg_replace('/[^A-Za-z0-9_-]+/', '', $f['numero']) . '.pdf';

while (ob_get_level() > 0) { ob_end_clean(); }

header('Content-Type: application/pdf');
header('Content-Disposition: ' . ($vue ? 'inline' : 'attachment') . '; filename="' . $nom . '"');
header('Content-Length: ' . strlen($contenu));
header('Cache-Control: private, max-age=0, must-revalidate');
header('X-Content-Type-Options: nosniff');
echo $contenu;
exit;
