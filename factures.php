<?php
/**
 * Missiona — Factures
 * ------------------------------------------------------------------
 * Modèle « paiement direct » : le client règle l'expert hors
 * plateforme. Missiona n'émet donc AUCUNE facture au client.
 *
 * Les factures listées ici sont celles que Missiona adresse à
 * l'expert : commission d'apport d'affaires (sans aucun frais fixe),
 * exigible une fois le client réglé (CGU, art. 4).
 */
require_once __DIR__ . '/includes/functions.php';
if (!is_logged()) { header('Location: ' . u('/connexion')); exit; }
$u = user();

/* Les entreprises clientes n'ont aucune facture Missiona : elles
   règlent l'expert directement. */
if (est_client($u['role'])) {
    $titre = "Facturation";
    require_once __DIR__ . '/includes/header.php';
    ?>
    <div class="app">
    <?php require __DIR__ . '/includes/sidebar.php'; ?>
    <main class="main">
      <div class="page-head">
        <div><h1>Facturation</h1><p>Aucune facture Missiona ne vous est adressée.</p></div>
      </div>
      <div class="panel">
        <div class="panel-body" style="text-align:center;padding:48px 24px">
          <div style="font-size:2.5rem">🤝</div>
          <h3 class="mt-2">Vous réglez l'expert directement</h3>
          <p class="muted" style="max-width:520px;margin:10px auto 0">
            Missiona n'encaisse aucun paiement et n'émet aucune facture en votre nom.
            C'est l'expert qui vous facture la prestation, selon les modalités convenues
            avec lui. Notre rémunération est facturée à l'expert, jamais à l'entreprise cliente.
          </p>
        </div>
      </div>
    </main>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

/* Marquer payée une facture de rémunération (admin uniquement). */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['facture_statut']) && $u['role']==='admin') {
    csrf_check();
    $fid = (int) $_POST['facture_statut'];
    $nouveau = ($_POST['statut'] ?? '') === 'payee' ? 'payee' : 'en_attente';
    $verif = db()->prepare("SELECT commission_prelevee_stripe, statut FROM factures WHERE id=?");
    $verif->execute([$fid]);
    $facture_modifiable = $verif->fetch();
    if (!$facture_modifiable || !empty($facture_modifiable['commission_prelevee_stripe'])
        || ($facture_modifiable['statut'] ?? '') === 'remboursee') {
        flash("Cette facture Stripe est rapprochée automatiquement et ne peut pas être modifiée manuellement.", 'error');
        header('Location: ' . u('/factures')); exit;
    }
    db()->prepare("UPDATE factures SET statut=? WHERE id=?")->execute([$nouveau, $fid]);
    if ($nouveau === 'payee') {
        $fs = db()->prepare("SELECT p.freelance_id, p.titre FROM factures f JOIN projets p ON p.id=f.projet_id WHERE f.id=?");
        $fs->execute([$fid]);
        if ($r = $fs->fetch()) {
            notify((int) $r['freelance_id'], "Votre facture de rémunération Missiona est soldée. Merci.", 'factures.php');
        }
        flash("Facture marquée payée.");
    } else {
        flash("Facture remise en attente.");
    }
    header('Location: ' . u('/factures')); exit;
}

