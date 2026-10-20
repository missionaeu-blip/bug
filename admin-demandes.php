<?php
require_once __DIR__ . '/includes/functions.php';
require_role('admin');

/* Traitement direct depuis la liste */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_traiter'])) {
    csrf_check();
    $did     = (int)($_POST['demande_id'] ?? 0);
    $action  = $_POST['action_demande'] ?? '';
    $reponse = trim($_POST['reponse'] ?? '');

    $ds = db()->prepare("SELECT d.*, p.titre, p.freelance_id, p.entreprise_id
                         FROM demandes d JOIN projets p ON p.id = d.projet_id
                         WHERE d.id=? AND d.statut='en_attente'");
    $ds->execute([$did]);

    if ($d = $ds->fetch()) {
        if (!peut_gerer_projet($d)) {
            flash(refus_gestion(), 'error');
            header('Location: ' . u('/admin-demandes')); exit;
        }
        if ($action === 'accepter') {
            $annulation = annuler_projet($d['projet_id'], true);
            if (!$annulation['rembourse']) {
                flash("Demande laissée en attente : " . implode(' ', $annulation['erreurs']), 'error');
            } else {
                db()->prepare("UPDATE demandes SET statut='acceptee', reponse=?, traite_le=CURRENT_TIMESTAMP WHERE id=?")
                    ->execute([$reponse, $did]);
                notify($d['auteur_id'], "Votre demande de " . $d['type'] . " sur « " . $d['titre'] . " » a été acceptée. Le remboursement Stripe a été demandé lorsque nécessaire.", 'projet.php?id='.$d['projet_id']);
                if ($d['freelance_id']) notify($d['freelance_id'], "La mission « " . $d['titre'] . " » a été annulée.", 'projet.php?id='.$d['projet_id']);
                flash("Demande acceptée : projet annulé.");
            }
        } elseif ($action === 'refuser') {
            db()->prepare("UPDATE demandes SET statut='refusee', reponse=?, traite_le=CURRENT_TIMESTAMP WHERE id=?")
                ->execute([$reponse, $did]);
            notify($d['auteur_id'], "Votre demande de " . $d['type'] . " sur « " . $d['titre'] . " » a été refusée.", 'projet.php?id='.$d['projet_id']);
            flash("Demande refusée.");
        }
    }
    header('Location: ' . u('/admin-demandes')); exit;
}

$en_attente = demandes_en_attente();

