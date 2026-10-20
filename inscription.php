<?php
require_once __DIR__ . '/includes/functions.php';
if (is_logged()) {
    $connecte = user();
    if (!empty($_GET['projet']) && projet_brouillon_lire() && est_client($connecte['role'])) {
        header('Location: ' . u('/nouveau-projet?recap=1'));
    } else {
        header('Location: ' . dashboard_url(null, true));
    }
    exit;
}

$erreur = '';
$roles = ['entreprise', 'particulier', 'freelance'];
$retour_projet = !empty($_GET['projet']) && projet_brouillon_lire() !== null;
$roles_autorises = $retour_projet ? ['entreprise', 'particulier'] : $roles;
$role_pre = in_array($_GET['role'] ?? '', $roles_autorises, true) ? $_GET['role'] : 'entreprise';
$domaine_code = $role_pre === 'entreprise' ? domaine_landing_code($_GET['domaine'] ?? '') : '';
$competences_form = is_string($_POST['competences'] ?? null) ? $_POST['competences'] : '';

function inscription_texte_post($cle) {
    return is_string($_POST[$cle] ?? null) ? trim($_POST[$cle]) : '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $retour_projet = !empty($_POST['projet']) && projet_brouillon_lire() !== null;
    $roles_autorises = $retour_projet ? ['entreprise', 'particulier'] : $roles;
    $role = in_array($_POST['role'] ?? '', $roles_autorises, true) ? $_POST['role'] : '';
    $role_pre = $role ?: $role_pre;
    $domaine_code = $role === 'entreprise' ? domaine_landing_code($_POST['domaine'] ?? '') : '';

    // Toutes les clés possibles sont listées ; les champs d'un rôle non choisi
    // sont ignorés côté serveur et ne peuvent pas modifier son profil.
    $champs_autorises = [
        'csrf','sid','role','domaine','projet','prenom','nom','email','telephone',
        'societe','taille','bio','titre_pro','competences','experience','disponibilite',
        'tarif_projet','siret','password','password2','rgpd','suivi_email',
    ];
    $champs_inattendus = array_diff(array_keys($_POST), $champs_autorises);

    $email  = strtolower(inscription_texte_post('email'));
    $pass   = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $pass2  = is_string($_POST['password2'] ?? null) ? $_POST['password2'] : '';
    $nom    = inscription_texte_post('nom');
    $prenom = inscription_texte_post('prenom');
    $siret  = preg_replace('/\s+/', '', inscription_texte_post('siret'));
    $competences = $role === 'freelance'
        ? normaliser_competences_soumises($_POST['competences'] ?? '')
        : ['ok' => true, 'competences' => '', 'invalide' => ''];

    if (!$role) {
        $erreur = "Veuillez choisir un type de compte.";
    } elseif ($champs_inattendus) {
        $erreur = "Ce formulaire contient des champs non autorisés.";
    } elseif ($role === 'freelance' && !$competences['ok']) {
        http_response_code(422);
        $erreur = 'La compétence « ' . $competences['invalide'] . ' » n’est plus proposée ou n’existe pas.';
    } elseif ($role === 'freelance' && !preg_match('/^\d{14}$/', $siret)) {
        $erreur = "Le SIRET doit comporter exactement 14 chiffres.";
    } else {
        csrf_check();
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $erreur = "Adresse e-mail invalide."; }
        elseif (strlen($pass) < 6)                       { $erreur = "Le mot de passe doit contenir au moins 6 caractères."; }
        elseif ($pass !== $pass2)                         { $erreur = "Les deux mots de passe ne correspondent pas."; }
        elseif (!$nom)                                    { $erreur = "Le nom est obligatoire."; }
        elseif (empty($_POST['rgpd']))                    { $erreur = "Vous devez accepter la politique de confidentialité."; }
        elseif (empty($_POST['suivi_email']))             { $erreur = "Vous devez confirmer les e-mails nécessaires au suivi de votre projet."; }
        elseif ($role === 'freelance' && !in_array($_POST['disponibilite'] ?? '', ['disponible','partiel','occupe'], true)) {
            $erreur = "Disponibilité invalide.";
        } else {
            $st = db()->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
            $st->execute([$email]);
            if ($st->fetchColumn()) {
                $erreur = "Un compte existe déjà avec cette adresse e-mail.";
            } else {
                $est_entreprise = $role === 'entreprise';
                db()->prepare("INSERT INTO users
                    (email,password_hash,role,nom,prenom,telephone,societe,siret,secteur,taille,titre_pro,bio,competences,tarif_projet,experience,disponibilite,email_suivi_consent,email_verifie)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                  ->execute([
                    $email, password_hash($pass, PASSWORD_DEFAULT), $role, $nom, $prenom,
                    inscription_texte_post('telephone'),
                    $est_entreprise ? inscription_texte_post('societe') : '',
                    $role === 'freelance' ? $siret : '',
                    '', $est_entreprise ? inscription_texte_post('taille') : '',
                    $role === 'freelance' ? inscription_texte_post('titre_pro') : '',
                    in_array($role, ['entreprise','freelance'], true) ? inscription_texte_post('bio') : '',
                    $competences['competences'],
                    $role === 'freelance' ? (int) ($_POST['tarif_projet'] ?? 0) : 0,
                    $role === 'freelance' ? (int) ($_POST['experience'] ?? 0) : 0,
                    $role === 'freelance' ? ($_POST['disponibilite'] ?? 'disponible') : 'disponible',
                    1, 0,
                  ]);
                $uid = (int) db()->lastInsertId();
                if (in_array($role, ['entreprise','particulier'], true)) { auto_assigner_client($uid); }
                notify($uid, "Bienvenue sur Missiona. Votre chargé de compte vous contactera sous 48 h.", 'index.php');
                notify(admin_id(), "Nouvelle inscription : " . $nom . " (" . $role . ").", 'admin.php');
                push_apres_inscription();

                // Le compte ne peut pas se connecter ni envoyer son projet
                // avant la preuve de maîtrise de l'adresse e-mail.
                email_verification_ouvrir($uid, $retour_projet);
                $envoi = email_verification_envoyer($uid, true);
                flash($envoi['message'], $envoi['ok'] ? 'success' : 'error');
                header('Location: ' . u('/verifier-email')); exit;
            }
        }
    }
}

