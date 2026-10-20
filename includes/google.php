<?php
/**
 * Missiona — Connexion avec Google (OAuth 2.0)
 * ------------------------------------------------------------------
 * Écrit en PHP pur : aucune bibliothèque, aucun script JavaScript
 * chargé depuis Google. On utilise le « code d'autorisation », le flux
 * classique côté serveur :
 *
 *   1. L'utilisateur clique et part chez Google (google_url_auth).
 *   2. Google le renvoie sur google-callback.php avec un code.
 *   3. Le serveur échange ce code contre un jeton (google_echange_code).
 *   4. Le serveur demande l'identité à Google (google_profil).
 *
 * Le jeton ne transite jamais par le navigateur, et le secret client
 * reste sur le serveur.
 *
 * Réglages à placer dans config.local.php :
 *   define('GOOGLE_CLIENT_ID',     '....apps.googleusercontent.com');
 *   define('GOOGLE_CLIENT_SECRET', '....');
 */

define('GOOGLE_AUTH_URL',     'https://accounts.google.com/o/oauth2/v2/auth');
define('GOOGLE_TOKEN_URL',    'https://oauth2.googleapis.com/token');
define('GOOGLE_USERINFO_URL', 'https://openidconnect.googleapis.com/v1/userinfo');

/** La connexion Google est-elle configurée ? */
function google_actif() {
    return defined('GOOGLE_CLIENT_ID') && GOOGLE_CLIENT_ID !== ''
        && defined('GOOGLE_CLIENT_SECRET') && GOOGLE_CLIENT_SECRET !== '';
}

/**
 * Adresse de retour déclarée chez Google.
 * Doit correspondre au caractère près à l'URI enregistrée dans la
 * console Google Cloud, sans quoi Google renvoie redirect_uri_mismatch.
 */
function google_redirect_uri() {
    if (defined('GOOGLE_REDIRECT_URI') && GOOGLE_REDIRECT_URI !== '') {
        return GOOGLE_REDIRECT_URI;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $hote  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base  = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
    return ($https ? 'https://' : 'http://') . $hote . $base . '/google-callback.php';
}

/**
 * Construit l'adresse vers laquelle envoyer l'utilisateur.
 * Le paramètre « state » est un jeton anti-CSRF : il est gardé en
 * session et revérifié au retour, pour qu'un tiers ne puisse pas
 * déclencher une connexion à notre insu.
 */
function google_url_auth($role_souhaite = '') {
    $state = bin2hex(random_bytes(16));
    $_SESSION['google_state'] = $state;
    $_SESSION['google_role']  = in_array($role_souhaite, ['entreprise', 'particulier', 'freelance'], true)
        ? $role_souhaite : '';

    return GOOGLE_AUTH_URL . '?' . http_build_query([
        'client_id'     => GOOGLE_CLIENT_ID,
        'redirect_uri'  => google_redirect_uri(),
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'state'         => $state,
        'prompt'        => 'select_account',
        // Pas de refresh token : on n'agit jamais au nom de l'utilisateur.
        'access_type'   => 'online',
    ], '', '&', PHP_QUERY_RFC3986);
}

/** Le « state » renvoyé par Google correspond-il à celui émis ? */
function google_state_valide($state) {
    $attendu = $_SESSION['google_state'] ?? '';
    unset($_SESSION['google_state']);
    return $attendu !== '' && is_string($state) && hash_equals($attendu, $state);
}

/** Appel HTTP minimal, sur le même modèle que la couche Stripe. */
function google_http($url, array $post = null) {
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => "L'extension cURL est absente du serveur.", 'data' => []];
    }
    $ch = curl_init($url);
    $opt = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    if ($post !== null) {
        $opt[CURLOPT_POST]       = true;
        $opt[CURLOPT_POSTFIELDS] = http_build_query($post);
    }
    curl_setopt_array($ch, $opt);
    $rep  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($rep === false) {
        return ['ok' => false, 'error' => $err ?: 'Connexion à Google impossible.', 'data' => []];
    }
    $data = json_decode($rep, true);
    if (!is_array($data)) {
        return ['ok' => false, 'error' => 'Réponse inattendue de Google.', 'data' => []];
    }
    if ($code >= 400) {
        $msg = $data['error_description'] ?? ($data['error'] ?? ('Erreur HTTP ' . $code));
        return ['ok' => false, 'error' => (string) $msg, 'data' => $data];
    }
    return ['ok' => true, 'error' => '', 'data' => $data];
}

/** Échange le code d'autorisation contre un jeton d'accès. */
function google_echange_code($code) {
    return google_http(GOOGLE_TOKEN_URL, [
        'code'          => $code,
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri'  => google_redirect_uri(),
        'grant_type'    => 'authorization_code',
    ]);
}

/**
 * Récupère l'identité auprès de Google.
 * On interroge le point d'accès « userinfo » plutôt que de décoder
 * l'id_token : la réponse vient directement de Google en HTTPS, il n'y
 * a donc pas de signature à vérifier nous-mêmes.
 */
function google_profil($access_token) {
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => "L'extension cURL est absente du serveur.", 'data' => []];
    }
    $ch = curl_init(GOOGLE_USERINFO_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $access_token],
    ]);
    $rep  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($rep === false) { return ['ok' => false, 'error' => $err ?: 'Google injoignable.', 'data' => []]; }
    $d = json_decode($rep, true);
    if (!is_array($d) || $code >= 400) {
        return ['ok' => false, 'error' => 'Profil Google illisible.', 'data' => []];
    }
    return ['ok' => true, 'error' => '', 'data' => $d];
}

/** Ouvre la session pour un utilisateur déjà identifié. */
function google_ouvrir_session($user_id) {
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $user_id;
    guide_premiere_connexion_programmer(user(true));
}

/**
 * Retrouve un compte à partir du profil Google.
 * @return array|null
 */
function google_trouver_compte($sub, $email) {
    $st = db()->prepare("SELECT * FROM users WHERE google_id = ? AND google_id <> ''");
    $st->execute([(string) $sub]);
    if ($u = $st->fetch()) { return $u; }

    $st = db()->prepare("SELECT * FROM users WHERE email = ?");
    $st->execute([strtolower(trim((string) $email))]);
    return $st->fetch() ?: null;
}
