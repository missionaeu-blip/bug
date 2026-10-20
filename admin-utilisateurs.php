<?php
require_once __DIR__ . '/includes/functions.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_check();
    $uid    = (int) ($_POST['uid'] ?? 0);
    $action = $_POST['action'] ?? 'statut';

    if ($action === 'supprimer') {
        if (($_POST['confirmation'] ?? '') !== 'SUPPRIMER') {
            flash("Saisissez SUPPRIMER pour confirmer l'effacement définitif.", 'error');
        } else {
            $r = supprimer_utilisateur_admin($uid, user());
            flash($r['erreur'], $r['ok'] ? 'success' : 'error');
        }
    } else {
        $cible = db()->prepare("SELECT * FROM users WHERE id=?"); $cible->execute([$uid]);
        $cible = $cible->fetch();
        $princ = admin_principal();
        if (!$cible) {
            flash("Compte introuvable.", 'error');
        } elseif ($princ && (int)$cible['id'] === (int)$princ['id']) {
            flash("L'administrateur principal ne peut pas être désactivé.", 'error');
        } elseif ($cible['role'] === 'admin' && !est_admin_principal()) {
            flash("Seul l'administrateur principal gère les comptes de l'équipe.", 'error');
        } elseif (in_array($cible['role'], ['entreprise','particulier'], true) && !peut_gerer_client($uid)) {
            flash(refus_gestion(), 'error');
        } else {
            db()->prepare("UPDATE users SET actif = 1 - actif WHERE id=?")->execute([$uid]);
            flash("Statut du compte mis à jour.");
        }
    }
    header('Location: ' . u('/admin-utilisateurs')); exit;
}

$r = $_GET['r'] ?? '';
$sql = "SELECT * FROM users"; $args = [];
if ($r) { $sql .= " WHERE role=?"; $args[] = $r; }
$sql .= " ORDER BY role, nom";
$st = db()->prepare($sql); $st->execute($args);
$users = $st->fetchAll();

$titre = "Utilisateurs";
require_once __DIR__ . '/includes/header.php';
?>
<div class="app">
<?php require __DIR__ . '/includes/sidebar.php'; ?>
<main class="main">

  <div class="page-head">
    <div><h1>Utilisateurs</h1><p><?= count($users) ?> compte<?= count($users)>1?'s':'' ?></p></div>
    <div class="flex gap-1 wrap">
      <a href="/admin-freelances" class="btn btn-ghost">🧑‍💻 Répertoire experts</a>
      <input type="search" class="input" style="max-width:260px" placeholder="🔍 Rechercher…" oninput="filterTable(this,'tbl')">
    </div>
  </div>

  <?php if ($f = flash()): ?><div class="alert alert-<?= e($f['t']) ?>"><?= e($f['m']) ?></div><?php endif; ?>

  <div class="flex gap-1 wrap mb-3">
    <a href="/admin-utilisateurs" class="btn <?= !$r?'btn-primary':'btn-ghost' ?> btn-sm">Tous</a>
    <a href="/admin-utilisateurs?r=entreprise" class="btn <?= $r==='entreprise'?'btn-primary':'btn-ghost' ?> btn-sm">Entreprises</a>
    <a href="/admin-utilisateurs?r=particulier" class="btn <?= $r==='particulier'?'btn-primary':'btn-ghost' ?> btn-sm">Particuliers</a>
    <a href="/admin-utilisateurs?r=freelance" class="btn <?= $r==='freelance'?'btn-primary':'btn-ghost' ?> btn-sm">Freelances</a>
    <a href="/admin-utilisateurs?r=admin" class="btn <?= $r==='admin'?'btn-primary':'btn-ghost' ?> btn-sm">Administration</a>
  </div>

  <div class="panel">
    <div class="table-wrap">
      <table id="tbl">
        <thead><tr><th>Utilisateur</th><th>Rôle</th><th>Détails</th><th>Note</th><th>Statut</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($users as $x): ?>
          <tr>
            <td>
              <div class="flex-center">
                <span class="avatar"><?= initiales($x) ?></span>
                <div><div class="t-title"><?= e(trim($x['prenom'].' '.$x['nom'])) ?></div><div class="t-sub"><?= e($x['email']) ?></div><?php if ($x['role']==='freelance' && $x['siret']): ?><div class="t-sub">SIRET <?= e($x['siret']) ?></div><?php endif; ?></div>
              </div>
            </td>
            <td><span class="badge <?= $x['role']==='admin'?'badge-violet':(est_client($x['role'])?'badge-blue':'badge-gray') ?>"><?= role_label($x['role']) ?></span></td>
            <td class="small">
              <?php if (est_client($x['role'])): ?>
                <?= e($x['societe'] ?: role_label($x['role'])) ?><br><span class="muted"><?= e(trim($x['taille'])) ?: '—' ?></span>
              <?php elseif ($x['role']==='freelance'): ?>
                <?= e($x['titre_pro']) ?><br><span class="muted">Tarif <?= euros($x['tarif_projet']) ?> · <?= (int)$x['experience'] ?> ans · <?= ucfirst($x['disponibilite']) ?></span>
              <?php else: ?><span class="muted">Équipe Missiona</span><?php endif; ?>
            </td>
            <td class="small">
              <?php if ($x['role']==='freelance'): ?>
                <span class="stars"><?= str_repeat('★',(int)round($x['note_moyenne'])) ?></span><br>
                <span class="muted"><?= number_format($x['note_moyenne'],1,',','') ?>/5 · <?= (int)$x['nb_missions'] ?> missions</span>
              <?php else: ?><span class="muted">—</span><?php endif; ?>
            </td>
            <td><span class="badge <?= $x['actif']?'badge-green':'badge-red' ?>"><?= $x['actif']?'Actif':'Suspendu' ?></span></td>
            <td>
              <div class="user-actions">
              <?php if ($x['role'] === 'freelance'): ?>
                <a href="/admin-freelance?id=<?= (int) $x['id'] ?>" class="btn btn-primary btn-sm">Voir la fiche</a>
              <?php endif; ?>
              <?php if ($x['id'] > 1): ?>
                <form method="post" style="margin:0"><?= csrf_field() ?>
                  <input type="hidden" name="uid" value="<?= $x['id'] ?>">
                  <input type="hidden" name="action" value="statut">
                  <button class="btn btn-ghost btn-sm"><?= $x['actif']?'Suspendre':'Réactiver' ?></button>
                </form>
              <?php endif; ?>
              <?php
                $peut_supprimer = (int) $x['id'] !== (int) $u['id'] && (
                    ($x['role'] === 'admin' && est_admin_principal())
                    || (est_client($x['role']) && peut_gerer_client($x['id']))
                    || ($x['role'] === 'freelance' && est_admin_principal())
                );
              ?>
              <?php if ($peut_supprimer): ?>
                <details class="user-delete">
                  <summary>Supprimer</summary>
                  <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="uid" value="<?= (int) $x['id'] ?>">
                    <input type="hidden" name="action" value="supprimer">
                    <label for="delete-<?= (int) $x['id'] ?>">Tapez <strong>SUPPRIMER</strong></label>
                    <input class="input" id="delete-<?= (int) $x['id'] ?>" name="confirmation" required autocomplete="off">
                    <button class="btn btn-danger btn-sm" type="submit">Effacer définitivement</button>
                  </form>
                </details>
              <?php endif; ?>
              </div>
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
