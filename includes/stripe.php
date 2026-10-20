<?php
/**
 * Missiona — client Stripe minimal, Connect Direct Charges
 * ----------------------------------------------------------------------
 * Tous les appels sont réalisés côté serveur. La version livrée est
 * explicitement verrouillée en mode test : une clé sk_live_ ne peut pas
 * activer par erreur un encaissement réel.
 */

/** Stripe est-il réellement prêt en environnement de test ? */
function stripe_actif() {
    return defined('STRIPE_MODE') && STRIPE_MODE === 'test'
        && defined('STRIPE_SECRET_KEY')
        && is_string(STRIPE_SECRET_KEY)
        && strpos(STRIPE_SECRET_KEY, 'sk_test_') === 0;
}

function stripe_mode_test() {
    return stripe_actif();
}

/** Clé publique de test, seule valeur exposable à Stripe.js dans le navigateur. */
function stripe_cle_publique() {
    return defined('STRIPE_PUBLIC_KEY') && is_string(STRIPE_PUBLIC_KEY)
        && strpos(STRIPE_PUBLIC_KEY, 'pk_test_') === 0 ? STRIPE_PUBLIC_KEY : '';
}

/**
 * Appelle l'API Stripe v1 avec des délais réseau bornés.
 * @return array{ok:bool,data:array,erreur:string,code?:int}
 */
function stripe_api($methode, $chemin, array $params = [], array $entetes_sup = []) {
    if (!stripe_actif()) {
        return ['ok' => false, 'data' => [], 'erreur' => "Stripe de test n'est pas configuré.", 'code' => 0];
    }
    $methode = strtoupper($methode);
    if (!in_array($methode, ['GET', 'POST'], true) || !preg_match('#^[A-Za-z0-9_./-]+$#', ltrim($chemin, '/'))) {
        return ['ok' => false, 'data' => [], 'erreur' => 'Requête Stripe invalide.', 'code' => 0];
    }

    $url = 'https://api.stripe.com/v1/' . ltrim($chemin, '/');
    $body = $params ? http_build_query($params, '', '&', PHP_QUERY_RFC3986) : '';
    if ($methode === 'GET' && $body !== '') { $url .= '?' . $body; $body = ''; }

    $entetes = [
        'Authorization: Bearer ' . STRIPE_SECRET_KEY,
        'Content-Type: application/x-www-form-urlencoded',
        'Stripe-Version: 2024-06-20',
    ];
    foreach ($entetes_sup as $entete) {
        // Les en-têtes additionnels sont construits exclusivement dans ce code.
        if (is_string($entete) && strpos($entete, "\n") === false && strpos($entete, "\r") === false) {
            $entetes[] = $entete;
        }
    }

    $brut = null;
    $code = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $methode,
            CURLOPT_HTTPHEADER => $entetes,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($body !== '') { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
        $brut = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $erreur_curl = curl_error($ch);
        curl_close($ch);
        if ($brut === false) {
            return ['ok' => false, 'data' => [], 'erreur' => 'Connexion à Stripe impossible : ' . $erreur_curl, 'code' => $code];
        }
    } else {
        $ctx = stream_context_create(['http' => [
            'method' => $methode,
            'header' => implode("\r\n", $entetes),
            'content' => $body,
            'timeout' => 30,
            'ignore_errors' => true,
        ]]);
        $brut = @file_get_contents($url, false, $ctx);
        if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
            $code = (int) $m[1];
        }
        if ($brut === false) {
            return ['ok' => false, 'data' => [], 'erreur' => 'Connexion à Stripe impossible.', 'code' => $code];
        }
    }

    $data = json_decode((string) $brut, true);
    if (!is_array($data)) { $data = []; }
    if ($code >= 200 && $code < 300) {
        return ['ok' => true, 'data' => $data, 'erreur' => '', 'code' => $code];
    }
    return [
        'ok' => false,
        'data' => $data,
        'erreur' => (string) ($data['error']['message'] ?? ('Erreur Stripe (HTTP ' . $code . ').')),
        'code' => $code,
    ];
}


