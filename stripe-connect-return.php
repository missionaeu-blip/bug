<?php
/** Retour Stripe Hosted Onboarding : état relu auprès de Stripe, sans supposer le KYC terminé. */
require_once __DIR__ . '/includes/functions.php';
require_role('freelance');

$u = user(true);
$token = (string) ($_GET['jeton'] ?? '');
if (!connect_retour_valide($u, $token)) {
    flash("Ce retour Stripe n'est plus valide. Reprenez la configuration depuis votre profil.", 'error');
    header('Location: ' . u('/profil?onglet=versements')); exit;
}

$etat = connect_rafraichir($u);
if ($etat['ok'] && $etat['charges']) {
    flash("Votre compte Stripe est prêt : vous pouvez recevoir des paiements par carte.", 'success');
} elseif ($etat['ok']) {
    $complement = $etat['attente'] ? ' Certaines informations restent à fournir à Stripe.' : '';
    flash("Votre dossier Stripe est enregistré et reste en cours de vérification." . $complement, 'info');
} else {
    flash("Stripe n'a pas encore pu confirmer l'état du compte. Réessayez dans quelques instants.", 'info');
}
header('Location: ' . u('/profil?onglet=versements')); exit;
