<?php
require_once __DIR__ . '/includes/functions.php';
if (is_logged()) { header('Location: ' . dashboard_url(null, true)); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    mdp_demander_reinit($_POST['email'] ?? '');
    // Réponse identique que le compte existe ou non : on ne doit pas
    // pouvoir deviner quelles adresses sont inscrites.
    header('Location: ' . u('/mdp-oublie.php?envoye=1'));
    exit;
}

$titre = "Mot de passe oublié";
$page  = 'connexion';
require_once __DIR__ . '/includes/header.php';
?>

<div class="auth-wrap">
  <div class="auth-card">
    <h1>Mot de passe oublié</h1>
    <p>Indiquez l'adresse e-mail de votre compte : si elle existe,
       vous recevrez un lien pour choisir un nouveau mot de passe.</p>

    <?php if (isset($_GET['envoye'])): ?>
      <div class="alert alert-success">
        Si un compte est associé à cette adresse, un e-mail vient de lui
        être envoyé. Pensez à vérifier vos courriers indésirables.
      </div>
    <?php endif; ?>

    <form method="post" novalidate>
      <?= csrf_field() ?>
      <div class="field">
        <label for="email">Adresse e-mail</label>
        <input type="email" id="email" name="email" class="input" required
               value="<?= e($_POST['email'] ?? '') ?>" placeholder="vous@entreprise.fr" autocomplete="email">
      </div>
      <button type="submit" class="btn btn-primary btn-block btn-lg">Envoyer le lien</button>
    </form>

    <div class="auth-alt">
      <a href="/connexion">Retour à la connexion</a>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