/**
 * Appelle l'API Stripe v2 (Accounts v2 / Account Links v2) en JSON.
 * Les objets de paiement restent volontairement sur l'API v1, qui demeure
 * compatible avec les comptes Connect créés en v2.
 *
 * @return array{ok:bool,data:array,erreur:string,code?:int}
 */
function stripe_api_v2($methode, $chemin, array $params = [], array $entetes_sup = []) {
    if (!stripe_actif()) {
        return ['ok' => false, 'data' => [], 'erreur' => "Stripe de test n'est pas configuré.", 'code' => 0];
    }
    $methode = strtoupper($methode);
    $chemin = ltrim((string) $chemin, '/');
    if (!in_array($methode, ['GET', 'POST'], true) || !preg_match('#^[A-Za-z0-9_./-]+$#', $chemin)) {
        return ['ok' => false, 'data' => [], 'erreur' => 'Requête Stripe v2 invalide.', 'code' => 0];
    }

    $url = 'https://api.stripe.com/v2/' . $chemin;
    $body = '';
    if ($methode === 'GET' && $params) {
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        if ($query !== '') { $url .= '?' . $query; }
    } elseif ($params) {
        $body = json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($body)) {
            return ['ok' => false, 'data' => [], 'erreur' => 'Données Stripe v2 invalides.', 'code' => 0];
        }
    }

    // La version v2 est explicitement requise par Stripe pour Accounts v2.
    $version = defined('STRIPE_ACCOUNTS_V2_VERSION') && is_string(STRIPE_ACCOUNTS_V2_VERSION)
        ? trim(STRIPE_ACCOUNTS_V2_VERSION) : '2026-09-30.endive';
    if ($version === '' || !preg_match('/^[A-Za-z0-9.-]+$/', $version)) {
        return ['ok' => false, 'data' => [], 'erreur' => 'Version Stripe v2 invalide.', 'code' => 0];
    }
    $entetes = [
        'Authorization: Bearer ' . STRIPE_SECRET_KEY,
        'Accept: application/json',
        'Content-Type: application/json',
        'Stripe-Version: ' . $version,
    ];
    foreach ($entetes_sup as $entete) {
        // Les en-têtes additionnels sont construits exclusivement dans ce code.
        if (is_string($entete) && strpos($entete, "\n") === false && strpos($entete, "\r") === false) {
            $entetes[] = $entete;
        }
    }

    $brut = null;
    $code = 0;
    if (function_exists('curl_init')) {
        // Token → Account → Account Link sont trois appels obligatoirement
        // séquentiels lors du premier parcours français. Le même handle garde
        // la connexion TLS vers api.stripe.com durant cette requête PHP et
        // évite deux ouvertures réseau inutiles. Il est libéré en fin de
        // requête, sans état partagé entre visiteurs.
        static $curl_v2 = null;
        if ($curl_v2 === null) {
            $curl_v2 = curl_init();
        } else {
            curl_reset($curl_v2);
        }
        $ch = $curl_v2;
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $methode,
            CURLOPT_HTTPHEADER => $entetes,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($body !== '') { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
        $brut = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $erreur_curl = curl_error($ch);
        if ($brut === false) {
            return ['ok' => false, 'data' => [], 'erreur' => 'Connexion à Stripe impossible : ' . $erreur_curl, 'code' => $code];
        }
    } else {
        $ctx = stream_context_create(['http' => [
            'method' => $methode,
            'header' => implode("\r\n", $entetes),
            'content' => $body,
            'timeout' => 30,
            'ignore_errors' => true,
        ]]);
        $brut = @file_get_contents($url, false, $ctx);
        if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
            $code = (int) $m[1];
        }
        if ($brut === false) {
            return ['ok' => false, 'data' => [], 'erreur' => 'Connexion à Stripe impossible.', 'code' => $code];
        }
    }

    $data = json_decode((string) $brut, true);
    if (!is_array($data)) { $data = []; }
    if ($code >= 200 && $code < 300) {
        return ['ok' => true, 'data' => $data, 'erreur' => '', 'code' => $code];
    }
    return [
        'ok' => false,
        'data' => $data,
        'erreur' => (string) ($data['error']['message'] ?? ('Erreur Stripe v2 (HTTP ' . $code . ').')),
        'code' => $code,
    ];
}