if ($u['role']==='freelance') {
    $st = db()->prepare("SELECT f.*, p.titre FROM factures f JOIN projets p ON p.id=f.projet_id WHERE p.freelance_id=? ORDER BY f.id DESC");
    $st->execute([$u['id']]);
} else {
    $st = db()->query("SELECT f.*, p.titre, fl.nom AS f_nom, fl.prenom AS f_prenom, e.societe
                       FROM factures f
                       JOIN projets p ON p.id=f.projet_id
                       LEFT JOIN users e  ON e.id=p.entreprise_id
                       LEFT JOIN users fl ON fl.id=p.freelance_id
                       ORDER BY f.id DESC");
}
$factures = $st->fetchAll();

$tot_du      = array_sum(array_column($factures, 'montant_ht'));
$tot_com     = array_sum(array_column($factures, 'commission'));
$en_attente  = array_sum(array_map(fn($f) => $f['statut'] === 'en_attente' ? $f['montant_ht'] : 0, $factures));
$preleve_stripe = array_sum(array_map(fn($f) => !empty($f['commission_prelevee_stripe']) && $f['statut'] === 'payee' ? $f['montant_ht'] : 0, $factures));

$titre = $u['role']==='freelance' ? "Mes factures Missiona" : "Facturation";
require_once __DIR__ . '/includes/header.php';
?>
<div class="app">
<?php require __DIR__ . '/includes/sidebar.php'; ?>
<main class="main">

  <div class="page-head">
    <div><h1><?= e($titre) ?></h1><p><?= count($factures) ?> facture<?= count($factures)>1?'s':'' ?></p></div>
  </div>

  <?php if ($u['role']==='freelance'): ?>
    <div class="alert alert-info mb-3">
      🤝 Le client vous règle <strong>directement</strong> le montant de la prestation.
      Lorsqu'un paiement est effectué par carte Stripe, la commission Missiona est
      <strong>prélevée une seule fois automatiquement</strong> dans ce paiement et la
      facture correspondante est déjà acquittée. Pour les règlements hors Stripe,
      la commission reste exigible après le règlement du client.
    </div>
    <div class="grid grid-3 mb-4">
      <div class="stat"><div class="lbl">Missions facturées</div><div class="val"><?= count($factures) ?></div><div class="sub">au total</div></div>
      <div class="stat accent"><div class="lbl">Commission déjà prélevée par Stripe</div><div class="val"><?= euros($preleve_stripe) ?></div><div class="sub">aucun second règlement</div></div>
      <div class="stat"><div class="lbl">Restant à payer à Missiona</div><div class="val" style="color:<?= $en_attente > 0 ? 'var(--amber,#B45309)' : 'var(--green)' ?>"><?= euros($en_attente) ?></div><div class="sub">factures hors Stripe en attente</div></div>
    </div>
  <?php else: ?>
    <div class="grid grid-3 mb-4">
      <div class="stat"><div class="lbl">Rémunération facturée</div><div class="val"><?= euros($tot_du) ?></div><div class="sub">toutes missions</div></div>
      <div class="stat accent"><div class="lbl">Commissions d'apport</div><div class="val"><?= euros($tot_com) ?></div><div class="sub"><?= (int) round(taux_frais()) ?> % par mission</div></div>
      <div class="stat"><div class="lbl">Frais fixes</div><div class="val">0 €</div><div class="sub">aucun forfait</div></div>
    </div>
  <?php endif; ?>

  <div class="panel">
    <div class="panel-head"><h3><?= $u['role']==='freelance' ? 'Factures de rémunération Missiona' : 'Factures de rémunération' ?></h3></div>
    <?php if (!$factures): ?>
      <div class="empty"><div class="ico">🧾</div><h3>Aucune facture</h3><p>Une facture de rémunération est émise à la clôture de chaque mission.</p></div>
    <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr>
          <th>Facture</th><th>Mission</th>
          <?php if ($u['role']==='admin'): ?><th>Expert</th><th>Client</th><?php endif; ?>
          <th>Commission</th><th>Montant</th>
          <th>Statut</th><th>Date</th><th>Document</th>
          <?php if ($u['role']==='admin'): ?><th></th><?php endif; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($factures as $f): ?>
          <tr>
            <td class="t-title"><?= e($f['numero']) ?></td>
            <td class="small"><?= e($f['titre']) ?></td>
            <?php if ($u['role']==='admin'): ?>
              <td class="small"><?= e(trim(($f['f_prenom'] ?? '') . ' ' . ($f['f_nom'] ?? '')) ?: '—') ?></td>
              <td class="small"><?= e($f['societe'] ?? '—') ?></td>
            <?php endif; ?>
            <td class="small"><?= euros($f['commission']) ?></td>
            <td class="strong"><?= euros($f['montant_ht']) ?></td>
            <?php $facture_remboursee = ($f['statut'] === 'remboursee'); ?>
            <td><span class="badge <?= $facture_remboursee ? 'badge-red' : ($f['statut']==='payee'?'badge-green':'badge-amber') ?>"><?= $facture_remboursee ? 'Commission remboursée' : (!empty($f['commission_prelevee_stripe']) ? 'Prélevée par Stripe' : ($f['statut']==='payee' ? 'Payée' : 'En attente')) ?></span></td>
            <td class="small muted"><?= date_fr($f['created_at']) ?></td>
            <td style="white-space:nowrap">
              <a class="btn btn-sm btn-ghost"
                 href="<?= u('/facture-telecharger.php?id='.(int)$f['id'].'&type=expert&vue=1') ?>"
                 target="_blank" rel="noopener"
                 title="Ouvrir le PDF dans un nouvel onglet">Ouvrir</a>
              <a class="btn btn-sm btn-ghost"
                 href="<?= u('/facture-telecharger.php?id='.(int)$f['id'].'&type=expert') ?>"
                 title="Télécharger le PDF">PDF</a>
            </td>
            <?php if ($u['role']==='admin'): ?>
              <td>
                <?php if (empty($f['commission_prelevee_stripe']) && $f['statut'] !== 'remboursee'): ?>
                  <form method="post" style="margin:0">
                    <?= csrf_field() ?>
                    <?php if ($f['statut']==='payee'): ?>
                      <button name="facture_statut" value="<?= (int)$f['id'] ?>" class="btn btn-sm btn-ghost" title="Remettre en attente">↩</button>
                      <input type="hidden" name="statut" value="en_attente">
                    <?php else: ?>
                      <button name="facture_statut" value="<?= (int)$f['id'] ?>" class="btn btn-sm btn-primary" title="Marquer payée">✓ Payée</button>
                      <input type="hidden" name="statut" value="payee">
                    <?php endif; ?>
                  </form>
                <?php endif; ?>
              </td>
            <?php endif; ?>
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
