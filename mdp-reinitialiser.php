<?php
require_once __DIR__ . '/includes/functions.php';
if (is_logged()) { header('Location: ' . dashboard_url(null, true)); exit; }

$jeton = $_GET['jeton'] ?? ($_POST['jeton'] ?? '');
$ligne = $jeton ? mdp_verifier_jeton($jeton) : false;

$erreur = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $nouveau  = $_POST['password'] ?? '';
    $controle = $_POST['password2'] ?? '';

    if (!$ligne) {
        $erreur = "Ce lien n'est plus valide. Refaites une demande de réinitialisation.";
    } elseif (strlen($nouveau) < 8) {
        $erreur = "Le nouveau mot de passe doit contenir au moins 8 caractères.";
    } elseif ($nouveau !== $controle) {
        $erreur = "Les deux mots de passe ne correspondent pas.";
    } else {
        mdp_utiliser_jeton($ligne, $nouveau);
        header('Location: ' . u('/connexion?mdp_reinitialise=1'));
        exit;
    }
}

$titre = "Nouveau mot de passe";
$page  = 'connexion';
require_once __DIR__ . '/includes/header.php';
?>

<div class="auth-wrap">
  <div class="auth-card">
    <h1>Nouveau mot de passe</h1>

    <?php if (!$ligne): ?>
      <div class="alert alert-error">
        Ce lien est invalide ou a expiré (les liens sont valables 1 heure).
      </div>
      <div class="auth-alt">
        <a href="/mdp-oublie.php">Faire une nouvelle demande</a>
      </div>
    <?php else: ?>
      <?php if ($erreur): ?>
        <div class="alert alert-error"><?= e($erreur) ?></div>
      <?php endif; ?>

      <form method="post" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="jeton" value="<?= e($jeton) ?>">
        <div class="field">
          <label for="password">Nouveau mot de passe</label>
          <input type="password" id="password" name="password" class="input" required
                 minlength="8" placeholder="8 caractères minimum" autocomplete="new-password">
        </div>
        <div class="field">
          <label for="password2">Confirmer le mot de passe</label>
          <input type="password" id="password2" name="password2" class="input" required
                 minlength="8" placeholder="••••••••" autocomplete="new-password">
        </div>
        <button type="submit" class="btn btn-primary btn-block btn-lg">Enregistrer</button>
      </form>
    <?php endif; ?>

    <div class="auth-alt">
      <a href="/connexion">Retour à la connexion</a>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
