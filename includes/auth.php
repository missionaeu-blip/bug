<?php
/* ==========================================================================
   PANNACOTTACLUB — authentification (PHP/MySQL)
   • Mots de passe hachés (password_hash / Argon2id ou bcrypt selon l'hébergeur)
   • Sessions persistées en base : 90 jours « rester connecté », 12 h sinon
   • Prolongation automatique à chaque usage → un livreur actif n'est jamais
     déconnecté (fin des « sessions expirées »)
   • Jeton dans un cookie httpOnly + en local : l'un sert de secours à l'autre
   ========================================================================== */

const PC_COOKIE = 'pc_session';

function hash_password(string $plain): string
{
    return password_hash($plain, PASSWORD_DEFAULT);
}

function verify_password(string $plain, string $hash): bool
{
    return is_string($hash) && $hash !== '' && password_verify($plain, $hash);
}

/** Durée d'une session (millisecondes). */
function session_ttl_ms(bool $remember): int
{
    if ($remember) return (int) cfg('session_days', 90) * 86400000;
    return (int) cfg('session_hours', 12) * 3600000;
}

/** Crée une session et renvoie son jeton. */
function session_create(string $userId, bool $remember = true): string
{
    $token = new_token(32);
    q('INSERT INTO sessions (token, user_id, remember, created_at, expires_at, ua)
       VALUES (?, ?, ?, ?, ?, ?)', [
        $token,
        $userId,
        $remember ? 1 : 0,
        now_ms(),
        now_ms() + session_ttl_ms($remember),
        mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160),
    ]);
    session_set_cookie($token, $remember);
    return $token;
}

function session_set_cookie(string $token, bool $remember): void
{
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $params = [
        'expires'  => $remember ? time() + (int) cfg('session_days', 90) * 86400 : 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    if (PHP_VERSION_ID >= 70300) {
        setcookie(PC_COOKIE, $token, $params);
    } else {
        setcookie(PC_COOKIE, $token, $params['expires'], '/; samesite=Lax', '', $secure, true);
    }
}

function session_clear_cookie(): void
{
    setcookie(PC_COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'samesite' => 'Lax']);
}

/** Jeton présenté par le client (cookie prioritaire, puis en-tête, puis paramètre). */
function session_token(): ?string
{
    if (!empty($_COOKIE[PC_COOKIE])) return (string) $_COOKIE[PC_COOKIE];

    $headers = function_exists('getallheaders') ? getallheaders() : [];
    foreach ($headers as $name => $value) {
        if (strcasecmp($name, 'Authorization') === 0 && stripos((string) $value, 'Bearer ') === 0) {
            return trim(substr((string) $value, 7));
        }
    }
    if (!empty($_SERVER['HTTP_AUTHORIZATION']) && stripos((string) $_SERVER['HTTP_AUTHORIZATION'], 'Bearer ') === 0) {
        return trim(substr((string) $_SERVER['HTTP_AUTHORIZATION'], 7));
    }
    if (!empty($_GET['token'])) return (string) $_GET['token'];
    $t = body('token');
    return is_string($t) && $t !== '' ? $t : null;
}

/** Utilisateur courant (ou null). Prolonge la session au passage. */
function current_user(): ?array
{
    static $cached = false;
    static $user = null;
    if ($cached !== false) return $user;

    $token = session_token();
    if (!$token) { $cached = true; return $user = null; }

    $s = q_one('SELECT * FROM sessions WHERE token = ?', [$token]);
    if (!$s) { $cached = true; return $user = null; }
    if ((int) $s['expires_at'] < now_ms()) {
        q('DELETE FROM sessions WHERE token = ?', [$token]);
        $cached = true;
        return $user = null;
    }
    $u = q_one('SELECT * FROM users WHERE id = ?', [$s['user_id']]);
    if (!$u || (int) $u['active'] !== 1) {
        q('DELETE FROM sessions WHERE token = ?', [$token]);
        $cached = true;
        return $user = null;
    }

    // prolongation : on repousse l'échéance dès la moitié de la durée consommée
    $ttl = session_ttl_ms((int) $s['remember'] === 1);
    if ((int) $s['expires_at'] - now_ms() < $ttl / 2) {
        q('UPDATE sessions SET expires_at = ? WHERE token = ?', [now_ms() + $ttl, $token]);
        session_set_cookie($token, (int) $s['remember'] === 1);
    }
    $cached = true;
    return $user = $u;
}

function require_login(): array
{
    $u = current_user();
    if (!$u) api_fail(401, 'Connexion requise.', ['code' => 'SESSION']);
    return $u;
}

function require_role(string $role): array
{
    $u = require_login();
    if ($u['role'] !== $role) api_fail(403, 'Accès refusé.');
    return $u;
}

function destroy_session(): void
{
    $token = session_token();
    if ($token) q('DELETE FROM sessions WHERE token = ?', [$token]);
    session_clear_cookie();
}

/** Représentation publique d'un compte (jamais le mot de passe). */
function public_user(?array $u): ?array
{
    if (!$u) return null;
    return [
        'id'     => $u['id'],
        'role'   => $u['role'],
        'name'   => $u['name'],
        'email'  => $u['email'],
        'phone'  => $u['phone'] ?? '',
        'online' => (int) ($u['online'] ?? 0) === 1,
    ];
}
