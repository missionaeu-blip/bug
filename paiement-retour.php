<?php
/** Retour Stripe Payment Element — le PaymentIntent est relu côté serveur. */
require_once __DIR__ . '/includes/functions.php';
if (!is_logged()) { header('Location: ' . u('/connexion')); exit; }

$u = user();
$pid = (int) ($_GET['projet'] ?? 0);
$intent = (string) ($_GET['payment_intent'] ?? '');
if (!est_client($u['role']) || $pid <= 0) {
    header('Location: ' . u('/projets')); exit;
}

if ($intent !== '') {
    $confirmation = stripe_confirmer_retour_direct($pid, $intent, (int) $u['id']);
    if ($confirmation['ok']) {
        flash($confirmation['nouveau']
            ? "Paiement confirmé : votre mission démarre."
            : "Votre paiement a déjà été confirmé.", 'success');
    } elseif (!empty($confirmation['en_attente'])) {
        flash("Votre paiement est en cours de traitement. Ne recommencez pas le paiement : nous vous préviendrons dès sa confirmation.", 'info');
    } else {
        flash("Le paiement n'a pas été confirmé. Vous pouvez réessayer depuis le projet.", 'error');
    }
} else {
    flash("Retour de paiement incomplet. Aucun règlement n'a été confirmé.", 'error');
}
header('Location: ' . u('projet.php?id=' . $pid));
exit;
