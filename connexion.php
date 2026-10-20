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
// Code fermé issu d'une carte de l'accueil : il détermine le préremplissage
// du formulaire de projet après authentification, jamais une URL arbitraire.
$domaine_code = domaine_landing_code($_GET['domaine'] ?? '');
// Un brouillon commencé sans compte ne transite pas dans l'URL. Le paramètre
// ne fait qu'activer le retour contrôlé vers son récapitulatif.
$retour_projet = !empty($_GET['projet']) && projet_brouillon_lire() !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $domaine_code = domaine_landing_code($_POST['domaine'] ?? '');
    $retour_projet = !empty($_POST['projet']) && projet_brouillon_lire() !== null;
    $em = strtolower(trim($_POST['email'] ?? ''));
    $pw = $_POST['password'] ?? '';
    csrf_check();

    // Évite de réutiliser une ancienne tentative de vérification après un
    // échec de connexion, puis login() recrée cette attente si nécessaire.
    unset($_SESSION['email_verification_user_id'], $_SESSION['email_verification_retour_projet']);
    $u = login($em, $pw);
    if ($u) {
        if ($retour_projet && est_client($u['role'])) {
            header('Location: ' . u('/nouveau-projet?recap=1'));
        } elseif ($domaine_code !== '' && est_client($u['role'])) {
            header('Location: ' . u('/nouveau-projet?domaine=' . rawurlencode($domaine_code)));
        } else {
            header('Location: ' . dashboard_url($u['role'], true));
        }
        exit;
    }
    if (!empty($_SESSION['email_verification_user_id'])) {
        $_SESSION['email_verification_retour_projet'] = $retour_projet;
        header('Location: ' . u('/verifier-email'));
        exit;
    }
    $erreur = "Identifiants incorrects. Vérifiez votre adresse e-mail et votre mot de passe.";
}

$titre = "Connexion";
$page  = 'connexion';
require_once __DIR__ . '/includes/header.php';
?>

<div class="auth-wrap">
  <div class="auth-card">
    <h1>Connexion</h1>
    <p><?= $retour_projet ? 'Connectez-vous pour retrouver le récapitulatif de votre projet.' : 'Accédez à votre espace Missiona.' ?></p>

    <?php if ($retour_projet): ?><div class="alert alert-info">Votre description est bien conservée. Après connexion ou inscription, vous pourrez la vérifier avant l’envoi.</div><?php endif; ?>
    <?php if ($erreur): ?>
      <div class="alert alert-error"><?= e($erreur) ?></div>
    <?php endif; ?>
    <?php if (isset($_GET['inscrit'])): ?>
      <div class="alert alert-success">Votre compte a bien été créé. Vous pouvez vous connecter.</div>
    <?php endif; ?>
    <?php if (isset($_GET['mdp_reinitialise'])): ?>
      <div class="alert alert-success">Votre mot de passe a été réinitialisé. Vous pouvez vous connecter.</div>
    <?php endif; ?>

    <form method="post" novalidate>
      <?= csrf_field() ?>
      <?php if ($domaine_code !== ''): ?>
        <input type="hidden" name="domaine" value="<?= e($domaine_code) ?>">
      <?php endif; ?>
      <?php if ($retour_projet): ?>
        <input type="hidden" name="projet" value="1">
      <?php endif; ?>
      <div class="field">
        <label for="email">Adresse e-mail professionnelle</label>
        <input type="email" id="email" name="email" class="input" required
               value="<?= e($_POST['email'] ?? '') ?>" placeholder="vous@entreprise.fr" autocomplete="username">
      </div>
      <div class="field">
        <label for="password">Mot de passe</label>
        <input type="password" id="password" name="password" class="input" required
               placeholder="••••••••" autocomplete="current-password">
      </div>
      <label class="check mb-3">
        <input type="checkbox" name="remember"> <span>Rester connecté sur cet appareil</span>
      </label>
      <button type="submit" class="btn btn-primary btn-block btn-lg">Se connecter</button>
      <div class="auth-alt" style="margin-top:12px">
        <a href="/mdp-oublie.php">Mot de passe oublié ?</a>
      </div>
    </form>
    <?php if (google_actif()): ?>
      <div class="sep-ou"><span>ou</span></div>
      <a class="btn btn-google btn-block" href="<?= e(google_url_auth('')) ?>">
        <svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true" focusable="false">
          <path fill="#4285F4" d="M45.1 24.5c0-1.6-.1-2.8-.4-4H24v7.3h12.1c-.2 2-1.6 5-4.5 7l-.1.3 6.5 5 .5.1c4.1-3.8 6.6-9.4 6.6-15.7"/>
          <path fill="#34A853" d="M24 46c5.9 0 10.9-1.9 14.5-5.3l-6.9-5.3c-1.8 1.3-4.3 2.2-7.6 2.2-5.8 0-10.7-3.8-12.5-9l-.3.1-6.7 5.2-.1.3C7.9 41.1 15.4 46 24 46"/>
          <path fill="#FBBC05" d="M11.5 28.6c-.5-1.4-.7-2.9-.7-4.6s.3-3.2.7-4.6v-.3l-6.8-5.3-.2.1C2.9 16.9 2 20.3 2 24s.9 7.1 2.5 10.1l7-5.5"/>
          <path fill="#EB4335" d="M24 10.7c4.1 0 6.9 1.8 8.5 3.3l6.2-6C34.9 4.5 29.9 2 24 2 15.4 2 7.9 6.9 4.5 13.9l7 5.5c1.8-5.2 6.7-8.7 12.5-8.7"/>
        </svg>
        <span>Continuer avec Google</span>
      </a>
    <?php endif; ?>


    <div class="auth-alt">
      <?php
        $inscription_params = ['role' => 'entreprise'];
        if ($domaine_code !== '') { $inscription_params['domaine'] = $domaine_code; }
        if ($retour_projet) { $inscription_params['projet'] = '1'; }
      ?>
      Pas encore de compte ? <a href="<?= e('/inscription?' . http_build_query($inscription_params)) ?>">Créer un compte</a>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