/** URL publique contrôlée dans la configuration, jamais dérivée de Host. */
function site_url() {
    $url = defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : '';
    if (preg_match('#^https?://[A-Za-z0-9.-]+(?::\d+)?(?:/[^\s]*)?$#i', $url)) { return $url; }
    return 'http://localhost';
}

function stripe_compte_entetes($compte, $idempotence = '') {
    if (!function_exists('connect_compte_valide') || !connect_compte_valide($compte)) { return []; }
    $out = ['Stripe-Account: ' . $compte];
    if ($idempotence !== '' && preg_match('/^[A-Za-z0-9_-]{8,255}$/', $idempotence)) {
        $out[] = 'Idempotency-Key: ' . $idempotence;
    }
    return $out;
}

/** Commission incluse dans un total carte payé : total = base + commission. */
function stripe_frais_application_centimes($montant_euros) {
    $total = max(0, (int) $montant_euros) * 100;
    $taux = (function_exists('taux_frais') && !mode_gratuit()) ? max(0.0, min(99.0, (float) taux_frais())) : 0.0;
    if ($total <= 1 || $taux <= 0) { return 0; }
    // Le taux est une part du total client : 250 € à 20 % = 50 € de commission.
    // Même arrondi à l'euro que decomposer_montant(), puis conversion en centimes.
    $part_euros = (int) round(((int) $montant_euros) * ($taux / 100.0));
    return max(0, min($total - 1, $part_euros * 100));
}

/**
 * Réserve une clé d'idempotence persistée pour une échéance. Le nom de colonne
 * historique stripe_checkout_key est conservé afin de migrer sans perte les
 * installations existantes ; la clé protège désormais le PaymentIntent.
 */
function stripe_paiement_reserver($paiement_id) {
    $pdo = db();
    $demarre = !$pdo->inTransaction();
    if ($demarre) { $pdo->beginTransaction(); }
    try {
        $st = $pdo->prepare("SELECT * FROM paiements WHERE id=?");
        $st->execute([(int) $paiement_id]);
        $paiement = $st->fetch();
        if (!$paiement || $paiement['statut'] !== 'a_payer' || $paiement['type'] !== 'projet') {
            if ($demarre) { $pdo->commit(); }
            return ['ok' => false, 'paiement' => null, 'erreur' => 'Cette échéance ne peut plus être réglée.'];
        }
        $cle = (string) ($paiement['stripe_checkout_key'] ?? '');
        if ($cle === '') {
            $candidate = bin2hex(random_bytes(24));
            $pdo->prepare("UPDATE paiements SET stripe_checkout_key=? WHERE id=? AND stripe_checkout_key=''")
                ->execute([$candidate, (int) $paiement_id]);
            $relire = $pdo->prepare("SELECT stripe_checkout_key FROM paiements WHERE id=?");
            $relire->execute([(int) $paiement_id]);
            $cle = (string) $relire->fetchColumn();
            if ($cle === '') { throw new RuntimeException('Clé de paiement absente.'); }
            $paiement['stripe_checkout_key'] = $cle;
        }
        if ($demarre) { $pdo->commit(); }
        return ['ok' => true, 'paiement' => $paiement, 'erreur' => ''];
    } catch (Throwable $e) {
        if ($demarre && $pdo->inTransaction()) { $pdo->rollBack(); }
        return ['ok' => false, 'paiement' => null, 'erreur' => 'Impossible de préparer le paiement.'];
    }
}

/** Lit un PaymentIntent direct depuis le compte connecté concerné. */
function stripe_intent_direct($intent_id, $compte) {
    if (!preg_match('/^pi_[A-Za-z0-9]+$/', (string) $intent_id)) {
        return ['ok' => false, 'data' => [], 'erreur' => 'Référence de paiement invalide.'];
    }
    $headers = stripe_compte_entetes($compte);
    if (!$headers) { return ['ok' => false, 'data' => [], 'erreur' => 'Compte Stripe invalide.']; }
    return stripe_api('GET', 'payment_intents/' . rawurlencode($intent_id), [], $headers);
}

