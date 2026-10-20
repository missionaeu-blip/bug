<?php
/**
 * Missiona — Notifications sur téléphone (Web Push)
 * ------------------------------------------------------------------
 * Écrit en PHP pur, sans bibliothèque.
 *
 * CHOIX D'ARCHITECTURE : les notifications sont envoyées SANS contenu.
 * Le protocole autorise un message chiffré, mais ce chiffrement
 * (ECDH + HKDF + AES-GCM) est la partie la plus fragile de Web Push
 * et casse silencieusement à la moindre erreur d'octet.
 *
 * Ici, le serveur envoie une impulsion vide ; le téléphone rappelle
 * alors le site pour lire le message à afficher. Résultat identique
 * pour l'utilisateur, beaucoup plus sûr à maintenir — et le contenu
 * ne transite jamais par les serveurs de Google ou Mozilla.
 */

/* ==================================================================
 *  Outils
 * ================================================================== */

function push_b64url($bin) {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function push_b64url_decode($txt) {
    $t = strtr($txt, '-_', '+/');
    return base64_decode($t . str_repeat('=', (4 - strlen($t) % 4) % 4));
}

/**
 * Convertit une signature ECDSA au format DER (ce que produit
 * OpenSSL) en 64 octets bruts r||s, seul format accepté par JWT.
 */
function push_der_vers_raw($der) {
    if (strlen($der) < 8 || ord($der[0]) !== 0x30) { return false; }
    $i = (ord($der[1]) & 0x80) ? 2 + (ord($der[1]) & 0x7F) : 2;

    $lire = function () use ($der, &$i) {
        if (ord($der[$i]) !== 0x02) { return false; }
        $len = ord($der[$i + 1]);
        $val = substr($der, $i + 2, $len);
        $i  += 2 + $len;
        $val = ltrim($val, "\x00");                       // retire le zéro de signe
        return str_pad($val, 32, "\x00", STR_PAD_LEFT);   // complète à 32 octets
    };
    $r = $lire(); if ($r === false) { return false; }
    $s = $lire(); if ($s === false) { return false; }
    return $r . $s;
}

/* ==================================================================
 *  Clés VAPID — l'identité du serveur auprès des services de push
 * ================================================================== */

/**
 * Récupère la paire de clés, en la créant au besoin.
 * @return array{prive:string,public:string}|null
 */
function push_vapid() {
    static $c = null;
    if ($c !== null) { return $c; }

    $pem = (string) param('vapid_prive', '');
    $pub = (string) param('vapid_public', '');

    if ($pem === '' || $pub === '') {
        $k = @openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        if (!$k) { return $c = null; }

        openssl_pkey_export($k, $pem);
        $d = openssl_pkey_get_details($k);
        if (empty($d['ec']['x']) || empty($d['ec']['y'])) { return $c = null; }

        // Point public non compressé : 0x04 || X || Y
        $brut = "\x04" . str_pad($d['ec']['x'], 32, "\x00", STR_PAD_LEFT)
                       . str_pad($d['ec']['y'], 32, "\x00", STR_PAD_LEFT);
        $pub = push_b64url($brut);

        set_param('vapid_prive', $pem);
        set_param('vapid_public', $pub);
    }
    return $c = ['prive' => $pem, 'public' => $pub];
}

/** Clé publique à transmettre au navigateur. */
function push_cle_publique() {
    $v = push_vapid();
    return $v ? $v['public'] : '';
}

/** Les notifications sont-elles utilisables sur ce serveur ? */
function push_disponible() {
    return extension_loaded('openssl') && push_cle_publique() !== '';
}

/**
 * Jeton d'authentification pour un service de push donné.
 * Valable 12 h, propre au domaine du destinataire.
 */
function push_jwt($endpoint) {
    $v = push_vapid();
    if (!$v) { return ''; }

    $p = parse_url($endpoint);
    if (empty($p['scheme']) || empty($p['host'])) { return ''; }
    $aud = $p['scheme'] . '://' . $p['host'];

    $contact = defined('SITE_EMAIL') && SITE_EMAIL !== ''
        ? 'mailto:' . SITE_EMAIL : 'mailto:admin@localhost';

    $entete = push_b64url(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $corps  = push_b64url(json_encode([
        'aud' => $aud,
        'exp' => time() + 43200,
        'sub' => $contact,
    ], JSON_UNESCAPED_SLASHES));

    $aSigner = $entete . '.' . $corps;
    $der = '';
    if (!openssl_sign($aSigner, $der, $v['prive'], OPENSSL_ALGO_SHA256)) { return ''; }

    $raw = push_der_vers_raw($der);
    if ($raw === false) { return ''; }

    return $aSigner . '.' . push_b64url($raw);
}

/* ==================================================================
 *  Envoi
 * ================================================================== */

/**
 * Requête HTTP, avec repli si cURL n'est pas installé.
 * @return int code HTTP, 0 si la requête n'a pas abouti
 */
function push_http($url, array $entetes) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => '',
            CURLOPT_HTTPHEADER     => $entetes,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code;
    }

    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => implode("\r\n", $entetes),
        'content'       => '',
        'timeout'       => 15,
        'ignore_errors' => true,
    ]]);
    @file_get_contents($url, false, $ctx);
    if (!isset($http_response_header[0])) { return 0; }
    return (int) (preg_match('#\s(\d{3})\s#', $http_response_header[0], $m) ? $m[1] : 0);
}