$traitees = db()->query("SELECT d.*, p.titre, u.nom, u.prenom, u.societe
                         FROM demandes d
                         JOIN projets p ON p.id = d.projet_id
                         JOIN users u   ON u.id = d.auteur_id
                         WHERE d.statut <> 'en_attente'
                         ORDER BY d.id DESC LIMIT 40")->fetchAll();

$titre = "Demandes";
require_once __DIR__ . '/includes/header.php';
?>
<div class="app">
<?php require __DIR__ . '/includes/sidebar.php'; ?>
<main class="main">

  <div class="page-head">
    <div>
      <h1>Demandes d'annulation</h1>
      <p class="lead-sm">Annulations et remboursements sollicités par les clients.</p>
    </div>
  </div>

  <?php if ($f = flash()): ?><div class="alert alert-<?= e($f['t']) ?>"><?= e($f['m']) ?></div><?php endif; ?>

  <div class="grid grid-3 mb-3">
    <div class="stat accent">
      <div class="lbl">À traiter</div>
      <div class="val"><?= count($en_attente) ?></div>
      <div class="sub">en attente de décision</div>
    </div>
    <div class="stat">
      <div class="lbl">Acceptées</div>
      <div class="val"><?= count(array_filter($traitees, fn($d)=>$d['statut']==='acceptee')) ?></div>
      <div class="sub">projets annulés</div>
    </div>
    <div class="stat">
      <div class="lbl">Refusées</div>
      <div class="val"><?= count(array_filter($traitees, fn($d)=>$d['statut']==='refusee')) ?></div>
      <div class="sub">demandes rejetées</div>
    </div>
  </div>

  <?php if (!$en_attente): ?>
    <div class="panel">
      <div class="panel-body">
        <div class="empty">
          <div class="ico">✅</div>
          <h3>Aucune demande en attente</h3>
          <p>Les demandes d'annulation des clients apparaîtront ici.</p>
        </div>
      </div>
    </div>
  <?php else: ?>
    <?php foreach ($en_attente as $d): ?>
      <div class="panel mb-2">
        <div class="panel-head">
          <h3><?= ucfirst(e($d['type'])) ?> — <?= e($d['titre']) ?></h3>
          <span class="badge badge-amber">À traiter</span>
        </div>
        <div class="panel-body">
          <div class="grid grid-2">
            <div>
              <div class="recap">
                <div class="recap-row"><span>Client</span><strong><?= e($d['societe'] ?: trim($d['prenom'].' '.$d['nom'])) ?></strong></div>
                <div class="recap-row"><span>Statut du projet</span><strong><?= statut_label($d['p_statut']) ?></strong></div>
                <div class="recap-row"><span>Déposée le</span><strong><?= date_fr($d['created_at']) ?></strong></div>
              </div>
              <?php
              $pays = paiements_projet($d['projet_id']);
              $verse = 0;
              foreach ($pays as $pp) { if (in_array($pp['statut'], ['en_suspens','paye','libere'], true)) { $verse += (int)$pp['montant']; } }
              ?>
              <div class="alert <?= $verse ? 'alert-warn' : 'alert-info' ?> mt-2" style="margin-bottom:0">
                <?= $verse ? 'Montant déjà versé à rembourser : <strong>'.euros($verse).'</strong>' : 'Aucune somme versée par le client.' ?>
              </div>
            </div>
            <div>
              <p class="small muted mb-1"><strong>Motif du client</strong></p>
              <p class="small" style="white-space:pre-line"><?= e($d['motif']) ?></p>
            </div>
          </div>

          <form method="post" class="mt-2">
            <?= csrf_field() ?>
            <input type="hidden" name="form_traiter" value="1">
            <input type="hidden" name="demande_id" value="<?= (int)$d['id'] ?>">
            <label class="field">
              <span>Réponse au client (facultatif)</span>
              <textarea name="reponse" class="input" rows="2" placeholder="Motif de la décision…"></textarea>
            </label>
            <div class="flex-gap">
              <button name="action_demande" value="accepter" class="btn btn-primary btn-sm"
                      onclick="return confirm('Accepter : le projet sera annulé et les sommes remboursées.')">
                Accepter et rembourser
              </button>
              <button name="action_demande" value="refuser" class="btn btn-ghost btn-sm">Refuser</button>
              <a href="/projet?id=<?= (int)$d['projet_id'] ?>" class="btn btn-ghost btn-sm">Voir le projet</a>
            </div>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($traitees): ?>
    <div class="panel mt-3">
      <div class="panel-head"><h3>Historique</h3></div>
      <div class="table-wrap">
        <table>
          <thead><tr>
            <th>Projet</th><th>Client</th><th>Type</th><th>Décision</th><th>Date</th><th></th>
          </tr></thead>
          <tbody>
          <?php foreach ($traitees as $d): ?>
            <tr>
              <td class="t-title"><?= e($d['titre']) ?></td>
              <td class="small"><?= e($d['societe'] ?: trim($d['prenom'].' '.$d['nom'])) ?></td>
              <td class="small"><?= ucfirst(e($d['type'])) ?></td>
              <td><span class="badge <?= $d['statut']==='acceptee'?'badge-green':'badge-red' ?>">
                <?= $d['statut']==='acceptee'?'Acceptée':'Refusée' ?>
              </span></td>
              <td class="small muted"><?= date_fr($d['traite_le'] ?: $d['created_at']) ?></td>
              <td><a href="/projet?id=<?= (int)$d['projet_id'] ?>" class="btn btn-ghost btn-sm">Voir</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

</main>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