/**
 * Crée ou reprend le PaymentIntent d'un paiement direct Connect.
 * La charge est créée sur le compte de l'expert via Stripe-Account ; Stripe
 * Payment Element reçoit seulement le client_secret, jamais la clé secrète.
 */
function stripe_creer_intent_direct($projet, $paiement, $client) {
    if (!stripe_actif() || stripe_cle_publique() === '') {
        return ['ok' => false, 'intent' => [], 'erreur' => "Les clés Stripe de test ne sont pas complètement configurées."];
    }
    if (($projet['statut'] ?? '') !== 'attribue' || (int) ($paiement['projet_id'] ?? 0) !== (int) ($projet['id'] ?? 0)
        || empty($paiement['stripe_direct'])) {
        return ['ok' => false, 'intent' => [], 'erreur' => 'Le projet ne peut pas être réglé à cette étape.'];
    }
    if ((int) ($paiement['montant'] ?? 0) <= 0) {
        return ['ok' => false, 'intent' => [], 'erreur' => 'Montant de paiement invalide.'];
    }

    $st = db()->prepare("SELECT * FROM users WHERE id=? AND role='freelance'");
    $st->execute([(int) ($projet['freelance_id'] ?? 0)]);
    $expert = $st->fetch();
    if (!$expert) { return ['ok' => false, 'intent' => [], 'erreur' => "L'expert associé à ce projet est introuvable."]; }
    $etat = connect_rafraichir($expert);
    if (!$etat['ok'] || !$etat['charges']) {
        return ['ok' => false, 'intent' => [], 'erreur' => "Le compte Stripe de l'expert n'est pas encore prêt à encaisser."];
    }
    $compte = (string) $expert['stripe_account'];

    $reserve = stripe_paiement_reserver((int) $paiement['id']);
    if (!$reserve['ok']) { return ['ok' => false, 'intent' => [], 'erreur' => $reserve['erreur']]; }
    $paiement = $reserve['paiement'];

    // Une ancienne Session Checkout encore ouverte ne doit pas être remplacée
    // par un PaymentIntent : cela éviterait un risque de double débit pendant
    // la migration vers Elements.
    if (!empty($paiement['stripe_session'])) {
        return ['ok' => false, 'intent' => [],
                'erreur' => "Un ancien paiement Checkout est encore en attente pour ce projet. Contactez Missiona avant de recommencer."];
    }

    $intent_id = (string) ($paiement['stripe_ref'] ?? '');
    if (preg_match('/^pi_[A-Za-z0-9]+$/', $intent_id)) {
        $ancien = stripe_intent_direct($intent_id, $compte);
        if ($ancien['ok']) {
            $statut = (string) ($ancien['data']['status'] ?? '');
            if ($statut === 'succeeded') {
                $marque = stripe_marquer_paiement_direct((int) $paiement['id'], $ancien['data'], $compte);
                return $marque['ok']
                    ? ['ok' => true, 'intent' => $ancien['data'], 'deja_paye' => true, 'erreur' => '']
                    : ['ok' => false, 'intent' => [], 'erreur' => $marque['erreur']];
            }
            if (in_array($statut, ['requires_payment_method', 'requires_confirmation', 'requires_action', 'processing'], true)
                && !empty($ancien['data']['client_secret'])) {
                // Ne jamais réexposer un client_secret si l'intent encore
                // ouvert ne correspond plus exactement à notre échéance.
                // Cela évite qu'un ancien devis puisse être débité après une
                // modification administrative ou un changement de taux.
                $frais_attendus = (int) ($paiement['stripe_application_fee'] ?? 0);
                if ($frais_attendus === 0 && stripe_frais_application_centimes((int) $paiement['montant']) > 0) {
                    $frais_attendus = stripe_frais_application_centimes((int) $paiement['montant']);
                }
                if ((int) ($ancien['data']['amount'] ?? -1) !== ((int) $paiement['montant'] * 100)
                    || (int) ($ancien['data']['application_fee_amount'] ?? 0) !== $frais_attendus) {
                    return ['ok' => false, 'intent' => [],
                            'erreur' => "Le paiement en attente ne correspond plus au devis. Annulez-le avant de le recréer."];
                }
                return ['ok' => true, 'intent' => $ancien['data'], 'deja_paye' => false, 'erreur' => ''];
            }
        }
        // Intent annulé, échoué ou inaccessible : une nouvelle tentative garde
        // une clé d'idempotence neuve, sans réutiliser une ancienne réponse.
        db()->prepare("UPDATE paiements SET stripe_ref='', stripe_account='', stripe_checkout_key=?
                       WHERE id=? AND statut='a_payer'")
            ->execute([bin2hex(random_bytes(24)), (int) $paiement['id']]);
        $reserve = stripe_paiement_reserver((int) $paiement['id']);
        if (!$reserve['ok']) { return ['ok' => false, 'intent' => [], 'erreur' => $reserve['erreur']]; }
        $paiement = $reserve['paiement'];
    }

    $montant_centimes = (int) $paiement['montant'] * 100;
    $frais_centimes = stripe_frais_application_centimes((int) $paiement['montant']);
    $params = [
        'amount' => $montant_centimes,
        'currency' => 'eur',
        // Stripe sélectionne dynamiquement les moyens compatibles avec la
        // devise, le pays et les réglages du compte connecté.
        'automatic_payment_methods[enabled]' => 'true',
        'metadata[missiona_projet_id]' => (int) $projet['id'],
        'metadata[missiona_paiement_id]' => (int) $paiement['id'],
    ];
    if ($frais_centimes > 0) {
        $params['application_fee_amount'] = $frais_centimes;
    }

    $r = stripe_api('POST', 'payment_intents', $params,
        stripe_compte_entetes($compte, 'missiona-intent-' . (int) $paiement['id'] . '-' . $paiement['stripe_checkout_key']));
    if (!$r['ok']) { return ['ok' => false, 'intent' => [], 'erreur' => $r['erreur']]; }
    $intent = $r['data'];
    $intent_id = (string) ($intent['id'] ?? '');
    if (!preg_match('/^pi_[A-Za-z0-9]+$/', $intent_id) || empty($intent['client_secret'])
        || (int) ($intent['application_fee_amount'] ?? 0) !== $frais_centimes) {
        return ['ok' => false, 'intent' => [], 'erreur' => 'Réponse PaymentIntent inattendue.'];
    }
    db()->prepare("UPDATE paiements SET stripe_ref=?, stripe_account=?, stripe_application_fee=?
                   WHERE id=? AND statut='a_payer' AND stripe_checkout_key=?")
        ->execute([$intent_id, $compte, $frais_centimes, (int) $paiement['id'], $paiement['stripe_checkout_key']]);
    return ['ok' => true, 'intent' => $intent, 'deja_paye' => false, 'erreur' => ''];
}

/**
 * Vérifie et enregistre un PaymentIntent réussi. L'opération est atomique :
 * retour navigateur et webhooks Connect peuvent se présenter plusieurs fois
 * sans faire démarrer une mission ou envoyer des notifications en double.
 */
function stripe_marquer_paiement_direct($paiement_id, $intent, $compte) {
    $echec = ['ok' => false, 'nouveau' => false, 'erreur' => 'Paiement non validé.'];
    if (!is_array($intent) || !connect_compte_valide($compte)) { return $echec; }
    $intent_id = (string) ($intent['id'] ?? '');
    if (!preg_match('/^pi_[A-Za-z0-9]+$/', $intent_id)
        || ($intent['status'] ?? '') !== 'succeeded'
        || strtolower((string) ($intent['currency'] ?? '')) !== 'eur') {
        return $echec;
    }

    $pdo = db();
    $demarre = !$pdo->inTransaction();
    if ($demarre) { $pdo->beginTransaction(); }
    try {
        $st = $pdo->prepare("SELECT pa.*, p.id AS projet_id, p.titre, p.statut AS projet_statut,
                                     p.freelance_id, p.entreprise_id, u.stripe_account AS expert_compte
                              FROM paiements pa
                              JOIN projets p ON p.id=pa.projet_id
                              JOIN users u ON u.id=p.freelance_id
                              WHERE pa.id=? AND pa.type='projet'");
        $st->execute([(int) $paiement_id]);
        $pa = $st->fetch();
        if (!$pa) { throw new RuntimeException('Échéance absente.'); }
        if ($pa['statut'] === 'paye' && hash_equals((string) ($pa['stripe_ref'] ?? ''), $intent_id)) {
            if ($demarre) { $pdo->commit(); }
            return ['ok' => true, 'nouveau' => false, 'erreur' => ''];
        }
        // La commission doit être celle mémorisée à la création du PI, et
        // non le taux éventuellement modifié depuis. Pour une ancienne ligne
        // sans trace, le contrôle conservateur utilise le calcul en vigueur.
        $frais_attendus = (int) ($pa['stripe_application_fee'] ?? 0);
        if ($frais_attendus === 0 && stripe_frais_application_centimes((int) $pa['montant']) > 0) {
            $frais_attendus = stripe_frais_application_centimes((int) $pa['montant']);
        }
        if ($pa['statut'] !== 'a_payer' || $pa['projet_statut'] !== 'attribue'
            || !hash_equals((string) ($pa['stripe_ref'] ?? ''), $intent_id)
            || !hash_equals((string) ($pa['stripe_account'] ?: $pa['expert_compte']), $compte)
            || (int) ($intent['amount'] ?? -1) !== ((int) $pa['montant'] * 100)
            || (int) ($intent['application_fee_amount'] ?? 0) !== $frais_attendus
            || (int) (($intent['metadata']['missiona_paiement_id'] ?? 0)) !== (int) $pa['id']
            || (int) (($intent['metadata']['missiona_projet_id'] ?? 0)) !== (int) $pa['projet_id']) {
            throw new RuntimeException('Les données PaymentIntent ne correspondent pas à cette échéance.');
        }

        $maj = $pdo->prepare("UPDATE paiements
                              SET statut='paye', paye_le=CURRENT_TIMESTAMP, reference=?, stripe_ref=?, stripe_account=?, stripe_application_fee=?
                              WHERE id=? AND statut='a_payer' AND stripe_ref=?");
        $maj->execute(['STRIPE-' . strtoupper(substr($intent_id, 0, 32)), $intent_id, $compte, $frais_attendus, (int) $pa['id'], $intent_id]);
        if ($maj->rowCount() !== 1) { throw new RuntimeException('Cette échéance a déjà changé.'); }
        $pdo->prepare("UPDATE projets SET statut='en_cours', avancement=MAX(avancement, 10)
                       WHERE id=? AND statut='attribue'")->execute([(int) $pa['projet_id']]);
        if ($demarre) { $pdo->commit(); }

        email_suivi_projet_client((int) $pa['projet_id'], 'en_cours');
        notify((int) $pa['entreprise_id'], "Paiement par carte confirmé pour « " . $pa['titre'] . " ». La mission démarre.", 'projet.php?id=' . (int) $pa['projet_id']);
        notify((int) $pa['freelance_id'], "Le paiement par carte est confirmé pour « " . $pa['titre'] . " ». La mission démarre.", 'projet.php?id=' . (int) $pa['projet_id']);
        notify(admin_id(), "Paiement Stripe confirmé pour « " . $pa['titre'] . " ».", 'projet.php?id=' . (int) $pa['projet_id']);
        return ['ok' => true, 'nouveau' => true, 'erreur' => ''];
    } catch (Throwable $e) {
        if ($demarre && $pdo->inTransaction()) { $pdo->rollBack(); }
        @error_log('[stripe-elements] validation paiement ' . (int) $paiement_id . ' : ' . $e->getMessage());
        return $echec;
    }
}

/** Retour navigateur : le PaymentIntent est relu côté serveur, pas l'URL. */
function stripe_confirmer_retour_direct($projet_id, $intent_id, $client_id) {
    $st = db()->prepare("SELECT pa.*, p.entreprise_id
                         FROM paiements pa JOIN projets p ON p.id=pa.projet_id
                         WHERE pa.projet_id=? AND pa.type='projet' LIMIT 1");
    $st->execute([(int) $projet_id]);
    $pa = $st->fetch();
    if (!$pa || (int) $pa['entreprise_id'] !== (int) $client_id
        || !hash_equals((string) ($pa['stripe_ref'] ?? ''), (string) $intent_id)) {
        return ['ok' => false, 'nouveau' => false, 'erreur' => 'Retour de paiement invalide.'];
    }
    $compte = (string) ($pa['stripe_account'] ?? '');
    $r = stripe_intent_direct($intent_id, $compte);
    if (!$r['ok']) { return ['ok' => false, 'nouveau' => false, 'erreur' => 'La confirmation est en cours.']; }
    if (($r['data']['status'] ?? '') === 'processing') {
        return ['ok' => false, 'nouveau' => false, 'en_attente' => true, 'erreur' => 'Paiement en cours de traitement.'];
    }
    return stripe_marquer_paiement_direct((int) $pa['id'], $r['data'], $compte);
}

/** Annule un PaymentIntent non encaissé avant une annulation de projet. */
function stripe_annuler_intent_direct($paiement) {
    $intent_id = (string) ($paiement['stripe_ref'] ?? '');
    $compte = (string) ($paiement['stripe_account'] ?? '');
    if ($intent_id === '' || !connect_compte_valide($compte)) {
        return ['ok' => true, 'paye' => false, 'intent' => [], 'erreur' => ''];
    }
    $lecture = stripe_intent_direct($intent_id, $compte);
    if (!$lecture['ok']) { return ['ok' => false, 'paye' => false, 'intent' => [], 'erreur' => $lecture['erreur']]; }
    if (($lecture['data']['status'] ?? '') === 'succeeded') {
        return ['ok' => true, 'paye' => true, 'intent' => $lecture['data'], 'erreur' => ''];
    }
    if (($lecture['data']['status'] ?? '') === 'canceled') {
        return ['ok' => true, 'paye' => false, 'intent' => [], 'erreur' => ''];
    }
    $r = stripe_api('POST', 'payment_intents/' . rawurlencode($intent_id) . '/cancel', [], stripe_compte_entetes($compte));
    if (!$r['ok']) { return ['ok' => false, 'paye' => false, 'intent' => [], 'erreur' => $r['erreur']]; }
    return ['ok' => true, 'paye' => false, 'intent' => [], 'erreur' => ''];
}

/** Rembourse une charge directe depuis le même compte connecté et rend la commission. */
function stripe_rembourser_paiement_direct($paiement) {
    $intent = (string) ($paiement['stripe_ref'] ?? '');
    $compte = (string) ($paiement['stripe_account'] ?? '');
    if (!preg_match('/^pi_[A-Za-z0-9]+$/', $intent) || !connect_compte_valide($compte)) {
        return ['ok' => false, 'erreur' => 'Référence Stripe de remboursement absente.'];
    }
    $r = stripe_api('POST', 'refunds', [
        'payment_intent' => $intent,
        'refund_application_fee' => 'true',
        'metadata[missiona_paiement_id]' => (int) ($paiement['id'] ?? 0),
    ], stripe_compte_entetes($compte, 'missiona-refund-' . (int) ($paiement['id'] ?? 0)));
    return ['ok' => $r['ok'], 'erreur' => $r['erreur']];
}

/* Compatibilité avec les anciens appels internes : les paiements directs ne
   créent plus de destination charges ni de sessions plateforme. */
function stripe_creer_session($projet, $type, $montant) {
    return ['ok' => false, 'url' => '', 'erreur' => 'Utilisez le flux de paiement direct Connect.'];
}
function stripe_session_payee($session_id) { return false; }
function stripe_payment_intent($session_id) { return ''; }
function stripe_rembourser($payment_intent, $montant = null) {
    return ['ok' => false, 'erreur' => 'Un remboursement direct doit être exécuté sur le compte connecté concerné.'];
}
