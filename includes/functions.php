<?php
// Missiona - Fonctions utilitaires, sessions, sécurité, matching
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/stripe.php';
require_once __DIR__ . '/stripe-connect.php';

if (session_status() === PHP_SESSION_NONE) {
    // Nom de session court et uniforme : c'est aussi le paramètre d'URL
    // utilisé en repli quand le navigateur bloque les cookies (?sid=...).
    session_name('sid');

    // Le cookie de session n'est envoyé lors des navigations inter-sites que
    // selon la politique SameSite=Lax, tout en restant Secure en HTTPS.
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $local = (strpos($host, 'localhost') === 0) || (strpos($host, '127.0.0.1') === 0);
    // Hors développement local, le site est servi en HTTPS : le cookie reste
    // Secure et HttpOnly ; SameSite=Lax limite son envoi inter-sites.
    $https = !$local
          || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
          || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'secure'   => $https,
        'samesite' => 'Lax',
    ]);
    // Repli : si le navigateur bloque les cookies, l'identifiant de session est
    // relayé par l'URL. PHP le reprend alors automatiquement.
    $sid = $_GET['sid'] ?? $_POST['sid'] ?? '';
    if (empty($_COOKIE[session_name()]) && $sid
        && preg_match('/^[A-Za-z0-9,\-]{20,128}$/', $sid)) {
        session_id($sid);
        // Le cookie est refusé : PHP réécrit lui-même TOUS les liens et
        // formulaires internes pour y propager l'identifiant de session.
        // Indispensable pour les liens construits dynamiquement
        // (ex. admin-matching.php?id=12), que l'on ne peut pas traiter un à un.
        ini_set('session.use_cookies',      '0');
        ini_set('session.use_only_cookies', '0');
        ini_set('session.use_trans_sid',    '1');
        ini_set('session.trans_sid_tags',   'a=href,area=href,frame=src,form=');
        $h = $_SERVER['HTTP_HOST'] ?? '';
        if ($h !== '') { ini_set('session.trans_sid_hosts', $h); }
        @ini_set('url_rewriter.tags',       'a=href,area=href,frame=src,form=');
    }
    session_start();
}

/**
 * URL d'un fichier statique suffixée par sa date de modification.
 * Le navigateur recharge automatiquement la feuille de style ou le script
 * dès qu'on les modifie, sans avoir à vider le cache manuellement.
 */
function asset($chemin) {
    // Sert la version minifiée quand elle existe et qu'elle est à jour
    $min = preg_replace('/\.(css|js)$/', '.min.$1', $chemin);
    if ($min !== $chemin) {
        $aMin = __DIR__ . '/../' . ltrim($min, '/');
        $aSrc = __DIR__ . '/../' . ltrim($chemin, '/');
        if (is_file($aMin) && (!is_file($aSrc) || filemtime($aMin) >= filemtime($aSrc))) {
            $chemin = $min;
        }
    }
    $abs = __DIR__ . '/../' . ltrim($chemin, '/');
    $v   = is_file($abs) ? filemtime($abs) : time();
    return '/' . ltrim($chemin, '/') . '?v=' . $v;
}

/** Ajoute l'identifiant de session à une URL quand le cookie est refusé. */
function u($url) {
    if (!empty($_COOKIE[session_name()]) || empty($_SESSION['uid'])) { return $url; }
    if (strpos($url, 'sid=') !== false || strpos($url, '://') !== false) { return $url; }
    return $url . (strpos($url, '?') === false ? '?' : '&') . 'sid=' . urlencode(session_id());
}

// Initialise la base et les données de démonstration dès le premier accès au site
db();

/* ---------- Compatibilité : repli si l'extension mbstring est absente ---------- */
if (!function_exists('mb_substr')) {
    function mb_substr($s, $start, $length = null, $enc = null) {
        return $length === null ? substr((string)$s, $start) : substr((string)$s, $start, $length);
    }
}
if (!function_exists('mb_strlen'))   { function mb_strlen($s, $e = null)   { return strlen((string)$s); } }
if (!function_exists('mb_strtolower')){ function mb_strtolower($s, $e = null){ return strtolower((string)$s); } }
if (!function_exists('mb_strtoupper')){ function mb_strtoupper($s, $e = null){ return strtoupper((string)$s); } }
if (!function_exists('mb_str_split')) {
    function mb_str_split($s, $n = 1, $e = null) { return str_split((string)$s, $n); }
}

/* ---------- Sécurité / helpers ---------- */
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** Clé de signature, persistée dans /data (hors racine web). */
function csrf_secret() {
    static $k = null;
    if ($k !== null) return $k;
    $f = __DIR__ . '/../data/.csrf_key';
    if (is_file($f)) { $k = trim(file_get_contents($f)); }
    if (empty($k)) {
        $k = bin2hex(random_bytes(32));
        if (!is_dir(dirname($f))) { @mkdir(dirname($f), 0755, true); }
        @file_put_contents($f, $k);
        @chmod($f, 0600);
    }
    return $k;
}

/**
 * Jeton CSRF signé (HMAC) et horodaté.
 * Il ne dépend PAS de la session : les formulaires restent utilisables même
 * quand le navigateur refuse les cookies (aperçu en iframe, cookies tiers
 * bloqués). La signature empêche toute falsification.
 */
function csrf_token() {
    $t = time();
    return $t . '.' . hash_hmac('sha256', (string)$t, csrf_secret());
}
function csrf_field() {
    $h = '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    // Relaie aussi la session quand le cookie est bloqué
    if (empty($_COOKIE[session_name()]) && !empty($_SESSION['uid'])) {
        $h .= '<input type="hidden" name="sid" value="' . htmlspecialchars(session_id(), ENT_QUOTES) . '">';
    }
    return $h;
}
function csrf_valide($jeton) {
    if (!is_string($jeton) || strpos($jeton, '.') === false) { return false; }
    [$t, $sig] = explode('.', $jeton, 2);
    if (!ctype_digit($t)) { return false; }
    if (abs(time() - (int)$t) > 86400) { return false; }          // valable 24 h
    return hash_equals(hash_hmac('sha256', $t, csrf_secret()), $sig);
}

function csrf_check() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { return; }
    if (csrf_valide($_POST['csrf'] ?? '')) { return; }

    // Échec : le plus souvent la session a expiré ou le navigateur refuse le cookie.
    http_response_code(400);
    $retour = htmlspecialchars($_SERVER['HTTP_REFERER'] ?? 'index.php', ENT_QUOTES);
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>Session expirée</title>'
       . '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;'
       . 'background:#F8FAFC;color:#0F172A;display:grid;place-items:center;min-height:100vh;margin:0;padding:24px}'
       . '.b{max-width:460px;background:#fff;border:1px solid #E2E8F0;border-radius:16px;padding:32px;text-align:center}'
       . 'h1{font-size:1.25rem;margin:0 0 10px}p{color:#64748B;font-size:.9375rem;line-height:1.6;margin:0 0 22px}'
       . 'a{display:inline-block;padding:12px 22px;background:#165DFF;color:#fff;border-radius:10px;'
       . 'text-decoration:none;font-weight:600;font-size:.9375rem}</style></head><body><div class="b">'
       . '<div style="font-size:2.5rem;margin-bottom:12px">⏱️</div>'
       . '<h1>Votre session a expiré</h1>'
       . '<p>Par sécurité, le formulaire a été refusé car votre session n\'était plus active. '
       . 'Cela arrive après une longue inactivité, ou si votre navigateur bloque les cookies. '
       . 'Il suffit de recommencer.</p>'
       . '<a href="' . $retour . '">Réessayer</a>'
       . '</div></body></html>';
    exit;
}

/* ---------- Authentification ---------- */
function user($recharger = false) {
    if (empty($_SESSION['uid'])) return null;
    static $cache = null;
    if ($recharger) { $cache = null; }
    if ($cache && $cache['id'] == $_SESSION['uid']) return $cache;
    $st = db()->prepare("SELECT * FROM users WHERE id = ?");
    $st->execute([$_SESSION['uid']]);
    $cache = $st->fetch() ?: null;
    return $cache;
}
function is_logged() { return user() !== null; }
function role() { $u = user(); return $u ? $u['role'] : null; }

function require_role($roles) {
    $roles = (array)$roles;
    if (!is_logged()) { header('Location: ' . u('/connexion')); exit; }
    if (!in_array(role(), $roles, true)) { header('Location: ' . u('/')); exit; }
}

/** Les comptes créés avant l'arrivée du contrôle sont considérés vérifiés. */
function email_est_verifie($u) {
    return !is_array($u) || !array_key_exists('email_verifie', $u) || (int) $u['email_verifie'] === 1;
}

/** Mémorise le compte en attente sans faire circuler de données dans l'URL. */
function email_verification_ouvrir($user_id, $retour_projet = false) {
    $_SESSION['email_verification_user_id'] = (int) $user_id;
    $_SESSION['email_verification_retour_projet'] = (bool) $retour_projet;
}

/**
 * Empreinte rapide non réversible du code : le code court est protégé par la
 * clé secrète locale et par les limites strictes de tentatives. Contrairement
 * à password_hash(), elle ne ralentit pas inutilement la validation écran.
 */
function email_verification_empreinte_code($user_id, $code) {
    return hash_hmac('sha256', (int) $user_id . '|' . (string) $code, csrf_secret());
}

