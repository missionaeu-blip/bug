<?php
/**
 * Vérification d'adresse après une inscription par mot de passe.
 * L'identifiant du compte et le retour vers un éventuel brouillon restent
 * exclusivement en session : aucun projet ni e-mail ne circule dans l'URL.
 */
require_once __DIR__ . '/includes/functions.php';

$uid = (int) ($_SESSION['email_verification_user_id'] ?? 0);
if ($uid <= 0) {
    flash("Votre session de vérification a expiré. Connectez-vous pour recevoir un nouveau code.", 'error');
    header('Location: ' . u('/connexion'));
    exit;
}

$st = db()->prepare("SELECT * FROM users WHERE id = ? AND actif = 1");
$st->execute([$uid]);
$compte = $st->fetch();
if (!$compte) {
    unset($_SESSION['email_verification_user_id'], $_SESSION['email_verification_retour_projet']);
    flash("Ce compte n'est plus disponible.", 'error');
    header('Location: ' . u('/connexion'));
    exit;
}

/** Ouvre la session seulement après validation de l'adresse, puis reprend le bon parcours. */
function verification_email_terminer($compte) {
    $retour_projet = !empty($_SESSION['email_verification_retour_projet'])
        && projet_brouillon_lire() !== null
        && est_client($compte['role']);

    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $compte['id'];
    guide_premiere_connexion_programmer($compte);
    unset($_SESSION['email_verification_user_id'], $_SESSION['email_verification_retour_projet']);

    if ($retour_projet) {
        header('Location: ' . u('/nouveau-projet?recap=1'));
    } else {
        header('Location: ' . u('/' . dashboard_url($compte['role'])));
    }
    exit;
}

// Une actualisation après vérification termine proprement le parcours.
if (email_est_verifie($compte)) {
    verification_email_terminer($compte);
}

$erreur = '';
$info = flash();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? 'valider';

    if ($action === 'renvoyer') {
        $r = email_verification_envoyer($uid);
        if ($r['ok']) { $info = ['m' => $r['message'], 't' => 'success']; }
        else { $erreur = $r['message']; }
    } else {
        $r = email_verification_valider($uid, $_POST['code'] ?? '');
        if ($r['ok']) {
            // Redirection immédiate : aucun second e-mail synchrone ne retarde
            // la validation du code. Le code reçu constitue déjà le message
            // transactionnel de bienvenue et de sécurité.
            verification_email_terminer($r['user']);
        }
        $erreur = $r['message'];
    }
}

$titre = "Vérifiez votre e-mail";
$page = 'verification-email';
require_once __DIR__ . '/includes/header.php';
?>
<main class="auth-wrap auth-page">
  <div class="auth-card" style="max-width:470px;text-align:center">
    <div aria-hidden="true" style="width:46px;height:46px;margin:0 auto 14px;border-radius:50%;display:grid;place-items:center;background:#e8f0ff;color:#165DFF;font-size:23px">✉</div>
    <h1 class="mb-1">Vérifiez votre e-mail</h1>
    <p class="muted mb-3">Un code à 6 chiffres a été envoyé à<br><strong><?= e($compte['email']) ?></strong></p>

    <?php if ($info): ?><div class="alert alert-<?= e($info['t'] ?? 'info') ?> mb-3" role="status"><?= e($info['m'] ?? '') ?></div><?php endif; ?>
    <?php if ($erreur): ?><div class="alert alert-error mb-3" role="alert"><?= e($erreur) ?></div><?php endif; ?>

    <form method="post" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="valider">
      <div class="field" style="max-width:270px;margin:0 auto 18px">
        <label for="code" class="sr-only">Code de vérification à 6 chiffres</label>
        <input id="code" name="code" class="input" required inputmode="numeric" autocomplete="one-time-code"
               pattern="[0-9]{6}" maxlength="6" placeholder="000000" autofocus
               style="text-align:center;letter-spacing:.38em;font-size:1.45rem;font-weight:700;padding-left:.8em">
      </div>
      <button type="submit" class="btn btn-primary btn-block btn-lg">Vérifier mon adresse</button>
    </form>

    <form method="post" class="mt-3">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="renvoyer">
      <button type="submit" class="btn btn-ghost">Renvoyer un code</button>
    </form>
    <p class="small muted mt-3 mb-0">Le code expire dans 15 minutes. Il sécurise votre compte et le suivi de votre projet&nbsp;: aucun e-mail commercial.</p>
  </div>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
