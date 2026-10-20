<?php
/**
 * Missiona — Sauvegarde et restauration de la base
 * ------------------------------------------------------------------
 * Permet de télécharger une copie complète des données avant une
 * mise à jour, et de restaurer une sauvegarde en cas de problème.
 */
require_once __DIR__ . '/includes/functions.php';
require_role('admin');

$dossier_sauv = dirname(DB_FILE) . '/sauvegardes';

/* ---------- Téléchargement ---------- */
if (isset($_GET['telecharger'])) {
    $nom = basename((string) $_GET['telecharger']);
    $src = ($nom === 'maintenant') ? DB_FILE : $dossier_sauv . '/' . $nom;

    if (!preg_match('/^(maintenant|(missiona|auto|avant-restauration)-[0-9\-]+\.sqlite)$/', $nom) || !is_file($src)) {
        flash('Sauvegarde introuvable.', 'error');
        header('Location: ' . u('/admin-sauvegarde')); exit;
    }
    $fichier = 'missiona-' . date('Ymd-His') . '.sqlite';
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $fichier . '"');
    header('Content-Length: ' . filesize($src));
    readfile($src);
    exit;
}

/* ---------- Actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();   // interrompt la requête si le jeton est invalide


    // Créer une sauvegarde immédiate
    if (isset($_POST['reglages_auto'])) {
        set_param('sauvegarde_intervalle', max(0, min(90, (int) ($_POST['sauvegarde_intervalle'] ?? 7))));
        set_param('sauvegarde_nombre',     max(3, min(30, (int) ($_POST['sauvegarde_nombre'] ?? 8))));
        flash("Réglages des sauvegardes automatiques enregistrés.", 'success');
        header('Location: ' . u('/admin-sauvegarde')); exit;
    }

    if (isset($_POST['creer'])) {
        if (!is_dir($dossier_sauv)) { @mkdir($dossier_sauv, 0700, true); proteger_dossier_base($dossier_sauv); }
        if (sauvegarder_base($dossier_sauv)) {
            flash('Sauvegarde créée.', 'success');
        } else {
            flash('La sauvegarde a échoué : dossier non inscriptible.', 'error');
        }
        header('Location: ' . u('/admin-sauvegarde')); exit;
    }

    // Restaurer une sauvegarde existante
    if (isset($_POST['restaurer'])) {
        $nom = basename((string) $_POST['restaurer']);
        $src = $dossier_sauv . '/' . $nom;
        if (!preg_match('/^(missiona|auto)-[0-9\-]+\.sqlite$/', $nom) || !is_file($src)) {
            flash('Sauvegarde introuvable.', 'error');
        } else {
            // On met d'abord la base actuelle de côté : une restauration
            // ne doit jamais être un aller sans retour.
            sauvegarder_base($dossier_sauv, 'avant-restauration');
            if (@copy($src, DB_FILE)) {
                flash('Base restaurée à partir de ' . $nom . '. Reconnectez-vous.', 'success');
                header('Location: ' . u('/deconnexion')); exit;
            }
            flash('La restauration a échoué.', 'error');
        }
        header('Location: ' . u('/admin-sauvegarde')); exit;
    }
}

/* ---------- Affichage ---------- */
$sauvegardes = glob($dossier_sauv . '/*.sqlite') ?: [];
rsort($sauvegardes);

