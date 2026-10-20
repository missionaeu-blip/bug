<?php
$titre = "Questions fréquentes";
$page  = 'faq';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/contenu.php';
$faq = wc_faq();
?>

<nav class="container" aria-label="Fil d'Ariane" style="padding:18px 0 0">
  <ol class="breadcrumb">
    <li><a href="/">Accueil</a></li>
    <li aria-current="page">Questions fréquentes</li>
  </ol>
</nav>

<section class="hero" style="padding:48px 0 48px">
  <div class="container-sm text-center">
    <span class="eyebrow">Aide</span>
    <h1 class="mt-2">Questions fréquentes</h1>
    <p class="lead mt-3" style="margin:16px auto 0">
      Fonctionnement, budget, délais, propriété des livrables et confidentialité :
      les réponses aux questions que nos clients posent avant de lancer un projet.
    </p>
  </div>
</section>

<section class="section bg-white">
  <div class="container-sm">
    <?php foreach ($faq as $i => $f): ?>
      <details class="faq-item" <?= $i === 0 ? 'open' : '' ?>>
        <summary><h2 style="display:inline;font-size:1.0625rem;font-weight:600"><?= e($f['q']) ?></h2></summary>
        <p><?= e($f['r']) ?></p>
      </details>
    <?php endforeach; ?>
  </div>
</section>

<section class="section">
  <div class="container-sm text-center">
    <h2>Votre question n'est pas là ?</h2>
    <p class="muted" style="margin:12px 0 24px">
      Écrivez-nous : nous répondons sous 48 heures ouvrées.
    </p>
    <a href="/contact" class="btn btn-primary btn-lg">Poser ma question</a>
  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
