<?php
require_once __DIR__ . '/includes/functions.php';
http_response_code(404);
$titre = "Page introuvable";
$page  = '404';
require_once __DIR__ . '/includes/header.php';
?>
<div class="container" style="padding:80px 0;text-align:center;max-width:640px">
  <p style="font-size:3rem;margin:0 0 8px;font-weight:700;color:var(--muted)">404</p>
  <h1>Cette page n'existe pas</h1>
  <p class="muted" style="margin-bottom:28px">
    Le lien est peut-être obsolète ou l'adresse mal saisie. Voici les pages les plus consultées :
  </p>
  <div class="flex-gap" style="justify-content:center;flex-wrap:wrap;margin-bottom:28px">
    <a href="/" class="btn btn-primary">Accueil</a>
    <a href="/services" class="btn btn-ghost">Nos expertises</a>
    <?php if (!beta_lien_masque('/tarifs')): ?>
    <a href="/tarifs" class="btn btn-ghost">Tarifs</a>
    <?php endif; ?>
    <a href="/contact" class="btn btn-ghost">Nous contacter</a>
  </div>
  <p class="small muted">
    Vous cherchiez à suivre un projet en cours ?
    <a href="/connexion">Connectez-vous à votre espace</a>.
  </p>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