// Comparaison avec un séparateur final : sans lui, un dossier voisin
// nommé « …-data » serait pris à tort pour un sous-dossier du site.
$_site = rtrim(realpath(__DIR__) ?: __DIR__, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
$_base = realpath(DB_FILE) ?: DB_FILE;
$dans_le_site = strpos($_base, $_site) === 0;

$titre = 'Sauvegarde des données';
require_once __DIR__ . '/includes/header.php';
?>
<div class="app">
<?php require __DIR__ . '/includes/sidebar.php'; ?>
<main class="main">

    <div class="page-head">
      <h1>Sauvegarde des données</h1>
      <p class="muted">Comptes, projets, messages et factures : tout est dans un seul fichier.</p>
    </div>

    <?php if ($f = flash()): ?>
      <div class="alert alert-<?= e($f['t']) ?>"><?= e($f['m']) ?></div>
    <?php endif; ?>

    <div class="panel mb-3">
      <div class="panel-head"><h3>Emplacement de la base</h3></div>
      <div class="panel-body">
        <p class="small mb-2"><code><?= e(DB_FILE) ?></code></p>
        <?php if ($dans_le_site): ?>
          <div class="alert alert-amber">
            <strong>Attention :</strong> la base est stockée à l'intérieur du dossier du site.
            Si vous remplacez tout le dossier lors d'une mise à jour, vous perdez vos données.
            Téléchargez systématiquement une sauvegarde avant d'envoyer de nouveaux fichiers,
            ou déplacez la base hors du site en ajoutant dans <code>config.local.php</code> :
            <br><code>define('DB_FILE', '/chemin/hors/du/site/missiona.sqlite');</code>
          </div>
        <?php else: ?>
          <div class="alert alert-green">
            La base est rangée <strong>hors du dossier du site</strong> : une mise à jour des
            fichiers ne peut pas l'effacer.
          </div>
        <?php endif; ?>
        <p class="small muted">
          Taille : <?= is_file(DB_FILE) ? number_format(filesize(DB_FILE) / 1024, 0, ',', ' ') . ' Ko' : '—' ?>
          — dernière modification :
          <?= is_file(DB_FILE) ? date('d/m/Y à H:i', filemtime(DB_FILE)) : '—' ?>
        </p>
      </div>
    </div>

    <div class="panel mb-3">
      <div class="panel-head"><h3>Avant chaque mise à jour</h3></div>
      <div class="panel-body">
        <p class="small mb-3">Téléchargez une copie sur votre ordinateur, puis conservez-la.</p>
        <a class="btn btn-primary" href="<?= u('/admin-sauvegarde?telecharger=maintenant') ?>">
          Télécharger la base maintenant
        </a>
        <form method="post" style="display:inline">
          <?= csrf_field() ?>
          <button type="submit" name="creer" value="1" class="btn btn-ghost">
            Créer une sauvegarde sur le serveur
          </button>
        </form>
      </div>
    </div>

    <div class="panel mb-3">
      <div class="panel-head"><h3>Sauvegardes automatiques</h3></div>
      <div class="panel-body">
        <p class="small mb-3">
          Une copie est prise <strong>avant chaque mise à jour qui modifie la structure
          de la base</strong>. Le site le détecte tout seul : vous n'avez aucun numéro de
          version à renseigner. Une copie est aussi prise si la dernière remonte à trop
          longtemps, pour qu'il existe toujours un point de restauration récent.
        </p>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="reglages_auto" value="1">
          <div class="field-row">
            <div class="field">
              <label for="sauvegarde_intervalle">Copie au moins tous les (jours)</label>
              <input type="number" name="sauvegarde_intervalle" id="sauvegarde_intervalle"
                     class="input" min="0" max="90" step="1"
                     value="<?= (int) param('sauvegarde_intervalle', 7) ?>">
              <div class="hint">0 : uniquement lors des mises à jour.</div>
            </div>
            <div class="field">
              <label for="sauvegarde_nombre">Copies conservées</label>
              <input type="number" name="sauvegarde_nombre" id="sauvegarde_nombre"
                     class="input" min="3" max="30" step="1"
                     value="<?= (int) param('sauvegarde_nombre', 8) ?>">
              <div class="hint">Les plus anciennes sont supprimées.</div>
            </div>
          </div>
          <button class="btn btn-primary btn-sm mt-2" type="submit">Enregistrer</button>
        </form>
      </div>
    </div>

    <div class="panel">
      <div class="panel-head"><h3>Sauvegardes disponibles</h3></div>
      <div class="panel-body">
        <?php if (!$sauvegardes): ?>
          <p class="muted small">Aucune sauvegarde pour le moment.</p>
        <?php else: ?>
          <table class="table">
            <thead><tr><th>Fichier</th><th>Date</th><th>Taille</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($sauvegardes as $s): $n = basename($s); ?>
              <tr>
                <td class="small"><?= e($n) ?></td>
                <td class="small muted"><?= date('d/m/Y à H:i', filemtime($s)) ?></td>
                <td class="small muted"><?= number_format(filesize($s) / 1024, 0, ',', ' ') ?> Ko</td>
                <td style="white-space:nowrap">
                  <a class="btn btn-sm btn-ghost"
                     href="<?= u('/admin-sauvegarde?telecharger=' . urlencode($n)) ?>">Télécharger</a>
                  <?php if (preg_match('/^(missiona|auto)-/', $n)): ?>
                    <form method="post" style="display:inline"
                          onsubmit="return confirm('Remplacer toutes les données actuelles par cette sauvegarde ?');">
                      <?= csrf_field() ?>
                      <button type="submit" name="restaurer" value="<?= e($n) ?>"
                              class="btn btn-sm btn-ghost">Restaurer</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <p class="hint mt-2">
            Une sauvegarde automatique est créée avant chaque évolution de la structure
            de la base. Les cinq plus récentes sont conservées.
          </p>
        <?php endif; ?>
      </div>
    </div>

</main>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
