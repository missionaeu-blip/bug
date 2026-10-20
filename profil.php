<?php
require_once __DIR__ . '/includes/functions.php';
if (!is_logged()) { header('Location: ' . u('/connexion')); exit; }
$u = user();
$erreur_profil = '';

if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_check();
    if (isset($_POST['form_profil'])) {
        $competences = normaliser_competences_soumises($_POST['competences'] ?? '');
        if (!$competences['ok']) {
            http_response_code(422);
            $erreur_profil = 'La compétence « ' . $competences['invalide'] . ' » n’est plus proposée ou n’existe pas.';
        } else {
            db()->prepare("UPDATE users SET nom=?,prenom=?,telephone=?,societe=?,siret=?,secteur=?,taille=?,titre_pro=?,bio=?,competences=?,tarif_projet=?,experience=?,disponibilite=? WHERE id=?")
                ->execute([
                  trim($_POST['nom']), trim($_POST['prenom']), trim($_POST['telephone']),
                  trim($_POST['societe'] ?? ''), trim($_POST['siret'] ?? ''), trim($_POST['secteur'] ?? ''), trim($_POST['taille'] ?? ''),
                  trim($_POST['titre_pro'] ?? ''), trim($_POST['bio'] ?? ''), $competences['competences'],
                  (int)($_POST['tarif_projet'] ?? 0), (int)($_POST['experience'] ?? 0), $_POST['disponibilite'] ?? 'disponible',
                  $u['id']
                ]);
            flash("Profil mis à jour.");
        }
    }
    // Coordonnées de versement (freelance)
    // --- DAC7 : informations fiscales de l'expert
    if (isset($_POST['form_fiscal']) && $u['role'] === 'freelance') {
        $type = ($_POST['type_vendeur'] ?? 'physique') === 'entite' ? 'entite' : 'physique';
        $nif  = strtoupper(preg_replace('/\s+/', '', $_POST['nif'] ?? ''));
        $tva  = strtoupper(preg_replace('/\s+/', '', $_POST['tva'] ?? ''));
        $npay = strtoupper(trim($_POST['nif_pays'] ?? ''));
        $pays = strtoupper(trim($_POST['pays'] ?? ''));

        $erreurs = [];
        if (!array_key_exists($pays, pays_liste())) {
            $erreurs[] = 'Pays de résidence invalide.';
        }
        if ($nif !== '') {
            $v = dac7_verifier_nif($nif, $npay ?: $pays, $type);
            if (!$v['ok']) { $erreurs[] = 'Numéro fiscal : ' . $v['message']; }
        }
        if ($tva !== '') {
            $v = dac7_verifier_tva($tva);
            if (!$v['ok']) { $erreurs[] = 'TVA : ' . $v['message']; }
        }
        $dn = trim($_POST['date_naissance'] ?? '');
        if ($dn !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dn)) {
            $erreurs[] = 'Date de naissance invalide.';
        }
        if ($dn !== '' && strtotime($dn) > time()) {
            $erreurs[] = 'La date de naissance ne peut pas être dans le futur.';
        }

        if ($erreurs) {
            flash(implode(' ', $erreurs), 'error');
        } else {
            db()->prepare("UPDATE users SET type_vendeur=?, pays=?, adresse=?, code_postal=?, ville=?,
                           date_naissance=?, lieu_naissance=?, nif=?, nif_pays=?, tva=?,
                           raison_sociale=?, num_immat=? WHERE id=?")
                ->execute([
                    $type, $pays,
                    trim($_POST['adresse'] ?? ''),
                    trim($_POST['code_postal'] ?? ''),
                    trim($_POST['ville'] ?? ''),
                    $dn,
                    trim($_POST['lieu_naissance'] ?? ''),
                    $nif, $npay, $tva,
                    trim($_POST['raison_sociale'] ?? ''),
                    trim($_POST['num_immat'] ?? ''),
                    $u['id'],
                ]);
            dac7_journal($u['id'], dac7_annee_defaut(), 'collecte',
                'Informations fiscales renseignées par l\'expert.');
            flash('Informations fiscales enregistrées.', 'success');
        }
        header('Location: ' . u('/profil?onglet=fiscal'));
        exit;
    }

    // Stripe Connect Direct Charges : Stripe héberge l'onboarding, conserve
    // les coordonnées bancaires et donne à l'expert son tableau de bord complet.
    if (isset($_POST['form_connect']) && $u['role'] === 'freelance') {
        $lien = connect_lien_onboarding($u);
        if ($lien['ok']) {
            header('Location: ' . $lien['url'], true, 303);
            exit;
        }
        flash("Impossible d'ouvrir Stripe pour le moment : " . $lien['erreur'], 'error');
        header('Location: ' . u('/profil?onglet=versements'));
        exit;
    }
    if (isset($_POST['form_connect_dash']) && $u['role'] === 'freelance') {
        $lien = connect_lien_dashboard($u);
        if ($lien['ok']) { header('Location: ' . $lien['url'], true, 303); exit; }
        flash("Impossible d’ouvrir Stripe Dashboard : " . $lien['erreur'], 'error');
        header('Location: ' . u('/profil?onglet=versements'));
        exit;
    }

    // Ajout d'un forfait (freelance)
    if (isset($_POST['form_forfait']) && $u['role'] === 'freelance') {
        if (ajouter_forfait($u['id'], $_POST['f_nom'] ?? '', $_POST['f_prix'] ?? 0,
                            $_POST['f_delai'] ?? 0, $_POST['f_desc'] ?? '')) {
            flash("Forfait ajouté.");
        } else {
            flash("Indiquez au moins un nom et un prix supérieur à 0.", 'error');
        }
    }
    // Suppression d'un forfait
    if (isset($_POST['suppr_forfait']) && $u['role'] === 'freelance') {
        supprimer_forfait((int) $_POST['suppr_forfait'], $u['id']);
        flash("Forfait supprimé.");
    }
    // Ajout d'une réalisation au portfolio (freelance)
    if (isset($_POST['form_portfolio']) && $u['role'] === 'freelance') {
        $r = traiter_upload($_FILES['image'] ?? [], 'portfolio', ext_img_autorisees());
        if ($r['ok']) {
            db()->prepare("INSERT INTO portfolio (user_id,titre,description,image) VALUES (?,?,?,?)")
                ->execute([$u['id'], trim($_POST['pf_titre'] ?? ''), trim($_POST['pf_desc'] ?? ''), $r['chemin']]);
            flash("Réalisation ajoutée à votre portfolio.");
        } else {
            flash($r['error'], 'error');
        }
    }
    // Suppression d'une réalisation
    if (isset($_POST['suppr_pf']) && $u['role'] === 'freelance') {
        $q = db()->prepare("SELECT * FROM portfolio WHERE id=? AND user_id=?");
        $q->execute([(int)$_POST['suppr_pf'], $u['id']]);
        if ($pf = $q->fetch()) {
            @unlink(__DIR__.'/uploads/'.$pf['image']);
            db()->prepare("DELETE FROM portfolio WHERE id=?")->execute([$pf['id']]);
            flash("Réalisation supprimée.");
        }
    }
    if (isset($_POST['form_mdp'])) {
        if (!password_verify($_POST['ancien'] ?? '', $u['password_hash'])) { flash("Mot de passe actuel incorrect.", 'error'); }
        elseif (strlen($_POST['nouveau'] ?? '') < 6) { flash("Le nouveau mot de passe doit faire 6 caractères minimum.", 'error'); }
        elseif ($_POST['nouveau'] !== $_POST['nouveau2']) { flash("Les deux mots de passe ne correspondent pas.", 'error'); }
        else {
            db()->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([password_hash($_POST['nouveau'], PASSWORD_DEFAULT), $u['id']]);
            flash("Mot de passe modifié.");
        }
    }
    if ($erreur_profil === '') {
        header('Location: ' . u('/profil')); exit;
    }
}

