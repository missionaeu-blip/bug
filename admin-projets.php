<?php
require_once __DIR__ . '/includes/functions.php';
require_role('admin');
$filtre = $_GET['s'] ?? '';
$sql = "SELECT p.*, e.societe, f.nom AS f_nom, f.prenom AS f_prenom
        FROM projets p LEFT JOIN users e ON e.id=p.entreprise_id LEFT JOIN users f ON f.id=p.freelance_id";
$args = [];
if ($filtre) { $sql .= " WHERE p.statut=?"; $args[] = $filtre; }
$sql .= " ORDER BY p.id DESC";
$st = db()->prepare($sql); $st->execute($args);
$projets = $st->fetchAll();

$titre = "Projets";
require_once __DIR__ . '/includes/header.php';
?>
<div class="app">
<?php require __DIR__ . '/includes/sidebar.php'; ?>
<main class="main">

  <div class="page-head">
    <div><h1>Tous les projets</h1><p><?= count($projets) ?> projet<?= count($projets)>1?'s':'' ?> enregistré<?= count($projets)>1?'s':'' ?></p></div>
    <input type="search" class="input" style="max-width:260px" placeholder="🔍 Rechercher…" oninput="filterTable(this,'tbl')">
  </div>

  <div class="flex gap-1 wrap mb-3">
    <a href="/admin-projets" class="btn <?= !$filtre?'btn-primary':'btn-ghost' ?> btn-sm">Tous</a>
    <?php foreach (['nouveau','analyse','attribue','en_cours','livraison','termine','annule'] as $s): ?>
      <a href="/admin-projets?s=<?= $s ?>" class="btn <?= $filtre===$s?'btn-primary':'btn-ghost' ?> btn-sm"><?= statut_label($s) ?></a>
    <?php endforeach; ?>
  </div>

  <div class="panel">
    <div class="table-wrap">
      <table id="tbl">
        <thead><tr><th>Projet</th><th>Client</th><th>Expert</th><th>Budget</th><th>Statut</th><th>Avancement</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($projets as $p): ?>
          <tr>
            <td><div class="t-title"><?= e($p['titre']) ?></div><div class="t-sub"><?= e($p['categorie']) ?></div></td>
            <td class="small"><?= e($p['societe']) ?></td>
            <td class="small"><?= e(trim($p['f_prenom'].' '.$p['f_nom'])) ?: '<span class="muted">Non attribué</span>' ?></td>
            <td class="small"><?= $p['montant_final'] ? '<strong>'.euros($p['montant_final']).'</strong>' : euros($p['budget_min']).' – '.euros_max($p['budget_max']) ?></td>
            <td><span class="badge <?= statut_classe($p['statut']) ?>"><?= statut_label($p['statut']) ?></span></td>
            <td style="min-width:110px"><div class="bar"><i style="width:<?= (int)$p['avancement'] ?>%"></i></div><div class="t-sub"><?= (int)$p['avancement'] ?> %</div></td>
            <td style="white-space:nowrap">
              <?php if (!$p['freelance_id']): ?>
                <a href="/admin-matching?id=<?= $p['id'] ?>" class="btn btn-primary btn-sm">🎯 Matching</a>
              <?php else: ?>
                <a href="/projet?id=<?= $p['id'] ?>" class="btn btn-ghost btn-sm">Gérer</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

</main>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
