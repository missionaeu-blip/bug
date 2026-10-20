<?php
/** Régénère un Account Link Stripe expiré ou déjà utilisé. */
require_once __DIR__ . '/includes/functions.php';
require_role('freelance');

$u = user(true);
$token = (string) ($_GET['jeton'] ?? '');
if (!connect_retour_valide($u, $token)) {
    flash("Ce lien Stripe a expiré. Relancez la configuration depuis votre profil.", 'error');
    header('Location: ' . u('/profil?onglet=versements')); exit;
}

$lien = connect_lien_onboarding($u);
if (!$lien['ok']) {
    flash("Impossible de rouvrir Stripe pour le moment : " . $lien['erreur'], 'error');
    header('Location: ' . u('/profil?onglet=versements')); exit;
}
header('Location: ' . $lien['url'], true, 303);
exit;
