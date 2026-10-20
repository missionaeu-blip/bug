<?php
require_once __DIR__ . '/includes/functions.php';

$u = user();
$est_client_connecte = $u && est_client($u['role']);
if ($u && !$est_client_connecte) {
    flash("Seuls les comptes entreprise et particulier peuvent déposer un projet.", 'error');
    header('Location: ' . u(dashboard_url($u['role'], true))); exit;
}

$erreur = '';
$domaine_code = domaine_landing_code($_GET['domaine'] ?? '');
$categorie_initiale = domaine_landing_valeur($domaine_code);
$budget_plafond = max(1000, (int) BUDGET_PLAFOND);
$budget_defaut_min = (int) round($budget_plafond * .02);
$budget_defaut_max = (int) round($budget_plafond * .10);
$delais = ['', 'Moins de 1 mois', '1 mois', '2 mois', '3 mois', '4 mois', '6 mois', 'Plus de 6 mois', 'Récurrent'];
$icones_domaines = [
    'Développement web' => '💻', 'Intelligence artificielle' => '🤖',
    'Design graphique & identité' => '🎨', 'Marketing digital' => '📈',
    'Vidéo & animation' => '🎬', 'Data & analyse' => '📊',
    'Finance & pilotage' => '💶', 'Ingénierie & architecture' => '🏗️',
    'Assistance & administration' => '🗂️', 'Conseil & stratégie' => '🧭',
    'Infrastructure, cloud & sécurité' => '🔐', 'Traduction & langues' => '🌍', 'Autre' => '✦',
];

