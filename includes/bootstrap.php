<?php
/* ==========================================================================
   PANNACOTTACLUB — socle commun (PHP)
   Chargé par api.php, index.php et install.php.
   ========================================================================== */

declare(strict_types=1);
date_default_timezone_set('Europe/Paris');

define('PC_ROOT', dirname(__DIR__));
define('PC_CONFIG_FILE', PC_ROOT . '/config.php');

$GLOBALS['PC_CFG'] = require PC_CONFIG_FILE;

/** Configuration générale. */
function cfg(?string $key = null, $default = null)
{
    $c = $GLOBALS['PC_CFG'];
    if ($key === null) return $c;
    foreach (explode('.', $key) as $part) {
        if (!is_array($c) || !array_key_exists($part, $c)) return $default;
        $c = $c[$part];
    }
    return $c;
}

/* --------------------------------------------------------------------- base */

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $d = cfg('db');
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $d['host'], (int) $d['port'], $d['name'], $d['charset']);
    try {
        $pdo = new PDO($dsn, $d['user'], $d['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET time_zone = '+02:00'");
    } catch (Throwable $e) {
        api_fail(500, "Connexion à la base impossible. Vérifiez les identifiants dans config.php.");
    }
    return $pdo;
}

/** Requête préparée : renvoie un PDOStatement. */
function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function q_one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function q_all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function q_val(string $sql, array $params = [], $default = null)
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? $default : $v;
}

/** Identifiants courts, sans caractères ambigus. */
function new_id(string $prefix = ''): string
{
    return $prefix . bin2hex(random_bytes(8));
}

function new_token(int $bytes = 32): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

/* --------------------------------------------------------------- réglages */

function settings_all(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    try {
        foreach (q_all('SELECT k, v FROM settings') as $row) $cache[$row['k']] = $row['v'];
    } catch (Throwable $e) {
        // tables pas encore créées (site non installé) : on renvoie les valeurs par défaut
        foreach (cfg('defaults') as $k => $v) $cache[$k] = is_bool($v) ? ($v ? '1' : '0') : (string) $v;
    }
    return $cache;
}

/** Le site est-il installé (tables créées) ? Résultat mis en cache. */
function tables_ready(): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        db()->query('SELECT 1 FROM products LIMIT 1');
        $ready = true;
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

function setting_get(string $key, $default = null)
{
    $all = settings_all();
    return array_key_exists($key, $all) ? $all[$key] : $default;
}

function setting_set(string $key, $value): void
{
    q('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$key, (string) $value]);
    $GLOBALS['PC_SETTINGS_DIRTY'] = true;
}

function bool_setting(string $key, bool $default = false): bool
{
    $v = setting_get($key, $default ? '1' : '0');
    return $v === '1' || $v === 1 || $v === true;
}

/** Zone de livraison (stockée en JSON). */
function zone(): array
{
    $z = json_decode((string) setting_get('zone', ''), true);
    if (!is_array($z) || !isset($z['lat'], $z['lng'])) $z = cfg('zone');
    return [
        'label'  => $z['label']  ?? cfg('zone.label'),
        'lat'    => (float) ($z['lat']  ?? cfg('zone.lat')),
        'lng'    => (float) ($z['lng']  ?? cfg('zone.lng')),
        'radius' => (float) ($z['radius'] ?? cfg('zone.radius')),
    ];
}

/* ------------------------------------------------------------ utilitaires */

function clean($value, int $max = 120): string
{
    $s = is_scalar($value) ? (string) $value : '';
    $s = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $s) ?? '';
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    return mb_substr($s, 0, $max, 'UTF-8');
}

function normalize_phone($value): ?string
{
    $raw = preg_replace('/[^0-9+]/', '', (string) $value) ?? '';
    if (preg_match('/^0\d{9}$/', $raw)) return $raw;
    if (preg_match('/^\+33\d{9}$/', $raw)) return '0' . substr($raw, 3);
    if (preg_match('/^33\d{9}$/', $raw)) return '0' . substr($raw, 2);
    return null;
}