/**
 * Envoie une impulsion à un abonnement.
 * @return int code HTTP (201 = accepté, 404/410 = abonnement périmé)
 */
function push_envoyer($abonne) {
    $jwt = push_jwt($abonne['endpoint']);
    if ($jwt === '') { return 0; }

    return push_http($abonne['endpoint'], [
        'Authorization: vapid t=' . $jwt . ', k=' . push_cle_publique(),
        'TTL: 86400',
        'Urgency: normal',
        'Content-Length: 0',
    ]);
}

/**
 * Prévient tous les administrateurs abonnés.
 * La notification est d'abord enregistrée en base : c'est elle que
 * le téléphone viendra lire. Sans abonnement actif, elle reste
 * simplement visible dans le back-office.
 */
function push_alerter_admins($texte, $lien = 'admin.php') {
    try {
        $admins = db()->query("SELECT id FROM users WHERE role='admin' AND actif=1")->fetchAll();
        foreach ($admins as $a) {
            notify((int) $a['id'], $texte, $lien);
        }
        if (!push_disponible()) { return; }

        $st = db()->query("SELECT * FROM push_abonnes");
        foreach ($st as $ab) {
            $code = push_envoyer($ab);
            if ($code === 404 || $code === 410) {
                // Abonnement revoque : inutile de le garder
                db()->prepare("DELETE FROM push_abonnes WHERE id=?")->execute([(int) $ab['id']]);
            } elseif ($code >= 200 && $code < 300) {
                db()->prepare("UPDATE push_abonnes SET dernier_ok=datetime('now'), echecs=0 WHERE id=?")
                    ->execute([(int) $ab['id']]);
            } else {
                db()->prepare("UPDATE push_abonnes SET echecs=echecs+1 WHERE id=?")
                    ->execute([(int) $ab['id']]);
                // Après 10 échecs consécutifs, on cesse d'insister
                db()->exec("DELETE FROM push_abonnes WHERE echecs > 10");
            }
        }
    } catch (Throwable $e) {
        @error_log('[push] ' . $e->getMessage());
    }
}

/* ==================================================================
 *  Déclencheurs métier
 * ================================================================== */

/** Appelé après chaque inscription : alerte tous les 10 comptes. */
function push_apres_inscription() {
    try {
        $palier = max(1, (int) param('push_palier_inscrits', 10));
        $total  = (int) db()->query("SELECT COUNT(*) FROM users WHERE role <> 'admin'")->fetchColumn();
        if ($total > 0 && $total % $palier === 0) {
            push_alerter_admins($total . ' inscrits sur Missiona.', 'admin-utilisateurs.php');
        }
    } catch (Throwable $e) {}
}

/** Appelé à la création d'un projet. */
function push_nouveau_projet($titre, $projet_id = 0) {
    $t = trim((string) $titre);
    if ($t === '') { $t = 'sans titre'; }
    if (function_exists('stats_tronquer')) { $t = stats_tronquer($t, 60); }
    push_alerter_admins('Nouveau projet : ' . $t,
        $projet_id ? 'projet.php?id=' . (int) $projet_id : 'admin-projets.php');
}