$formulaire = [
    'titre' => '', 'categorie' => $categorie_initiale, 'description' => '', 'competences' => '',
    'budget_min' => $budget_defaut_min, 'budget_max' => $budget_defaut_max,
    'delai' => '', 'date_limite' => '',
];
$brouillon = $est_client_connecte ? projet_brouillon_lire() : null;
if ($brouillon) {
    foreach ($formulaire as $cle => $defaut) {
        if (array_key_exists($cle, $brouillon)) { $formulaire[$cle] = $brouillon[$cle]; }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $controle = projet_brouillon_preparer($_POST);
    if (!$controle['ok']) {
        $erreur = $controle['erreur'];
        foreach ($formulaire as $cle => $defaut) {
            if (array_key_exists($cle, $_POST) && is_string($_POST[$cle])) {
                $formulaire[$cle] = trim($_POST[$cle]);
            }
        }
    } elseif (!$est_client_connecte) {
        // Le visiteur décrit son besoin avant son compte : le brouillon reste
        // exclusivement côté serveur et ne passe jamais dans une URL.
        $_SESSION['projet_brouillon'] = $controle['data'];
        header('Location: ' . u('/connexion?projet=1')); exit;
    } else {
        $formulaire = $controle['data'];
        db()->prepare("INSERT INTO projets (entreprise_id,titre,categorie,description,competences,budget_min,budget_max,delai,date_limite,statut,cree_en_beta)
                       VALUES (?,?,?,?,?,?,?,?,?,'nouveau',?)")
            ->execute([$u['id'], $formulaire['titre'], $formulaire['categorie'], $formulaire['description'],
                       $formulaire['competences'], (int) $formulaire['budget_min'], (int) $formulaire['budget_max'],
                       $formulaire['delai'], $formulaire['date_limite'], beta_actif() ? 1 : 0]);
        $pid = db()->lastInsertId();
        auto_assigner_client($u['id']);
        $gs = db()->prepare("SELECT gestionnaire_id FROM users WHERE id=?");
        $gs->execute([$u['id']]);
        $gest = (int) $gs->fetchColumn() ?: admin_id();
        notify($u['id'], beta_actif()
            ? "Votre projet « {$formulaire['titre']} » est enregistré. Il sera étudié dès l'ouverture du service."
            : "Votre projet « {$formulaire['titre']} » a bien été enregistré. Réponse sous 48 h.",
            'projet.php?id=' . $pid);
        notify($gest, "Nouveau projet à analyser : « {$formulaire['titre']} » (" . ($u['societe'] ?: $u['nom']) . ").", 'admin-matching.php?id=' . $pid);
        push_alerter_admins("Nouveau projet : " . $formulaire['titre'], 'admin-matching.php?id=' . $pid);
        db()->prepare("INSERT INTO messages (projet_id,expediteur_id,destinataire_id,contenu) VALUES (?,1,?,?)")
            ->execute([$pid, $u['id'], beta_actif()
                ? "Bonjour, nous avons bien reçu votre projet « {$formulaire['titre']} ». Missiona est actuellement en préinscription : votre demande est enregistrée et sera étudiée en priorité dès l'ouverture du service. Vous serez prévenu par courriel."
                : "Bonjour, nous avons bien reçu votre projet « {$formulaire['titre']} ». Notre équipe analyse votre besoin et revient vers vous sous 48 heures avec une proposition d'expert et une estimation budgétaire."]);

        // Seulement le suivi opérationnel choisi à l'inscription : jamais une
        // communication commerciale. L'adresse vient d'être vérifiée avant
        // que ce chemin de dépôt puisse être atteint.
        email_suivi_projet_client($pid, 'Projet en cours de traitement');
        projet_brouillon_oublier();
        // Ce marqueur à usage unique est lu par projet.php : la confirmation
        // ne peut donc apparaître qu'après une insertion réussie.
        $_SESSION['projet_soumis_popup'] = (int) $pid;
        header('Location: ' . u('projet.php?id=' . $pid)); exit;
    }
}

$depart_recap = $est_client_connecte && isset($_GET['recap']) && $brouillon ? 4 : 0;
$titre = "Décrire mon projet";
require_once __DIR__ . '/includes/header.php';
?>
<?php if ($est_client_connecte): ?>
<div class="app">
<?php require __DIR__ . '/includes/sidebar.php'; ?>
<main class="main">
<?php else: ?>
<main class="project-public">
  <div class="container">
<?php endif; ?>

  <div class="wizard project-wizard">
    <div class="page-head project-page-head">
      <div>
        <h1>Décrivez votre projet</h1>
      </div>
    </div>

    <span class="project-private" title="Votre projet n’est jamais public">🔒 Confidentiel</span>

    <?php if (beta_actif() && !beta_exempt()): ?>
      <div class="alert alert-bleu mb-3">
        <strong>Missiona est en préinscription.</strong> Votre projet est conservé et étudié en priorité dès l'ouverture.
      </div>
    <?php endif; ?>
    <?php if ($erreur): ?><div class="alert alert-error"><?= e($erreur) ?></div><?php endif; ?>

    <form method="post" id="wizard" data-start-step="<?= (int) $depart_recap ?>">
      <?= csrf_field() ?>
      <div class="wizard-bar">
        <div class="wz-step active"><div class="n">1</div><div class="t">Le projet</div></div>
        <div class="wz-step"><div class="n">2</div><div class="t">Votre besoin</div></div>
        <div class="wz-step"><div class="n">3</div><div class="t">Compétences</div></div>
        <div class="wz-step"><div class="n">4</div><div class="t">Budget &amp; délai</div></div>
        <div class="wz-step"><div class="n">5</div><div class="t"><?= $est_client_connecte ? 'Récapitulatif' : 'Votre compte' ?></div></div>
      </div>

      <div class="panel project-panel">
        <div class="panel-body">

          <section class="wz-panel active">
            <h2>Le projet</h2>
            <div class="field">
              <label for="titre">Titre</label>
              <input type="text" id="titre" name="titre" class="input project-input" required maxlength="160"
                     value="<?= e($formulaire['titre']) ?>" placeholder="Ex. Refonte de notre site internet">
            </div>

            <div class="project-domain-current" id="projectDomainCurrentBox">
              <div>
                <span>Domaine</span>
                <strong id="projectDomainCurrent"><?= $formulaire['categorie'] ? e($formulaire['categorie']) : 'Choisir' ?></strong>
              </div>
              <button type="button" class="btn btn-ghost" id="projectDomainToggle" aria-expanded="false">Choisir</button>
            </div>
            <fieldset class="project-fieldset project-domain-picker" id="projectDomainPicker" hidden>
              <legend>Domaine</legend>
              <div class="project-choice-grid project-domain-grid">
              <?php foreach (domaines_projet() as $domaine): ?>
                <label class="project-choice <?= $formulaire['categorie'] === $domaine ? 'selected' : '' ?>">
                  <input type="radio" name="categorie" value="<?= e($domaine) ?>" required <?= $formulaire['categorie'] === $domaine ? 'checked' : '' ?>>
                  <span class="project-choice-icon"><?= $icones_domaines[$domaine] ?? '✦' ?></span>
                  <span><?= e($domaine) ?></span>
                </label>
              <?php endforeach; ?>
              </div>
            </fieldset>
          </section>

          <section class="wz-panel">
            <h2>Votre besoin</h2>
            <div class="field">
              <label for="description">Décrivez-le</label>
              <textarea id="description" name="description" class="textarea project-textarea" required data-counter="cnt" maxlength="8000"
                        placeholder="Ex. Moderniser notre site pour générer plus de demandes."><?= e($formulaire['description']) ?></textarea>
              <div class="hint" id="cnt">0 caractère</div>
            </div>
          </section>

          <section class="wz-panel">
            <h2>Compétences <span class="project-optional">facultatif</span></h2>
            <div class="field">
              <?php champ_competences_projet($formulaire['competences']); ?>
            </div>
          </section>

          <section class="wz-panel">
            <h2>Budget et délai</h2>
            <div class="field">
              <label>Fourchette budgétaire</label>
              <div class="range-wrap" id="budgetRange">
                <div class="range-values">
                  <div class="range-val">
                    <span class="range-lbl">Minimum</span>
                    <button type="button" class="range-prix" id="budgetMinTxt" data-cible="budget_min" data-suivi-non title="Cliquez pour saisir le montant exact"><?= euros((int) $formulaire['budget_min']) ?></button>
                  </div>
                  <div class="range-val" style="text-align:right">
                    <span class="range-lbl">Maximum</span>
                    <button type="button" class="range-prix" id="budgetMaxTxt" data-cible="budget_max" data-suivi-non title="Cliquez pour saisir le montant exact"><?= euros((int) $formulaire['budget_max']) ?></button>
                  </div>
                </div>
                <input type="hidden" name="budget_min" id="budget_min" value="<?= (int) $formulaire['budget_min'] ?>">
                <input type="hidden" name="budget_max" id="budget_max" value="<?= (int) $formulaire['budget_max'] ?>">
                <div class="range-track">
                  <div class="range-fill" id="budgetFill"></div>
                  <input type="range" id="rgMin" min="0" max="<?= $budget_plafond ?>" step="1" value="<?= (int) $formulaire['budget_min'] ?>" aria-label="Budget minimum">
                  <input type="range" id="rgMax" min="0" max="<?= $budget_plafond ?>" step="1" value="<?= (int) $formulaire['budget_max'] ?>" aria-label="Budget maximum">
                </div>
                <div class="range-scale">
                  <?php for ($i = 0; $i <= 4; $i++): $v = (int) round($budget_plafond * $i / 4); ?>
                    <span><?= euros($v) ?><?= $i === 4 ? '+' : '' ?></span>
                  <?php endfor; ?>
                </div>
              </div>

            </div>
            <div class="field-row">
              <div class="field">
                <label for="delai">Durée souhaitée</label>
                <select id="delai" name="delai" class="select">
                  <?php foreach ($delais as $delai): ?>
                    <option value="<?= e($delai) ?>" <?= $formulaire['delai'] === $delai ? 'selected' : '' ?>><?= $delai === '' ? 'Sélectionner…' : e($delai) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field">
                <label for="date_limite">Date de livraison souhaitée</label>
                <input type="date" id="date_limite" name="date_limite" class="input" value="<?= e($formulaire['date_limite']) ?>">
              </div>
            </div>
            <div class="alert alert-warn" style="margin:0">La publication du projet et l'étude sont gratuites. Aucun engagement à ce stade.</div>
          </section>

          <?php if (!$est_client_connecte): ?>
          <section class="wz-panel">
            <h2>Presque terminé</h2>
            <div class="project-account-card">
              <div class="project-account-icon">🔒</div>
              <div><strong>Vos réponses sont sauvegardées</strong></div>
            </div>
          </section>
          <?php else: ?>
          <section class="wz-panel">
            <h2>Dernière vérification</h2>
            <p class="project-question">Vous pouvez encore tout modifier.</p>
            <div class="recap" id="recapBox"></div>
            <div class="alert alert-info mt-3" style="margin-bottom:0">✅ Réponse de notre équipe sous 48 h ouvrées.</div>
          </section>
          <?php endif; ?>

        </div>
      </div>

      <div class="wz-nav">
        <button type="button" class="btn btn-ghost" id="wzPrev">← Précédent</button>
        <div style="display:flex;gap:10px">
          <a href="<?= $est_client_connecte ? '/dashboard-entreprise' : '/' ?>" class="btn btn-ghost">Annuler</a>
          <button type="button" class="btn btn-primary" id="wzNext">Continuer →</button>
          <?php if ($est_client_connecte): ?>
            <button type="submit" name="action" value="deposer" class="btn btn-primary" id="wzSubmit" style="display:none">Envoyer le projet</button>
          <?php else: ?>
            <button type="submit" name="action" value="connexion" class="btn btn-primary" id="wzSubmit" style="display:none">Continuer vers mon compte →</button>
          <?php endif; ?>
        </div>
      </div>
    </form>
  </div>

<?php if ($est_client_connecte): ?>
</main>
</div>
<?php else: ?>
  </div>
</main>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
