<?php
require_once __DIR__ . '/includes/functions.php';
require_admin_principal();   // réservé à l'administrateur principal
$u = user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Identité du chargé de compte vue par les clients
    if (isset($_POST['form_charge'])) {
        set_param('charge_nom',  mb_substr(trim($_POST['charge_nom'] ?? ''), 0, 60));
        set_param('charge_role', mb_substr(trim($_POST['charge_role'] ?? ''), 0, 60));
        flash("Identité du chargé de compte mise à jour.");
        header('Location: ' . u('/admin-equipe')); exit;
    }

    // Création d'un administrateur
    if (isset($_POST['form_admin'])) {
        $r = creer_admin($_POST['ad_email'] ?? '', $_POST['ad_mdp'] ?? '',
                         $_POST['ad_nom'] ?? '', $_POST['ad_prenom'] ?? '');
        flash($r['ok'] ? "Compte administrateur créé." : $r['erreur'], $r['ok'] ? 'success' : 'error');
        header('Location: ' . u('/admin-equipe')); exit;
    }

    // Assignation d'un client à un gestionnaire
    if (isset($_POST['form_assign'])) {
        $ok = assigner_gestionnaire((int)($_POST['client_id'] ?? 0), (int)($_POST['gestionnaire_id'] ?? 0));
        flash($ok ? "Assignation enregistrée." : "Assignation impossible.", $ok ? 'success' : 'error');
        header('Location: ' . u('/admin-equipe')); exit;
    }

    // Réglages + actions de répartition automatique
    if (isset($_POST['form_repartition'])) {
        set_param('repartition_auto', isset($_POST['repartition_auto']) ? 1 : 0);
        set_param('repartition_inclut_principal', isset($_POST['inclut_principal']) ? 1 : 0);
        flash("Réglages de répartition enregistrés.");
        header('Location: ' . u('/admin-equipe')); exit;
    }
    if (isset($_POST['form_repartir'])) {
        $tout = ($_POST['portee'] ?? 'nouveaux') === 'tout';
        $n = repartir_clients($tout);
        flash($n ? "$n client(s) répartis entre les gestionnaires." : "Aucun client à répartir.", $n ? 'success' : 'error');
        header('Location: ' . u('/admin-equipe')); exit;
    }

    // Suppression d'un administrateur
    if (isset($_POST['suppr_admin'])) {
        $r = supprimer_admin((int) $_POST['suppr_admin'], $u['id']);
        flash($r['ok'] ? "Compte supprimé." : $r['erreur'], $r['ok'] ? 'success' : 'error');
        header('Location: ' . u('/admin-equipe')); exit;
    }
}

