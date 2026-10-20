<?php
/**
 * Missiona — Stripe Connect, paiements directs
 * ------------------------------------------------------------------
 * Architecture retenue :
 * - compte connecté créé avec Stripe Accounts v2, tableau de bord Stripe
 *   complet (dashboard: full) ;
 * - onboarding Stripe hébergé avec Account Links v2 ;
 * - prélèvement direct sur le compte de l'expert (voir stripe.php).
 *
 * Missiona ne collecte ni IBAN ni information KYC. Seul l'identifiant
 * Stripe acct_… est conservé. Cette version est volontairement limitée au
 * mode test tant qu'une mise en production n'a pas été revue séparément.
 */

/** Connect n'est utilisable qu'avec une clé Stripe de TEST valide. */
function connect_actif() {
    return stripe_actif() && stripe_mode_test();
}

/** Pays proposés à l'inscription Connect. Configurables par l'administration. */
function connect_pays_supportes() {
    $perso = trim((string) param('connect_pays', ''));
    if ($perso !== '') {
        $l = array_filter(array_map('trim', explode(',', strtoupper($perso))));
        if ($l) { return $l; }
    }
    return array_keys(pays_liste());
}

function connect_pays_ok($pays) {
    $p = strtoupper(trim((string) $pays));
    return $p !== '' && $p !== 'AUTRE' && in_array($p, connect_pays_supportes(), true);
}

/** Un identifiant de compte Stripe ne doit jamais venir librement d'un POST. */
function connect_compte_valide($compte) {
    return is_string($compte) && (bool) preg_match('/^acct_[A-Za-z0-9]+$/', $compte);
}

/** Le freelance est-il éligible au parcours Connect actif ? */
function connect_pour($u) {
    if (!connect_actif() || !is_array($u) || ($u['role'] ?? '') !== 'freelance') { return false; }
    if (!empty($u['payout_manuel'])) { return false; }
    if (!empty($u['stripe_account'])) { return connect_compte_valide($u['stripe_account']); }
    $pays = strtoupper(trim((string) ($u['pays'] ?? '')));
    return connect_pays_ok($pays);
}

/**
 * Ancien repli historique. Il ne doit plus être proposé par le nouveau
 * parcours de paiement direct ; il reste afin de ne pas casser une base
 * existante qui possède déjà cette colonne.
 */
function connect_basculer_en_manuel($user_id, $motif = '') {
    db()->prepare("UPDATE users SET payout_manuel = 1 WHERE id = ?")->execute([(int) $user_id]);
    if ($motif !== '') {
        @error_log('[stripe-connect] repli manuel user ' . (int) $user_id . ' : ' . $motif);
    }
}

/** Champs v2 explicitement demandés afin d'évaluer l'état du compte. */
function connect_compte_v2_lire($compte) {
    if (!connect_compte_valide($compte)) {
        return ['ok' => false, 'data' => [], 'erreur' => 'Identifiant de compte Stripe invalide.', 'code' => 0];
    }
    return stripe_api_v2('GET', 'core/accounts/' . rawurlencode($compte), [
        'include[0]' => 'configuration.merchant',
        'include[1]' => 'requirements',
        'include[2]' => 'identity',
        'include[3]' => 'defaults',
    ]);
}

/** Nom sobre transmis à Stripe ; les données KYC seront saisies chez Stripe. */
function connect_nom_affichage($u) {
    $nom = trim((string) ($u['prenom'] ?? '') . ' ' . (string) ($u['nom'] ?? ''));
    if ($nom === '') { $nom = trim((string) ($u['titre_pro'] ?? '')); }
    if ($nom === '') {
        $email = trim((string) ($u['email'] ?? ''));
        $nom = strpos($email, '@') !== false ? substr($email, 0, strpos($email, '@')) : $email;
    }
    if ($nom === '') { $nom = 'Expert Missiona'; }
    return function_exists('mb_substr') ? mb_substr($nom, 0, 250, 'UTF-8') : substr($nom, 0, 250);
}

/**
 * Un Account Token v2 est généré uniquement par Stripe côté serveur, jamais
 * issu d'un POST. Stripe traite ses identifiants comme opaques : ne pas
 * figer ici un préfixe ou un format susceptible d'évoluer selon la version
 * Accounts v2 / l'environnement de test.
 */
function connect_token_compte_valide($token) {
    return is_string($token) && (bool) preg_match('/^[A-Za-z0-9_-]{8,255}$/', $token);
}

