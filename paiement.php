<?php
/**
 * Paiement embarqué Stripe Payment Element.
 * Les données de carte restent dans l'iframe Stripe.js : Missiona ne reçoit
 * ni numéro de carte, ni CVC. Le PaymentIntent est créé côté serveur sur le
 * compte connecté de l'expert avant d'être confirmé dans le navigateur.
 */
require_once __DIR__ . '/includes/functions.php';
if (!is_logged()) { header('Location: ' . u('/connexion')); exit; }

$u = user();
$projet_id = (int) ($_GET['projet'] ?? 0);
$st = db()->prepare("SELECT p.*, e.societe, e.nom AS e_nom, f.nom AS f_nom, f.prenom AS f_prenom
                     FROM projets p
                     JOIN users e ON e.id=p.entreprise_id
                     LEFT JOIN users f ON f.id=p.freelance_id
                     WHERE p.id=?");
$st->execute([$projet_id]);
$projet = $st->fetch();
if (!$projet || !est_client($u['role']) || (int) $projet['entreprise_id'] !== (int) $u['id']) {
    header('Location: ' . u('/projets')); exit;
}

$paiements = paiements_projet($projet_id);
$paiement = $paiements['projet'] ?? null;
if (!$paiement || empty($paiement['stripe_direct']) || $projet['statut'] !== 'attribue') {
    flash("Ce paiement n'est plus disponible.", 'info');
    header('Location: ' . u('projet.php?id=' . $projet_id)); exit;
}

$creation = stripe_creer_intent_direct($projet, $paiement, $u);
if (!$creation['ok']) {
    flash($creation['erreur'], 'error');
    header('Location: ' . u('projet.php?id=' . $projet_id)); exit;
}
if (!empty($creation['deja_paye'])) {
    flash("Ce paiement a déjà été confirmé.", 'success');
    header('Location: ' . u('projet.php?id=' . $projet_id)); exit;
}

$intent = $creation['intent'];
// Le compte est relu depuis la base : il ne vient jamais de l'URL ou du DOM.
$q = db()->prepare("SELECT stripe_account FROM paiements WHERE id=?");
$q->execute([(int) $paiement['id']]);
$compte = (string) $q->fetchColumn();
if (!connect_compte_valide($compte) || empty($intent['client_secret'])) {
    flash("La préparation du paiement a échoué. Réessayez dans quelques instants.", 'error');
    header('Location: ' . u('projet.php?id=' . $projet_id)); exit;
}

$titre = 'Paiement sécurisé';
$montant = (int) $paiement['montant'];
$retour = rtrim(site_url(), '/') . '/paiement-retour?projet=' . $projet_id;
require_once __DIR__ . '/includes/header.php';
?>
<div class="app">
<?php require __DIR__ . '/includes/sidebar.php'; ?>
<main class="main">
  <div class="page-head">
    <div>
      <p class="small muted mb-1"><a href="<?= u('projet.php?id=' . $projet_id) ?>" style="color:var(--blue)">← Retour au projet</a></p>
      <h1>Paiement sécurisé</h1>
      <p><?= e($projet['titre']) ?> · <?= euros($montant) ?></p>
    </div>
  </div>

  <div class="panel" style="max-width:620px">
    <div class="panel-head"><h3>Choisissez votre moyen de paiement</h3></div>
    <div class="panel-body">
      <div class="alert alert-info" style="margin:0 0 20px">
        🔒 Vos informations sont saisies directement dans le formulaire sécurisé Stripe.
        Missiona ne voit ni votre numéro de carte ni votre CVC.
      </div>
      <form id="payment-form" novalidate>
        <div id="payment-element" style="min-height:120px"></div>
        <div id="payment-error" class="small" role="alert" style="color:#B42318;margin-top:12px;min-height:20px"></div>
        <button id="payment-submit" class="btn btn-primary btn-block" style="margin-top:8px">
          <span id="payment-label">Payer <?= euros($montant) ?></span>
        </button>
      </form>
      <p class="small muted mb-0" style="margin-top:16px">Les moyens disponibles sont proposés automatiquement par Stripe selon votre situation.</p>
    </div>
  </div>
</main>
</div>
<script src="https://js.stripe.com/v3/"></script>
<script>
(function () {
  var publicKey = <?= json_encode(stripe_cle_publique(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var accountId = <?= json_encode($compte, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var clientSecret = <?= json_encode((string) $intent['client_secret'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var returnUrl = <?= json_encode($retour, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var form = document.getElementById('payment-form');
  var button = document.getElementById('payment-submit');
  var label = document.getElementById('payment-label');
  var errorBox = document.getElementById('payment-error');

  if (!window.Stripe || !publicKey || !accountId || !clientSecret) {
    errorBox.textContent = "Le formulaire sécurisé n'a pas pu être chargé. Réessayez dans quelques instants.";
    button.disabled = true;
    return;
  }

  var stripe = Stripe(publicKey, {stripeAccount: accountId});
  var elements = stripe.elements({
    clientSecret: clientSecret,
    appearance: {
      theme: 'stripe',
      variables: {colorPrimary: '#165DFF', borderRadius: '10px', fontFamily: 'Inter, system-ui, sans-serif'}
    }
  });
  elements.create('payment', {layout: 'accordion'}).mount('#payment-element');

  function afficherErreur(message) {
    errorBox.textContent = message || '';
    button.disabled = false;
    label.textContent = 'Payer <?= e(euros($montant)) ?>';
  }

  form.addEventListener('submit', async function (event) {
    event.preventDefault();
    errorBox.textContent = '';
    button.disabled = true;
    label.textContent = 'Vérification…';

    var validation = await elements.submit();
    if (validation.error) {
      afficherErreur(validation.error.message);
      return;
    }

    var result = await stripe.confirmPayment({
      elements: elements,
      confirmParams: {return_url: returnUrl},
      redirect: 'if_required'
    });
    if (result.error) {
      afficherErreur(result.error.message);
      return;
    }
    if (result.paymentIntent) {
      window.location.assign(returnUrl + '&payment_intent=' + encodeURIComponent(result.paymentIntent.id));
    }
  });
}());
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
