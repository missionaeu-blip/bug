<?php
/**
 * Page affichée aux visiteurs pendant la maintenance.
 * Répond en 503 avec Retry-After : Google comprend qu'il s'agit d'une
 * indisponibilité temporaire et ne désindexe pas les pages.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/maintenance.php';

// Si la maintenance a été levée entre-temps, on renvoie l'internaute à l'accueil
if (!maintenance_active()) {
    header('Location: /'); exit;
}

http_response_code(503);
header('Retry-After: 3600');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$u_courant = function_exists('user') ? user() : null;
$deja_admin = $u_courant && function_exists('est_admin_principal') && est_admin_principal($u_courant);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Maintenance en cours — Missiona</title>
<meta name="robots" content="noindex, nofollow">
<meta name="description" content="La plateforme Missiona est momentanément indisponible pour maintenance.">
<link rel="icon" type="image/svg+xml" href="<?= e(asset('assets/img/favicon.svg')) ?>">
<link rel="icon" type="image/x-icon" href="<?= e(asset('assets/img/favicon.ico')) ?>">
<meta name="theme-color" content="#12336e">
<style>
  *{box-sizing:border-box}
  body{margin:0;min-height:100vh;min-height:100dvh;display:flex;flex-direction:column;
    align-items:center;justify-content:center;padding:32px 20px;
    font-family:system-ui,-apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
    background:#F8FAFC;color:#0F172A;text-align:center;line-height:1.6}
  .mark{width:56px;height:56px;border-radius:14px;background:#12336e;color:#fff;
    display:flex;align-items:center;justify-content:center;font-weight:700;
    font-size:1.25rem;margin:0 auto 22px;letter-spacing:.5px}
  h1{font-size:1.5rem;margin:0 0 12px;font-weight:650}
  p{margin:0 0 10px;color:#475569;max-width:33rem}
  .card{background:#fff;border:1px solid #E2E8F0;border-radius:14px;
    padding:40px 32px;max-width:36rem;width:100%;
    box-shadow:0 1px 3px rgba(15,23,42,.04)}
  .contact{margin-top:22px;padding-top:20px;border-top:1px solid #E2E8F0;
    font-size:.875rem;color:#64748B}
  .contact a{color:#165DFF;text-decoration:none}
  .contact a:hover{text-decoration:underline}
  .admin-zone{margin-top:30px;font-size:.8125rem}
  .admin-zone a{color:#94A3B8;text-decoration:none;
    border-bottom:1px dotted #CBD5E1;padding-bottom:1px}
  .admin-zone a:hover{color:#165DFF;border-bottom-color:#165DFF}
  .badge{display:inline-block;background:#FEF3C7;color:#B45309;
    font-size:.75rem;font-weight:600;padding:4px 10px;border-radius:99px;
    margin-bottom:18px;letter-spacing:.2px}
</style>
</head>
<body>

<div class="card">
  <div class="mark">WC</div>
  <span class="badge">Maintenance en cours</span>
  <h1>Le site est momentanément indisponible</h1>
  <p><?= e(maintenance_message()) ?></p>
  <p>Merci de votre patience.</p>

  <div class="contact">
    Une urgence ? Écrivez-nous à
    <a href="mailto:<?= e(SITE_EMAIL) ?>"><?= e(SITE_EMAIL) ?></a>
  </div>
</div>

<div class="admin-zone">
  <?php if ($deja_admin): ?>
    <a href="/admin-parametres">Désactiver la maintenance</a>
  <?php else: ?>
    <a href="/connexion?admin=1" rel="nofollow">Accès administrateur</a>
  <?php endif; ?>
</div>

</body>
</html>