$liste   = admins();
$clients = db()->query("SELECT * FROM users WHERE role IN ('entreprise','particulier') ORDER BY societe, nom")->fetchAll();
$princ   = admin_principal();
$titre   = "Équipe";
require_once __DIR__ . '/includes/header.php';
?>
<div class="app">
<?php require __DIR__ . '/includes/sidebar.php'; ?>
<main class="main">

  <div class="page-head">
    <div>
      <h1>Équipe Missiona</h1>
      <p>Comptes administrateurs et identité affichée aux clients.</p>
    </div>
  </div>

  <?php if ($f = flash()): ?><div class="alert alert-<?= e($f['t']) ?>"><?= e($f['m']) ?></div><?php endif; ?>

  <!-- Chargé de compte -->
  <div class="panel mb-3" style="max-width:720px">
    <div class="panel-head"><h3>Chargé de compte</h3></div>
    <div class="panel-body">
      <p class="small muted mb-2">
        Ce nom apparaît aux entreprises et aux freelances dans la messagerie
        et sur leur tableau de bord. Il représente votre équipe, pas un compte
        de connexion.
      </p>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="form_charge" value="1">
        <div class="field-row">
          <div class="field">
            <label for="charge_nom">Nom affiché</label>
            <input type="text" id="charge_nom" name="charge_nom" class="input" maxlength="60"
                   value="<?= e(charge_compte_nom()) ?>" required>
          </div>
          <div class="field">
            <label for="charge_role">Intitulé</label>
            <input type="text" id="charge_role" name="charge_role" class="input" maxlength="60"
                   value="<?= e(charge_compte_role()) ?>" required>
          </div>
        </div>
        <button class="btn btn-primary">Enregistrer</button>
      </form>
    </div>
  </div>

  <!-- Administrateurs -->
  <div class="panel mb-3" style="max-width:720px">
    <div class="panel-head"><h3>Administrateurs</h3><span class="badge badge-gray"><?= count($liste) ?></span></div>
    <div class="panel-body">
      <table class="table">
        <thead><tr><th>Nom</th><th>E-mail</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($liste as $a): ?>
          <tr>
            <td>
              <strong><?= e(trim($a['prenom'] . ' ' . $a['nom'])) ?></strong>
              <?php if ($princ && $a['id'] == $princ['id']): ?><span class="badge badge-blue" style="margin-left:6px">Principal</span><?php endif; ?>
              <?php if ($a['id'] == $u['id']): ?><span class="badge badge-green" style="margin-left:6px">Vous</span><?php endif; ?>
              <?php $nb = count(clients_du_gestionnaire($a['id'])); ?>
              <?php if ($nb): ?><br><span class="small muted"><?= $nb ?> client<?= $nb>1?'s':'' ?> suivi<?= $nb>1?'s':'' ?></span><?php endif; ?>
            </td>
            <td class="small muted"><?= e($a['email']) ?></td>
            <td style="text-align:right">
              <?php if ($a['id'] != $u['id'] && !($princ && $a['id'] == $princ['id'])): ?>
                <form method="post" onsubmit="return confirm('Supprimer définitivement ce compte administrateur ?')">
                  <?= csrf_field() ?>
                  <button class="btn btn-ghost btn-sm" name="suppr_admin" value="<?= (int)$a['id'] ?>">Supprimer</button>
                </form>
              <?php else: ?>
                <span class="small muted">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Répartition automatique -->
  <div class="panel mb-3" style="max-width:720px">
    <div class="panel-head"><h3>Répartition automatique</h3><span class="badge badge-blue">Équilibrage</span></div>
    <div class="panel-body">
      <p class="small muted mb-2">
        Chaque nouveau client est confié automatiquement au gestionnaire qui suit
        le moins de projets en cours, afin d'équilibrer la charge de l'équipe.
        Vous pouvez à tout moment corriger une attribution à la main ci-dessous.
      </p>

      <?php $charge = charge_gestionnaires(); $maxc = max(1, max($charge ?: [1])); ?>
      <table class="table mb-2">
        <thead><tr><th>Gestionnaire</th><th>Clients</th><th>Projets en cours</th><th style="width:180px">Charge</th></tr></thead>
        <tbody>
        <?php foreach (gestionnaires_repartition() as $g):
              $gid = (int) $g['id'];
              $nb  = (int) ($charge[$gid] ?? 0);
              $st  = db()->prepare("SELECT COUNT(*) FROM users WHERE gestionnaire_id=?"); $st->execute([$gid]);
              $nc  = (int) $st->fetchColumn(); ?>
          <tr>
            <td><?= e(trim($g['prenom'] . ' ' . $g['nom'])) ?>
              <?= est_admin_principal($g) ? '<span class="badge badge-gray">Principal</span>' : '' ?></td>
            <td><?= $nc ?></td>
            <td><strong><?= $nb ?></strong></td>
            <td><div class="bar"><span style="width:<?= (int) round($nb * 100 / $maxc) ?>%"></span></div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <form method="post" class="mb-2">
        <?= csrf_field() ?><input type="hidden" name="form_repartition" value="1">
        <label class="flex-gap" style="align-items:center;margin-bottom:8px">
          <input type="checkbox" name="repartition_auto" value="1" <?= (int) param('repartition_auto', 1) ? 'checked' : '' ?>>
          <span>Attribuer automatiquement les nouveaux clients</span>
        </label>
        <label class="flex-gap" style="align-items:center;margin-bottom:8px">
          <input type="checkbox" name="inclut_principal" value="1" <?= (int) param('repartition_inclut_principal', 1) ? 'checked' : '' ?>>
          <span>Inclure l'administrateur principal dans la répartition</span>
        </label>
        <button type="submit" class="btn btn-ghost btn-sm">Enregistrer les réglages</button>
      </form>

      <hr class="sep">
      <form method="post" class="flex-gap" style="align-items:flex-end;flex-wrap:wrap">
        <?= csrf_field() ?><input type="hidden" name="form_repartir" value="1">
        <div class="field" style="margin:0">
          <label for="portee">Rééquilibrer maintenant</label>
          <select name="portee" id="portee" class="select">
            <option value="nouveaux">Seulement les clients non assignés</option>
            <option value="tout">Tous les clients (redistribution complète)</option>
          </select>
        </div>
        <button type="submit" class="btn btn-primary">Lancer la répartition</button>
      </form>
      <p class="small muted" style="margin-top:8px">
        La redistribution complète réattribue aussi les clients déjà suivis :
        les portefeuilles constitués à la main seront écrasés.
      </p>
    </div>
  </div>

  <!-- Assignation des clients -->
  <div class="panel mb-3" style="max-width:720px">
    <div class="panel-head"><h3>Attribution manuelle</h3></div>
    <div class="panel-body">
      <p class="small muted mb-2">
        Assignez chaque entreprise à un membre de l'équipe. Le gestionnaire
        devient son interlocuteur : son nom s'affiche dans la messagerie et
        sur le tableau de bord du client.
      </p>
      <?php if (!$clients): ?>
        <div class="alert-info">Aucune entreprise inscrite pour le moment.</div>
      <?php else: ?>
        <table class="table">
          <thead><tr><th>Client</th><th>Gestionnaire</th></tr></thead>
          <tbody>
          <?php foreach ($clients as $c): ?>
            <tr>
              <td>
                <strong><?= e($c['societe'] ?: trim($c['prenom'].' '.$c['nom'])) ?></strong>
                <br><span class="small muted"><?= e($c['email']) ?></span>
              </td>
              <td>
                <form method="post" class="flex-gap">
                  <?= csrf_field() ?>
                  <input type="hidden" name="form_assign" value="1">
                  <input type="hidden" name="client_id" value="<?= (int)$c['id'] ?>">
                  <select name="gestionnaire_id" class="select" onchange="this.form.submit()">
                    <option value="0">— Équipe Missiona —</option>
                    <?php foreach ($liste as $a): ?>
                      <option value="<?= (int)$a['id'] ?>" <?= (int)$c['gestionnaire_id']===(int)$a['id']?'selected':'' ?>>
                        <?= e(trim($a['prenom'].' '.$a['nom'])) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <noscript><button class="btn btn-ghost btn-sm">OK</button></noscript>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>

  <!-- Nouveau compte -->
  <div class="panel" style="max-width:720px">
    <div class="panel-head"><h3>Créer un administrateur</h3></div>
    <div class="panel-body">
      <div class="alert-warn mb-3">
        Un administrateur a accès à l'ensemble de la plateforme : projets,
        utilisateurs, paiements et paramètres. Ne créez ces comptes que pour
        les membres de votre équipe.
      </div>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="form_admin" value="1">
        <div class="field-row">
          <div class="field">
            <label for="ad_prenom">Prénom</label>
            <input type="text" id="ad_prenom" name="ad_prenom" class="input" maxlength="60">
          </div>
          <div class="field">
            <label for="ad_nom">Nom</label>
            <input type="text" id="ad_nom" name="ad_nom" class="input" maxlength="60" required>
          </div>
        </div>
        <div class="field">
          <label for="ad_email">Adresse e-mail</label>
          <input type="email" id="ad_email" name="ad_email" class="input" required>
        </div>
        <div class="field">
          <label for="ad_mdp">Mot de passe</label>
          <input type="password" id="ad_mdp" name="ad_mdp" class="input" minlength="8" required>
          <div class="hint">8 caractères minimum. Communiquez-le à la personne concernée, elle pourra le changer depuis son profil.</div>
        </div>
        <button class="btn btn-primary">Créer le compte</button>
      </form>
    </div>
  </div>

</main>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
