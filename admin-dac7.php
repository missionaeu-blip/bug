<?php
/**
 * Missiona — Back-office : campagne déclarative DAC7
 * Réservé à l'équipe. Pilotage des diligences, contrôle des données,
 * export du fichier DGFiP et envoi des récapitulatifs aux experts.
 */
require_once __DIR__ . '/includes/functions.php';
require_role('admin');

$u = user();

$annee = isset($_GET['annee']) ? (int) $_GET['annee'] : dac7_annee_defaut();
if ($annee < 2023 || $annee > (int) date('Y')) { $annee = dac7_annee_defaut(); }

// Identité de l'opérateur déclarant, tirée des paramètres.
$operateur = [
    'nom'        => param('dac7_nom', SITE_NOM),
    'nif'        => param('dac7_nif', ''),
    'adresse'    => param('dac7_adresse', ''),
    'cp'         => param('dac7_cp', ''),
    'ville'      => param('dac7_ville', ''),
    'pays'       => param('dac7_pays', 'FR'),
    'plateforme' => SITE_NOM,
];

/* ---------- Actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Coordonnées de l'opérateur déclarant
    if (isset($_POST['form_operateur'])) {
        foreach (['nom', 'nif', 'adresse', 'cp', 'ville', 'pays'] as $k) {
            set_param('dac7_' . $k, trim($_POST[$k] ?? ''));
        }
        flash('Identité du déclarant enregistrée.', 'success');
        header('Location: ' . u('/admin-dac7?annee=' . $annee));
        exit;
    }

    // Relance d'un expert au dossier incomplet
    if (isset($_POST['relancer'])) {
        $st = db()->prepare("SELECT * FROM users WHERE id=? AND role='freelance'");
        $st->execute([(int) $_POST['relancer']]);
        if ($fl = $st->fetch()) {
            $r = dac7_relancer($fl, $annee);
            flash($r['ok'] ? 'Relance envoyée (n°' . $r['relances'] . ').'
                           : $r['error'],
                  $r['ok'] ? 'success' : 'error');
        }
        header('Location: ' . u('/admin-dac7?annee=' . $annee));
        exit;
    }

    // Relance groupée
    if (isset($_POST['relancer_tous'])) {
        $n = 0;
        foreach (dac7_vendeurs_declarables($annee) as $fl) {
            if (!dac7_complet($fl)) { dac7_relancer($fl, $annee); $n++; }
        }
        flash($n . ' relance(s) envoyée(s).', 'success');
        header('Location: ' . u('/admin-dac7?annee=' . $annee));
        exit;
    }

    // Diligences accomplies pour un expert
    if (isset($_POST['verifier'])) {
        $st = db()->prepare("SELECT * FROM users WHERE id=? AND role='freelance'");
        $st->execute([(int) $_POST['verifier']]);
        if ($fl = $st->fetch()) {
            dac7_marquer_verifie($fl, $annee);
            flash('Diligences enregistrées.', 'success');
        }
        header('Location: ' . u('/admin-dac7?annee=' . $annee));
        exit;
    }

    // Envoi des récapitulatifs annuels aux experts
    if (isset($_POST['envoyer_attestations'])) {
        $n = 0;
        foreach (dac7_vendeurs_declarables($annee) as $fl) {
            dac7_envoyer_attestation($fl, $annee); $n++;
        }
        flash($n . ' récapitulatif(s) communiqué(s) aux experts.', 'success');
        header('Location: ' . u('/admin-dac7?annee=' . $annee));
        exit;
    }
}

/* ---------- Téléchargements ---------- */
if (isset($_GET['export'])) {
    if ($_GET['export'] === 'xml') {
        $xml = dac7_export_xml($annee, $operateur);
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="DPI-DAC7-' . $annee . '.xml"');
        echo $xml;
        exit;
    }
    if ($_GET['export'] === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="dac7-controle-' . $annee . '.csv"');
        echo "\xEF\xBB\xBF" . dac7_export_csv($annee);
        exit;
    }
    if ($_GET['export'] === 'attestation' && isset($_GET['id'])) {
        $st = db()->prepare("SELECT * FROM users WHERE id=? AND role='freelance'");
        $st->execute([(int) $_GET['id']]);
        if ($fl = $st->fetch()) {
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="recapitulatif-'
                 . $annee . '-' . preg_replace('/[^a-z0-9]+/i', '-', $fl['nom']) . '.txt"');
            echo dac7_attestation_texte($fl, $annee);
            exit;
        }
    }
}

