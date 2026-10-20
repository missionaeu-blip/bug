<?php
require_once __DIR__ . '/includes/functions.php';
require_role(['entreprise','particulier']);
$u = user();

$st = db()->prepare("SELECT p.*, f.nom AS f_nom, f.prenom AS f_prenom, f.titre_pro
                     FROM projets p LEFT JOIN users f ON f.id = p.freelance_id
                     WHERE p.entreprise_id = ? ORDER BY p.id DESC");
$st->execute([$u['id']]);
$projets = $st->fetchAll();

$total     = count($projets);
$en_cours  = count(array_filter($projets, fn($p) => in_array($p['statut'], ['attribue','en_cours','livraison'])));
$attente   = count(array_filter($projets, fn($p) => in_array($p['statut'], ['nouveau','analyse'])));
$termines  = count(array_filter($projets, fn($p) => $p['statut'] === 'termine'));
$budget    = array_sum(array_column($projets, 'montant_final'));

$titre = "Tableau de bord";
require_once __DIR__ . '/includes/header.php';
?>
<div class="app">
<?php require __DIR__ . '/includes/sidebar.php'; ?>
<main class="main">

  <div class="page-head">
    <div>
      <h1>Bonjour <?= e($u['prenom'] ?: $u['nom']) ?> 👋</h1>
      <p><?= e($u['societe'] ?: 'Votre espace entreprise') ?> — suivez l'avancement de vos projets en temps réel.</p>
    </div>
    <?php if (!beta_lien_masque('/nouveau-projet')): ?>
    <a href="/nouveau-projet" class="btn btn-primary">➕ Nouveau projet</a>
    <?php endif; ?>
  </div>

  <?php if ($f = flash()): ?><div class="alert alert-<?= e($f['t']) ?>"><?= e($f['m']) ?></div><?php endif; ?>

  <div class="grid grid-4 mb-4">
    <div class="stat"><div class="lbl">Projets au total</div><div class="val"><?= $total ?></div><div class="sub">depuis la création du compte</div></div>
    <div class="stat"><div class="lbl">En cours</div><div class="val" style="color:var(--blue)"><?= $en_cours ?></div><div class="sub">pilotés par Missiona</div></div>
    <div class="stat"><div class="lbl">En cours d'analyse</div><div class="val" style="color:var(--amber)"><?= $attente ?></div><div class="sub">réponse sous 48 h</div></div>
    <div class="stat accent"><div class="lbl">Montant engagé</div><div class="val"><?= euros($budget) ?></div><div class="sub"><?= $termines ?> projet<?= $termines>1?'s':'' ?> livré<?= $termines>1?'s':'' ?></div></div>
  </div>

  <div class="grid" style="grid-template-columns:1.7fr 1fr;gap:24px;align-items:start">
    <div class="panel">
      <div class="panel-head">
        <h3>Mes projets</h3>
        <?php if (!beta_lien_masque('/projets')): ?>
        <a href="/projets" class="btn btn-ghost btn-sm">Tout voir</a>
        <?php endif; ?>
      </div>
      <?php if (!$projets): ?>
        <div class="empty">
          <div class="ico">📁</div>
          <h3>Aucun projet pour le moment</h3>
          <p>Décrivez votre premier besoin, notre équipe s'occupe du reste.</p>
          <?php if (!beta_lien_masque('/nouveau-projet')): ?>
          <a href="/nouveau-projet" class="btn btn-primary">Créer mon premier projet</a>
          <?php endif; ?>
        </div>
      <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Projet</th><th>Statut</th><th>Expert</th><th>Avancement</th><th></th></tr></thead>
          <tbody>
          <?php foreach (array_slice($projets, 0, 6) as $p): ?>
            <tr>
              <td>
                <div class="t-title"><?= e($p['titre']) ?></div>
                <div class="t-sub"><?= e($p['categorie']) ?> · <?= euros($p['budget_min']) ?> – <?= euros_max($p['budget_max']) ?></div>
              </td>
              <td><span class="badge <?= statut_classe($p['statut']) ?>"><?= statut_label($p['statut']) ?></span>
                  <?= beta_etiquette($p) ?></td>
              <td class="small">
                <?php if ($p['freelance_id']): ?>
                  <?= e($p['f_prenom'].' '.mb_substr($p['f_nom'],0,1).'.') ?><br>
                  <span class="muted"><?= e($p['titre_pro']) ?></span>
                <?php else: ?>
                  <span class="muted">En cours d'attribution</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="bar <?= $p['avancement']>=100?'green':'' ?>"><i style="width:<?= (int)$p['avancement'] ?>%"></i></div>
                <div class="t-sub"><?= (int)$p['avancement'] ?> %</div>
              </td>
              <td><a href="/projet?id=<?= $p['id'] ?>" class="btn btn-ghost btn-sm">Détail</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <div class="grid" style="gap:24px">
      <div class="panel">
        <div class="panel-head"><h3>Votre chargé de compte</h3></div>
        <div class="panel-body">
          <div class="flex-center mb-3">
            <span class="avatar lg dark"><?= e(charge_initiales()) ?></span>
            <div>
              <h4><?= e(charge_compte_nom()) ?></h4>
              <p class="small muted"><?= e(charge_compte_role()) ?></p>
            </div>
          </div>
          <p class="small muted mb-3">Votre interlocuteur unique pour tous vos projets. Réponse garantie sous 4 heures ouvrées.</p>
          <a href="/messages" class="btn btn-primary btn-block">💬 Envoyer un message</a>
        </div>
      </div>

      <div class="panel">
        <div class="panel-head"><h3>Activité récente</h3></div>
        <div class="panel-body">
          <?php $ns = notifications($u['id'], 5); ?>
          <?php if (!$ns): ?>
            <p class="small muted">Aucune activité récente.</p>
          <?php else: ?>
            <div class="timeline">
              <?php foreach ($ns as $i => $n): ?>
                <div class="tl-item <?= $i===0?'current':'done' ?>">
                  <h4 style="font-weight:500;font-size:.875rem"><?= e($n['texte']) ?></h4>
                  <p><?= date_fr($n['created_at']) ?></p>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

</main>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
