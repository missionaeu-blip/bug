<?php
/* ==========================================================================
   PANNACOTTACLUB — page unique (PHP)
   Sert la même application pour : le client, le suivi, le livreur, l'admin.
   Les adresses /commander, /suivi, /livreur, /admin sont réécrites ici par
   le fichier .htaccess.
   ========================================================================== */
require __DIR__ . '/includes/bootstrap.php';

/**
 * Adresse du site : vide s'il est à la racine du domaine, « /boutique » s'il est
 * dans un sous-dossier. Les trois méthodes se complètent, car selon la
 * configuration de l'hébergeur l'information n'est pas au même endroit.
 */
function pc_base_path(): string
{
    // 1) Cas courant : /index.php ou /boutique/index.php
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir = rtrim(dirname($script), '/');
    if ($dir !== '' && $dir !== '.' && $dir !== '/') return $dir;

    // 2) Installation dans un sous-dossier du répertoire public
    $docroot = rtrim(str_replace('\\', '/', (string) ($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
    if ($docroot !== '' && strpos(PC_ROOT, $docroot) === 0) {
        $rel = str_replace('\\', '/', substr(PC_ROOT, strlen($docroot)));
        if ($rel !== '' && $rel !== '/') return rtrim($rel, '/');
    }

    // 3) Dernier recours : on déduit le préfixe de l'adresse demandée
    $uri = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if (preg_match('#^(.*?)/(?:index\.php|api\.php|install\.php|commander|suivi|livreur|admin|equipe)/?$#i', $uri, $m)) {
        return rtrim($m[1], '/');
    }
    return '';
}
$base = pc_base_path();
$v = static function (string $file): string {
    $p = PC_ROOT . '/' . ltrim($file, '/');
    return is_file($p) ? (string) filemtime($p) : '1';
};

// Le site doit avoir été installé (tables créées)
$installed = true;
try {
    db()->query('SELECT 1 FROM products LIMIT 1');
} catch (Throwable $e) {
    $installed = false;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>PANNACOTTACLUB — Livré directement sur la plage, à Cabourg</title>
<meta name="description" content="Glaces, boissons et encas livrés directement sur la plage de Cabourg, entre les postes de secours 1 et 2. Scannez, commandez, on arrive.">
<meta name="theme-color" content="#060CD4">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="PannacottaClub">
<meta property="og:title" content="PANNACOTTACLUB — Livré directement sur la plage">
<meta property="og:description" content="Glaces, boissons et encas livrés sur la plage de Cabourg. Paiement à la livraison.">
<meta property="og:image" content="<?= htmlspecialchars($base) ?>/assets/img/logo.png">

<?php /* base : permet au site de fonctionner aussi bien à la racine du domaine que dans un sous-dossier */ ?>
<base href="<?= htmlspecialchars($base) ?>/">

<link rel="icon" href="assets/img/favicon-64.png" sizes="64x64" type="image/png">
<link rel="icon" href="assets/img/favicon-32.png" sizes="32x32" type="image/png">
<link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png">
<link rel="manifest" href="manifest.webmanifest">
<link rel="stylesheet" href="assets/css/style.css?v=<?= $v('assets/css/style.css') ?>">

<!-- Carte Leaflet : photo locale fournie et points GPS superposés. -->
<script>window.PC_BASE = <?= json_encode($base === '' ? '/' : $base . '/') ?>;</script>
</head>
<body>

<?php if (!$installed): ?>
  <!-- Le site n'est pas encore installé : on guide l'utilisateur -->
  <div style="min-height:100vh;display:grid;place-items:center;background:#EEF0FF;padding:24px;font-family:system-ui,sans-serif">
    <div style="max-width:460px;background:#fff;border-radius:22px;padding:28px;box-shadow:0 18px 50px rgba(6,12,212,.14);text-align:center">
      <img src="assets/img/logo.png" alt="" style="width:90px;margin:0 auto 12px">
      <h1 style="color:#060CD4;margin:0 0 8px;font-size:1.5rem">PANNACOTTACLUB</h1>
      <p style="color:#5A5E8C;margin:0 0 18px">Le site n'est pas encore installé.</p>
      <a href="install.php" style="display:inline-block;background:#060CD4;color:#fff;text-decoration:none;font-weight:700;padding:14px 22px;border-radius:14px">
        Lancer l'installation
      </a>
      <p style="color:#5A5E8C;font-size:.82rem;margin:16px 0 0">
        Renseignez d'abord vos identifiants MySQL dans <code>config.php</code>.
      </p>
    </div>
  </div>
<?php else: ?>

<div class="splash" id="splash">
  <div>
    <img src="assets/img/logo.png" alt="PANNACOTTACLUB">
    <div class="slogan">Livré directement sur la plage</div>
  </div>
</div>

<div class="offline-bar" id="offlineBar">Connexion interrompue — reconnexion en cours…</div>

<header class="header" id="header" hidden>
  <div class="wrap wrap-wide header-inner">
    <a class="brand" href="./" data-link>
      <img class="brand-logo" src="assets/img/logo.png" alt="PANNACOTTACLUB">
      <span class="brand-word">PannacottaClub<span>Cabourg · Plage</span></span>
    </a>
    <nav class="nav" id="nav"></nav>
    <div class="header-actions" id="headerActions"></div>
  </div>
</header>

<main id="view"></main>

<footer class="footer" id="footer" hidden>
  <div class="wrap wrap-wide">
    <div class="brand" style="gap:12px">
      <img class="brand-logo" src="assets/img/logo.png" alt="">
      <span class="brand-word">PannacottaClub<span>Cabourg · Plage</span></span>
    </div>
    <div class="footer-links">
      <a href="./" data-link>Accueil</a>
      <a href="commander" data-link>Commander</a>
      <a href="suivi" data-link>Suivre ma commande</a>
      <a href="livreur" data-link>Espace livreur</a>
      <a href="admin" data-link>Administration</a>
    </div>
    <p class="small" id="footerInfo"></p>
    <div class="legal">
      PANNACOTTACLUB — service de livraison sur la plage de Cabourg (postes de secours 1 à 2).
      Paiement à la livraison uniquement, aucun paiement en ligne.
      Votre position GPS sert exclusivement à vous livrer : elle n'est ni publique, ni conservée après la livraison.
    </div>
  </div>
</footer>

<div class="cartbar" id="cartbar" hidden>
  <div class="cartbar-inner">
    <span class="count" id="cartCount">0</span>
    <span class="grow">
      <span class="small muted" style="display:block">Panier</span>
      <span class="total mono" id="cartTotal">0,00 €</span>
    </span>
    <button class="btn" style="width:auto;padding:0 22px" id="cartOpen">Voir le panier</button>
  </div>
</div>

<div class="sheet-scrim" id="scrim"></div>
<aside class="sheet" id="sheet" role="dialog" aria-modal="true">
  <div class="grab"></div>
  <div class="sheet-head">
    <h3 id="sheetTitle">Panier</h3>
    <button class="icon-btn" id="sheetClose" aria-label="Fermer">
      <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
    </button>
  </div>
  <div class="sheet-body" id="sheetBody"></div>
  <div class="sheet-foot" id="sheetFoot" hidden></div>
</aside>

<div class="toast-host" id="toasts"></div>

<!-- Leaflet est hébergé localement ; la photo de fond aussi. -->
<!-- Générateur de QR code intégré (licence MIT, aucune requête externe) -->
<script src="assets/js/qrcode-generator.js?v=<?= $v('assets/js/qrcode-generator.js') ?>" defer></script>
<script type="module" src="assets/js/app.js?v=<?= $v('assets/js/app.js') ?>"></script>

<?php endif; ?>
</body>
</html>