/** Génère un code temporaire, ne conserve que son empreinte et l'envoie. */
function email_verification_envoyer($user_id, $ignorer_delai = false) {
    $st = db()->prepare("SELECT id, email, prenom, nom, email_verifie, email_verif_envoi_le,
                                 email_verif_envois, email_verif_fenetre_le
                          FROM users WHERE id = ? AND actif = 1");
    $st->execute([(int) $user_id]);
    $u = $st->fetch();
    if (!$u || email_est_verifie($u)) {
        return ['ok' => false, 'message' => "Cette vérification n'est plus nécessaire."];
    }

    $maintenant = time();
    $dernier = !empty($u['email_verif_envoi_le']) ? strtotime($u['email_verif_envoi_le']) : 0;
    if (!$ignorer_delai && $dernier && ($maintenant - $dernier) < 60) {
        return ['ok' => false, 'message' => "Patientez une minute avant de demander un nouveau code."];
    }

    // Au plus cinq e-mails de code par heure et par compte : le délai court
    // empêche le martelage, la fenêtre empêche la saturation d'une boîte.
    $fenetre = !empty($u['email_verif_fenetre_le']) ? strtotime($u['email_verif_fenetre_le']) : 0;
    $envois = (int) ($u['email_verif_envois'] ?? 0);
    if (!$fenetre || ($maintenant - $fenetre) >= 3600) { $fenetre = 0; $envois = 0; }
    if ($envois >= 5) {
        return ['ok' => false, 'message' => "Trop de codes ont été demandés. Réessayez dans une heure."];
    }

    // Six chiffres tirés par le générateur cryptographiquement sûr de PHP.
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    db()->prepare("UPDATE users
                   SET email_verif_code_hash = ?,
                       email_verif_expire_at = datetime('now', '+15 minutes'),
                       email_verif_essais = 0,
                       email_verif_envoi_le = CURRENT_TIMESTAMP,
                       email_verif_envois = ?,
                       email_verif_fenetre_le = CASE WHEN ? = 1 THEN CURRENT_TIMESTAMP ELSE email_verif_fenetre_le END,
                       email_verifie = 0
                   WHERE id = ?")
        ->execute([
            email_verification_empreinte_code($u['id'], $code), $envois + 1,
            $fenetre ? 0 : 1, (int) $u['id'],
        ]);

    $envoi = email_code_verification($u['email'], $u['prenom'] ?: $u['nom'], $code);
    if (!$envoi['ok']) {
        // Le code n'est jamais affiché dans l'interface ou les logs : l'erreur
        // technique est déjà journalisée par email_envoyer().
        return ['ok' => false, 'message' => "L'envoi du code n'a pas abouti. Réessayez dans un instant."];
    }
    return ['ok' => true, 'message' => "Un code à 6 chiffres vient d'être envoyé."];
}

/** Contrôle le code, son délai et le nombre de tentatives. */
function email_verification_valider($user_id, $code) {
    $code = preg_replace('/\D/', '', (string) $code);
    if (!preg_match('/^\d{6}$/', $code)) {
        return ['ok' => false, 'message' => "Saisissez les 6 chiffres du code."];
    }

    $st = db()->prepare("SELECT * FROM users WHERE id = ? AND actif = 1");
    $st->execute([(int) $user_id]);
    $u = $st->fetch();
    if (!$u) { return ['ok' => false, 'message' => "Votre session a expiré. Connectez-vous à nouveau."]; }
    if (email_est_verifie($u)) { return ['ok' => true, 'user' => $u]; }

    if ((int) ($u['email_verif_essais'] ?? 0) >= 5) {
        return ['ok' => false, 'message' => "Trop d'essais. Demandez un nouveau code."];
    }
    if (empty($u['email_verif_code_hash']) || empty($u['email_verif_expire_at'])
        || strtotime($u['email_verif_expire_at']) < time()) {
        return ['ok' => false, 'message' => "Ce code a expiré. Demandez-en un nouveau."];
    }

    $empreinte = email_verification_empreinte_code($u['id'], $code);
    $valide = hash_equals((string) $u['email_verif_code_hash'], $empreinte);
    // Compatibilité de très courte durée avec un code généré juste avant
    // cette mise à jour (ancien stockage password_hash, valable 15 minutes).
    if (!$valide && str_starts_with((string) $u['email_verif_code_hash'], '$')) {
        $valide = password_verify($code, $u['email_verif_code_hash']);
    }
    if (!$valide) {
        db()->prepare("UPDATE users SET email_verif_essais = email_verif_essais + 1 WHERE id = ?")
            ->execute([(int) $u['id']]);
        return ['ok' => false, 'message' => "Ce code n'est pas valide."];
    }

    db()->prepare("UPDATE users
                   SET email_verifie = 1, email_verif_code_hash = '',
                       email_verif_expire_at = '', email_verif_essais = 0
                   WHERE id = ?")
        ->execute([(int) $u['id']]);
    $u['email_verifie'] = 1;
    return ['ok' => true, 'user' => $u];
}

/** Programme le guide une seule fois, juste après une authentification réussie. */
function guide_premiere_connexion_programmer($u) {
    if (!is_array($u) || empty($u['id'])) { return; }
    if (empty($u['guide_premiere_connexion_vu'])) {
        $_SESSION['guide_premiere_connexion'] = (int) $u['id'];
    }
}

function login($email, $password) {
    $st = db()->prepare("SELECT * FROM users WHERE email = ? AND actif = 1");
    $st->execute([strtolower(trim($email))]);
    $u = $st->fetch();
    if ($u && password_verify($password, $u['password_hash'])) {
        if (!email_est_verifie($u)) {
            // On ne connecte jamais un compte non vérifié, mais on retient
            // l'identité le temps strictement nécessaire à la saisie du code.
            email_verification_ouvrir($u['id']);
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['uid'] = $u['id'];
        guide_premiere_connexion_programmer($u);
        return $u;
    }
    return false;
}
function logout() { $_SESSION = []; session_destroy(); }

/* ---------- Réinitialisation de mot de passe (par e-mail) ---------- */

/**
 * Crée un jeton de réinitialisation pour un compte et envoie le lien.
 * Renvoie toujours true, que le compte existe ou non, pour ne pas
 * révéler quelles adresses sont inscrites.
 */
function mdp_demander_reinit($email) {
    $email = strtolower(trim((string) $email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { return true; }

    $st = db()->prepare("SELECT * FROM users WHERE email = ? AND actif = 1");
    $st->execute([$email]);
    $u = $st->fetch();
    if (!$u) { return true; }

    // Un jeton aléatoire fort ; seul son condensat est stocké.
    $jeton = bin2hex(random_bytes(32));
    db()->prepare("INSERT INTO reinit_mdp (user_id, jeton_hash, expire_at)
                    VALUES (?, ?, datetime('now', '+1 hour'))")
        ->execute([(int) $u['id'], hash('sha256', $jeton)]);

    $lien = email_url('/mdp-reinitialiser.php?jeton=' . $jeton);
    $sujet = "Réinitialisation de votre mot de passe Missiona";
    $html  = email_gabarit("Réinitialisation de votre mot de passe",
        "<p>Bonjour,</p>"
      . "<p>Une demande de réinitialisation du mot de passe de votre compte Missiona a été faite. "
      . "Cliquez sur le lien ci-dessous pour choisir un nouveau mot de passe :</p>"
      . '<p style="margin:20px 0;"><a href="' . e($lien) . '" style="display:inline-block;background:#0b1c3d;color:#ffffff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:bold;">Choisir un nouveau mot de passe</a></p>'
      . "<p style=\"color:#6b7280;font-size:13px;\">Ce lien est valable 1 heure. Si vous n'êtes pas à l'origine de cette demande, "
      . "vous pouvez ignorer cet e-mail : votre mot de passe reste inchangé.</p>");
    email_envoyer($email, $sujet, $html);
    return true;
}

/** Vérifie un jeton ; renoie la ligne (user_id compris) ou false. */
function mdp_verifier_jeton($jeton) {
    if (!preg_match('/^[a-f0-9]{64}$/', (string) $jeton)) { return false; }
    $st = db()->prepare("SELECT * FROM reinit_mdp
                         WHERE jeton_hash = ? AND utilise = 0
                           AND expire_at > datetime('now')");
    $st->execute([hash('sha256', $jeton)]);
    return $st->fetch() ?: false;
}

/** Applique le nouveau mot de passe puis invalide tous les jetons du compte. */
function mdp_utiliser_jeton($ligne, $nouveau_mdp) {
    db()->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
        ->execute([password_hash($nouveau_mdp, PASSWORD_DEFAULT), (int) $ligne['user_id']]);
    db()->prepare("UPDATE reinit_mdp SET utilise = 1 WHERE user_id = ?")
        ->execute([(int) $ligne['user_id']]);
    return true;
}

/**
 * Le compte administrateur utilise-t-il encore le mot de passe livré
 * par défaut ? Tant que c'est le cas, n'importe qui connaissant le
 * logiciel peut entrer : on le signale de façon très visible.
 */
/**
 * L'application installable est-elle proposée à cet utilisateur ?
 * ------------------------------------------------------------------
 * Par défaut, elle est réservée à l'administration : tant que le
 * service n'est pas ouvert, inutile que des visiteurs installent une
 * application qu'ils ne pourraient pas encore utiliser.
 *
 * Le réglage « Application mobile » du back-office permet de l'ouvrir
 * à tous le moment venu, sans toucher au code.
 */
function app_mobile_visible($u = null) {
    if ((int) param('app_mobile_public', 0) === 1) { return true; }
    $u = $u ?: user();
    return $u && ($u['role'] ?? '') === 'admin';
}

function mot_de_passe_par_defaut($u = null) {
    $u = $u ?: user();
    if (!$u || ($u['role'] ?? '') !== 'admin') { return false; }
    if (!defined('ADMIN_PASSWORD') || ADMIN_PASSWORD === '')  { return false; }
    return password_verify(ADMIN_PASSWORD, (string) $u['password_hash']);
}

function dashboard_url($role = null, $avec_sid = false) {
    $role = $role ?: role();
    $url = match ($role) {
        'admin'                     => 'admin.php',
        'entreprise', 'particulier' => 'dashboard-entreprise.php',
        'freelance'                 => 'dashboard-freelance.php',
        default                     => 'index.php',
    };
    return $avec_sid ? u($url) : $url;
}

/** Un client est une entreprise OU un particulier : même parcours, même espace. */
function est_client($role = null) {
    $role = $role ?: role();
    return in_array($role, ['entreprise', 'particulier'], true);
}

/** Libellé lisible d'un rôle */
function role_label($role) {
    return ['entreprise'=>'Entreprise','particulier'=>'Particulier','freelance'=>'Freelance','admin'=>'Administration'][$role] ?? ucfirst($role);
}

/* ---------- Données ---------- */
function flash($msg = null, $type = 'success') {
    if ($msg !== null) { $_SESSION['flash'] = ['m' => $msg, 't' => $type]; return; }
    if (!empty($_SESSION['flash'])) { $f = $_SESSION['flash']; unset($_SESSION['flash']); return $f; }
    return null;
}

/* ─────────────────────────────────────────────────────────────
   PAIEMENT DIRECT CLIENT → EXPERT
   L'entreprise règle l'expert directement, au montant convenu :
   Missiona n'encaisse rien. La rémunération de Missiona (commission
   d'apport d'affaires) est facturée à l'expert, et n'est exigible
   qu'une fois le client réglé. Aucun frais fixe : le forfait de
   service a été supprimé le 2 octobre 2026.
   ───────────────────────────────────────────────────────────── */

/**
 * Mode gratuit : Missiona renonce à sa propre rémunération.
 * ------------------------------------------------------------------
 * Le client règle TOUJOURS l'expert directement, hors plateforme —
 * Missiona n'encaisse jamais. Ce mode suspend en plus la facturation
 * de la commission d'apport d'affaires que Missiona adresse
 * normalement à l'expert.
 *
 * Utile en phase de lancement ou pour un test sans facturation.
 */
function mode_gratuit() { return (int) param('mode_gratuit', 0) === 1; }

/** Taux de commission d'apport d'affaires en % (paramétrable dans l'admin). */
function taux_frais() { return mode_gratuit() ? 0.0 : (float) param('commission', 20); }

/**
 * Historique : frais de dossier facturés à l'entreprise dans l'ancien
 * modèle. Conservé pour la lecture d'anciennes données ; le modèle en
 * vigueur est le paiement direct (aucun frais côté entreprise).
 */
function frais_dossier() {
    if (mode_gratuit()) { return 0; }
    return defined('FRAIS_DOSSIER') ? (int) FRAIS_DOSSIER : 2;
}

/**
 * Commission d'un règlement direct, facturée séparément à l'expert.
 * Le taux est appliqué au prix que son client lui a réglé directement.
 */
function commission_reglement_direct($base) {
    $base = max(0, (int) $base);
    return (int) round($base * (taux_frais() / 100));
}

/**
 * Décompose un total carte Stripe Connect à partir de la rémunération expert.
 * Le taux correspond à la part Missiona du total payé par le client : à 20 %,
 * une base expert de 200 € donne donc 250 € = 200 € + 50 €.
 * En règlement hors Stripe, le client règle seulement la base et la commission
 * est calculée par commission_reglement_direct(), puis facturée à l'expert.
 * @param int $base Montant revenant au freelance (son prix).
 * @return array{base:int,part:int,total:int}
 */
function decomposer_montant($base) {
    $base = max(0, (int) $base);
    $taux = max(0.0, min(99.0, taux_frais()));
    if ($base === 0 || $taux <= 0) { return ['base' => $base, 'part' => 0, 'total' => $base]; }
    $total = (int) round($base * 100 / (100 - $taux));
    $part = max(0, $total - $base);
    return ['base' => $base, 'part' => $part, 'total' => $total];
}

/** Les deux paiements d'un projet, indexés par type. */
function paiements_projet($projet_id) {
    $st = db()->prepare("SELECT * FROM paiements WHERE projet_id=? ORDER BY id");
    $st->execute([$projet_id]);
    $out = [];
    foreach ($st->fetchAll() as $r) { $out[$r['type']] = $r; }
    return $out;
}

/** Crée l'échéance demandée si elle n'existe pas déjà. */
function creer_paiement($projet_id, $type, $montant, $statut = 'a_payer') {
    $st = db()->prepare("SELECT COUNT(*) FROM paiements WHERE projet_id=? AND type=?");
    $st->execute([$projet_id, $type]);
    if ($st->fetchColumn()) { return false; }
    db()->prepare("INSERT INTO paiements (projet_id,type,montant,statut) VALUES (?,?,?,?)")
        ->execute([$projet_id, $type, (int) $montant, $statut]);
    return true;
}

/**
 * Enregistre un règlement.
 * En paiement direct, la ligne « projet » passe à « paye » lorsque
 * l'entreprise confirme avoir réglé l'expert. L'ancienne ligne « frais »
 * (héritée) resterait « en suspens » jusqu'à la livraison.
 */
function regler_paiement($projet_id, $type, $stripe_ref = '') {
    $statut = ($type === 'frais') ? 'en_suspens' : 'paye';
    db()->prepare("UPDATE paiements SET statut=?, paye_le=CURRENT_TIMESTAMP, reference=?, stripe_ref=? WHERE projet_id=? AND type=? AND statut='a_payer'")
        ->execute([
            $statut,
            strtoupper($type[0]) . '-' . date('Ymd') . '-' . str_pad($projet_id, 4, '0', STR_PAD_LEFT),
            $stripe_ref, $projet_id, $type,
        ]);
}

/**
 * Met à jour le montant de l'échéance « projet » tant qu'elle n'est pas réglée.
 * Indispensable : le montant fixé à l'attribution est une estimation basée sur
 * le tarif de l'expert ; l'équipe arrête le montant réel à la livraison.
 *
 * En paiement direct historique, l'entreprise règle la base à l'expert et la
 * commission est facturée séparément à celui-ci. Seul Stripe Connect ajoute la
 * commission au total carte afin de la prélever comme application fee.
 */
function maj_montant_projet($projet_id, $base) {
    $base = max(0, (int) $base);
    $d = decomposer_montant($base);
    $lecture = db()->prepare("SELECT id,stripe_direct FROM paiements WHERE projet_id=? AND type='projet' AND statut='a_payer'");
    $lecture->execute([$projet_id]);
    $paiement = $lecture->fetch();
    if ($paiement) {
        $montant = !empty($paiement['stripe_direct']) ? $d['total'] : $base;
        db()->prepare("UPDATE paiements SET montant=? WHERE id=? AND statut='a_payer'")
            ->execute([$montant, (int) $paiement['id']]);
    } else {
        // Sans échéance existante, aucun compte Connect n'est encore associé :
        // le circuit par défaut est donc le règlement direct de la base.
        creer_paiement($projet_id, 'projet', $base, 'a_payer');
    }
    return $d;
}

/* ─────────────────────────────────────────────────────────────
   ÉQUIPE MISSIONA
   ───────────────────────────────────────────────────────────── */

/**
 * L'administrateur principal : le compte dont l'e-mail est ADMIN_EMAIL.
 * Lui seul gère les comptes admin et les assignations. À défaut (compte
 * renommé ou supprimé), on retombe sur le plus ancien admin.
 */
function admin_principal() {
    static $p = null;
    if ($p !== null) { return $p; }
    if (defined('ADMIN_EMAIL')) {
        $st = db()->prepare("SELECT * FROM users WHERE role='admin' AND email=? LIMIT 1");
        $st->execute([ADMIN_EMAIL]);
        if ($r = $st->fetch()) { return $p = $r; }
    }
    $r = db()->query("SELECT * FROM users WHERE role='admin' ORDER BY id LIMIT 1")->fetch();
    return $p = ($r ?: null);
}

/** L'utilisateur donné est-il l'administrateur principal ? */
function est_admin_principal($u = null) {
    $u = $u ?: user();
    if (!$u || ($u['role'] ?? '') !== 'admin') { return false; }
    $p = admin_principal();
    return $p && (int) $p['id'] === (int) $u['id'];
}

/** Réservé à l'administrateur principal : redirige les autres. */
function require_admin_principal() {
    require_role('admin');
    if (!est_admin_principal()) {
        flash("Cette action est réservée à l'administrateur principal.", 'error');
        header('Location: ' . u('/admin')); exit;
    }
}

/**
 * Cet admin peut-il MODIFIER ce projet ?
 * Le principal peut tout ; un gestionnaire n'agit que sur ses clients.
 * Un projet sans gestionnaire assigné reste ouvert à toute l'équipe.
 */
function peut_gerer_projet($projet, $u = null) {
    $u = $u ?: user();
    if (!$u || ($u['role'] ?? '') !== 'admin') { return false; }
    if (est_admin_principal($u))       { return true; }
    $st = db()->prepare("SELECT gestionnaire_id FROM users WHERE id=?");
    $st->execute([(int) ($projet['entreprise_id'] ?? 0)]);
    $g = (int) $st->fetchColumn();
    return $g === 0 || $g === (int) $u['id'];
}

/** Idem pour un client donné (entreprise/particulier). */
function peut_gerer_client($client_id, $u = null) {
    $u = $u ?: user();
    if (!$u || ($u['role'] ?? '') !== 'admin') { return false; }
    if (est_admin_principal($u))       { return true; }
    $st = db()->prepare("SELECT gestionnaire_id FROM users WHERE id=?");
    $st->execute([(int) $client_id]);
    $g = (int) $st->fetchColumn();
    return $g === 0 || $g === (int) $u['id'];
}

/** Message affiché quand la modification est refusée. */
function refus_gestion() {
    return "Ce client est suivi par un autre gestionnaire : vous pouvez consulter, pas modifier.";
}

/** Les entreprises/particuliers suivis par un gestionnaire. */
function clients_du_gestionnaire($admin_id) {
    $st = db()->prepare("SELECT * FROM users
                         WHERE role IN ('entreprise','particulier') AND gestionnaire_id=?
                         ORDER BY societe, nom");
    $st->execute([(int) $admin_id]);
    return $st->fetchAll();
}

/** Assigne un client à un gestionnaire (0 = aucun). */
function assigner_gestionnaire($client_id, $admin_id) {
    $admin_id = (int) $admin_id;
    if ($admin_id !== 0) {
        $st = db()->prepare("SELECT COUNT(*) FROM users WHERE id=? AND role='admin'");
        $st->execute([$admin_id]);
        if (!$st->fetchColumn()) { return false; }
    }
    $st = db()->prepare("UPDATE users SET gestionnaire_id=? WHERE id=? AND role IN ('entreprise','particulier')");
    $st->execute([$admin_id, (int) $client_id]);
    return $st->rowCount() > 0;
}

/** Admins éligibles à la répartition automatique (principal inclus selon le réglage). */
function gestionnaires_repartition() {
    $rows = db()->query("SELECT id, prenom, nom, email FROM users WHERE role='admin' AND actif=1 ORDER BY id")->fetchAll();
    if (!$rows) { return []; }
    if (!(int) param('repartition_inclut_principal', 1)) {
        $princ = admin_principal();
        if ($princ) {
            $sans = array_values(array_filter($rows, fn($r) => (int) $r['id'] !== (int) $princ['id']));
            if ($sans) { return $sans; }
        }
    }
    return $rows;
}

/** Nombre de projets actifs suivis par chaque admin éligible : [admin_id => total]. */
function charge_gestionnaires() {
    $charge = [];
    foreach (gestionnaires_repartition() as $g) { $charge[(int) $g['id']] = 0; }
    if (!$charge) { return []; }
    $sql = "SELECT c.gestionnaire_id AS gid, COUNT(p.id) AS n
            FROM projets p JOIN users c ON c.id = p.entreprise_id
            WHERE c.gestionnaire_id > 0 AND p.statut NOT IN ('termine','annule')
            GROUP BY c.gestionnaire_id";
    foreach (db()->query($sql) as $r) {
        $gid = (int) $r['gid'];
        if (isset($charge[$gid])) { $charge[$gid] = (int) $r['n']; }
    }
    return $charge;
}

/** Gestionnaire le moins chargé (égalité départagée par le moins de clients, puis par id). */
function gestionnaire_le_moins_charge() {
    $charge = charge_gestionnaires();
    if (!$charge) { return 0; }
    $best = 0; $bestN = PHP_INT_MAX; $bestC = PHP_INT_MAX;
    foreach ($charge as $gid => $n) {
        $st = db()->prepare("SELECT COUNT(*) FROM users WHERE gestionnaire_id=?");
        $st->execute([$gid]);
        $c = (int) $st->fetchColumn();
        if ($n < $bestN || ($n === $bestN && $c < $bestC)) { $best = $gid; $bestN = $n; $bestC = $c; }
    }
    return $best;
}

/** Assigne un client non suivi au gestionnaire le moins chargé. Renvoie l'id retenu ou 0. */
function auto_assigner_client($client_id) {
    if (!(int) param('repartition_auto', 1)) { return 0; }
    $st = db()->prepare("SELECT gestionnaire_id FROM users WHERE id=? AND role IN ('entreprise','particulier')");
    $st->execute([(int) $client_id]);
    $cur = $st->fetchColumn();
    if ($cur === false || (int) $cur > 0) { return 0; }
    $gid = gestionnaire_le_moins_charge();
    if ($gid && assigner_gestionnaire($client_id, $gid)) { return $gid; }
    return 0;
}

/** Rééquilibre tous les clients entre gestionnaires. $tout=false : seuls les clients non assignés. */
function repartir_clients($tout = false) {
    $gs = array_map(fn($g) => (int) $g['id'], gestionnaires_repartition());
    if (!$gs) { return 0; }
    $sql = "SELECT c.id, COUNT(CASE WHEN p.statut NOT IN ('termine','annule') THEN 1 END) AS n
            FROM users c LEFT JOIN projets p ON p.entreprise_id = c.id
            WHERE c.role IN ('entreprise','particulier')" . ($tout ? "" : " AND c.gestionnaire_id = 0") . "
            GROUP BY c.id ORDER BY n DESC, c.id";
    $clients = db()->query($sql)->fetchAll();
    if (!$clients) { return 0; }
    $charge = $tout ? array_fill_keys($gs, 0) : charge_gestionnaires();
    foreach ($gs as $g) { if (!isset($charge[$g])) { $charge[$g] = 0; } }
    $n = 0;
    db()->beginTransaction();
    try {
        foreach ($clients as $c) {
            asort($charge);
            $gid = (int) array_key_first($charge);
            if (assigner_gestionnaire($c['id'], $gid)) { $n++; }
            $charge[$gid] += max(1, (int) $c['n']);
        }
        db()->commit();
    } catch (Exception $ex) { db()->rollBack(); return 0; }
    return $n;
}

/** Gestionnaire d'un client, ou null. */
function gestionnaire_de($client) {
    $id = (int) ($client['gestionnaire_id'] ?? 0);
    if (!$id) { return null; }
    $st = db()->prepare("SELECT * FROM users WHERE id=? AND role='admin'");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/**
 * Nom du chargé de compte vu par le client : son gestionnaire assigné si
 * l'équipe lui en a désigné un, sinon le nom générique paramétrable.
 */
function charge_compte_nom($client = null) {
    $client = $client ?: user();
    if ($client && isset($client['gestionnaire_id']) && (int) $client['gestionnaire_id'] > 0) {
        if ($g = gestionnaire_de($client)) {
            $n = trim($g['prenom'] . ' ' . $g['nom']);
            if ($n !== '') { return $n; }
        }
    }
    $v = trim((string) param('charge_nom', ''));
    return $v !== '' ? $v : 'Sophie Dupont';
}

/** Intitulé du chargé de compte. */
function charge_compte_role($client = null) {
    $client = $client ?: user();
    if ($client && isset($client['gestionnaire_id']) && (int) $client['gestionnaire_id'] > 0 && gestionnaire_de($client)) {
        return 'Gestionnaire de projet';
    }
    $v = trim((string) param('charge_role', ''));
    return $v !== '' ? $v : 'Chargée de compte';
}

/** Initiales du chargé de compte, pour l'avatar. */
function charge_initiales($client = null) {
    $parts = preg_split('/\s+/', trim(charge_compte_nom($client)));
    $a = mb_substr($parts[0] ?? '', 0, 1);
    $b = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';
    return mb_strtoupper($a . $b) ?: 'WC';
}

/** Enregistre un paramètre. */
function set_param($cle, $valeur) {
    db()->prepare("INSERT INTO parametres (cle,valeur) VALUES (?,?)
                   ON CONFLICT(cle) DO UPDATE SET valeur=excluded.valeur")
        ->execute([$cle, (string) $valeur]);
}

/** Tous les comptes administrateurs. */
function admins() {
    return db()->query("SELECT * FROM users WHERE role='admin' ORDER BY id")->fetchAll();
}

/** Nombre de comptes admin encore actifs. */
function nb_admins() {
    return (int) db()->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
}

/**
 * Crée un compte administrateur.
 * @return array{ok:bool,erreur:string}
 */
function creer_admin($email, $mdp, $nom, $prenom = '') {
    $email = trim(mb_strtolower($email));
    $nom   = trim($nom);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'erreur' => "Adresse e-mail invalide."];
    }
    if (mb_strlen($mdp) < 8) {
        return ['ok' => false, 'erreur' => "Le mot de passe doit faire au moins 8 caractères."];
    }
    if ($nom === '') {
        return ['ok' => false, 'erreur' => "Le nom est obligatoire."];
    }
    $st = db()->prepare("SELECT COUNT(*) FROM users WHERE email=?");
    $st->execute([$email]);
    if ($st->fetchColumn()) {
        return ['ok' => false, 'erreur' => "Cette adresse est déjà utilisée."];
    }
    db()->prepare("INSERT INTO users (email,password_hash,role,nom,prenom) VALUES (?,?,'admin',?,?)")
        ->execute([$email, password_hash($mdp, PASSWORD_DEFAULT), $nom, trim($prenom)]);
    return ['ok' => true, 'erreur' => ''];
}

/**
 * Supprime un compte administrateur.
 * Refuse de supprimer le dernier admin ou soi-même.
 */
function supprimer_admin($id, $moi) {
    $id = (int) $id;
    if ($id === (int) $moi)  { return ['ok' => false, 'erreur' => "Vous ne pouvez pas supprimer votre propre compte."]; }
    $p = admin_principal();
    if ($p && $id === (int) $p['id']) {
        return ['ok' => false, 'erreur' => "L'administrateur principal ne peut pas être supprimé."];
    }
    if (nb_admins() <= 1)    { return ['ok' => false, 'erreur' => "Il doit rester au moins un administrateur."]; }
    $st = db()->prepare("SELECT COUNT(*) FROM users WHERE id=? AND role='admin'");
    $st->execute([$id]);
    if (!$st->fetchColumn()) { return ['ok' => false, 'erreur' => "Compte introuvable."]; }
    // Le compte est référencé par ses messages et notifications : on les
    // transfère au principal plutôt que d'échouer sur une clé étrangère.
    $repli = admin_principal();
    $repli = $repli ? (int) $repli['id'] : 0;
    if ($repli === $id || !$repli) {
        return ['ok' => false, 'erreur' => "Aucun compte de repli pour reprendre ses échanges."];
    }
    try {
        db()->beginTransaction();
        // Ses clients reviennent à l'équipe, ils ne restent pas orphelins.
        db()->prepare("UPDATE users SET gestionnaire_id=0 WHERE gestionnaire_id=?")->execute([$id]);
        // L'historique des échanges est conservé, rattaché au principal.
        db()->prepare("UPDATE messages SET expediteur_id=?   WHERE expediteur_id=?")->execute([$repli, $id]);
        db()->prepare("UPDATE messages SET destinataire_id=? WHERE destinataire_id=?")->execute([$repli, $id]);
        db()->prepare("DELETE FROM notifications WHERE user_id=?")->execute([$id]);
        db()->prepare("DELETE FROM users WHERE id=? AND role='admin'")->execute([$id]);
        db()->commit();
    } catch (Exception $e) {
        if (db()->inTransaction()) { db()->rollBack(); }
        return ['ok' => false, 'erreur' => "Suppression impossible : ce compte est encore rattaché à des données."];
    }
    return ['ok' => true, 'erreur' => ''];
}

/** Efface un fichier téléversé sans jamais sortir du dossier uploads. */
function supprimer_fichier_televerse($chemin) {
    $relatif = ltrim(str_replace('\\', '/', (string) $chemin), '/');
    if (!preg_match('#^(projets|messages)/[A-Za-z0-9._-]+$#', $relatif)) { return; }
    $racine = realpath(__DIR__ . '/../uploads');
    if (!$racine) { return; }
    $fichier = $racine . '/' . $relatif;
    if (is_file($fichier)) { @unlink($fichier); }
}

/**
 * Suppression irréversible d'un compte client ou freelance par l'équipe.
 * Les missions ouvertes bloquent l'opération : on évite de couper une
 * collaboration active. Les données liées aux missions closes sont purgées
 * avec le compte, y compris les documents qu'il a téléversés.
 *
 * @return array{ok:bool,erreur:string}
 */
function supprimer_utilisateur_admin($id, $admin = null) {
    $id = (int) $id;
    $admin = $admin ?: user();
    if (!$admin || ($admin['role'] ?? '') !== 'admin') {
        return ['ok' => false, 'erreur' => "Action réservée à l'administration."];
    }
    if ($id <= 0 || $id === (int) $admin['id']) {
        return ['ok' => false, 'erreur' => "Vous ne pouvez pas supprimer votre propre compte."];
    }

    $st = db()->prepare("SELECT * FROM users WHERE id = ?");
    $st->execute([$id]);
    $cible = $st->fetch();
    if (!$cible) { return ['ok' => false, 'erreur' => "Compte introuvable."]; }

    // Les comptes d'équipe suivent leur procédure dédiée, réservée au
    // principal, afin de préserver la continuité des échanges internes.
    if ($cible['role'] === 'admin') {
        if (!est_admin_principal($admin)) {
            return ['ok' => false, 'erreur' => "Seul l'administrateur principal peut supprimer un compte de l'équipe."];
        }
        return supprimer_admin($id, (int) $admin['id']);
    }
    if (!in_array($cible['role'], ['entreprise', 'particulier', 'freelance'], true)) {
        return ['ok' => false, 'erreur' => "Ce type de compte ne peut pas être supprimé ici."];
    }
    if (est_client($cible['role']) && !peut_gerer_client($id, $admin)) {
        return ['ok' => false, 'erreur' => refus_gestion()];
    }
    if ($cible['role'] === 'freelance' && !est_admin_principal($admin)) {
        return ['ok' => false, 'erreur' => "Seul l'administrateur principal peut supprimer un compte freelance."];
    }

    $actifs = db()->prepare("SELECT COUNT(*) FROM projets
                              WHERE (entreprise_id = ? OR freelance_id = ?)
                                AND statut NOT IN ('termine', 'annule')");
    $actifs->execute([$id, $id]);
    if ((int) $actifs->fetchColumn() > 0) {
        return ['ok' => false, 'erreur' => "Ce compte a un projet en cours. Suspendez-le ou clôturez les projets avant de le supprimer."];
    }

    $fichiers = [];
    try {
        db()->beginTransaction();

        // Les projets appartenant au client sont clos : ils peuvent être
        // purgés avec leurs paiements, messages, demandes et factures.
        $ps = db()->prepare("SELECT id FROM projets WHERE entreprise_id = ?");
        $ps->execute([$id]);
        $projets_client = array_map('intval', array_column($ps->fetchAll(), 'id'));
        foreach ($projets_client as $projet_id) {
            $qf = db()->prepare("SELECT chemin FROM fichiers WHERE projet_id = ?");
            $qf->execute([$projet_id]);
            $fichiers = array_merge($fichiers, array_column($qf->fetchAll(), 'chemin'));
            $qm = db()->prepare("SELECT fichier_chemin FROM messages WHERE projet_id = ? AND fichier_chemin <> ''");
            $qm->execute([$projet_id]);
            $fichiers = array_merge($fichiers, array_column($qm->fetchAll(), 'fichier_chemin'));
            supprimer_projet($projet_id);
        }

        // Pour un expert, les projets clients clos restent lisibles, mais
        // l'affectation et les traces personnelles de l'expert disparaissent.
        $qf = db()->prepare("SELECT chemin FROM fichiers WHERE uploader_id = ?");
        $qf->execute([$id]);
        $fichiers = array_merge($fichiers, array_column($qf->fetchAll(), 'chemin'));
        $qm = db()->prepare("SELECT fichier_chemin FROM messages
                             WHERE (expediteur_id = ? OR destinataire_id = ?) AND fichier_chemin <> ''");
        $qm->execute([$id, $id]);
        $fichiers = array_merge($fichiers, array_column($qm->fetchAll(), 'fichier_chemin'));

        db()->prepare("UPDATE projets SET freelance_id = NULL WHERE freelance_id = ?")->execute([$id]);
        db()->prepare("UPDATE users SET gestionnaire_id = 0 WHERE gestionnaire_id = ?")->execute([$id]);
        db()->prepare("DELETE FROM messages WHERE expediteur_id = ? OR destinataire_id = ?")->execute([$id, $id]);
        db()->prepare("DELETE FROM fichiers WHERE uploader_id = ?")->execute([$id]);
        db()->prepare("DELETE FROM portfolio WHERE user_id = ?")->execute([$id]);
        db()->prepare("DELETE FROM forfaits WHERE user_id = ?")->execute([$id]);
        db()->prepare("DELETE FROM notifications WHERE user_id = ?")->execute([$id]);
        db()->prepare("DELETE FROM reinit_mdp WHERE user_id = ?")->execute([$id]);
        db()->prepare("DELETE FROM push_abonnes WHERE user_id = ?")->execute([$id]);
        db()->prepare("DELETE FROM dac7_registre WHERE user_id = ?")->execute([$id]);
        db()->prepare("DELETE FROM retraits WHERE freelance_id = ?")->execute([$id]);
        db()->prepare("DELETE FROM demandes WHERE auteur_id = ?")->execute([$id]);
        db()->prepare("DELETE FROM evaluations WHERE auteur_id = ? OR cible_id = ?")->execute([$id, $id]);
        db()->prepare("DELETE FROM visites WHERE user_id = ?")->execute([$id]);
        db()->prepare("DELETE FROM evenements WHERE user_id = ?")->execute([$id]);
        db()->prepare("UPDATE admin_journal SET cible_id = 0 WHERE cible_id = ?")->execute([$id]);
        db()->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) { db()->rollBack(); }
        @error_log('[users] suppression compte ' . $id . ' : ' . $e->getMessage());
        return ['ok' => false, 'erreur' => "Suppression impossible : certaines données sont encore rattachées à ce compte."];
    }

    foreach (array_unique(array_filter($fichiers, 'is_string')) as $fichier) {
        supprimer_fichier_televerse($fichier);
    }
    return ['ok' => true, 'erreur' => "Le compte et ses données associées ont été supprimés."];
}

/* ─────────────────────────────────────────────────────────────
   FORFAITS DES EXPERTS
   ───────────────────────────────────────────────────────────── */

/** Les forfaits proposés par un expert, du moins cher au plus cher. */
function forfaits_de($user_id) {
    $st = db()->prepare("SELECT * FROM forfaits WHERE user_id=? ORDER BY prix, id");
    $st->execute([$user_id]);
    return $st->fetchAll();
}

/** Ajoute un forfait. Retourne false si les données sont inexploitables. */
function ajouter_forfait($user_id, $nom, $prix, $delai = 0, $description = '') {
    $nom  = trim($nom);
    $prix = max(0, (int) $prix);
    if ($nom === '' || $prix <= 0) { return false; }
    db()->prepare("INSERT INTO forfaits (user_id,nom,description,prix,delai) VALUES (?,?,?,?,?)")
        ->execute([$user_id, mb_substr($nom, 0, 60), trim($description), $prix, max(0, (int) $delai)]);
    return true;
}

/** Supprime un forfait, à condition qu'il appartienne bien à l'utilisateur. */
function supprimer_forfait($id, $user_id) {
    db()->prepare("DELETE FROM forfaits WHERE id=? AND user_id=?")->execute([(int) $id, $user_id]);
}

/** Tarif de référence d'un expert : son forfait le moins cher, sinon son tarif unique. */
function tarif_reference($user) {
    $f = forfaits_de($user['id']);
    if ($f) { return (int) $f[0]['prix']; }
    return (int) ($user['tarif_projet'] ?? 0);
}

/** Libère les frais en suspens : la prestation est livrée. */
function liberer_frais($projet_id) {
    db()->prepare("UPDATE paiements SET statut='libere' WHERE projet_id=? AND type='frais' AND statut='en_suspens'")
        ->execute([$projet_id]);
}

/** Rembourse les frais en suspens (projet annulé). */
function rembourser_frais($projet_id) {
    db()->prepare("UPDATE paiements SET statut='rembourse' WHERE projet_id=? AND type='frais' AND statut IN ('en_suspens','a_payer')")
        ->execute([$projet_id]);
}

/** Étape 1 réglée ? Conditionne le démarrage de la mission. */
function frais_regles($projet_id) {
    $p = paiements_projet($projet_id);
    return isset($p['frais']) && in_array($p['frais']['statut'], ['en_suspens','libere'], true);
}

/* ─────────────────────────────────────────────────────────────
   DEMANDES D'ANNULATION ET DE REMBOURSEMENT
   ───────────────────────────────────────────────────────────── */

/** Demande en cours sur un projet, ou null. */
function demande_active($projet_id) {
    $st = db()->prepare("SELECT * FROM demandes WHERE projet_id=? AND statut='en_attente' ORDER BY id DESC LIMIT 1");
    $st->execute([$projet_id]);
    return $st->fetch() ?: null;
}

/** Historique complet des demandes d'un projet. */
function demandes_projet($projet_id) {
    $st = db()->prepare("SELECT d.*, u.nom, u.prenom, u.societe FROM demandes d
                         JOIN users u ON u.id = d.auteur_id
                         WHERE d.projet_id=? ORDER BY d.id DESC");
    $st->execute([$projet_id]);
    return $st->fetchAll();
}

/** Toutes les demandes en attente (back-office). */
function demandes_en_attente() {
    return db()->query("SELECT d.*, p.titre, p.statut AS p_statut, u.nom, u.prenom, u.societe
                        FROM demandes d
                        JOIN projets p ON p.id = d.projet_id
                        JOIN users u   ON u.id = d.auteur_id
                        WHERE d.statut='en_attente' ORDER BY d.id DESC")->fetchAll();
}

function nb_demandes_en_attente() {
    return (int) db()->query("SELECT COUNT(*) FROM demandes WHERE statut='en_attente'")->fetchColumn();
}

/**
 * Le client peut-il encore demander l'annulation ?
 * Impossible sur un projet déjà clos.
 */
function peut_demander_annulation($projet) {
    return !in_array($projet['statut'], ['termine','annule'], true);
}

/**
 * Annule un projet et solde ses paiements.
 * Pour une charge directe Connect déjà payée, le remboursement est créé sur
 * le même compte connecté et rend aussi la commission Missiona. Une ligne
 * n'est jamais marquée « remboursée » avant la réponse positive de Stripe.
 * @return array{rembourse:bool,erreurs:array}
 */
function annuler_projet($projet_id, $rembourser = true) {
    $projet_id = (int) $projet_id;
    $erreurs = [];
    $paiements = paiements_projet($projet_id);

    // Un PaymentIntent ouvert doit être annulé avant de fermer le projet,
    // sinon une ancienne page de paiement pourrait encore débiter le client.
    foreach ($paiements as $pa) {
        if (($pa['type'] ?? '') === 'projet' && ($pa['statut'] ?? '') === 'a_payer'
            && !empty($pa['stripe_ref']) && function_exists('stripe_annuler_intent_direct')) {
            $r = stripe_annuler_intent_direct($pa);
            if (!$r['ok']) {
                // Mieux vaut ne pas fermer le projet si le PaymentIntent ne
                // peut pas être annulé de manière fiable.
                return ['rembourse' => false, 'erreurs' => [$r['erreur']]];
            }
            if (!empty($r['paye']) && function_exists('stripe_marquer_paiement_direct')) {
                $v = stripe_marquer_paiement_direct((int) $pa['id'], $r['intent'], (string) $pa['stripe_account']);
                if (!$v['ok']) { return ['rembourse' => false, 'erreurs' => [$v['erreur']]]; }
            }
        }
    }

    // Le statut a pu passer à « paye » pendant la relecture ci-dessus.
    $paiements = paiements_projet($projet_id);
    // Une charge directe déjà capturée ne peut pas être annulée sans son
    // remboursement : sinon la mission serait close alors que le client
    // resterait débité et la commission conserverait un faux état « payée ».
    foreach ($paiements as $pa) {
        if (($pa['type'] ?? '') === 'projet' && ($pa['statut'] ?? '') === 'paye'
            && !empty($pa['stripe_direct']) && !empty($pa['stripe_ref']) && !empty($pa['stripe_account'])
            && !$rembourser) {
            return ['rembourse' => false, 'erreurs' => ['Un paiement Stripe confirmé doit être remboursé avant l’annulation.']];
        }
    }
    if ($rembourser) {
        rembourser_frais($projet_id);
        foreach ($paiements as $pa) {
            if (($pa['type'] ?? '') !== 'projet' || ($pa['statut'] ?? '') !== 'paye') { continue; }
            if (!empty($pa['stripe_ref']) && !empty($pa['stripe_account'])
                && function_exists('stripe_rembourser_paiement_direct')) {
                $r = stripe_rembourser_paiement_direct($pa);
                if ($r['ok']) {
                    db()->prepare("UPDATE paiements SET statut='rembourse' WHERE id=? AND statut='paye'")
                        ->execute([(int) $pa['id']]);
                } else {
                    $erreurs[] = $r['erreur'];
                }
            } else {
                // Trace historique : règlement hors Stripe, traité par les parties.
                db()->prepare("UPDATE paiements SET statut='rembourse' WHERE id=? AND statut='paye'")
                    ->execute([(int) $pa['id']]);
            }
        }
    }
    // Ne pas annoncer une annulation si Stripe n'a pas permis d'expirer ou
    // rembourser de manière fiable. La demande reste alors réexaminable.
    if ($erreurs) { return ['rembourse' => false, 'erreurs' => $erreurs]; }
    db()->prepare("UPDATE projets SET statut='annule' WHERE id=?")->execute([$projet_id]);
    db()->prepare("UPDATE paiements SET statut='annule' WHERE projet_id=? AND statut='a_payer'")
        ->execute([$projet_id]);
    // Une commission collectée par application fee a été remboursée avec le
    // paiement : on conserve sa trace, mais elle ne peut plus apparaître comme
    // une facture acquittée ni comme une somme à payer.
    db()->prepare("UPDATE factures SET statut='remboursee' WHERE projet_id=? AND COALESCE(commission_prelevee_stripe,0)=1")
        ->execute([$projet_id]);
    db()->prepare("DELETE FROM factures WHERE projet_id=? AND statut='en_attente'")->execute([$projet_id]);
    email_suivi_projet_client($projet_id, 'annule');
    return ['rembourse' => true, 'erreurs' => []];
}

/** Supprime définitivement un projet et tout ce qui s'y rattache. */
function supprimer_projet($projet_id) {
    foreach (['demandes','paiements','factures','evaluations','messages','fichiers'] as $t) {
        db()->prepare("DELETE FROM $t WHERE projet_id=?")->execute([$projet_id]);
    }
    db()->prepare("DELETE FROM projets WHERE id=?")->execute([$projet_id]);
}

/** Identifiant du compte administrateur (robuste même après suppression du n°1). */
function admin_id() {
    static $id = null;
    if ($id !== null) return $id;
    $id = (int) db()->query("SELECT id FROM users WHERE role='admin' ORDER BY id LIMIT 1")->fetchColumn();
    return $id ?: 0;
}

function notify($user_id, $texte, $lien = '') {
    if (!$user_id) { return; }   // destinataire inconnu : on n'enregistre rien
    db()->prepare("INSERT INTO notifications (user_id,texte,lien) VALUES (?,?,?)")->execute([$user_id, $texte, $lien]);
}

/**
 * E-mail de statut projet : strictement opérationnel, et uniquement pour
 * une adresse vérifiée dont le titulaire a coché l'accord dédié. Aucun
 * contenu promotionnel ne transite par cette fonction.
 */
function email_suivi_projet_client($projet_id, $statut) {
    $st = db()->prepare("SELECT p.id, p.titre, u.email, u.prenom, u.nom,
                                u.email_suivi_consent, u.email_verifie
                         FROM projets p JOIN users u ON u.id = p.entreprise_id
                         WHERE p.id = ?");
    $st->execute([(int) $projet_id]);
    $r = $st->fetch();
    if (!$r || empty($r['email_suivi_consent']) || !email_est_verifie($r)) { return false; }

    return email_suivi_projet(
        $r['email'], $r['prenom'] ?: $r['nom'], $r['titre'], statut_label($statut),
        email_url('/projet.php?id=' . (int) $r['id'])
    );
}

function notifications($user_id, $limit = 10) {
    $st = db()->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT ?");
    $st->execute([$user_id, $limit]);
    return $st->fetchAll();
}
function nb_notifs_non_lues($user_id) {
    $st = db()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND lu = 0");
    $st->execute([$user_id]);
    return (int)$st->fetchColumn();
}

function statut_label($s) {
    return [
        'nouveau'   => 'Nouveau',
        'analyse'   => 'En analyse',
        'attribue'  => 'Attribué',
        'en_cours'  => 'En cours',
        'livraison' => 'En livraison',
        'termine'   => 'Terminé',
        'annule'    => 'Annulé',
    ][$s] ?? $s;
}
/**
 * Avancement implicite d'un statut, en pourcentage.
 * Sert de valeur par défaut : l'équipe peut toujours saisir une autre valeur.
 */
function avancement_auto($statut) {
    return [
        'nouveau'   => 0,
        'analyse'   => 10,
        'attribue'  => 15,
        'en_cours'  => 50,
        'livraison' => 85,
        'termine'   => 100,
        'annule'    => 0,
    ][$statut] ?? 0;
}

function statut_classe($s) {
    return [
        'nouveau'   => 'badge-gray',
        'analyse'   => 'badge-amber',
        'attribue'  => 'badge-blue',
        'en_cours'  => 'badge-blue',
        'livraison' => 'badge-violet',
        'termine'   => 'badge-green',
        'annule'    => 'badge-red',
    ][$s] ?? 'badge-gray';
}
function euros($n) { return number_format((float)$n, 0, ',', ' ') . ' €'; }
/* Plafond du curseur budget : au-delà on affiche « 5 000 €+ ».
   Suit le réglage de config.php au lieu d'une valeur figée. */
define('BUDGET_PLAFOND', defined('BUDGET_PLAFOND_CFG') ? (int) BUDGET_PLAFOND_CFG : 5000);
function euros_max($n) {
    return number_format((float)$n, 0, ',', ' ') . ' €' . ((float)$n >= BUDGET_PLAFOND ? '+' : '');
}
function date_fr($d) { return $d ? date('d/m/Y', strtotime($d)) : '—'; }
function initiales($u) { return strtoupper(mb_substr($u['prenom'] ?: $u['nom'], 0, 1) . mb_substr($u['nom'], 0, 1)); }

/* ---------- Moteur de matching (critères mesurables, coefficients configurables) ---------- */
function calculer_matching($projet) {
    $coefs = [
        'competences' => (float)param('coef_competences', 40),
        'budget'      => (float)param('coef_budget', 25),
        'dispo'       => (float)param('coef_dispo', 15),
        'experience'  => (float)param('coef_experience', 10),
        'note'        => (float)param('coef_note', 10),
    ];
    $total_coef = array_sum($coefs) ?: 1;

    $freelances = db()->query("SELECT * FROM users WHERE role = 'freelance' AND actif = 1")->fetchAll();
    $requises = array_filter(array_map('trim', explode(',', mb_strtolower($projet['competences']))));
    $budget_moy = ($projet['budget_min'] + $projet['budget_max']) / 2;

    $res = [];
    foreach ($freelances as $f) {
        $detail = [];

        // 1. Correspondance des compétences
        $skills = array_filter(array_map('trim', explode(',', mb_strtolower($f['competences']))));
        $match = $requises ? count(array_intersect($requises, $skills)) / count($requises) : 0;
        $detail['competences'] = round($match * 100);

        // 2. Adéquation budgétaire : on retient le forfait le moins cher
        //    de l'expert, ou son tarif unique s'il n'en propose aucun.
        $tarif = tarif_reference($f);
        if ($budget_moy <= 0)      { $sb = 50; }   // budget inconnu : neutre
        elseif ($tarif <= 0)       { $sb = 50; }   // tarif non renseigné : neutre
        elseif ($tarif <= $budget_moy) { $sb = 100; }
        else { $sb = max(0, 100 - (($tarif - $budget_moy) / $budget_moy) * 100); }
        $detail['budget'] = round($sb);

        // 3. Disponibilité
        $detail['dispo'] = $f['disponibilite'] === 'disponible' ? 100 : ($f['disponibilite'] === 'partiel' ? 50 : 10);

        // 4. Expérience (plafonnée à 10 ans)
        $detail['experience'] = round(min($f['experience'], 10) / 10 * 100);

        // 5. Note moyenne
        $detail['note'] = round(($f['note_moyenne'] / 5) * 100);

        $score = 0;
        foreach ($coefs as $k => $c) { $score += $detail[$k] * $c; }
        $score = round($score / $total_coef);

        $res[] = ['freelance' => $f, 'score' => $score, 'detail' => $detail];
    }
    usort($res, fn($a, $b) => $b['score'] <=> $a['score']);
    return $res;
}

/* ---------- Statistiques admin ---------- */
function stats_globales() {
    $d = db();
    return [
        'entreprises'   => (int)$d->query("SELECT COUNT(*) FROM users WHERE role IN ('entreprise','particulier')")->fetchColumn(),
        'freelances'    => (int)$d->query("SELECT COUNT(*) FROM users WHERE role='freelance'")->fetchColumn(),
        'projets'       => (int)$d->query("SELECT COUNT(*) FROM projets")->fetchColumn(),
        'en_cours'      => (int)$d->query("SELECT COUNT(*) FROM projets WHERE statut IN ('attribue','en_cours','livraison')")->fetchColumn(),
        'a_traiter'     => (int)$d->query("SELECT COUNT(*) FROM projets WHERE statut IN ('nouveau','analyse')")->fetchColumn(),
        'termines'      => (int)$d->query("SELECT COUNT(*) FROM projets WHERE statut='termine'")->fetchColumn(),
        'ca'            => (int)$d->query("SELECT COALESCE(SUM(montant_final),0) FROM projets WHERE statut='termine'")->fetchColumn(),
        'commissions'   => (int)$d->query("SELECT COALESCE(SUM(commission),0) FROM factures")->fetchColumn(),
        'messages_nl'   => (int)$d->query("SELECT COUNT(*) FROM messages WHERE destinataire_id=1 AND lu=0")->fetchColumn(),
    ];
}

/* ============================================
   Gestion des fichiers téléversés
   ============================================ */

define('UPLOAD_MAX', 8 * 1024 * 1024); // 8 Mo

/** Extensions autorisées pour les livrables de projet */
function ext_doc_autorisees() {
    return ['pdf','doc','docx','xls','xlsx','ppt','pptx','txt','csv','zip','rar',
            'jpg','jpeg','png','gif','webp','svg','ai','psd','fig','mp4','mov',
            // livrables techniques : ils sont stockés hors exécution et ne
            // sont jamais servis comme du code actif sur le site
            'html','htm','js','css','json','md','php','py','sql','sh','log',
            'yml','yaml','ini','xml'];
}
/**
 * Formats affichables dans la fenêtre d'aperçu.
 * Volontairement restreint : le SVG est un format exécutable, il ne
 * doit jamais être rendu en ligne sur notre domaine.
 */
function ext_apercu() {
    return array_merge(
        ['jpg','jpeg','png','gif','webp','pdf'],
        ['html','htm'],
        ext_code_apercu()
    );
}

/** Extensions de code/texte affichables en lecture seule dans l'aperçu. */
function ext_code_apercu() {
    return ['txt','csv','md','json','js','css','php','py','sh','sql','log',
            'yml','yaml','ini','xml'];
}

/**
 * Mode d'affichage d'un fichier dans la boîte d'aperçu protégée.
 *  - img   : image
 *  - pdf   : document PDF
 *  - html  : page rendue dans un cadre isolé (jamais le code)
 *  - texte : code affiché en lecture seule
 *  - false : aucun aperçu (téléchargement seul, si autorisé)
 * Le SVG et les formats exécutables restent exclus de l'aperçu.
 */
function apercu_mode($nom) {
    $ext = strtolower(pathinfo((string) $nom, PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg','jpeg','png','gif','webp'], true)) { return 'img'; }
    if ($ext === 'pdf')  { return 'pdf'; }
    if (in_array($ext, ['html','htm'], true)) { return 'html'; }
    if (in_array($ext, ext_code_apercu(), true)) { return 'texte'; }
    return false;
}

function fichier_previsualisable($nom) {
    return apercu_mode($nom) !== false;
}

/**
 * En-têtes + contenu pour l'affichage d'un document dans la boîte
 * d'aperçu. Chaque mode reçoit le type MIME qui l'empêche d'exécuter
 * du code dans notre domaine (le HTML est par ailleurs isolé côté
 * navigateur dans un cadre « sandbox »).
 */
function servir_apercu($chemin, $nom) {
    $mode = apercu_mode($nom);
    $ext  = strtolower(pathinfo((string) $nom, PATHINFO_EXTENSION));

    $mimes_img = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png',
                  'gif'=>'image/gif','webp'=>'image/webp'];

    switch ($mode) {
        case 'img':   $mime = $mimes_img[$ext]; break;
        case 'pdf':   $mime = 'application/pdf'; break;
        case 'html':  $mime = 'text/html'; break;
        case 'texte': $mime = 'text/plain'; break;
        default:      return false;
    }

    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: ' . $mime . '; charset=' . ($mode === 'img' || $mode === 'pdf' ? 'binary' : 'utf-8'));
    header('Content-Disposition: inline; filename="' . str_replace('"', '', $nom) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0, must-revalidate');
    // Le document ne peut être encadré que par nos propres pages.
    header("Content-Security-Policy: frame-ancestors 'self'");
    header('Content-Length: ' . filesize($chemin));
    readfile($chemin);
    exit;
}

/** Extensions autorisées pour les images de portfolio */
function ext_img_autorisees() { return ['jpg','jpeg','png','gif','webp']; }

/**
 * Traite un fichier téléversé et le range dans /uploads/<sous_dossier>/
 * Renvoie ['ok'=>bool, 'nom'=>string, 'chemin'=>string, 'taille'=>int, 'erreur'=>string]
 */
function traiter_upload(array $file, $sous_dossier, array $extensions) {
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'erreur' => "Aucun fichier sélectionné."];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'erreur' => "Le téléversement a échoué (le fichier est peut-être trop lourd)."];
    }
    if ($file['size'] > UPLOAD_MAX) {
        return ['ok' => false, 'erreur' => "Fichier trop volumineux : 8 Mo maximum."];
    }

    $nom_original = $file['name'];
    $ext = strtolower(pathinfo($nom_original, PATHINFO_EXTENSION));
    if (!in_array($ext, $extensions, true)) {
        return ['ok' => false, 'erreur' => "Format non autorisé (." . htmlspecialchars($ext) . ")."];
    }

    // Nom de stockage aléatoire : empêche l'écrasement et l'exécution
    $nom_stocke = bin2hex(random_bytes(12)) . '.' . $ext;
    $dossier = __DIR__ . '/../uploads/' . $sous_dossier;
    if (!is_dir($dossier)) { @mkdir($dossier, 0755, true); }
    $destination = $dossier . '/' . $nom_stocke;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        return ['ok' => false, 'erreur' => "Impossible d'enregistrer le fichier sur le serveur."];
    }
    @chmod($destination, 0644);

    return [
        'ok'     => true,
        'nom'    => mb_substr($nom_original, 0, 180),
        'chemin' => $sous_dossier . '/' . $nom_stocke,
        'taille' => (int)$file['size'],
    ];
}

/** Récupère le portfolio d'un freelance */
function portfolio_de($user_id) {
    $st = db()->prepare("SELECT * FROM portfolio WHERE user_id = ? ORDER BY id DESC");
    $st->execute([$user_id]);
    return $st->fetchAll();
}

/** URL protégée d'une image de portfolio, sans exposer le chemin de stockage. */
function portfolio_image_url($portfolio_id) {
    return u('/portfolio-image.php?id=' . max(0, (int) $portfolio_id));
}

/** Icône associée à une extension de fichier */
function icone_fichier($nom) {
    $e = strtolower(pathinfo($nom, PATHINFO_EXTENSION));
    if (in_array($e, ['jpg','jpeg','png','gif','webp','svg','psd','ai','fig'])) return '🖼️';
    if (in_array($e, ['pdf'])) return '📕';
    if (in_array($e, ['doc','docx','txt'])) return '📄';
    if (in_array($e, ['xls','xlsx','csv'])) return '📊';
    if (in_array($e, ['ppt','pptx'])) return '📽️';
    if (in_array($e, ['zip','rar'])) return '🗜️';
    if (in_array($e, ['mp4','mov'])) return '🎬';
    if (in_array($e, ['html','htm','js','css','json','php','py','sql','sh','yml','yaml','ini','xml','md','log'])) return '💻';
    return '📎';
}

/** Taille lisible */
function taille_lisible($o) {
    if ($o >= 1048576) return round($o / 1048576, 1) . ' Mo';
    if ($o >= 1024)    return round($o / 1024) . ' Ko';
    return $o . ' o';
}

/* ============================================
   Référentiel des compétences, par domaine
   ============================================ */

/**
 * Source de données unique des compétences autorisées. Le tableau est
 * conservé en mémoire pendant la requête pour les formulaires et le matching.
 */
function config_competences() {
    static $config = null;
    if ($config !== null) { return $config; }

    $fichier = __DIR__ . '/../config/skills.php';
    $charge  = is_file($fichier) ? require $fichier : [];
    return $config = is_array($charge) ? $charge : [];
}

/** Référentiel simplifié [libellé de groupe => liste de libellés]. */
function competences_par_domaine() {
    $domaines = [];
    foreach (config_competences() as $groupe) {
        $libelle = $groupe['group_label'] ?? '';
        $skills  = $groupe['skills'] ?? [];
        if (!is_string($libelle) || $libelle === '' || !is_array($skills)) { continue; }
        $domaines[$libelle] = array_values(array_filter($skills, 'is_string'));
    }
    return $domaines;
}

/**
 * Domaines proposés au client lorsqu'il dépose un projet.
 * Tirés du même référentiel que les compétences des experts : sans
 * cela, un projet classé dans un domaine absent du profil ne rencontrerait
 * jamais l'expert concerné.
 */
function domaines_projet() {
    $d = array_keys(competences_par_domaine());
    $d[] = 'Autre';
    return $d;
}

/**
 * Correspondance fermée entre les cartes de l'accueil et le domaine du
 * formulaire de dépôt. Les codes (et non des libellés libres) circulent dans
 * les URL afin qu'une valeur forgée ne puisse pas préremplir le formulaire.
 *
 * @return array<string,string>
 */
function domaines_landing() {
    return [
        'developpement' => 'Développement web',
        'ia'            => 'Intelligence artificielle',
        'design'         => 'Design graphique & identité',
        'marketing'      => 'Marketing digital',
        'video'          => 'Vidéo & animation',
        'data'           => 'Data & analyse',
        'finance'        => 'Finance & pilotage',
        'ingenierie'     => 'Ingénierie & architecture',
        'assistance'     => 'Assistance & administration',
        'conseil'        => 'Conseil & stratégie',
        'cybersecurite'  => 'Infrastructure, cloud & sécurité',
        'traduction'     => 'Traduction & langues',
    ];
}

/** Retourne le code de domaine validé ou une chaîne vide. */
function domaine_landing_code($code) {
    $code = is_string($code) ? $code : '';
    return array_key_exists($code, domaines_landing()) ? $code : '';
}

/** Retourne le domaine du formulaire associé à un code de carte validé. */
function domaine_landing_valeur($code) {
    $code = domaine_landing_code($code);
    $domaine = $code === '' ? '' : domaines_landing()[$code];
    return in_array($domaine, domaines_projet(), true) ? $domaine : '';
}

/**
 * Brouillon d'un projet commencé avant connexion.
 * Les données restent exclusivement dans la session du visiteur et expirent
 * après quatre heures : elles ne transitent jamais dans une URL.
 */
function projet_brouillon_lire() {
    $brouillon = $_SESSION['projet_brouillon'] ?? null;
    if (!is_array($brouillon) || empty($brouillon['cree_le'])
        || (time() - (int) $brouillon['cree_le']) > 4 * 3600) {
        unset($_SESSION['projet_brouillon']);
        return null;
    }
    return $brouillon;
}

function projet_brouillon_oublier() {
    unset($_SESSION['projet_brouillon']);
}

/**
 * Vérifie et conserve un brouillon de dépôt. Le même contrôle est refait au
 * moment de l'envoi final : le navigateur ne décide jamais seul des données.
 *
 * @return array{ok:bool,data:array<string,mixed>,erreur:string}
 */
function projet_brouillon_preparer($source) {
    $source = is_array($source) ? $source : [];
    $texte = static function ($cle, $limite) use ($source) {
        $v = $source[$cle] ?? '';
        return is_string($v) ? mb_substr(trim($v), 0, $limite) : '';
    };
    $montant = static function ($cle, $defaut, $maximum) use ($source) {
        $v = $source[$cle] ?? $defaut;
        if (!is_string($v) && !is_int($v)) { return $defaut; }
        $v = trim((string) $v);
        if ($v === '' || !preg_match('/^\d{1,9}$/', $v)) { return $defaut; }
        return min($maximum, max(0, (int) $v));
    };

    $titre       = $texte('titre', 160);
    $categorie   = $texte('categorie', 100);
    $description = $texte('description', 8000);
    $delai       = $texte('delai', 60);
    $date_limite = $texte('date_limite', 10);
    $plafond     = max(1000, (int) BUDGET_PLAFOND);
    $budget_min  = $montant('budget_min', (int) round($plafond * .02), $plafond);
    $budget_max  = $montant('budget_max', (int) round($plafond * .10), $plafond);
    $competences = normaliser_competences_soumises($source['competences'] ?? '');
    $delais_autorises = ['', 'Moins de 1 mois', '1 mois', '2 mois', '3 mois', '4 mois', '6 mois', 'Plus de 6 mois', 'Récurrent'];

    if ($titre === '' || $categorie === '' || $description === '') {
        return ['ok' => false, 'data' => [], 'erreur' => "Le titre, le domaine et la description sont obligatoires."];
    }
    if (!in_array($categorie, domaines_projet(), true)) {
        return ['ok' => false, 'data' => [], 'erreur' => "Le domaine sélectionné n'est pas disponible."];
    }
    if (!$competences['ok']) {
        return ['ok' => false, 'data' => [], 'erreur' => 'La compétence « ' . $competences['invalide'] . ' » n’est plus proposée ou n’existe pas.'];
    }
    if ($budget_min > $budget_max) {
        return ['ok' => false, 'data' => [], 'erreur' => 'Le budget minimum ne peut pas dépasser le budget maximum.'];
    }
    if (!in_array($delai, $delais_autorises, true)) { $delai = ''; }
    if ($date_limite !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_limite) || !strtotime($date_limite))) {
        return ['ok' => false, 'data' => [], 'erreur' => "La date souhaitée n'est pas valide."];
    }

    return ['ok' => true, 'erreur' => '', 'data' => [
        'titre' => $titre,
        'categorie' => $categorie,
        'description' => $description,
        'competences' => $competences['competences'],
        'budget_min' => $budget_min,
        'budget_max' => $budget_max,
        'delai' => $delai,
        'date_limite' => $date_limite,
        'cree_le' => time(),
    ]];
}

function projet_brouillon_enregistrer($source) {
    $r = projet_brouillon_preparer($source);
    if ($r['ok']) { $_SESSION['projet_brouillon'] = $r['data']; }
    return $r;
}

/** Parcours d'une carte : le besoin est décrit avant la création de compte. */
function lien_domaine_landing($code) {
    $code = domaine_landing_code($code);
    if ($code === '') { return u('/nouveau-projet'); }
    return u('/nouveau-projet?domaine=' . rawurlencode($code));
}

/** Liste à plat de toutes les compétences autorisées. */
function toutes_competences() {
    $out = [];
    foreach (competences_par_domaine() as $liste) { $out = array_merge($out, $liste); }
    return array_values(array_unique($out));
}

/**
 * Liste blanche serveur pour les compétences postées par un formulaire.
 * Le front n'est jamais une frontière de sécurité : les valeurs sont donc
 * vérifiées ici, quelle que soit la forme de l'envoi (chaîne CSV ou [] PHP).
 *
 * @return array{ok:bool,competences:string,invalide:string}
 */
function normaliser_competences_soumises($soumis) {
    $valeurs = is_array($soumis) ? $soumis : [$soumis];
    $skills  = [];

    foreach ($valeurs as $valeur) {
        if (!is_string($valeur)) {
            return ['ok' => false, 'competences' => '', 'invalide' => 'format invalide'];
        }
        foreach (explode(',', $valeur) as $competence) {
            $competence = trim($competence);
            if ($competence !== '') { $skills[] = $competence; }
        }
    }

    $autorisees = toutes_competences();
    foreach ($skills as $competence) {
        if (!in_array($competence, $autorisees, true)) {
            return ['ok' => false, 'competences' => '', 'invalide' => $competence];
        }
    }

    return [
        'ok'           => true,
        'competences'  => implode(',', array_values(array_unique($skills))),
        'invalide'     => '',
    ];
}

/**
 * Affiche le sélecteur de compétences groupé par domaine.
 * $selection : chaîne "PHP,Figma" des compétences déjà choisies.
 */
function champ_competences($selection = '', $input_id = 'competences') {
    $brute = is_string($selection) ? $selection : '';
    $sel   = array_values(array_unique(array_filter(array_map('trim', explode(',', $brute)))));
    $valides = toutes_competences();
    $anciennes = array_values(array_diff($sel, $valides));
    $sel = array_values(array_intersect($sel, $valides));
    $id  = htmlspecialchars($input_id, ENT_QUOTES, 'UTF-8');

    echo '<input type="hidden" id="' . $id . '" name="' . $id . '" value="'
       . htmlspecialchars(implode(',', $sel), ENT_QUOTES, 'UTF-8') . '">';

    echo '<div class="skills-box" data-input="' . $id . '">';

    if ($anciennes) {
        echo '<p class="hint" style="margin:0 0 10px">Certaines compétences enregistrées ne sont plus proposées. '
           . 'Elles restent conservées tant que ce profil n\'est pas enregistré à nouveau.</p>';
    }

    // Recherche : le référentiel rassemble de nombreuses compétences ; parcourir
    // tous les groupes un à un serait décourageant.
    echo '<div class="skills-search">'
       . '<input type="text" class="input" placeholder="Rechercher une compétence…" '
       . 'data-skills-search aria-label="Rechercher une compétence">'
       . '<span class="skills-count" data-skills-count></span>'
       . '</div>';
    echo '<p class="skills-vide" data-skills-vide hidden>Aucune compétence ne correspond.</p>';

    foreach (competences_par_domaine() as $domaine => $liste) {
        // Un domaine s'ouvre d'emblée s'il contient déjà une sélection.
        $actifs = count(array_intersect($liste, $sel));
        $ouvert = $actifs > 0;

        echo '<div class="skills-group' . ($ouvert ? ' ouvert' : '') . '">';
        echo '<button type="button" class="skills-title" data-skills-toggle>'
           . '<span class="chev" aria-hidden="true">▸</span>'
           . '<span>' . htmlspecialchars($domaine, ENT_QUOTES, 'UTF-8') . '</span>'
           . '<span class="skills-badge" data-skills-actifs>' . ($actifs ?: '') . '</span>'
           . '</button>';
        echo '<div class="tagbox-inner">';
        foreach ($liste as $c) {
            $on = in_array($c, $sel, true) ? ' on' : '';
            $valeur = htmlspecialchars($c, ENT_QUOTES, 'UTF-8');
            echo '<span class="tag-opt' . $on . '" data-value="' . $valeur . '">' . $valeur . '</span>';
        }
        echo '</div></div>';
    }
    echo '</div>';
}


/**
 * Sélecteur client : les compétences du domaine choisi sont proposées en
 * premier. Le catalogue complet reste disponible sur demande, plus bas.
 */
function champ_competences_projet($selection = '', $input_id = 'competences') {
    $brute = is_string($selection) ? $selection : '';
    $sel = array_values(array_unique(array_intersect(
        array_filter(array_map('trim', explode(',', $brute))),
        toutes_competences()
    )));
    $id = htmlspecialchars($input_id, ENT_QUOTES, 'UTF-8');
    $domaines = competences_par_domaine();

    echo '<input type="hidden" id="' . $id . '" name="' . $id . '" value="'
       . htmlspecialchars(implode(',', $sel), ENT_QUOTES, 'UTF-8') . '">';
    echo '<div class="skills-box project-skills-box" data-input="' . $id . '">';
    echo '<div class="project-skills-empty" id="projectSkillsEmpty">Choisissez un domaine.</div>';

    foreach ($domaines as $domaine => $liste) {
        echo '<section class="project-domain-skills" data-project-domain-skills="'
           . htmlspecialchars($domaine, ENT_QUOTES, 'UTF-8') . '" hidden>';
        echo '<p><strong>Pour votre domaine</strong></p>';
        echo '<div class="tagbox-inner project-suggested-tags">';
        foreach ($liste as $competence) {
            $on = in_array($competence, $sel, true) ? ' on' : '';
            $valeur = htmlspecialchars($competence, ENT_QUOTES, 'UTF-8');
            echo '<span class="tag-opt' . $on . '" data-value="' . $valeur . '">' . $valeur . '</span>';
        }
        echo '</div></section>';
    }

    echo '<details class="project-all-skills">';
    echo '<summary><span>Voir toutes les compétences</span><span class="project-details-arrow" aria-hidden="true">⌄</span></summary>';
    echo '<div class="project-all-skills-body">';
    echo '<div class="skills-search"><input type="text" class="input" placeholder="Rechercher une compétence…" data-skills-search aria-label="Rechercher une compétence"><span class="skills-count" data-skills-count></span></div>';
    echo '<p class="skills-vide" data-skills-vide hidden>Aucune compétence ne correspond.</p>';
    foreach ($domaines as $domaine => $liste) {
        $actifs = count(array_intersect($liste, $sel));
        $ouvert = $actifs > 0;
        echo '<div class="skills-group' . ($ouvert ? ' ouvert' : '') . '">';
        echo '<button type="button" class="skills-title" data-skills-toggle>'
           . '<span class="chev" aria-hidden="true">▸</span>'
           . '<span>' . htmlspecialchars($domaine, ENT_QUOTES, 'UTF-8') . '</span>'
           . '<span class="skills-badge" data-skills-actifs>' . ($actifs ?: '') . '</span>'
           . '</button><div class="tagbox-inner">';
        foreach ($liste as $competence) {
            $on = in_array($competence, $sel, true) ? ' on' : '';
            $valeur = htmlspecialchars($competence, ENT_QUOTES, 'UTF-8');
            echo '<span class="tag-opt' . $on . '" data-value="' . $valeur . '">' . $valeur . '</span>';
        }
        echo '</div></div>';
    }
    echo '</div></details></div>';
}

/* ---------------------------------------------------------------
   Mode maintenance — verrou global.
   Chargé en dernier pour que toutes les fonctions soient définies.
   --------------------------------------------------------------- */
require_once __DIR__ . '/payouts.php';
require_once __DIR__ . '/dac7.php';
require_once __DIR__ . '/pdf.php';
require_once __DIR__ . '/facture-pdf.php';
require_once __DIR__ . '/email.php';
require_once __DIR__ . '/google.php';
require_once __DIR__ . '/stats.php';
require_once __DIR__ . '/push.php';
require_once __DIR__ . '/equipe.php';
require_once __DIR__ . '/messagerie.php';
require_once __DIR__ . '/maintenance.php';
require_once __DIR__ . '/beta.php';

// La page de maintenance elle-même ne doit pas se verrouiller (boucle infinie)
// Présence de l'utilisateur, notée avant toute lecture de données
// pour que son propre voyant soit juste dès la première page.
equipe_marquer_presence();

if (basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'maintenance.php') {
    maintenance_verrou();
    // La maintenance prime : elle ferme tout, la bêta ne fait que restreindre.
    beta_verrou();
}
