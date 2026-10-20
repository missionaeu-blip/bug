<?php
/**
 * Missiona — Suivi de l'équipe d'administration
 * ------------------------------------------------------------------
 * Qui est connecté, qui traite quoi, et possibilité de se connecter
 * à la place d'un administrateur pour reproduire un problème.
 *
 * Réservé à l'administrateur principal : cette page expose l'activité
 * détaillée de chacun et donne accès à leurs comptes.
 */
require_once __DIR__ . '/includes/functions.php';
require_admin_principal();

/* ---------- Actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (isset($_POST['emprunter'])) {
        $st = db()->prepare("SELECT * FROM users WHERE id = ? AND role = 'admin'");
        $st->execute([(int) $_POST['emprunter']]);
        $cible = $st->fetch();

        $refus = emprunt_refus($cible);
        if ($refus !== '') {
            flash($refus, 'error');
            header('Location: ' . u('/admin-suivi-equipe')); exit;
        }
        emprunt_demarrer($cible);
        flash("Vous êtes maintenant connecté en tant que "
            . trim($cible['prenom'] . ' ' . $cible['nom']) . ".", 'success');
        header('Location: ' . u('/admin')); exit;
    }
}

$admins = equipe_admins();
$detail = null;
$vu     = (int) ($_GET['admin'] ?? 0);
if ($vu > 0) {
    foreach ($admins as $a) { if ((int) $a['id'] === $vu) { $detail = $a; break; } }
}

$titre = 'Suivi de l’équipe';
require_once __DIR__ . '/includes/header.php';
?>
<div class="app">
<?php require __DIR__ . '/includes/sidebar.php'; ?>
<main class="main">

  <div class="page-head">
    <div>
      <h1>Suivi de l'équipe</h1>
      <p>Présence, charge de travail et accès de dépannage.</p>
    </div>
  </div>

  <?php if ($f = flash()): ?>
    <div class="alert alert-<?= e($f['t']) ?>"><?= e($f['m']) ?></div>
  <?php endif; ?>

<?php if ($detail === null): ?>

  <!-- ===================== LISTE ===================== -->
  <div class="grid grid-3 mb-3">
    <div class="stat">
      <div class="lbl">Administrateurs</div>
      <div class="val"><?= count($admins) ?></div>
      <div class="sub">comptes actifs</div>
    </div>
    <div class="stat accent">
      <div class="lbl">Connectés</div>
      <div class="val"><?= count(array_filter($admins, fn($a) => $a['en_ligne'])) ?></div>
      <div class="sub">ces <?= equipe_delai_presence() ?> dernières minutes</div>
    </div>
    <div class="stat">
      <div class="lbl">Projets à traiter</div>
      <div class="val"><?= array_sum(array_map(fn($a) => equipe_chiffres($a['id'])['a_traiter'], $admins)) ?></div>
      <div class="sub">en attente d'analyse</div>
    </div>
  </div>

  <div class="panel mb-3">
    <div class="panel-head">
      <h3>Administrateurs</h3>
      <span class="small muted" id="maj-direct">
        <span class="led led-on" style="animation:pulse 2s infinite"></span>
        en direct
      </span>
    </div>
    <div class="panel-body">
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>État</th><th>Administrateur</th>
              <th class="right">Clients</th><th class="right">Projets</th>
              <th class="right">En cours</th><th class="right">Avancement</th>
              <th>Page en cours</th><th>Dernière venue</th><th></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($admins as $a): $c = equipe_chiffres($a['id']); ?>
            <tr>
              <td style="white-space:nowrap">
                <span class="led <?= $a['en_ligne'] ? 'led-on' : 'led-off' ?>"
                      data-led="<?= (int) $a['id'] ?>"
                      title="<?= $a['en_ligne'] ? 'Connecté' : 'Hors ligne' ?>"></span>
                <span class="small muted" data-etat="<?= (int) $a['id'] ?>"><?= $a['en_ligne'] ? 'en ligne' : 'absent' ?></span>
              </td>
              <td>
                <strong><?= e(trim($a['prenom'] . ' ' . $a['nom'])) ?></strong>
                <?php if ($a['principal']): ?>
                  <span class="badge badge-gray">principal</span>
                <?php endif; ?>
                <div class="small muted"><?= e($a['email']) ?></div>
              </td>
              <td class="right"><?= (int) $c['clients'] ?></td>
              <td class="right"><?= (int) $c['projets'] ?></td>
              <td class="right">
                <?= (int) $c['en_cours'] ?>
                <?php if ($c['a_traiter'] > 0): ?>
                  <span class="badge badge-amber"><?= (int) $c['a_traiter'] ?> à traiter</span>
                <?php endif; ?>
              </td>
              <td class="right">
                <?= $c['avancement'] === null ? '—' : (int) $c['avancement'] . ' %' ?>
              </td>
              <td class="small" data-page="<?= (int) $a['id'] ?>">
                <?php if ($a['en_ligne']): ?>
                  <span class="puce-page"><?= e(equipe_nom_page($a['page_actuelle'] ?? '')) ?></span>
                <?php else: ?>
                  <span class="muted">—</span>
                <?php endif; ?>
              </td>
              <td class="small muted" data-depuis="<?= (int) $a['id'] ?>"><?= e(equipe_depuis($a['derniere_vue'])) ?></td>
              <td class="right" style="white-space:nowrap">
                <a class="btn btn-sm btn-ghost"
                   href="<?= u('/admin-suivi-equipe?admin=' . (int) $a['id']) ?>">Détail</a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <?php $journal = equipe_journal(15); ?>
  <?php if ($journal): ?>
  <div class="panel">
    <div class="panel-head"><h3>Journal des accès de dépannage</h3></div>
    <div class="panel-body">
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Quand</th><th>Qui</th><th>Action</th><th>Détail</th></tr></thead>
          <tbody>
          <?php foreach ($journal as $j): ?>
            <tr>
              <td class="small muted"><?= e(date_fr($j['created_at'])) ?></td>
              <td class="small"><?= e(trim(($j['a_prenom'] ?? '') . ' ' . ($j['a_nom'] ?? '')) ?: '—') ?></td>
              <td><span class="badge badge-gray"><?= e($j['action']) ?></span></td>
              <td class="small muted"><?= e($j['detail']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>

<?php else: ?>

  <!-- ===================== DÉTAIL ===================== -->
  <?php $c = equipe_chiffres($detail['id']); ?>

  <p class="mb-3">
    <a class="btn btn-sm btn-ghost" href="<?= u('/admin-suivi-equipe') ?>">← Tous les administrateurs</a>
  </p>

  <div class="panel mb-3">
    <div class="panel-head">
      <h3>
        <span class="led <?= $detail['en_ligne'] ? 'led-on' : 'led-off' ?>"></span>
        <?= e(trim($detail['prenom'] . ' ' . $detail['nom'])) ?>
      </h3>
      <span class="badge <?= $detail['en_ligne'] ? 'badge-green' : 'badge-gray' ?>">
        <?= $detail['en_ligne'] ? 'Connecté' : 'Hors ligne' ?>
      </span>
    </div>
    <div class="panel-body">
      <div class="recap mb-3">
        <div class="recap-row"><span>Adresse</span><strong><?= e($detail['email']) ?></strong></div>
        <div class="recap-row"><span>Rôle</span>
          <strong><?= $detail['principal'] ? 'Administrateur principal' : 'Administrateur' ?></strong></div>
        <div class="recap-row"><span>Dernière venue</span>
          <strong><?= e(equipe_depuis($detail['derniere_vue'])) ?></strong></div>
        <div class="recap-row"><span>Compte créé le</span>
          <strong><?= e(date_fr($detail['created_at'])) ?></strong></div>
      </div>

      <?php if (!$detail['principal']): ?>
        <div class="sep"></div>
        <h4 style="margin:0 0 8px;font-size:.95rem">Accès de dépannage</h4>
        <p class="small muted mb-3">
          Ouvre une session à son nom, dans <em>votre</em> navigateur, pour voir
          exactement ce qu'il voit. Son appareil n'est pas contrôlé et il n'est
          pas déconnecté. L'opération est inscrite au journal, et un bandeau
          rouge reste affiché tant qu'elle dure.
        </p>
        <form method="post"
              onsubmit="return confirm('Ouvrir une session au nom de <?= e(addslashes(trim($detail['prenom'].' '.$detail['nom']))) ?> ? Cette action est journalisée.');">
          <?= csrf_field() ?>
          <button type="submit" name="emprunter" value="<?= (int) $detail['id'] ?>"
                  class="btn btn-sm btn-light">Prendre la main sur ce compte</button>
        </form>
      <?php else: ?>
        <div class="alert alert-info small mb-0">
          C'est le compte principal : il ne peut pas être emprunté, y compris par lui-même.
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="grid grid-4 mb-3">
    <div class="stat"><div class="lbl">Clients suivis</div><div class="val"><?= (int) $c['clients'] ?></div></div>
    <div class="stat"><div class="lbl">Projets</div><div class="val"><?= (int) $c['projets'] ?></div>
      <div class="sub"><?= (int) $c['attribues'] ?> attribués</div></div>
    <div class="stat"><div class="lbl">Terminés</div><div class="val"><?= (int) $c['termines'] ?></div>
      <div class="sub"><?= euros($c['ca']) ?> livrés</div></div>
    <div class="stat accent"><div class="lbl">À traiter</div><div class="val"><?= (int) $c['a_traiter'] ?></div>
      <div class="sub">en attente</div></div>
  </div>

  <div class="grid grid-2 mb-3">
    <div class="panel">
      <div class="panel-head"><h3>Projets suivis</h3></div>
      <div class="panel-body">
        <?php $pr = equipe_projets($detail['id'], 25); ?>
        <?php if (!$pr): ?>
          <p class="muted small mb-0">Aucun projet rattaché à cet administrateur.</p>
        <?php else: ?>
          <div class="table-wrap">
            <table class="table">
              <thead><tr><th>Projet</th><th>Client</th><th>Statut</th><th class="right">Avancement</th></tr></thead>
              <tbody>
              <?php foreach ($pr as $p): ?>
                <tr>
                  <td class="small"><a href="<?= u('/projet.php?id=' . (int) $p['id']) ?>"><?= e($p['titre']) ?></a></td>
                  <td class="small muted"><?= e($p['societe'] ?: $p['c_nom']) ?></td>
                  <td><span class="badge badge-gray"><?= e($p['statut']) ?></span></td>
                  <td class="right small"><?= (int) $p['avancement'] ?> %</td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="panel">
      <div class="panel-head"><h3>Dernières actions</h3></div>
      <div class="panel-body">
        <?php $act = equipe_activite($detail['id'], 25); ?>
        <?php if (!$act): ?>
          <p class="muted small mb-0">Aucune action enregistrée.</p>
        <?php else: ?>
          <div class="table-wrap">
            <table class="table">
              <thead><tr><th>Quand</th><th>Type</th><th>Détail</th></tr></thead>
              <tbody>
              <?php foreach ($act as $x): ?>
                <tr>
                  <td class="small muted" style="white-space:nowrap"><?= e(date_fr($x['quand'])) ?></td>
                  <td><span class="badge badge-gray"><?= e($x['type']) ?></span></td>
                  <td class="small"><?= e($x['quoi']) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

<?php endif; ?>

<?php if ($detail === null): ?>
<script>
/* Rafraichit l'etat de presence sans recharger la page. */
(function () {
  var actif = true;
  document.addEventListener('visibilitychange', function () { actif = !document.hidden; });

  function majuscule(t) { return t.charAt(0).toUpperCase() + t.slice(1); }

  function rafraichir() {
    if (!actif) { return; }
    fetch('/admin-presence.php', { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d || !d.ok) { return; }
        d.admins.forEach(function (a) {
          var led = document.querySelector('[data-led="' + a.id + '"]');
          if (led) {
            led.className = 'led ' + (a.ligne ? 'led-on' : 'led-off');
            led.title = a.ligne ? 'Connecte' : 'Hors ligne';
          }
          var etat = document.querySelector('[data-etat="' + a.id + '"]');
          if (etat) { etat.textContent = a.ligne ? 'en ligne' : 'absent'; }

          var page = document.querySelector('[data-page="' + a.id + '"]');
          if (page) {
            page.innerHTML = a.ligne
              ? '<span class="puce-page"></span>'
              : '<span class="muted">\u2014</span>';
            if (a.ligne) { page.firstChild.textContent = a.page; }
          }
          var dep = document.querySelector('[data-depuis="' + a.id + '"]');
          if (dep) { dep.textContent = a.depuis; }
        });
        var t = document.getElementById('maj-direct');
        if (t) { t.title = 'Derniere mise a jour ' + d.heure; }
      })
      .catch(function () {});
  }
  setInterval(rafraichir, 8000);
})();
</script>
<?php endif; ?>

</main>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
