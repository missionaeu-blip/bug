<?php
require_once __DIR__ . '/includes/functions.php';
require_role('admin');
$u = user();
$s = stats_globales();

$a_traiter = db()->query("SELECT p.*, e.societe FROM projets p LEFT JOIN users e ON e.id=p.entreprise_id
                          WHERE p.statut IN ('nouveau','analyse') ORDER BY p.id DESC")->fetchAll();
$actifs = db()->query("SELECT p.*, e.societe, f.nom AS f_nom, f.prenom AS f_prenom
                       FROM projets p LEFT JOIN users e ON e.id=p.entreprise_id
                       LEFT JOIN users f ON f.id=p.freelance_id
                       WHERE p.statut IN ('attribue','en_cours','livraison') ORDER BY p.id DESC")->fetchAll();

$titre = "Back-office";
require_once __DIR__ . '/includes/header.php';
?>
<div class="app">
<?php require __DIR__ . '/includes/sidebar.php'; ?>
<main class="main">

  <div class="page-head">
    <div>
      <h1>Back-office Missiona</h1>
      <p>Pilotage global de la plateforme, des projets et des attributions.</p>
    </div>
    <?php if (est_admin_principal()): ?><a href="/admin-parametres" class="btn btn-ghost">⚙️ Paramètres de matching</a><?php endif; ?>
  </div>

  <?php if ($f = flash()): ?><div class="alert alert-<?= e($f['t']) ?>"><?= e($f['m']) ?></div><?php endif; ?>

  <div class="grid grid-4 mb-3">
    <div class="stat"><div class="lbl">Entreprises</div><div class="val"><?= $s['entreprises'] ?></div><div class="sub">comptes actifs</div></div>
    <div class="stat"><div class="lbl">Freelances</div><div class="val"><?= $s['freelances'] ?></div><div class="sub">dans le réseau</div></div>
    <div class="stat"><div class="lbl">Projets à traiter</div><div class="val" style="color:var(--amber)"><?= $s['a_traiter'] ?></div><div class="sub">en attente d'attribution</div></div>
    <div class="stat"><div class="lbl">Projets actifs</div><div class="val" style="color:var(--blue)"><?= $s['en_cours'] ?></div><div class="sub">en production</div></div>
  </div>

  <div class="grid grid-4 mb-4">
    <div class="stat"><div class="lbl">Projets livrés</div><div class="val" style="color:var(--green)"><?= $s['termines'] ?></div><div class="sub">cumul</div></div>
    <div class="stat"><div class="lbl">Volume d'affaires</div><div class="val"><?= euros($s['ca']) ?></div><div class="sub">projets terminés</div></div>
    <div class="stat accent"><div class="lbl">Commissions encaissées</div><div class="val"><?= euros($s['commissions']) ?></div><div class="sub">taux : <?= param('commission',20) ?> %</div></div>
    <div class="stat"><div class="lbl">Messages non lus</div><div class="val" style="color:var(--red)"><?= $s['messages_nl'] ?></div><div class="sub"><a href="/messages" style="color:var(--blue)">Traiter →</a></div></div>
  </div>

  <div class="panel mb-4">
    <div class="panel-head">
      <h3>⚡ Projets à attribuer</h3>
      <span class="badge badge-amber"><?= count($a_traiter) ?> en attente</span>
    </div>
    <?php if (!$a_traiter): ?>
      <div class="empty" style="padding:36px"><div class="ico">✅</div><h3>Aucun projet en attente</h3><p>Tous les projets ont été attribués.</p></div>
    <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Projet</th><th>Client</th><th>Budget</th><th>Statut</th><th>Reçu le</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($a_traiter as $p): ?>
          <tr>
            <td>
              <div class="t-title"><?= e($p['titre']) ?></div>
              <div class="t-sub"><?= e($p['categorie']) ?></div>
            </td>
            <td class="small"><?= e($p['societe']) ?></td>
            <td class="small"><?= euros($p['budget_min']) ?> – <?= euros_max($p['budget_max']) ?></td>
            <td><span class="badge <?= statut_classe($p['statut']) ?>"><?= statut_label($p['statut']) ?></span></td>
            <td class="small muted"><?= date_fr($p['created_at']) ?></td>
            <td><a href="/admin-matching?id=<?= $p['id'] ?>" class="btn btn-primary btn-sm">🎯 Lancer le matching</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <div class="panel">
    <div class="panel-head">
      <h3>Projets en production</h3>
      <a href="/admin-projets" class="btn btn-ghost btn-sm">Tous les projets</a>
    </div>
    <?php if (!$actifs): ?>
      <div class="empty" style="padding:36px"><div class="ico">📁</div><h3>Aucun projet en production</h3></div>
    <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Projet</th><th>Client</th><th>Expert affecté</th><th>Avancement</th><th>Statut</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($actifs as $p): ?>
          <tr>
            <td>
              <div class="t-title"><?= e($p['titre']) ?></div>
              <div class="t-sub">Échéance : <?= date_fr($p['date_limite']) ?></div>
            </td>
            <td class="small"><?= e($p['societe']) ?></td>
            <td class="small"><?= e(trim($p['f_prenom'].' '.$p['f_nom'])) ?: '—' ?></td>
            <td style="min-width:120px">
              <div class="bar"><i style="width:<?= (int)$p['avancement'] ?>%"></i></div>
              <div class="t-sub"><?= (int)$p['avancement'] ?> %</div>
            </td>
            <td><span class="badge <?= statut_classe($p['statut']) ?>"><?= statut_label($p['statut']) ?></span></td>
            <td><a href="/projet?id=<?= $p['id'] ?>" class="btn btn-ghost btn-sm">Gérer</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

</main>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