$titre = "Créer un compte";
$page  = 'inscription';
require_once __DIR__ . '/includes/header.php';
?>

<div class="auth-wrap">
  <div class="auth-card" style="max-width:560px">
    <h1><?= $retour_projet ? 'Créer votre compte' : 'Créer un compte' ?></h1>
    <p><?= $retour_projet ? 'Votre projet est conservé. Créez simplement votre accès pour voir le récapitulatif et l’envoyer.' : 'Quelques informations pour démarrer. Cela prend moins de 3 minutes.' ?></p>

    <?php if ($erreur): ?><div class="alert alert-error"><?= e($erreur) ?></div><?php endif; ?>

    <form method="post" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="role" id="role" value="<?= e($role_pre) ?>">
      <?php if ($role_pre === 'entreprise' && $domaine_code !== ''): ?>
        <input type="hidden" name="domaine" value="<?= e($domaine_code) ?>">
      <?php endif; ?>
      <?php if ($retour_projet): ?><input type="hidden" name="projet" value="1"><?php endif; ?>

      <label class="strong small mb-1" style="display:block">Je suis…</label>
      <div class="role-grid <?= $retour_projet ? '' : 'role-grid-3' ?>">
        <div class="role-opt <?= $role_pre === 'entreprise' ? 'selected' : '' ?>" data-target="role" data-value="entreprise">
          <div class="ico">🏢</div><strong>Une entreprise</strong><span>Je veux faire réaliser un projet</span>
        </div>
        <div class="role-opt <?= $role_pre === 'particulier' ? 'selected' : '' ?>" data-target="role" data-value="particulier">
          <div class="ico">🙋</div><strong>Un particulier</strong><span>J’ai un projet personnel</span>
        </div>
        <?php if (!$retour_projet): ?>
        <div class="role-opt <?= $role_pre === 'freelance' ? 'selected' : '' ?>" data-target="role" data-value="freelance">
          <div class="ico">👩‍💻</div><strong>Un freelance</strong><span>Je veux recevoir des missions</span>
        </div>
        <?php endif; ?>
      </div>

      <div class="field-row">
        <div class="field"><label for="prenom">Prénom</label><input type="text" id="prenom" name="prenom" class="input" value="<?= e($_POST['prenom'] ?? '') ?>"></div>
        <div class="field"><label for="nom">Nom *</label><input type="text" id="nom" name="nom" class="input" required value="<?= e($_POST['nom'] ?? '') ?>"></div>
      </div>
      <div class="field"><label for="email">Adresse e-mail *</label><input type="email" id="email" name="email" class="input" required value="<?= e($_POST['email'] ?? '') ?>"></div>
      <div class="field"><label for="telephone">Téléphone</label><input type="tel" id="telephone" name="telephone" class="input" value="<?= e($_POST['telephone'] ?? '') ?>"></div>

      <fieldset data-role-block="entreprise" <?= $role_pre === 'entreprise' ? '' : 'disabled' ?> style="border:0;padding:0;margin:0;min-width:0">
        <div class="field"><label for="societe">Nom de l’entreprise</label><input type="text" id="societe" name="societe" class="input" value="<?= e($_POST['societe'] ?? '') ?>"></div>
        <div class="field"><label for="taille">Effectif</label><select id="taille" name="taille" class="select"><option value="">Sélectionner…</option><?php foreach (['1-10','10-50','50-200','200-1000','1000+'] as $t): ?><option value="<?= $t ?>" <?= ($_POST['taille'] ?? '') === $t ? 'selected' : '' ?>><?= $t ?> salariés</option><?php endforeach; ?></select></div>
        <div class="field"><label for="bio_entreprise">Description</label><textarea id="bio_entreprise" name="bio" class="input" rows="4"><?= e($_POST['bio'] ?? '') ?></textarea></div>
      </fieldset>

      <fieldset data-role-block="particulier" <?= $role_pre === 'particulier' ? '' : 'disabled' ?> style="border:0;padding:0;margin:0;min-width:0">
        <div class="alert alert-info mb-3">🙋 Vous décrivez simplement votre projet : notre équipe s’occupe du cadrage et de la sélection de l’expert.</div>
      </fieldset>

      <fieldset data-role-block="freelance" <?= $role_pre === 'freelance' ? '' : 'disabled' ?> style="border:0;padding:0;margin:0;min-width:0">
        <div class="field"><label for="titre_pro">Titre professionnel</label><input type="text" id="titre_pro" name="titre_pro" class="input" placeholder="Ex. Développeuse Full-Stack" value="<?= e($_POST['titre_pro'] ?? '') ?>"></div>
        <div class="field"><label for="siret">SIRET *</label><input type="text" id="siret" name="siret" class="input" required inputmode="numeric" maxlength="17" value="<?= e($_POST['siret'] ?? '') ?>"><div class="hint">14 chiffres, visible sur vos mentions légales ou votre facture.</div></div>
        <div class="field"><label for="bio_freelance">Présentation</label><textarea id="bio_freelance" name="bio" class="input" rows="5" placeholder="Décrivez votre parcours et vos domaines de spécialité."><?= e($_POST['bio'] ?? '') ?></textarea></div>
        <div class="field"><label>Vos compétences</label><?php champ_competences($competences_form); ?><div class="hint">Sélectionnez toutes vos compétences, dans un ou plusieurs domaines.</div></div>
        <div class="field-row">
          <div class="field"><label for="tarif_projet">Tarif par projet (€)</label><input type="number" id="tarif_projet" name="tarif_projet" class="input" min="0" step="1" placeholder="Ex. 2000" value="<?= e($_POST['tarif_projet'] ?? '') ?>"></div>
          <div class="field"><label for="experience">Années d’expérience</label><input type="number" id="experience" name="experience" class="input" min="0" max="50" value="<?= e($_POST['experience'] ?? '') ?>"></div>
        </div>
        <div class="field"><label for="disponibilite">Disponibilité</label><select id="disponibilite" name="disponibilite" class="select"><option value="disponible" <?= ($_POST['disponibilite'] ?? 'disponible') === 'disponible' ? 'selected' : '' ?>>Disponible immédiatement</option><option value="partiel" <?= ($_POST['disponibilite'] ?? '') === 'partiel' ? 'selected' : '' ?>>Partiellement disponible</option><option value="occupe" <?= ($_POST['disponibilite'] ?? '') === 'occupe' ? 'selected' : '' ?>>Actuellement en mission</option></select></div>
      </fieldset>

      <div class="field-row">
        <div class="field"><label for="password">Mot de passe *</label><input type="password" id="password" name="password" class="input" required minlength="6"><div class="hint">6 caractères minimum.</div></div>
        <div class="field"><label for="password2">Confirmation *</label><input type="password" id="password2" name="password2" class="input" required minlength="6"></div>
      </div>
      <label class="check mb-3"><input type="checkbox" name="rgpd" value="1" required <?= !empty($_POST['rgpd']) ? 'checked' : '' ?>><span>J’accepte les <a href="/cgu" style="color:var(--blue)">conditions générales</a> et la <a href="/politique-confidentialite" style="color:var(--blue)">politique de confidentialité</a>.</span></label>
      <label class="check mb-3"><input type="checkbox" name="suivi_email" value="1" required <?= !empty($_POST['suivi_email']) ? 'checked' : '' ?>><span>J’accepte les e-mails indispensables au fonctionnement de mon compte et au traitement / suivi de mes projets. <strong>Aucun e-mail commercial.</strong></span></label>
      <button type="submit" class="btn btn-primary btn-block btn-lg">Créer mon compte</button>
    </form>

    <?php if (google_actif() && !$retour_projet): ?>
      <div class="sep-ou"><span>ou</span></div>
      <a class="btn btn-google btn-block" href="<?= e(google_url_auth($role_pre)) ?>">
        <svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true" focusable="false">
          <path fill="#4285F4" d="M45.1 24.5c0-1.6-.1-2.8-.4-4H24v7.3h12.1c-.2 2-1.6 5-4.5 7l-.1.3 6.5 5 .5.1c4.1-3.8 6.6-9.4 6.6-15.7"/>
          <path fill="#34A853" d="M24 46c5.9 0 10.9-1.9 14.5-5.3l-6.9-5.3c-1.8 1.3-4.3 2.2-7.6 2.2-5.8 0-10.7-3.8-12.5-9l-.3.1-6.7 5.2-.1.3C7.9 41.1 15.4 46 24 46"/>
          <path fill="#FBBC05" d="M11.5 28.6c-.5-1.4-.7-2.9-.7-4.6s.3-3.2.7-4.6v-.3l-6.8-5.3-.2.1C2.9 16.9 2 20.3 2 24s.9 7.1 2.5 10.1l7-5.5"/>
          <path fill="#EB4335" d="M24 10.7c4.1 0 6.9 1.8 8.5 3.3l6.2-6C34.9 4.5 29.9 2 24 2 15.4 2 7.9 6.9 4.5 13.9l7 5.5c1.8-5.2 6.7-8.7 12.5-8.7"/>
        </svg>
        <span>S'inscrire avec Google</span>
      </a>
    <?php endif; ?>

    <?php $connexion_params = $retour_projet ? '?projet=1' : ($domaine_code !== '' ? '?domaine=' . rawurlencode($domaine_code) : ''); ?>
    <div class="auth-alt">Déjà inscrit ? <a href="<?= e('/connexion' . $connexion_params) ?>">Se connecter</a></div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
udes/footer.php'; ?>
