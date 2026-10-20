<?php
require_once __DIR__ . '/includes/functions.php';
if (!is_logged()) { header('Location: ' . u('/connexion')); exit; }
$u = user();
$filtre = $_GET['s'] ?? '';

if (est_client($u['role'])) {
    $sql = "SELECT p.*, f.nom AS f_nom, f.prenom AS f_prenom FROM projets p LEFT JOIN users f ON f.id=p.freelance_id WHERE p.entreprise_id=?";
} elseif ($u['role']==='freelance') {
    $sql = "SELECT p.*, e.societe FROM projets p LEFT JOIN users e ON e.id=p.entreprise_id WHERE p.freelance_id=?";
} else { header('Location: ' . u('/admin-projets')); exit; }

$args = [$u['id']];
if ($filtre) { $sql .= " AND p.statut=?"; $args[] = $filtre; }
$sql .= " ORDER BY p.id DESC";
$st = db()->prepare($sql); $st->execute($args);
$projets = $st->fetchAll();

$titre = est_client($u['role']) ? "Mes projets" : "Mes missions";
$page  = 'projets';
require_once __DIR__ . '/includes/header.php';
?>
<div class="app">
<?php require __DIR__ . '/includes/sidebar.php'; ?>
<main class="main">

  <div class="page-head">
    <div>
      <h1><?= e($titre) ?></h1>
      <p><?= count($projets) ?> résultat<?= count($projets)>1?'s':'' ?></p>
    </div>
    <?php if (est_client($u['role'])): ?>
      <a href="/nouveau-projet" class="btn btn-primary">➕ Nouveau projet</a>
    <?php endif; ?>
  </div>

  <div class="flex gap-1 wrap mb-3">
    <a href="/projets" class="btn <?= !$filtre?'btn-primary':'btn-ghost' ?> btn-sm">Tous</a>
    <?php foreach (['nouveau','analyse','attribue','en_cours','livraison','termine'] as $s): ?>
      <a href="/projets?s=<?= $s ?>" class="btn <?= $filtre===$s?'btn-primary':'btn-ghost' ?> btn-sm"><?= statut_label($s) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if (!$projets): ?>
    <div class="panel"><div class="empty">
      <div class="ico">📁</div><h3>Aucun projet</h3>
      <p>Aucun projet ne correspond à ce filtre.</p>
      <?php if (est_client($u['role'])): ?><a href="/nouveau-projet" class="btn btn-primary">Créer un projet</a><?php endif; ?>
    </div></div>
  <?php else: ?>
    <div class="grid grid-2">
      <?php foreach ($projets as $p): ?>
        <div class="card card-hover">
          <div class="flex-between mb-2">
            <span class="badge <?= statut_classe($p['statut']) ?>"><?= statut_label($p['statut']) ?></span>
            <?= beta_etiquette($p) ?>
            <span class="small muted"><?= date_fr($p['created_at']) ?></span>
          </div>
          <h3><?= e($p['titre']) ?></h3>
          <p class="small mt-1"><?= e(mb_substr($p['description'],0,120)) ?>…</p>
          <div class="crit mt-2">
            <?php foreach (array_slice(array_filter(array_map('trim', explode(',', $p['competences']))),0,4) as $c): ?>
              <span><?= e($c) ?></span>
            <?php endforeach; ?>
          </div>
          <div class="mt-3">
            <div class="flex-between small muted mb-1">
              <span>Avancement</span><strong><?= (int)$p['avancement'] ?> %</strong>
            </div>
            <div class="bar <?= $p['avancement']>=100?'green':'' ?>"><i style="width:<?= (int)$p['avancement'] ?>%"></i></div>
          </div>
          <div class="flex-between mt-3" style="padding-top:14px;border-top:1px solid var(--line-2)">
            <span class="small strong"><?= euros($p['budget_min']) ?> – <?= euros_max($p['budget_max']) ?></span>
            <a href="/projet?id=<?= $p['id'] ?>" class="btn btn-ghost btn-sm">Voir le détail →</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</main>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