/** Supprime un jeton expiré/utilisé de la session de l'expert concerné. */
function connect_oublier_token_compte($user_id) {
    $memoire = $_SESSION['stripe_connect_account_token'] ?? [];
    if (is_array($memoire) && (int) ($memoire['user_id'] ?? 0) === (int) $user_id) {
        unset($_SESSION['stripe_connect_account_token']);
    }
}

/**
 * Crée ou réutilise brièvement un Account Token v2. Les plateformes basées en
 * France doivent transmettre l'identité initiale par ce jeton, et non dans la
 * requête Accounts v2. En test, Stripe autorise sa création serveur ; le
 * verrou strict sk_test_ de Missiona empêche ce chemin en production.
 *
 * La session réutilise le même jeton et la même clé d'idempotence en cas de
 * coupure réseau afin d'éviter un doublon de compte. Les jetons Stripe sont à
 * usage unique et expirent rapidement.
 *
 * @return array{ok:bool,token:string,idempotence:string,erreur:string}
 */
function connect_obtenir_token_compte($u, $pays) {
    $memoire = $_SESSION['stripe_connect_account_token'] ?? [];
    if (is_array($memoire)
        && (int) ($memoire['user_id'] ?? 0) === (int) ($u['id'] ?? 0)
        && connect_token_compte_valide((string) ($memoire['token'] ?? ''))
        && is_string($memoire['idempotence'] ?? null)
        && preg_match('/^[A-Za-z0-9_-]{8,255}$/', (string) $memoire['idempotence'])
        && (int) ($memoire['expire'] ?? 0) > time() + 15) {
        return ['ok' => true, 'token' => (string) $memoire['token'],
                'idempotence' => (string) $memoire['idempotence'], 'erreur' => ''];
    }
    connect_oublier_token_compte((int) ($u['id'] ?? 0));

    $est_entite = (($u['type_vendeur'] ?? 'physique') === 'entite');
    $identite = [
        // Account Tokens v2 accept entity_type but not identity.country.
        // Stripe requires the country separately on the Accounts v2 request.
        'entity_type' => $est_entite ? 'company' : 'individual',
    ];
    // Le nom légal éventuellement connu est transmis uniquement dans le
    // jeton Stripe ; il n'est jamais dupliqué dans la requête Account v2.
    $raison = trim((string) ($u['raison_sociale'] ?? ''));
    if ($est_entite && $raison !== '') {
        $identite['business_details'] = ['registered_name' => $raison];
    }
    $r = stripe_api_v2('POST', 'core/account_tokens', [
        'contact_email' => (string) ($u['email'] ?? ''),
        'display_name' => connect_nom_affichage($u),
        'identity' => $identite,
    ]);
    if (!$r['ok']) {
        return ['ok' => false, 'token' => '', 'idempotence' => '', 'erreur' => $r['erreur']];
    }
    $token = (string) ($r['data']['id'] ?? '');
    if (!connect_token_compte_valide($token)) {
        return ['ok' => false, 'token' => '', 'idempotence' => '',
                'erreur' => 'Réponse Stripe Account Token inattendue.'];
    }
    // Le hash évite de révéler le jeton dans un en-tête HTTP ou les logs.
    $idempotence = 'missiona-connect-accounts-v3-' . (int) $u['id'] . '-'
        . substr(hash('sha256', $token), 0, 24);
    $_SESSION['stripe_connect_account_token'] = [
        'user_id' => (int) $u['id'],
        'token' => $token,
        'idempotence' => $idempotence,
        // Stripe Account Tokens sont valables dix minutes ; Missiona leur
        // donne neuf minutes pour ne jamais tenter un jeton limite.
        'expire' => time() + 540,
    ];
    return ['ok' => true, 'token' => $token, 'idempotence' => $idempotence, 'erreur' => ''];
}

/**
 * Crée un compte connecté Stripe Accounts v2. dashboard: full donne à
 * l'expert son Stripe Dashboard complet ; merchant + card_payments permet
 * les paiements directs. Il n'y a volontairement aucun paramètre Accounts v1
 * (type, controller, capabilities[...] ou account_links v1) dans ce parcours.
 *
 * @return array{ok:bool,compte:string,erreur:string}
 */