$vendeurs   = dac7_vendeurs_declarables($annee);
$hors_champ = dac7_vendeurs_hors_champ($annee);
$synth      = dac7_synthese($annee);
$registre   = dac7_registre($annee, 40);
$op_pret    = ($operateur['nif'] !== '' && $operateur['adresse'] !== '');

$titre = 'Déclaration DAC7';
include __DIR__ . '/includes/header.php';
?>
<div class="app">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main">

    <div class="page-head flex-between" style="flex-wrap:wrap;gap:12px">
      <div>
        <h1>Déclaration DAC7 — <?= (int) $annee ?></h1>
        <p class="muted">
          Dépôt à effectuer au plus tard le <strong><?= e(dac7_echeance($annee)) ?></strong>
          sur le portail Tiers déclarants (Télé-TD) de la DGFiP.
        </p>
      </div>
      <form method="get" class="flex-gap">
        <select name="annee" class="select" onchange="this.form.submit()">
          <?php for ($a = (int) date('Y') - 1; $a >= 2023; $a--): ?>
            <option value="<?= $a ?>" <?= $a === $annee ? 'selected' : '' ?>>Exercice <?= $a ?></option>
          <?php endfor; ?>
        </select>
      </form>
    </div>

    <?php if ($f = flash()): ?><div class="alert alert-<?= e($f['t']) ?>"><?= e($f['m']) ?></div><?php endif; ?>

    <div class="alert alert-info mb-3">
      <strong>Règle à connaître.</strong> Le seuil d'exclusion « moins de 30 opérations
      et au plus 2 000 € » ne s'applique qu'à la <em>vente de biens</em>.
      Missiona relève des <em>services personnels</em> :
      <strong>tout expert résident de l'Union européenne est déclarable dès le premier euro</strong>,
      sans aucun seuil.
    </div>

    <!-- Statistiques -->
    <div class="grid grid-5 mb-3">
      <div class="stat"><div class="lbl">Experts à déclarer</div>
        <div class="val"><?= (int) $synth['declarables'] ?></div></div>
      <div class="stat"><div class="lbl">Dossiers complets</div>
        <div class="val" style="color:var(--ok,#1c7a4a)"><?= (int) $synth['complets'] ?></div></div>
      <div class="stat"><div class="lbl">Dossiers incomplets</div>
        <div class="val" style="color:<?= $synth['incomplets'] ? '#a32828' : 'inherit' ?>">
          <?= (int) $synth['incomplets'] ?></div></div>
      <div class="stat"><div class="lbl">Hors champ (hors UE)</div>
        <div class="val"><?= (int) $synth['hors_champ'] ?></div></div>
      <div class="stat"><div class="lbl">Contreparties déclarées</div>
        <div class="val"><?= euros($synth['montant']) ?></div></div>
    </div>

    <!-- Identité du déclarant -->
    <div class="panel mb-3">
      <div class="panel-head">
        <h3>Identité du déclarant</h3>
        <span class="badge <?= $op_pret ? 'badge-green' : 'badge-amber' ?>">
          <?= $op_pret ? 'Renseignée' : 'À compléter' ?>
        </span>
      </div>
      <div class="panel-body">
        <p class="small muted mb-3">
          Ces informations identifient Missiona dans le fichier déposé.
          Le NIF est le SIRET de la société (14 chiffres).
        </p>
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="form_operateur" value="1">
          <div class="field-row">
            <div class="field">
              <label for="o_nom">Raison sociale</label>
              <input type="text" name="nom" id="o_nom" class="input" value="<?= e($operateur['nom']) ?>">
            </div>
            <div class="field">
              <label for="o_nif">NIF / SIRET</label>
              <input type="text" name="nif" id="o_nif" class="input" value="<?= e($operateur['nif']) ?>">
            </div>
          </div>
          <div class="field mb-3">
            <label for="o_adresse">Adresse du siège</label>
            <input type="text" name="adresse" id="o_adresse" class="input" value="<?= e($operateur['adresse']) ?>">
          </div>
          <div class="field-row">
            <div class="field">
              <label for="o_cp">Code postal</label>
              <input type="text" name="cp" id="o_cp" class="input" value="<?= e($operateur['cp']) ?>">
            </div>
            <div class="field">
              <label for="o_ville">Ville</label>
              <input type="text" name="ville" id="o_ville" class="input" value="<?= e($operateur['ville']) ?>">
            </div>
            <div class="field">
              <label for="o_pays">Pays</label>
              <input type="text" name="pays" id="o_pays" class="input" style="max-width:100px"
                     value="<?= e($operateur['pays']) ?>" maxlength="2">
            </div>
          </div>
          <button class="btn btn-primary" type="submit">Enregistrer</button>
        </form>
      </div>
    </div>

    <!-- Experts déclarables -->
    <div class="panel mb-3">
      <div class="panel-head">
        <h3>Experts à déclarer</h3>
        <?php if ($synth['incomplets']): ?>
          <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <button type="submit" name="relancer_tous" value="1" class="btn btn-sm btn-ghost">
              Relancer les <?= (int) $synth['incomplets'] ?> dossiers incomplets
            </button>
          </form>
        <?php endif; ?>
      </div>
      <div class="panel-body">
        <?php if (!$vendeurs): ?>
          <p class="muted mb-0">Aucun expert résident de l'UE n'a perçu de revenu en <?= (int) $annee ?>.</p>
        <?php else: ?>
          <table class="table">
            <thead>
              <tr>
                <th>Expert</th><th>Pays</th><th>NIF</th>
                <th>T1</th><th>T2</th><th>T3</th><th>T4</th>
                <th>Total</th><th>Dossier</th><th></th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($vendeurs as $fl):
                    $d = dac7_donnees_vendeur($fl['id'], $annee);
                    $mq = dac7_champs_manquants($fl); ?>
              <tr>
                <td>
                  <strong><?= e(trim($fl['prenom'] . ' ' . $fl['nom'])) ?></strong>
                  <div class="small muted"><?= e($fl['email']) ?></div>
                </td>
                <td><?= e($fl['pays']) ?></td>
                <td class="small"><?= e($fl['nif'] ?: '—') ?></td>
                <?php foreach ([1,2,3,4] as $q): ?>
                  <td class="small"><?= euros($d['trimestres'][$q]['contrepartie']) ?></td>
                <?php endforeach; ?>
                <td><strong><?= euros($d['total_contrepartie']) ?></strong></td>
                <td>
                  <?php if (!$mq): ?>
                    <span class="badge badge-green">Complet</span>
                    <?php if ($fl['dac7_verifie_le']): ?>
                      <div class="small muted">Vérifié</div>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="badge badge-amber">Incomplet</span>
                    <div class="small muted" title="<?= e(implode(', ', $mq)) ?>">
                      <?= e(implode(', ', array_slice($mq, 0, 2))) ?><?= count($mq) > 2 ? '…' : '' ?>
                    </div>
                    <?php if ($fl['dac7_relances']): ?>
                      <div class="small muted"><?= (int) $fl['dac7_relances'] ?> relance(s)</div>
                    <?php endif; ?>
                  <?php endif; ?>
                </td>
                <td style="white-space:nowrap">
                  <?php if ($mq): ?>
                    <form method="post" style="display:inline">
                      <?= csrf_field() ?>
                      <button type="submit" name="relancer" value="<?= (int) $fl['id'] ?>"
                              class="btn btn-sm btn-ghost">Relancer</button>
                    </form>
                  <?php elseif (!$fl['dac7_verifie_le']): ?>
                    <form method="post" style="display:inline">
                      <?= csrf_field() ?>
                      <button type="submit" name="verifier" value="<?= (int) $fl['id'] ?>"
                              class="btn btn-sm btn-ghost">Marquer vérifié</button>
                    </form>
                  <?php endif; ?>
                  <a class="btn btn-sm btn-ghost"
                     href="<?= u('/admin-dac7?annee=' . $annee . '&export=attestation&id=' . (int) $fl['id']) ?>">
                    Récapitulatif
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($hors_champ): ?>
    <div class="panel mb-3">
      <div class="panel-head"><h3>Hors champ de la déclaration</h3>
        <span class="badge badge-gray"><?= count($hors_champ) ?></span></div>
      <div class="panel-body">
        <p class="small muted mb-3">
          Experts non résidents de l'Union européenne : hors du champ de la déclaration
          française. Listés ici pour justifier leur exclusion en cas de contrôle.
        </p>
        <table class="table">
          <thead><tr><th>Expert</th><th>Pays</th><th>Total <?= (int) $annee ?></th></tr></thead>
          <tbody>
          <?php foreach ($hors_champ as $fl): $d = dac7_donnees_vendeur($fl['id'], $annee); ?>
            <tr>
              <td><?= e(trim($fl['prenom'] . ' ' . $fl['nom'])) ?></td>
              <td><?= e($fl['pays'] ?: '—') ?></td>
              <td><?= euros($d['total_contrepartie']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <!-- Dépôt -->
    <div class="panel mb-3">
      <div class="panel-head"><h3>Dépôt et communication</h3></div>
      <div class="panel-body">
        <?php if ($synth['incomplets']): ?>
          <div class="alert alert-warn mb-3">
            <?= (int) $synth['incomplets'] ?> dossier(s) encore incomplet(s).
            Le fichier peut être produit, mais il sera rejeté ou incomplet :
            terminez les diligences avant le 31 décembre <?= (int) $annee ?>.
          </div>
        <?php endif; ?>
        <?php if (!$op_pret): ?>
          <div class="alert alert-warn mb-3">
            Renseignez d'abord l'identité du déclarant ci-dessus.
          </div>
        <?php endif; ?>

        <div class="flex-gap" style="flex-wrap:wrap;gap:10px">
          <a class="btn btn-primary" href="<?= u('/admin-dac7?annee=' . $annee . '&export=xml') ?>">
            Télécharger le fichier XML
          </a>
          <a class="btn btn-light" href="<?= u('/admin-dac7?annee=' . $annee . '&export=csv') ?>">
            Export CSV de contrôle
          </a>
          <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <button type="submit" name="envoyer_attestations" value="1" class="btn btn-light">
              Communiquer les récapitulatifs aux experts
            </button>
          </form>
        </div>

        <div class="sep"></div>
        <p class="small muted mb-0">
          <strong>Avant tout dépôt réel :</strong> validez le fichier contre le schéma XSD
          officiel publié sur impots.gouv.fr (cahier des charges DPI-DAC7) et effectuez
          un dépôt à blanc. Le schéma évolue chaque année.
          La communication du récapitulatif à chaque expert est obligatoire :
          son absence est sanctionnée jusqu'à 50 000 €.
        </p>
      </div>
    </div>

    <!-- Registre -->
    <div class="panel">
      <div class="panel-head"><h3>Registre des diligences</h3>
        <span class="badge badge-gray">Conservation 10 ans</span></div>
      <div class="panel-body">
        <?php if (!$registre): ?>
          <p class="muted mb-0">Aucune démarche enregistrée pour <?= (int) $annee ?>.</p>
        <?php else: ?>
          <table class="table">
            <thead><tr><th>Date</th><th>Expert</th><th>Action</th><th>Détail</th><th>Auteur</th></tr></thead>
            <tbody>
            <?php foreach ($registre as $r): ?>
              <tr>
                <td class="small"><?= date_fr($r['created_at']) ?></td>
                <td class="small"><?= e(trim(($r['prenom'] ?? '') . ' ' . ($r['nom'] ?? ''))) ?: '—' ?></td>
                <td><span class="badge badge-blue"><?= e($r['action']) ?></span></td>
                <td class="small"><?= e($r['detail']) ?></td>
                <td class="small muted"><?= e($r['auteur']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>

  </main>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