/** Distance en mètres (Haversine). */
function distance_m(?array $a, ?array $b): float
{
    if (!$a || !$b) return INF;
    $lat1 = (float) $a['lat']; $lng1 = (float) $a['lng'];
    $lat2 = (float) $b['lat']; $lng2 = (float) $b['lng'];
    $R = 6371000.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $h = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return 2 * $R * asin(min(1.0, sqrt($h)));
}

function within_zone(?array $pos, array $z): bool
{
    if (!$pos) return false;
    return distance_m($pos, ['lat' => $z['lat'], 'lng' => $z['lng']]) <= (float) $z['radius'];
}

/** Valide et normalise une position GPS envoyée par un téléphone. */
function normalize_position($input): array
{
    if (!is_array($input)) api_fail(422, 'Position GPS manquante.');
    // accepte {lat,lng} comme la structure brute du navigateur {coords:{latitude,longitude}}
    if (isset($input['coords']) && is_array($input['coords'])) {
        $c = $input['coords'];
        $input = [
            'lat'      => $c['latitude']  ?? null,
            'lng'      => $c['longitude'] ?? null,
            'accuracy' => $c['accuracy']  ?? 0,
            'at'       => $input['timestamp'] ?? null,
        ];
    }
    $lat = isset($input['lat']) ? (float) $input['lat'] : null;
    $lng = isset($input['lng']) ? (float) $input['lng'] : null;
    if ($lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        api_fail(422, 'Position GPS invalide.');
    }
    $acc = (int) round((float) ($input['accuracy'] ?? 0));
    $at  = (int) ($input['at'] ?? 0);
    if ($at < 1000000000) $at = (int) round(microtime(true) * 1000);
    return [
        'lat'      => round($lat, 6),
        'lng'      => round($lng, 6),
        'accuracy' => max(0, min(5000, $acc)),
        'at'       => $at,
    ];
}

/* ------------------------------------------------------ réponses HTTP / JSON */

function api_ok(array $data = []): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_fail(int $status, string $message, array $extra = []): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(array_merge(['ok' => false, 'error' => $message], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Corps JSON de la requête. */
function body_json(): array
{
    static $body = null;
    if ($body !== null) return $body;
    $raw = file_get_contents('php://input');
    $decoded = $raw ? json_decode($raw, true) : [];
    $body = is_array($decoded) ? $decoded : [];
    return $body;
}

function body(string $key, $default = null)
{
    $b = body_json();
    return array_key_exists($key, $b) ? $b[$key] : $default;
}

function method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

/** IP du client (derrière le proxy Hostinger). */
function client_ip(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = explode(',', (string) $_SERVER[$k])[0];
            return trim($ip);
        }
    }
    return 'inconnue';
}

/* ------------------------------------------------ limitation de débit simple */

function rate_limit(string $key, int $max, int $windowSeconds): void
{
    $dir = PC_ROOT . '/data';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $file = $dir . '/rl_' . preg_replace('/[^a-z0-9_]/i', '_', $key) . '_' . md5(client_ip()) . '.json';
    $now = time();
    $data = ['count' => 0, 'reset' => $now + $windowSeconds];
    if (is_file($file)) {
        $json = json_decode((string) @file_get_contents($file), true);
        if (is_array($json) && isset($json['count'], $json['reset']) && $json['reset'] > $now) $data = $json;
    }
    $data['count']++;
    @file_put_contents($file, json_encode($data), LOCK_EX);
    if ($data['count'] > $max) {
        $wait = max(1, $data['reset'] - $now);
        header('Retry-After: ' . $wait);
        api_fail(429, 'Trop de tentatives. Merci de patienter quelques secondes.');
    }
}

/** Nettoyage d'entretien (sessions expirées, traces GPS terminées). */
function housekeeping(): void
{
    if (!tables_ready()) return;
    // une fois par heure environ, sans ralentir les requêtes
    $last = (int) setting_get('last_cleanup', '0');
    if (time() - $last < 3600) return;
    setting_set('last_cleanup', time());
    q('DELETE FROM sessions WHERE expires_at < ?', [now_ms()]);
    q('DELETE FROM courier_positions WHERE at < ?', [now_ms() - 86400000]); // traces de plus de 24 h
}

function now_ms(): int
{
    return (int) round(microtime(true) * 1000);
}
