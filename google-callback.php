<?php
/**
 * Missiona — Retour de Google après authentification
 * ------------------------------------------------------------------
 * Trois cas possibles :
 *   1. Le compte Google est déjà rattaché  -> connexion directe.
 *   2. L'adresse existe déjà sur le site   -> rattachement puis connexion.
 *   3. Aucun compte                        -> on demande le type de compte
 *      et le consentement, sur google-inscription.php.
 */
require_once __DIR__ . '/includes/functions.php';

if (!google_actif()) {
    flash("La connexion Google n'est pas configurée sur ce site.", 'error');
    header('Location: ' . u('/connexion')); exit;
}

// L'utilisateur a refusé, ou Google a renvoyé une erreur.
if (!empty($_GET['error'])) {
    flash("Connexion Google interrompue.", 'error');
    header('Location: ' . u('/connexion')); exit;
}

// Jeton anti-CSRF : sans lui, un tiers pourrait forcer une connexion.
if (!google_state_valide($_GET['state'] ?? '')) {
    flash("Session expirée, merci de recommencer.", 'error');
    header('Location: ' . u('/connexion')); exit;
}

$code = trim((string) ($_GET['code'] ?? ''));
if ($code === '') {
    flash("Réponse incomplète de Google.", 'error');
    header('Location: ' . u('/connexion')); exit;
}

$jeton = google_echange_code($code);
if (!$jeton['ok'] || empty($jeton['data']['access_token'])) {
    flash("Google n'a pas validé la connexion : " . $jeton['error'], 'error');
    header('Location: ' . u('/connexion')); exit;
}

$profil = google_profil($jeton['data']['access_token']);
if (!$profil['ok']) {
    flash("Impossible de lire votre profil Google.", 'error');
    header('Location: ' . u('/connexion')); exit;
}

$p     = $profil['data'];
$sub   = (string) ($p['sub'] ?? '');
$email = strtolower(trim((string) ($p['email'] ?? '')));

// Une adresse non vérifiée par Google ne prouve rien : la refuser
// évite qu'un tiers s'empare d'un compte en déclarant votre adresse.
$verifiee = !empty($p['email_verified']) && $p['email_verified'] !== 'false';

if ($sub === '' || $email === '' || !$verifiee) {
    flash("Votre adresse Google n'est pas vérifiée : connexion refusée.", 'error');
    header('Location: ' . u('/connexion')); exit;
}

$u = google_trouver_compte($sub, $email);

/* ---------- Compte existant ---------- */
if ($u) {
    if ((int) $u['actif'] !== 1) {
        flash("Ce compte est désactivé. Contactez-nous.", 'error');
        header('Location: ' . u('/connexion')); exit;
    }

    // Premier passage par Google sur un compte créé par mot de passe :
    // on rattache l'identifiant Google. L'adresse ayant été vérifiée
    // par Google, il s'agit bien du même propriétaire.
    if (empty($u['google_id'])) {
        // Google vient de confirmer cette adresse ; le rattachement valide
        // aussi l'e-mail d'un éventuel compte créé auparavant par mot de passe.
        db()->prepare("UPDATE users SET google_id = ?, email_verifie = 1 WHERE id = ?")
            ->execute([$sub, $u['id']]);
    }
    if (empty($u['avatar_url']) && !empty($p['picture'])) {
        db()->prepare("UPDATE users SET avatar_url = ? WHERE id = ?")
            ->execute([(string) $p['picture'], $u['id']]);
    }

    google_ouvrir_session($u['id']);
    unset($_SESSION['google_role']);
    header('Location: ' . u('/' . dashboard_url($u['role'])));
    exit;
}

/* ---------- Nouveau venu ---------- */
// On ne crée rien tout de suite : il manque le type de compte et le
// consentement. Les données sont mises de côté le temps de l'étape.
$_SESSION['google_inscription'] = [
    'sub'     => $sub,
    'email'   => $email,
    'prenom'  => (string) ($p['given_name'] ?? ''),
    'nom'     => (string) ($p['family_name'] ?? ''),
    'picture' => (string) ($p['picture'] ?? ''),
    'expire'  => time() + 1800,
];

header('Location: ' . u('/google-inscription'));
exit;