$titre = "Mon profil";
require_once __DIR__ . '/includes/header.php';
?>
<div class="app">
<?php require __DIR__ . '/includes/sidebar.php'; ?>
<main class="main">

  <div class="page-head">
    <div class="profile-head">
      <span class="avatar lg"><?= initiales($u) ?></span>
      <div>
        <h1 style="font-size:1.5rem"><?= e(trim($u['prenom'].' '.$u['nom'])) ?></h1>
        <p><?= e(est_client($u['role']) ? ($u['societe'] ?: role_label($u['role'])) : ($u['role']==='freelance' ? $u['titre_pro'] : 'Équipe Missiona')) ?> · <?= e($u['email']) ?></p>
      </div>
    </div>
  </div>

  <?php if ($erreur_profil): ?><div class="alert alert-error"><?= e($erreur_profil) ?></div><?php endif; ?>
  <?php if ($f = flash()): ?><div class="alert alert-<?= e($f['t']) ?>"><?= e($f['m']) ?></div><?php endif; ?>

  <div data-tabs class="tabs">
    <div class="tab active" data-tab="p1">Informations</div>
    <?php if ($u['role']==='freelance'): ?>
      <div class="tab" data-tab="p5">Mes forfaits</div>
      <div class="tab" data-tab="p4">Portfolio</div>
      <?php /* L'onboarding Stripe de test ne fait circuler aucun fonds :
               l'expert doit pouvoir préparer son compte même en bêta. */ ?>
      <div class="tab" data-tab="p6">Versements</div>
      <?php if (!beta_actif() || beta_exempt() || connect_actif()): ?>
        <div class="tab" data-tab="p7">Informations fiscales</div>
      <?php endif; ?>
    <?php endif; ?>
    <div class="tab" data-tab="p2">Sécurité</div>
    <div class="tab" data-tab="p3">Confidentialité / RGPD</div>
  </div>

  <div data-panel="p1" class="tab-panel active">
    <form method="post" style="max-width:720px">
      <?= csrf_field() ?><input type="hidden" name="form_profil" value="1">
      <div class="panel">
        <div class="panel-body">
          <div class="field-row">
            <div class="field"><label for="prenom">Prénom</label><input type="text" id="prenom" name="prenom" class="input" value="<?= e($u['prenom']) ?>"></div>
            <div class="field"><label for="nom">Nom</label><input type="text" id="nom" name="nom" class="input" value="<?= e($u['nom']) ?>"></div>
          </div>
          <div class="field"><label for="telephone">Téléphone</label><input type="tel" id="telephone" name="telephone" class="input" value="<?= e($u['telephone']) ?>"></div>

          <?php if (est_client($u['role'])): ?>
            <div class="field"><label for="societe">Raison sociale</label><input type="text" id="societe" name="societe" class="input" value="<?= e($u['societe']) ?>"></div>
            <div class="field-row">
              <div class="field"><label for="siret">SIRET</label><input type="text" id="siret" name="siret" class="input" value="<?= e($u['siret']) ?>"></div>
              <div class="field"><label for="secteur">Secteur</label><input type="text" id="secteur" name="secteur" class="input" value="<?= e($u['secteur']) ?>"></div>
            </div>
            <div class="field"><label for="taille">Effectif</label><input type="text" id="taille" name="taille" class="input" value="<?= e($u['taille']) ?>"></div>
            <div class="field"><label for="bio">Présentation de l'entreprise</label><textarea id="bio" name="bio" class="textarea"><?= e($u['bio']) ?></textarea></div>
          <?php elseif ($u['role']==='freelance'): ?>
            <div class="field"><label for="titre_pro">Titre professionnel</label><input type="text" id="titre_pro" name="titre_pro" class="input" value="<?= e($u['titre_pro']) ?>"></div>
            <div class="field"><label for="bio">Présentation</label><textarea id="bio" name="bio" class="textarea"><?= e($u['bio']) ?></textarea></div>
            <div class="field">
              <label>Vos compétences</label>
              <?php champ_competences($u['competences']); ?>
              <div class="hint">Elles alimentent directement votre score de matching.</div>
            </div>
            <div class="field-row">
              <div class="field"><label for="tarif_projet">Tarif de base par projet (€)</label><input type="number" id="tarif_projet" name="tarif_projet" class="input" min="0" step="1" value="<?= (int)$u['tarif_projet'] ?>"><div class="hint">Utilisé si vous ne proposez aucun forfait. Voir l'onglet « Mes forfaits ».</div></div>
              <div class="field"><label for="experience">Années d'expérience</label><input type="number" id="experience" name="experience" class="input" value="<?= (int)$u['experience'] ?>"></div>
            </div>
            <div class="field">
              <label for="disponibilite">Disponibilité</label>
              <select id="disponibilite" name="disponibilite" class="select">
                <?php foreach (['disponible'=>'Disponible immédiatement','partiel'=>'Partiellement disponible','occupe'=>'Actuellement en mission'] as $k=>$v): ?>
                  <option value="<?= $k ?>" <?= $u['disponibilite']===$k?'selected':'' ?>><?= $v ?></option>
                <?php endforeach; ?>
              </select>
              <div class="hint">Ce paramètre influence directement votre score de matching.</div>
            </div>
          <?php endif; ?>

          <button type="submit" class="btn btn-primary">Enregistrer</button>
        </div>
      </div>
    </form>
  </div>

  <?php if ($u['role']==='freelance'): $pfs = portfolio_de($u['id']); ?>
  <?php if ($u['role']==='freelance'): $mes_forfaits = forfaits_de($u['id']); ?>
  <div data-panel="p5" class="tab-panel">
    <div class="panel mb-3" style="max-width:860px">
      <div class="panel-head"><h3>Proposer plusieurs formules</h3></div>
      <div class="panel-body">
        <p class="small muted mb-2">
          Plutôt qu'un tarif unique, proposez plusieurs niveaux de prestation.
          Notre équipe s'appuie dessus pour bâtir la proposition envoyée au client.
          Indiquez le montant que <strong>vous percevez</strong> : notre part est ajoutée ensuite.
        </p>

        <?php if ($mes_forfaits): ?>
          <table class="table mb-3">
            <thead><tr><th>Formule</th><th>Délai</th><th>Prix</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($mes_forfaits as $fo): ?>
              <tr>
                <td>
                  <strong><?= e($fo['nom']) ?></strong>
                  <?php if ($fo['description']): ?><br><span class="small muted"><?= e($fo['description']) ?></span><?php endif; ?>
                </td>
                <td><?= $fo['delai'] ? (int)$fo['delai'].' j' : '—' ?></td>
                <td><strong><?= euros($fo['prix']) ?></strong></td>
                <td style="text-align:right">
                  <form method="post" onsubmit="return confirm('Supprimer ce forfait ?')">
                    <?= csrf_field() ?>
                    <button class="btn btn-ghost btn-sm" name="suppr_forfait" value="<?= (int)$fo['id'] ?>">Supprimer</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php else: ?>
          <div class="alert-info mb-3">
            Vous n'avez pas encore de forfait. Sans forfait, c'est votre
            « tarif par projet » de l'onglet Informations qui est utilisé.
          </div>
        <?php endif; ?>

        <hr class="sep">
        <h4 class="mb-2">Ajouter un forfait</h4>
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="form_forfait" value="1">
          <div class="field-row">
            <div class="field">
              <label for="f_nom">Nom de la formule</label>
              <input type="text" id="f_nom" name="f_nom" class="input" maxlength="60" placeholder="Ex. Essentiel" required>
            </div>
            <div class="field">
              <label for="f_prix">Prix (€)</label>
              <input type="number" id="f_prix" name="f_prix" class="input" min="1" step="1" placeholder="Ex. 800" required>
            </div>
            <div class="field">
              <label for="f_delai">Délai (jours)</label>
              <input type="number" id="f_delai" name="f_delai" class="input" min="0" placeholder="Ex. 14">
            </div>
          </div>
          <div class="field">
            <label for="f_desc">Ce que comprend la formule</label>
            <textarea id="f_desc" name="f_desc" class="input" rows="3" placeholder="Ex. Maquette, intégration de 5 pages, mise en ligne."></textarea>
          </div>
          <button class="btn btn-primary">Ajouter ce forfait</button>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div data-panel="p4" class="tab-panel">
    <div class="panel mb-3" style="max-width:860px">
      <div class="panel-head">
        <h3>Ajouter une réalisation</h3>
        <span class="badge badge-gray"><?= count($pfs) ?> visuel<?= count($pfs)>1?'s':'' ?></span>
      </div>
      <div class="panel-body">
        <form method="post" enctype="multipart/form-data">
          <?= csrf_field() ?><input type="hidden" name="form_portfolio" value="1">
          <div class="field-row">
            <div class="field">
              <label for="pf_titre">Titre de la réalisation</label>
              <input type="text" id="pf_titre" name="pf_titre" class="input" placeholder="Ex. Refonte e-commerce Maison Duval">
            </div>
            <div class="field">
              <label for="pf_desc">Courte description</label>
              <input type="text" id="pf_desc" name="pf_desc" class="input" placeholder="Ex. Site sur mesure, +38 % de conversion">
            </div>
          </div>
          <div class="field">
            <label for="pfImage">Visuel *</label>
            <input type="file" name="image" id="pfImage" accept="image/jpeg,image/png,image/gif,image/webp" required
                   class="input" onchange="previewImg(this,'pfPreview')">
            <div class="hint">JPG, PNG, GIF ou WEBP — 8 Mo maximum.</div>
            <img id="pfPreview" class="pf-preview" alt="Aperçu de votre photo de profil">
          </div>
          <button type="submit" class="btn btn-primary">Ajouter au portfolio</button>
        </form>
      </div>
    </div>

    <div class="panel" style="max-width:860px">
      <div class="panel-head"><h3>Mes réalisations</h3></div>
      <?php if (!$pfs): ?>
        <div class="empty" style="padding:40px">
          <div class="ico">🖼️</div>
          <h3>Votre portfolio est vide</h3>
          <p>Ajoutez des visuels de vos projets : ils sont transmis aux clients lors de nos recommandations.</p>
        </div>
      <?php else: ?>
        <div class="panel-body">
          <div class="pf-grid">
            <?php foreach ($pfs as $pf): ?>
              <figure class="pf-card">
                <img src="<?= e(portfolio_image_url($pf['id'])) ?>" alt="<?= e($pf['titre']) ?>" loading="lazy">
                <figcaption>
                  <strong><?= e($pf['titre'] ?: 'Sans titre') ?></strong>
                  <?php if ($pf['description']): ?><span><?= e($pf['description']) ?></span><?php endif; ?>
                  <form method="post" onsubmit="return confirm('Supprimer cette réalisation ?')">
                    <?= csrf_field() ?>
                    <button name="suppr_pf" value="<?= $pf['id'] ?>" class="btn btn-ghost btn-sm">🗑 Supprimer</button>
                  </form>
                </figcaption>
              </figure>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($u['role']==='freelance'): ?>
  <div data-panel="p6" class="tab-panel">
    <div class="panel" style="max-width:720px">
      <div class="panel-head">
        <h3>Paiements par carte</h3>
        <span class="badge <?= connect_etat_badge($u) ?>"><?= e(connect_etat_label($u)) ?></span>
      </div>
      <div class="panel-body">
        <?php if (connect_actif()): ?>
          <div class="alert alert-info" style="margin:0 0 14px">
            💳 Vos clients règlent par carte via Stripe. Le paiement est créé directement
            sur <strong>votre compte Stripe</strong> ; Missiona ne conserve aucune coordonnée bancaire.
          </div>
          <?php if (empty($u['stripe_account']) && !connect_pays_ok($u['pays'] ?? '')): ?>
            <div class="alert alert-warn" style="margin:0 0 14px">
              Renseignez d'abord votre pays de résidence dans les <a href="<?= u('/profil?onglet=fiscal') ?>">informations fiscales</a>.
              Stripe en a besoin pour créer correctement votre compte connecté.
            </div>
          <?php elseif (empty($u['stripe_account'])): ?>
            <p class="small muted mb-3">Reliez votre compte pour recevoir les paiements et accéder au tableau de bord Stripe complet.</p>
            <form method="post" class="stripe-connect-form"><!-- Account Link Stripe, à usage unique -->
              <?= csrf_field() ?><input type="hidden" name="form_connect" value="1">
              <button type="submit" class="btn btn-primary" data-stripe-connect-button>Configurer mes paiements avec Stripe</button>
            </form>
          <?php elseif (empty($u['stripe_charges_ok'])): ?>
            <p class="small muted mb-3">Votre compte est créé. Stripe doit encore vérifier certaines informations avant d'autoriser les paiements par carte.</p>
            <div class="flex gap-1 wrap">
              <form method="post" class="stripe-connect-form">
                <?= csrf_field() ?><input type="hidden" name="form_connect" value="1">
                <button type="submit" class="btn btn-primary" data-stripe-connect-button>Reprendre la configuration Stripe</button>
              </form>
              <a href="https://dashboard.stripe.com/test/dashboard" target="_blank" rel="noopener" class="btn btn-ghost">Ouvrir Stripe Dashboard (test)</a>
            </div>
          <?php else: ?>
            <p class="small muted mb-2">Votre compte est prêt à recevoir les paiements par carte. Les virements, justificatifs et réglages restent gérés dans Stripe.</p>
            <p class="small muted mb-3">Le tableau de bord s’ouvre en mode test : connectez-vous avec l’adresse utilisée pendant l’onboarding Stripe.</p>
            <a href="https://dashboard.stripe.com/test/dashboard" target="_blank" rel="noopener" class="btn btn-primary">Ouvrir Stripe Dashboard (test)</a>
          <?php endif; ?>
          <p class="small muted mb-0" style="margin-top:14px">
            Stripe applique ses propres frais de traitement sur votre compte connecté. La commission Missiona est prélevée automatiquement comme frais d'application, selon le taux convenu.
          </p>
        <?php else: ?>
          <div class="alert alert-info" style="margin:0 0 14px">
            🤝 <strong>Paiement direct :</strong> chaque client vous règle directement le montant
            de la prestation, selon les modalités que vous convenez avec lui (virement bancaire, etc.).
          </div>
          <p class="small muted mb-0">Le module de paiement par carte Stripe est désactivé tant que ses clés de test et son webhook ne sont pas configurés.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>


  <div data-panel="p7" class="tab-panel">
    <?php
      $manquants = dac7_champs_manquants($u);
      $entite    = (($u['type_vendeur'] ?? 'physique') === 'entite');
      $an_fisc   = dac7_annee_defaut();
      $don       = dac7_donnees_vendeur($u['id'], $an_fisc);
    ?>
    <div class="panel mb-3" style="max-width:820px">
      <div class="panel-head">
        <h3>Informations fiscales</h3>
        <?php if (!$manquants): ?>
          <span class="badge badge-green">Dossier complet</span>
        <?php else: ?>
          <span class="badge badge-amber"><?= count($manquants) ?> information(s) manquante(s)</span>
        <?php endif; ?>
      </div>
      <div class="panel-body">

        <p class="small muted mb-3">
          En tant que plateforme européenne, <?= e(SITE_NOM) ?> est tenue de déclarer
          chaque année à l'administration fiscale les revenus que vous percevez ici
          (directive européenne <strong>DAC7</strong>, articles 1649 ter A et suivants
          du code général des impôts). Ces informations sont obligatoires et
          <strong>ne servent qu'à cette déclaration</strong>.
        </p>

        <?php if ($manquants): ?>
          <div class="alert alert-warn mb-3">
            Merci de compléter : <strong><?= e(implode(', ', $manquants)) ?></strong>.
            Sans ces éléments, nous ne pouvons pas remplir notre obligation légale
            et l'attribution de nouvelles missions pourra être suspendue.
          </div>
        <?php endif; ?>

        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="form_fiscal" value="1">

          <div class="field mb-3">
            <label for="type_vendeur">Vous déclarez en tant que</label>
            <select name="type_vendeur" id="type_vendeur" class="select"
                    style="max-width:340px" onchange="wcFiscal()">
              <option value="physique" <?= !$entite ? 'selected' : '' ?>>Personne physique (indépendant)</option>
              <option value="entite"   <?= $entite ? 'selected' : '' ?>>Société</option>
            </select>
          </div>

          <div id="wc-physique" class="<?= $entite ? 'hidden' : '' ?>">
            <div class="field-row">
              <div class="field">
                <label for="date_naissance">Date de naissance</label>
                <input type="date" name="date_naissance" id="date_naissance" class="input"
                       value="<?= e($u['date_naissance']) ?>">
              </div>
              <div class="field">
                <label for="lieu_naissance">Ville de naissance</label>
                <input type="text" name="lieu_naissance" id="lieu_naissance" class="input"
                       value="<?= e($u['lieu_naissance']) ?>">
                <div class="hint">Requise si votre pays ne délivre pas de numéro fiscal.</div>
              </div>
            </div>
          </div>

          <div id="wc-entite" class="<?= $entite ? '' : 'hidden' ?>">
            <div class="field-row">
              <div class="field">
                <label for="raison_sociale">Dénomination sociale</label>
                <input type="text" name="raison_sociale" id="raison_sociale" class="input"
                       value="<?= e($u['raison_sociale']) ?>">
              </div>
              <div class="field">
                <label for="num_immat">Numéro d'immatriculation</label>
                <input type="text" name="num_immat" id="num_immat" class="input"
                       value="<?= e($u['num_immat']) ?>" placeholder="SIREN, RCS…">
              </div>
            </div>
          </div>

          <div class="sep"></div>

          <div class="field mb-3">
            <label for="pays">Pays de résidence</label>
            <select name="pays" id="pays" class="select" required>
              <option value="">Sélectionnez votre pays</option>
              <?php foreach (pays_liste() as $code => $nom): ?>
                <option value="<?= $code ?>" <?= ($u['pays'] ?? '') === $code ? 'selected' : '' ?>><?= e($nom) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="hint">Utilisé pour les obligations fiscales et la création du compte Stripe Connect.</div>
          </div>

          <div class="field mb-3">
            <label for="adresse">Adresse</label>
            <input type="text" name="adresse" id="adresse" class="input"
                   value="<?= e($u['adresse']) ?>" placeholder="Numéro et rue">
          </div>
          <div class="field-row">
            <div class="field">
              <label for="code_postal">Code postal</label>
              <input type="text" name="code_postal" id="code_postal" class="input"
                     value="<?= e($u['code_postal']) ?>">
            </div>
            <div class="field">
              <label for="ville">Ville</label>
              <input type="text" name="ville" id="ville" class="input"
                     value="<?= e($u['ville']) ?>">
            </div>
          </div>

          <div class="sep"></div>

          <div class="field-row">
            <div class="field">
              <label for="nif">Numéro fiscal (NIF)</label>
              <input type="text" name="nif" id="nif" class="input" value="<?= e($u['nif']) ?>">
              <div class="hint">
                En France : 13 chiffres pour un particulier (avis d'imposition),
                SIRET à 14 chiffres pour une société.
              </div>
            </div>
            <div class="field">
              <label for="nif_pays">Pays de délivrance</label>
              <select name="nif_pays" id="nif_pays" class="select">
                <option value="">Identique au pays de résidence</option>
                <?php foreach (pays_liste() as $code => $nom): ?>
                  <option value="<?= $code ?>" <?= $u['nif_pays'] === $code ? 'selected' : '' ?>>
                    <?= e($nom) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="field mb-3">
            <label for="tva">Numéro de TVA intracommunautaire</label>
            <input type="text" name="tva" id="tva" class="input" style="max-width:340px"
                   value="<?= e($u['tva']) ?>" placeholder="FR12345678901">
            <div class="hint">Facultatif, si vous en avez un.</div>
          </div>

          <button class="btn btn-primary" type="submit">Enregistrer</button>
        </form>
      </div>
    </div>

    <div class="panel" style="max-width:820px">
      <div class="panel-head"><h3>Ce qui sera déclaré pour <?= (int) $an_fisc ?></h3></div>
      <div class="panel-body">
        <p class="small muted mb-3">
          Montants qui vous ont été crédités, nets de notre commission.
          Vous recevrez ce récapitulatif avant le <?= e(dac7_echeance($an_fisc)) ?>.
        </p>
        <table class="table">
          <thead><tr><th>Trimestre</th><th>Contrepartie</th><th>Missions</th><th>Commissions</th></tr></thead>
          <tbody>
            <?php foreach ([1,2,3,4] as $q): $tr = $don['trimestres'][$q]; ?>
              <tr>
                <td>T<?= $q ?></td>
                <td><?= euros($tr['contrepartie']) ?></td>
                <td><?= (int) $tr['activites'] ?></td>
                <td><?= euros($tr['frais']) ?></td>
              </tr>
            <?php endforeach; ?>
            <tr>
              <td><strong>Total</strong></td>
              <td><strong><?= euros($don['total_contrepartie']) ?></strong></td>
              <td><strong><?= (int) $don['total_activites'] ?></strong></td>
              <td><strong><?= euros($don['total_frais']) ?></strong></td>
            </tr>
          </tbody>
        </table>
        <p class="small muted mb-0">
          Ce tableau est une information : il ne remplace ni votre comptabilité,
          ni votre déclaration de revenus.
        </p>
      </div>
    </div>

    <script>
    function wcFiscal(){
      var s = document.getElementById('type_vendeur');
      if (!s) return;
      var ent = (s.value === 'entite');
      document.getElementById('wc-entite').classList.toggle('hidden', !ent);
      document.getElementById('wc-physique').classList.toggle('hidden', ent);
    }
    document.addEventListener('DOMContentLoaded', wcFiscal);
    </script>
  </div>
  <?php endif; ?>

  <div data-panel="p2" class="tab-panel">
    <form method="post" style="max-width:520px">
      <?= csrf_field() ?><input type="hidden" name="form_mdp" value="1">
      <div class="panel"><div class="panel-body">
        <div class="field"><label for="ancien">Mot de passe actuel</label><input type="password" id="ancien" name="ancien" class="input" required></div>
        <div class="field"><label for="nouveau">Nouveau mot de passe</label><input type="password" id="nouveau" name="nouveau" class="input" required minlength="6"></div>
        <div class="field"><label for="nouveau2">Confirmation</label><input type="password" id="nouveau2" name="nouveau2" class="input" required minlength="6"></div>
        <button type="submit" class="btn btn-primary">Modifier le mot de passe</button>
      </div></div>
    </form>
  </div>

  <div data-panel="p3" class="tab-panel">
    <div class="panel" style="max-width:720px"><div class="panel-body">
      <h3 class="mb-2">Vos données personnelles</h3>
      <p class="small mb-3">
        Conformément au RGPD, vos données sont traitées uniquement dans le cadre de la mise en relation
        et du suivi de vos projets. Elles ne sont ni revendues ni transmises à des tiers non impliqués.
      </p>
      <div class="recap mb-3">
        <div class="recap-row"><span>Données collectées</span><strong>Identité, contact, profil professionnel</strong></div>
        <div class="recap-row"><span>Finalité</span><strong>Mise en relation et suivi de projet</strong></div>
        <div class="recap-row"><span>Durée de conservation</span><strong>3 ans après le dernier contact</strong></div>
        <div class="recap-row"><span>Visibilité inter-utilisateurs</span><strong>Aucune donnée privée exposée</strong></div>
      </div>
      <div class="alert alert-info">
        🔒 Les entreprises ne voient jamais les coordonnées des freelances, et inversement.
        Tous les échanges transitent par votre chargé de compte Missiona.
      </div>
      <div class="flex gap-1 wrap">
        <a href="/politique-confidentialite" class="btn btn-ghost btn-sm">Politique de confidentialité</a>
        <a href="/messages" class="btn btn-ghost btn-sm">Demander l'export de mes données</a>
        <a href="/messages" class="btn btn-ghost btn-sm">Demander la suppression du compte</a>
      </div>
    </div></div>
  </div>

</main>
</div>

<script>
(function () {
  document.querySelectorAll('.stripe-connect-form').forEach(function (form) {
    form.addEventListener('submit', function () {
      var button = form.querySelector('[data-stripe-connect-button]');
      if (!button || button.disabled) { return; }
      button.disabled = true;
      button.classList.add('is-opening-stripe');
      button.textContent = 'Ouverture sécurisée de Stripe…';
    });
  });
}());
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
