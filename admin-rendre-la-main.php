<?php
/**
 * Missiona — Fin d'une session de dépannage
 * Rend la main au compte d'origine.
 */
require_once __DIR__ . '/includes/functions.php';
if (emprunt_actif()) {
    emprunt_arreter();
    flash("Vous êtes de nouveau connecté sur votre compte.", 'success');
}
header('Location: ' . u('/admin-suivi-equipe'));
exit;
