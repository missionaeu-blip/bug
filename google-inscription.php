<?php
/**
 * Missiona — Dernière étape d'une inscription via Google
 * ------------------------------------------------------------------
 * Google fournit l'identité, mais ni le type de compte ni le
 * consentement à la politique de confidentialité. On les demande ici,
 * puis seulement le compte est créé.
 */
require_once __DIR__ . '/includes/functions.php';

$d = $_SESSION['google_inscription'] ?? null;

// Rien en attente, ou demande trop ancienne : on repart du début.
if (!$d || empty($d['sub']) || ($d['expire'] ?? 0) < time()) {
    unset($_SESSION['google_inscription']);
    flash("Votre demande a expiré, merci de recommencer.", 'error');
    header('Location: ' . u('/connexion')); exit;
}

$erreur   = '';
$role_pre = $_SESSION['google_role'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $role = in_array($_POST['role'] ?? '', ['entreprise', 'particulier', 'freelance'], true)
          ? $_POST['role'] : '';

    if (!$role) {
        $erreur = "Veuillez choisir un type de compte.";
    } elseif (empty($_POST['rgpd'])) {
        $erreur = "Vous devez accepter la politique de confidentialité.";
    } elseif (empty($_POST['suivi_email'])) {
        $erreur = "Vous devez confirmer les e-mails nécessaires au suivi de votre projet.";
    } else {
        // Sécurité : entre-temps, quelqu'un a pu créer ce compte.
        $st = db()->prepare("SELECT id FROM users WHERE email = ?");
        $st->execute([$d['email']]);
        if ($st->fetch()) {
            unset($_SESSION['google_inscription']);
            flash("Un compte existe déjà avec cette adresse. Connectez-vous.", 'error');
            header('Location: ' . u('/connexion')); exit;
        }

        // Aucun mot de passe : la connexion se fera par Google.
        // password_verify() échoue toujours face à une empreinte vide,
        // le compte n'est donc pas accessible par le formulaire.
        $ins = db()->prepare(
            "INSERT INTO users (email, password_hash, role, nom, prenom, google_id, avatar_url, email_suivi_consent, email_verifie, actif)
             VALUES (?, '', ?, ?, ?, ?, ?, 1, 1, 1)");
        $ins->execute([
            $d['email'], $role,
            $d['nom'] !== '' ? $d['nom'] : $d['prenom'],
            $d['prenom'], $d['sub'], $d['picture'],
        ]);
        $uid = (int) db()->lastInsertId();

        if (in_array($role, ['entreprise', 'particulier'], true)) { auto_assigner_client($uid); }
        notify(admin_id(), "Nouvelle inscription via Google : " . $d['email'] . " (" . $role . ").", 'admin.php');
        push_apres_inscription();

        unset($_SESSION['google_inscription'], $_SESSION['google_role']);
        google_ouvrir_session($uid);
        header('Location: ' . u('/' . dashboard_url($role)));
        exit;
    }
    $role_pre = $role ?: $role_pre;
}

$titre = "Finaliser votre inscription";
require_once __DIR__ . '/includes/header.php';
?>
<main class="auth-page">
  <div class="auth-card" style="max-width:560px">

    <h1 class="mb-1">Encore une étape</h1>
    <p class="muted mb-3">
      Nous avons récupéré votre identité auprès de Google.
      Indiquez-nous simplement qui vous êtes sur Missiona.
    </p>

    <div class="panel mb-3">
      <div class="panel-body" style="display:flex;align-items:center;gap:12px">
        <div>
          <div class="strong"><?= e(trim($d['prenom'] . ' ' . $d['nom'])) ?></div>
          <div class="small muted"><?= e($d['email']) ?></div>
        </div>
      </div>
    </div>

    <?php if ($erreur): ?>
      <div class="alert alert-error mb-3"><?= e($erreur) ?></div>
    <?php endif; ?>

    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="role" id="role" value="<?= e($role_pre) ?>">

      <label class="label mb-2">Vous êtes…</label>
      <div class="role-grid role-grid-3 mb-3">
        <div class="role-opt <?= $role_pre === 'entreprise' ? 'selected' : '' ?>"
             data-target="role" data-value="entreprise">
          <div class="strong">Une entreprise</div>
          <div class="small muted">Je confie des projets</div>
        </div>
        <div class="role-opt <?= $role_pre === 'particulier' ? 'selected' : '' ?>"
             data-target="role" data-value="particulier">
          <div class="strong">Un particulier</div>
          <div class="small muted">J'ai un projet ponctuel</div>
        </div>
        <div class="role-opt <?= $role_pre === 'freelance' ? 'selected' : '' ?>"
             data-target="role" data-value="freelance">
          <div class="strong">Un expert</div>
          <div class="small muted">Je réalise des missions</div>
        </div>
      </div>

      <label class="check mb-3">
        <input type="checkbox" name="rgpd" value="1" <?= !empty($_POST['rgpd']) ? 'checked' : '' ?>>
        <span>
          J'accepte les <a href="/cgu" target="_blank" rel="noopener">conditions
          d'utilisation</a> et la <a href="/politique-confidentialite" target="_blank" rel="noopener">politique
          de confidentialité</a>.
        </span>
      </label>
      <label class="check mb-3">
        <input type="checkbox" name="suivi_email" value="1" required <?= !empty($_POST['suivi_email']) ? 'checked' : '' ?>>
        <span>J’accepte les e-mails indispensables au fonctionnement de mon compte et au traitement / suivi de mes projets. <strong>Aucun e-mail commercial.</strong></span>
      </label>

      <button type="submit" class="btn btn-primary btn-block">Créer mon compte</button>
    </form>

    <p class="small muted mt-3 mb-0">
      Vous vous connecterez ensuite d'un clic avec Google. Nous ne recevons
      jamais votre mot de passe Google.
    </p>

  </div>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