function connect_compte_creer($u) {
    if (!connect_actif()) {
        return ['ok' => false, 'compte' => '', 'erreur' => "Stripe Connect de test n'est pas encore configuré."];
    }
    if (!is_array($u) || ($u['role'] ?? '') !== 'freelance') {
        return ['ok' => false, 'compte' => '', 'erreur' => "Ce parcours est réservé aux experts."];
    }
    if (!empty($u['stripe_account'])) {
        $compte = (string) $u['stripe_account'];
        if (!connect_compte_valide($compte)) {
            return ['ok' => false, 'compte' => '', 'erreur' => "L'identifiant Stripe enregistré est invalide."];
        }
        $verif = connect_compte_v2_lire($compte);
        if (!$verif['ok']) {
            return ['ok' => false, 'compte' => '',
                'erreur' => "Impossible de vérifier le compte Stripe Accounts v2 : " . $verif['erreur']];
        }
        $dashboard = (string) ($verif['data']['dashboard'] ?? '');
        $marchand = $verif['data']['configuration']['merchant'] ?? null;
        if ($dashboard !== 'full' || !is_array($marchand)) {
            return ['ok' => false, 'compte' => '',
                'erreur' => "Ce compte Stripe existant n'est pas un compte Accounts v2 avec tableau de bord complet. "
                          . "Contactez Missiona avant tout remplacement."];
        }
        return ['ok' => true, 'compte' => $compte, 'erreur' => ''];
    }

    $pays = strtoupper(trim((string) ($u['pays'] ?? '')));
    if (!connect_pays_ok($pays)) {
        return ['ok' => false, 'compte' => '',
                'erreur' => "Renseignez votre pays de résidence parmi les pays Stripe pris en charge avant de configurer vos paiements."];
    }

    $jeton = connect_obtenir_token_compte($u, $pays);
    if (!$jeton['ok']) { return ['ok' => false, 'compte' => '', 'erreur' => $jeton['erreur']]; }

    $params = [
        // Le jeton porte contact_email, display_name et entity_type. L'API
        // Account Token v2 ne connaît pas identity.country : Stripe demande
        // donc ce pays obligatoire dans la requête Accounts v2 elle-même.
        'account_token' => $jeton['token'],
        'identity' => ['country' => strtolower($pays)],
        'configuration' => [
            'merchant' => [
                'capabilities' => [
                    'card_payments' => ['requested' => true],
                ],
            ],
        ],
        // Stripe assume les frais Stripe et les pertes, comme pour un compte
        // connecté à tableau de bord complet. Missiona garde uniquement son
        // application_fee_amount sur chaque paiement direct.
        'defaults' => [
            'responsibilities' => [
                'fees_collector' => 'stripe',
                'losses_collector' => 'stripe',
            ],
        ],
        'dashboard' => 'full',
        'metadata' => [
            'missiona_user_id' => (string) (int) $u['id'],
            'missiona_platform' => 'Missiona',
        ],
        'include' => ['configuration.merchant', 'requirements', 'identity', 'defaults'],
    ];
    $r = stripe_api_v2('POST', 'core/accounts', $params, [
        // Nouvelle génération : la clé v2 précédente a mémorisé l'erreur
        // française causée par identity envoyé hors Account Token.
        'Idempotency-Key: ' . $jeton['idempotence'],
    ]);
    if (!$r['ok']) {
        // Les réponses 4xx ne peuvent pas devenir valides avec ce même jeton.
        // On repartira avec un Account Token et une clé v3 fraîche au clic suivant.
        if ((int) ($r['code'] ?? 0) >= 400 && (int) ($r['code'] ?? 0) < 500) {
            connect_oublier_token_compte((int) $u['id']);
        }
        return ['ok' => false, 'compte' => '', 'erreur' => $r['erreur']];
    }

    $acct = (string) ($r['data']['id'] ?? '');
    if (!connect_compte_valide($acct)) {
        return ['ok' => false, 'compte' => '', 'erreur' => 'Réponse Stripe Accounts v2 inattendue.'];
    }
    connect_oublier_token_compte((int) $u['id']);
    db()->prepare("UPDATE users SET stripe_account=?, stripe_charges_ok=0,
                         stripe_payouts_ok=0, stripe_details_ok=0 WHERE id=?")
        ->execute([$acct, (int) $u['id']]);
    return ['ok' => true, 'compte' => $acct, 'erreur' => ''];
}

/** Jeton de retour lié à la session de l'expert, pas à une URL devinable. */
function connect_preparer_retour($u, $compte) {
    $token = bin2hex(random_bytes(24));
    $_SESSION['stripe_connect_retour'] = [
        'user_id' => (int) $u['id'],
        'account' => $compte,
        'token' => $token,
        'expire' => time() + 3600,
    ];
    return $token;
}

function connect_retour_valide($u, $token) {
    $r = $_SESSION['stripe_connect_retour'] ?? [];
    return is_array($r)
        && (int) ($r['user_id'] ?? 0) === (int) ($u['id'] ?? 0)
        && connect_compte_valide((string) ($r['account'] ?? ''))
        && !empty($r['token']) && is_string($token)
        && hash_equals((string) $r['token'], $token)
        && (int) ($r['expire'] ?? 0) >= time();
}

/**
 * Génère un Account Link v2, à usage unique. refresh_url et return_url
 * repassent toutes deux par la session Missiona : le retour Stripe ne vaut
 * jamais, à lui seul, comme preuve que le compte est prêt.
 */
function connect_lien_onboarding($u) {
    // Une fois l'identifiant acct_… créé et conservé côté serveur, relancer
    // l'onboarding n'a pas besoin d'une lecture préalable de l'Account v2 :
    // l'Account Link vérifie Stripe directement. Cela retire un aller-retour
    // réseau à chaque clic de reprise (et le lien reste refusé par Stripe si
    // le compte n'est plus utilisable).
    if (is_array($u) && !empty($u['stripe_account']) && connect_compte_valide((string) $u['stripe_account'])) {
        $c = ['ok' => true, 'compte' => (string) $u['stripe_account'], 'erreur' => ''];
    } else {
        $c = connect_compte_creer($u);
    }
    if (!$c['ok']) { return ['ok' => false, 'url' => '', 'erreur' => $c['erreur']]; }

    $token = connect_preparer_retour($u, $c['compte']);
    $base = rtrim(site_url(), '/');
    $suffixe = '?jeton=' . rawurlencode($token);
    $r = stripe_api_v2('POST', 'core/account_links', [
        'account' => $c['compte'],
        'use_case' => [
            'type' => 'account_onboarding',
            'account_onboarding' => [
                // Avec Stripe-Version 2026-09-30.endive, les configurations
                // sont lues directement sur le compte v2 et ne font plus
                // partie d'un Account Link. On collecte les exigences Stripe
                // à venir dès ce premier onboarding hébergé.
                'collection_options' => ['fields' => 'eventually_due'],
                'refresh_url' => $base . '/stripe-connect-refresh' . $suffixe,
                'return_url' => $base . '/stripe-connect-return' . $suffixe,
            ],
        ],
    ]);
    if (!$r['ok']) { return ['ok' => false, 'url' => '', 'erreur' => $r['erreur']]; }
    $url = (string) ($r['data']['url'] ?? '');
    if (!preg_match('#^https://#i', $url)) {
        return ['ok' => false, 'url' => '', 'erreur' => 'Stripe n’a pas fourni de lien d’inscription valide.'];
    }
    return ['ok' => true, 'url' => $url, 'erreur' => ''];
}

/**
 * Ouvre le tableau de bord Stripe complet du compte connecté.
 *
 * Missiona est volontairement verrouillé en mode test. L'ancienne URL sans
 * préfixe ouvrait le dashboard Stripe générique (souvent le mode live ou le
 * compte de la plateforme), ce qui donnait l'impression que le bouton ne
 * fonctionnait pas pour l'expert. On vérifie d'abord le compte v2 enregistré,
 * puis on dirige explicitement vers le dashboard TEST. Stripe conserve sa
 * propre authentification : l'expert se connecte avec l'adresse utilisée lors
 * de son onboarding.
 *
 * @return array{ok:bool,url:string,erreur:string}
 */
function connect_lien_dashboard($u) {
    if (!connect_actif()) {
        return ['ok' => false, 'url' => '', 'erreur' => "Stripe Connect de test n'est pas configuré."];
    }
    $compte = is_array($u) ? (string) ($u['stripe_account'] ?? '') : '';
    if (!connect_compte_valide($compte)) {
        return ['ok' => false, 'url' => '', 'erreur' => "Aucun compte Stripe valide n'est relié à ce profil."];
    }

    $verif = connect_compte_v2_lire($compte);
    if (!$verif['ok']) {
        return ['ok' => false, 'url' => '', 'erreur' => "Impossible de vérifier le compte Stripe : " . $verif['erreur']];
    }
    if (($verif['data']['dashboard'] ?? '') !== 'full') {
        return ['ok' => false, 'url' => '', 'erreur' => "Ce compte n'a pas accès au tableau de bord Stripe complet."];
    }

    return ['ok' => true, 'url' => 'https://dashboard.stripe.com/test/dashboard', 'erreur' => ''];
}

/**
 * Convertit la réponse Accounts v2 en état local de paiement. La capacité
 * card_payments active autorise les charges directes ; stripe_balance.payouts
 * renseigne aussi l'expert sur la disponibilité des versements Stripe.
 *
 * @return array{charges:bool,payouts:bool,details:bool,attente:array}
 */
function connect_etat_depuis_compte_v2($d) {
    $marchand = is_array($d['configuration']['merchant'] ?? null)
        ? $d['configuration']['merchant'] : [];
    $capabilities = is_array($marchand['capabilities'] ?? null) ? $marchand['capabilities'] : [];
    $carte = is_array($capabilities['card_payments'] ?? null) ? $capabilities['card_payments'] : [];
    $solde = is_array($capabilities['stripe_balance'] ?? null) ? $capabilities['stripe_balance'] : [];
    $versements = is_array($solde['payouts'] ?? null) ? $solde['payouts'] : [];
    $charges = (string) ($carte['status'] ?? '') === 'active';
    $payouts = (string) ($versements['status'] ?? '') === 'active';

    $attente = [];
    $requis = is_array($d['requirements'] ?? null) ? $d['requirements'] : [];
    $entrees = is_array($requis['entries'] ?? null) ? $requis['entries'] : [];
    foreach ($entrees as $entree) {
        if (!is_array($entree)) { continue; }
        $description = trim((string) ($entree['description'] ?? ''));
        if ($description !== '') { $attente[] = $description; }
        foreach ((array) ($entree['errors'] ?? []) as $erreur) {
            if (is_array($erreur) && !empty($erreur['code'])) { $attente[] = (string) $erreur['code']; }
        }
    }
    $attente = array_values(array_unique($attente));
    // Sans entrée de requirements, Stripe a reçu les informations requises.
    return ['charges' => $charges, 'payouts' => $payouts,
            'details' => empty($attente), 'attente' => $attente];
}

/**
 * Actualise l'état contrôlé par Stripe Accounts v2. Une vérification fraîche
 * est également faite avant la création du PaymentIntent dans stripe.php.
 *
 * @return array{ok:bool,charges:bool,payouts:bool,details:bool,attente:array,erreur:string}
 */
function connect_rafraichir($u) {
    $vide = ['ok' => false, 'charges' => false, 'payouts' => false,
             'details' => false, 'attente' => [], 'erreur' => ''];
    if (!connect_actif() || empty($u['stripe_account']) || !connect_compte_valide($u['stripe_account'])) {
        return $vide;
    }
    $r = connect_compte_v2_lire((string) $u['stripe_account']);
    if (!$r['ok']) { $vide['erreur'] = $r['erreur']; return $vide; }

    $etat = connect_etat_depuis_compte_v2($r['data']);
    db()->prepare("UPDATE users SET stripe_charges_ok=?, stripe_payouts_ok=?, stripe_details_ok=?
                   WHERE id=?")
        ->execute([$etat['charges'] ? 1 : 0, $etat['payouts'] ? 1 : 0,
                   $etat['details'] ? 1 : 0, (int) $u['id']]);
    return ['ok' => true, 'charges' => $etat['charges'], 'payouts' => $etat['payouts'],
            'details' => $etat['details'], 'attente' => $etat['attente'], 'erreur' => ''];
}

/** État mémorisé : une vérification fraîche est aussi faite avant Payment Element. */
function connect_pret($u) {
    return connect_actif() && !empty($u['stripe_account'])
        && connect_compte_valide($u['stripe_account']) && !empty($u['stripe_charges_ok']);
}

function connect_destination($u) {
    if (empty($u['stripe_account'])) {
        return ['pret' => false, 'compte' => '', 'banque' => '',
                'texte' => "L'expert n'a pas encore relié son compte Stripe."];
    }
    $pret = !empty($u['stripe_charges_ok']);
    return [
        'pret' => $pret,
        'compte' => (string) $u['stripe_account'],
        'banque' => '',
        'texte' => $pret
            ? 'Compte Stripe vérifié, prêt à recevoir les paiements par carte.'
            : 'Compte Stripe en cours de vérification : les paiements par carte ne sont pas encore disponibles.',
    ];
}

/**
 * Les transferts plateforme → expert ne font pas partie des paiements directs.
 * Cette garde empêche tout retour accidentel au modèle historique.
 */
function connect_transferer($u, $montant, $retrait_id) {
    return ['ok' => false, 'transfert' => '',
            'erreur' => "Les paiements directs ne créent aucun transfert depuis Missiona."];
}

/** Les frais d'application arrivent sur le solde de la plateforme. */
function connect_solde_plateforme() {
    if (!connect_actif()) { return null; }
    $r = stripe_api('GET', 'balance');
    if (!$r['ok']) { return null; }
    $total = 0;
    foreach (($r['data']['available'] ?? []) as $b) {
        if (strtolower((string) ($b['currency'] ?? '')) === 'eur') { $total += (int) ($b['amount'] ?? 0); }
    }
    return intdiv($total, 100);
}

/** Vérifie la signature Stripe v1 et limite les rejouements trop anciens. */
function connect_webhook_valide($charge_utile, $entete_signature, $secret, $tolerance = 300) {
    if ($secret === '' || $entete_signature === '') { return false; }
    $horodatage = '';
    $signatures = [];
    foreach (explode(',', $entete_signature) as $partie) {
        $kv = explode('=', trim($partie), 2);
        if (count($kv) !== 2) { continue; }
        if ($kv[0] === 't') { $horodatage = $kv[1]; }
        if ($kv[0] === 'v1') { $signatures[] = $kv[1]; }
    }
    if ($horodatage === '' || !$signatures || !ctype_digit($horodatage)) { return false; }
    if (abs(time() - (int) $horodatage) > $tolerance) { return false; }
    $attendue = hash_hmac('sha256', $horodatage . '.' . $charge_utile, $secret);
    foreach ($signatures as $signature) {
        if (hash_equals($attendue, $signature)) { return true; }
    }
    return false;
}

/** Synchronisation locale depuis le snapshot Connect account.updated. */
function connect_synchroniser_evenement_compte($compte) {
    if (!is_array($compte) || !connect_compte_valide((string) ($compte['id'] ?? ''))) { return false; }
    // Accounts v2 continue d'émettre le snapshot Connect account.updated. Ces
    // champs v1 y donnent le statut immédiat ; le retour Account Link fait
    // ensuite une lecture Accounts v2 complète avec requirements inclus.
    $charges = !empty($compte['charges_enabled']);
    $payouts = !empty($compte['payouts_enabled']);
    $details = !empty($compte['details_submitted']);
    $su = db()->prepare("SELECT id, stripe_charges_ok FROM users WHERE stripe_account=? AND role='freelance' LIMIT 1");
    $su->execute([(string) $compte['id']]);
    $expert = $su->fetch();
    db()->prepare("UPDATE users SET stripe_charges_ok=?, stripe_payouts_ok=?, stripe_details_ok=? WHERE stripe_account=?")
        ->execute([$charges ? 1 : 0, $payouts ? 1 : 0, $details ? 1 : 0, $compte['id']]);

    // Le client qui attendait cet onboarding est averti dans son espace dès
    // que Stripe autorise effectivement la carte. L'intent reste toutefois
    // revalidé fraîchement au clic, sans faire confiance à cette notification.
    if ($charges && $expert && empty($expert['stripe_charges_ok'])) {
        $sp = db()->prepare("SELECT p.entreprise_id, p.id, p.titre
                             FROM projets p JOIN paiements pa ON pa.projet_id=p.id AND pa.type='projet'
                             WHERE p.freelance_id=? AND p.statut='attribue'
                               AND pa.statut='a_payer' AND COALESCE(pa.stripe_direct,0)=1");
        $sp->execute([(int) $expert['id']]);
        foreach ($sp->fetchAll() as $projet) {
            notify((int) $projet['entreprise_id'],
                   "Le paiement par carte est maintenant disponible pour « " . $projet['titre'] . " ».",
                   'projet.php?id=' . (int) $projet['id']);
        }
    }
    return true;
}

function connect_etat_label($u) {
    if (!connect_actif()) { return 'Paiement par carte non configuré'; }
    if (empty($u['stripe_account'])) { return 'Non démarré'; }
    if (!empty($u['stripe_charges_ok'])) { return 'Prêt à encaisser'; }
    if (!empty($u['stripe_details_ok'])) { return 'Vérification en cours'; }
    return 'À compléter';
}

function connect_etat_badge($u) {
    if (!connect_actif() || empty($u['stripe_account'])) { return 'badge-gray'; }
    if (!empty($u['stripe_charges_ok'])) { return 'badge-green'; }
    if (!empty($u['stripe_details_ok'])) { return 'badge-blue'; }
    return 'badge-amber';
}
