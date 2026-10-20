<?php
$titre = "Nos expertises";
$page  = 'services';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/contenu.php';
$expertises = wc_expertises();
?>

<nav class="container" aria-label="Fil d'Ariane" style="padding:18px 0 0">
  <ol class="breadcrumb">
    <li><a href="/">Accueil</a></li>
    <li aria-current="page">Nos expertises</li>
  </ol>
</nav>

<section class="hero" style="padding:48px 0 56px">
  <div class="container-sm text-center">
    <span class="eyebrow">Domaines couverts</span>
    <h1 class="mt-2">Douze domaines d'expertise, un seul interlocuteur</h1>
    <p class="lead mt-3" style="margin:16px auto 0">
      Missiona couvre le développement web et mobile, le design d'interface, la data et l'automatisation, le marketing digital, la traduction et les langues, la vidéo, la finance et le pilotage, l'assistance, l'ingénierie et le conseil. Pour chacun, nous sélectionnons l'expert, pilotons la réalisation et vous livrons le résultat — vous n'avez aucun prestataire à chercher ni à manager.
    </p>
  </div>
</section>

<section class="section bg-white">
  <div class="container">
    <div class="grid grid-2" style="gap:24px">
      <?php foreach ($expertises as $x): ?>
        <article class="card" id="<?= e($x['slug']) ?>">
          <h2 style="font-size:1.25rem;margin-bottom:8px"><?= e($x['titre']) ?></h2>
          <p class="muted" style="margin-bottom:12px"><?= e($x['desc']) ?></p>
          <ul class="list-check">
            <?php foreach ($x['items'] as $i): ?><li><?= e($i) ?></li><?php endforeach; ?>
          </ul>
          <?php if (!empty($x['note'])): ?>
            <p class="small muted" style="margin-top:12px"><?= $x['note'] ?></p>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section">
  <div class="container-sm">
    <div class="section-head">
      <h2>Un besoin qui n'entre dans aucune case ?</h2>
      <p>
        La plupart des projets mélangent plusieurs domaines : un site marchand demande
        du développement, du design et du référencement. C'est précisément ce que nous
        prenons en charge — constituer la bonne équipe et la coordonner à votre place.
      </p>
    </div>
    <div class="text-center">
      <a href="/nouveau-projet" class="btn btn-primary btn-lg">Décrire mon projet</a>
      <?php if (!beta_lien_masque('/tarifs')): ?>
      <a href="/tarifs" class="btn btn-ghost btn-lg">Consulter les tarifs</a>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
