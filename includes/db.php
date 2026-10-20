<?php
// Missiona - Connexion base de données SQLite (PHP pur, aucune API externe)

require_once __DIR__ . '/../config.php';

/**
 * Emplacement de la base de données.
 * ------------------------------------------------------------------
 * Par défaut, la base est rangée dans un dossier SŒUR du site
 * (…/missiona-data/), et non dans le site lui-même. Ainsi, quand
 * vous réenvoyez les fichiers du site lors d'une mise à jour, la base
 * n'est jamais touchée : aucun compte, aucun projet n'est perdu.
 *
 * Vous pouvez imposer un autre chemin depuis config.local.php :
 *     define('DB_FILE', '/home/monuser/prive/missiona.sqlite');
 */
if (!defined('DB_FILE')) {
    $_racine  = dirname(__DIR__);              // dossier du site
    $_externe = dirname($_racine) . '/missiona-data';
    $_interne = $_racine . '/data';

    // Le dossier parent est-il utilisable ? Certains hébergements
    // mutualisés ne laissent pas écrire au-dessus du site.
    $_ok = is_dir($_externe)
        ? is_writable($_externe)
        : (is_dir(dirname($_racine)) && is_writable(dirname($_racine)) && @mkdir($_externe, 0700, true));

    define('DB_FILE', ($_ok ? $_externe : $_interne) . '/missiona.sqlite');
    unset($_racine, $_externe, $_interne, $_ok);
}
define('COMMISSION_RATE', 0.20); // Commission Missiona par défaut : 20%

function db() {
    static $pdo = null;
    if ($pdo === null) {
        if (!is_dir(dirname(DB_FILE))) { mkdir(dirname(DB_FILE), 0700, true); }
        proteger_dossier_base(dirname(DB_FILE));
        reprendre_base_existante();
        $pdo = new PDO('sqlite:' . DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        install_schema($pdo);
        sauvegarde_avant_migration();
        migrer_schema($pdo);
        // Injecte les données de démonstration si la base est vide
        if ((int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() === 0) {
            seed_data($pdo);
        }
    }
    return $pdo;
}


/**
 * Interdit le téléchargement de la base si elle est restée dans le site.
 */
function proteger_dossier_base($dossier) {
    $ht = $dossier . '/.htaccess';
    if (!is_file($ht)) { @file_put_contents($ht, "Require all denied\nDeny from all\n"); }
    if (!is_file($dossier . '/index.html')) { @file_put_contents($dossier . '/index.html', ''); }
}

/**
 * Récupère une base créée par une version antérieure, quand elle se
 * trouvait encore dans le dossier du site. Sans cela, le déplacement
 * de l'emplacement par défaut ferait repartir le site à vide.
 */
function reprendre_base_existante() {
    if (is_file(DB_FILE) && filesize(DB_FILE) > 0) { return; }

    $racine = dirname(__DIR__);
    $parent = dirname($racine);

    // Emplacements utilisés par les versions précédentes, du plus récent
    // au plus ancien. La plateforme s'est appelée WorkConnects avant de
    // devenir Missiona : une base portant l'ancien nom doit être reprise,
    // sans quoi le site repartirait à vide avec zéro compte.
    $candidats = [
        $parent . '/missiona-data/missiona.sqlite',
        $parent . '/workconnects-data/workconnects.sqlite',
        $parent . '/workconnects-data/missiona.sqlite',
        $racine . '/data/missiona.sqlite',
        $racine . '/data/workconnects.sqlite',
    ];

    foreach ($candidats as $ancienne) {
        if (!is_file($ancienne) || filesize($ancienne) === 0) { continue; }
        if (realpath($ancienne) === realpath(DB_FILE)) { continue; }

        // On vérifie que le fichier contient bien des comptes avant de
        // l'adopter : une base vide ne vaut pas mieux que pas de base.
        try {
            $t = new PDO('sqlite:' . $ancienne);
            $t->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $n = (int) $t->query("SELECT COUNT(*) FROM users")->fetchColumn();
            $t = null;
            if ($n === 0) { continue; }
        } catch (Throwable $e) {
            continue;
        }

        if (@copy($ancienne, DB_FILE)) {
            // L'original est conservé, seulement renommé : en cas de
            // doute, il reste récupérable à la main.
            @rename($ancienne, $ancienne . '.repris-' . date('Ymd-His'));
            @error_log('[base] reprise de ' . $ancienne . ' (' . $n . ' comptes)');
            return;
        }
    }
}

/**
 * Crée une copie cohérente de la base.
 * ------------------------------------------------------------------
 * VACUUM INTO produit un instantané propre même si des écritures ont
 * lieu au même moment, ce qu'une simple copie de fichier ne garantit
 * pas. Le nom est rendu unique pour éviter qu'une sauvegarde manuelle
 * et une sauvegarde automatique prises dans la même seconde ne
 * s'écrasent l'une l'autre.
 *
 * @return string|false chemin du fichier créé
 */
function sauvegarder_base($dossier, $prefixe = 'missiona') {
    if (!is_file(DB_FILE) || filesize(DB_FILE) === 0) { return false; }
    if (!is_dir($dossier)) { @mkdir($dossier, 0700, true); proteger_dossier_base($dossier); }

    $base = $dossier . '/' . $prefixe . '-' . date('Ymd-His');
    $cible = $base . '.sqlite';
    for ($i = 2; is_file($cible) && $i < 100; $i++) { $cible = $base . '-' . $i . '.sqlite'; }

    try {
        $st = db()->prepare('VACUUM INTO ?');
        $st->execute([$cible]);
        if (is_file($cible) && filesize($cible) > 0) { return $cible; }
    } catch (Throwable $e) {
        // SQLite antérieur à 3.27 : on retombe sur une copie simple.
    }
    return @copy(DB_FILE, $cible) ? $cible : false;
}

/**
 * Copie horodatée de la base avant toute évolution de structure.
 * Les cinq dernières sauvegardes sont conservées.
 */
function sauvegarde_signature() {
    // La structure de la base est définie par CE fichier : son empreinte
    // change dès qu'une table ou une colonne est ajoutée. C'est donc le
    // signal le plus fiable qu'une migration va avoir lieu — bien plus
    // sûr qu'un numéro de version qu'un oubli laisse figé.
    $emp = @md5_file(__FILE__) ?: '0';
    $ver = defined('APP_VERSION') ? APP_VERSION : '0';
    return substr($emp, 0, 12) . '-' . $ver;
}

/**
 * Sauvegarde automatique de la base.
 * ------------------------------------------------------------------
 * Déclenchée dans deux cas :
 *   1. la structure de la base a changé depuis le dernier passage,
 *      c'est-à-dire juste avant une mise à jour ;
 *   2. la dernière sauvegarde date de plus de N jours, pour qu'il
 *      existe toujours un point de restauration récent même si le
 *      site n'a pas été mis à jour depuis longtemps.
 */
function sauvegarde_avant_migration() {
    if (!is_file(DB_FILE) || filesize(DB_FILE) === 0) { return; }
    $dir = dirname(DB_FILE) . '/sauvegardes';
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); proteger_dossier_base($dir); }

    $signature = sauvegarde_signature();
    $marqueur  = $dir . '/.version';
    $connue    = is_file($marqueur) ? trim((string) @file_get_contents($marqueur)) : '';

    $raison = '';
    if ($connue !== $signature) {
        $raison = 'maj';
    } else {
        // Filet de sécurité : une sauvegarde qui date de trop longtemps
        // ne protège plus de grand-chose.
        $jours = (int) param('sauvegarde_intervalle', 7);
        if ($jours > 0) {
            $liste = glob($dir . '/auto-*.sqlite') ?: [];
            $recent = 0;
            foreach ($liste as $f) { $recent = max($recent, (int) @filemtime($f)); }
            if ($recent === 0 || (time() - $recent) > $jours * 86400) { $raison = 'periodique'; }
        }
    }
    if ($raison !== '' && sauvegarder_base($dir, 'auto')) {
        @file_put_contents($marqueur, $signature);
    }

    sauvegarde_menage($dir);
}

/**
 * Ne garde que les dernières sauvegardes automatiques.
 * Appelé à chaque passage, et non seulement quand une copie vient
 * d'être créée : sinon d'anciens fichiers pourraient s'accumuler
 * sans jamais être nettoyés.
 */
function sauvegarde_menage($dir) {
    $garde = max(3, (int) param('sauvegarde_nombre', 8));
    $liste = glob($dir . '/auto-*.sqlite') ?: [];
    if (count($liste) <= $garde) { return; }
    usort($liste, fn($a, $b) => filemtime($a) <=> filemtime($b));
    foreach (array_slice($liste, 0, count($liste) - $garde) as $vieux) { @unlink($vieux); }
}

/**
 * Ajoute les colonnes apparues après la première mise en production.
 * CREATE TABLE IF NOT EXISTS ne modifie pas une table déjà créée : sans cela,
 * une base existante resterait sans les champs de paiement.
 */
function migrer_schema(PDO $pdo) {
    // Mesure d'audience : pages vues et actions. L'IP n'est jamais stockée,
    // seulement un condensé salé quotidiennement (voir includes/stats.php).
    $pdo->exec("CREATE TABLE IF NOT EXISTS visites (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        visiteur TEXT NOT NULL DEFAULT '',
        user_id INTEGER,
        chemin TEXT NOT NULL DEFAULT '',
        source TEXT DEFAULT '',
        appareil TEXT DEFAULT '',
        pays TEXT DEFAULT '',       -- code ISO 2 lettres fourni par l'infrastructure, jamais une IP
        langue TEXT DEFAULT '',     -- première langue déclarée par le navigateur
        navigateur TEXT DEFAULT '',
        os TEXT DEFAULT '',
        robot INTEGER DEFAULT 0,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_visites_date ON visites(created_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_visites_chemin ON visites(chemin)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_visites_visiteur ON visites(visiteur)");
    // Les installations existantes ajoutent seulement des informations
    // d'audience agrégées ; aucun historique d'IP n'est créé ni reconstruit.
    $visites_cols = [];
    foreach ($pdo->query("PRAGMA table_info(visites)") as $c) { $visites_cols[] = $c['name']; }
    foreach ([
        'pays' => "TEXT DEFAULT ''", 'langue' => "TEXT DEFAULT ''",
        'navigateur' => "TEXT DEFAULT ''", 'os' => "TEXT DEFAULT ''",
    ] as $nom => $type) {
        if (in_array($nom, $visites_cols, true)) { continue; }
        try { $pdo->exec("ALTER TABLE visites ADD COLUMN {$nom} {$type}"); }
        catch (Throwable $e) { @error_log('[schema] visites.' . $nom . ' : ' . $e->getMessage()); }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS evenements (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        visiteur TEXT NOT NULL DEFAULT '',
        user_id INTEGER,
        type TEXT NOT NULL DEFAULT '',
        cible TEXT NOT NULL DEFAULT '',
        detail TEXT DEFAULT '',
        chemin TEXT DEFAULT '',
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_evt_date ON evenements(created_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_evt_visiteur ON evenements(visiteur)");

    // Preuve minimale des choix cookies : aucun identifiant brut, aucune IP,
    // aucune donnée de navigation. Le jeton local est condensé avant insertion.
    $pdo->exec("CREATE TABLE IF NOT EXISTS cookie_consentements (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        token_hash TEXT NOT NULL UNIQUE,
        decision TEXT NOT NULL DEFAULT '',
        fonctionnalites INTEGER NOT NULL DEFAULT 0,
        audience INTEGER NOT NULL DEFAULT 0,
        version TEXT NOT NULL DEFAULT '',
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cookie_consentements_updated ON cookie_consentements(updated_at)");

    // Événements Stripe signés déjà traités. L'identifiant Stripe est unique :
    // cette trace rend les webhooks rejoués idempotents sans conserver leur
    // payload, qui peut contenir des données de paiement inutiles ici.
    $pdo->exec("CREATE TABLE IF NOT EXISTS stripe_evenements (
        event_id TEXT PRIMARY KEY,
        type TEXT NOT NULL DEFAULT '',
        compte TEXT DEFAULT '',
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    // Abonnements aux notifications sur telephone (Web Push).
    // L'URL « endpoint » est fournie par le navigateur et sert aussi
    // de secret : elle seule permet de lire les messages en attente.
    // Journal des actions sensibles de l'équipe d'administration
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_journal (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        auteur_id INTEGER NOT NULL,
        cible_id INTEGER DEFAULT 0,
        action TEXT NOT NULL DEFAULT '',
        detail TEXT DEFAULT '',
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS push_abonnes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        endpoint TEXT NOT NULL UNIQUE,
        appareil TEXT DEFAULT '',
        echecs INTEGER DEFAULT 0,
        dernier_ok TEXT DEFAULT '',
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    // Registre des diligences DAC7 : trace horodatée des démarches entreprises.
    $pdo->exec("CREATE TABLE IF NOT EXISTS dac7_registre (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        annee INTEGER NOT NULL,
        action TEXT NOT NULL,
        detail TEXT DEFAULT '',
        auteur TEXT DEFAULT '',
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    // Jetons de réinitialisation de mot de passe (lien envoyé par e-mail).
    $pdo->exec("CREATE TABLE IF NOT EXISTS reinit_mdp (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id    INTEGER NOT NULL,
        jeton_hash TEXT NOT NULL,
        expire_at  TEXT NOT NULL,
        utilise    INTEGER DEFAULT 0,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    // Pièces jointes des conversations. Elles sont rattachées au message
    // plutôt qu'à la table fichiers, dont la colonne projet_id est
    // obligatoire — une conversation n'est pas toujours liée à un projet.
    $mc = [];
    foreach ($pdo->query("PRAGMA table_info(messages)") as $c) { $mc[] = $c['name']; }
    foreach ([
        'fichier_nom'       => "TEXT DEFAULT ''",
        'fichier_chemin'    => "TEXT DEFAULT ''",
        'fichier_taille'    => "INTEGER DEFAULT 0",
        'fichier_empreinte' => "TEXT DEFAULT ''",
        'fichier_protege'   => "INTEGER DEFAULT 0",   // remis à une entreprise
    ] as $col => $type) {
        if ($mc && !in_array($col, $mc, true)) {
            try { $pdo->exec("ALTER TABLE messages ADD COLUMN {$col} {$type}"); }
            catch (Throwable $e) { @error_log('[schema] messages.' . $col . ' : ' . $e->getMessage()); }
        }
    }

    // Contrôle des livrables avant remise au client : un fichier déposé
    // par un expert reste en attente tant que l'équipe ne l'a pas vérifié.
    // L'empreinte permet de détecter qu'un fichier a été remplacé sur le
    // disque après validation.
    $fic = [];
    foreach ($pdo->query("PRAGMA table_info(fichiers)") as $c) { $fic[] = $c['name']; }
    foreach ([
        'statut'      => "TEXT DEFAULT 'valide'",   // valide | en_attente | refuse
        'empreinte'   => "TEXT DEFAULT ''",
        'verifie_par' => "INTEGER DEFAULT 0",
        'verifie_le'  => "TEXT DEFAULT ''",
        'motif'       => "TEXT DEFAULT ''",
    ] as $col => $type) {
        if ($fic && !in_array($col, $fic, true)) {
            try { $pdo->exec("ALTER TABLE fichiers ADD COLUMN {$col} {$type}"); }
            catch (Throwable $e) { @error_log('[schema] fichiers.' . $col . ' : ' . $e->getMessage()); }
        }
    }

    // Projets déposés pendant la préinscription : signalés au client
    // pour qu'il sache qu'ils seront étudiés à l'ouverture du service.
    $pc = [];
    foreach ($pdo->query("PRAGMA table_info(projets)") as $c) { $pc[] = $c['name']; }
    if ($pc && !in_array('cree_en_beta', $pc, true)) {
        $pdo->exec("ALTER TABLE projets ADD COLUMN cree_en_beta INTEGER DEFAULT 0");
    }
    // Contact direct entreprise↔expert : fermé par défaut. L'équipe peut
    // l'ouvrir projet par projet ; les deux parties peuvent alors
    // échanger messages et pièces jointes sans intermédiaire.
    if ($pc && !in_array('contact_direct', $pc, true)) {
        $pdo->exec("ALTER TABLE projets ADD COLUMN contact_direct INTEGER DEFAULT 0");
    }


    // Factures réglées directement à l'expert (mode gratuit) : elles ne
    // doivent jamais alimenter son solde de retrait, sous peine de le
    // payer une seconde fois.
    $fc = [];
    foreach ($pdo->query("PRAGMA table_info(factures)") as $c) { $fc[] = $c['name']; }
    if ($fc && !in_array('versement_direct', $fc, true)) {
        $pdo->exec("ALTER TABLE factures ADD COLUMN versement_direct INTEGER DEFAULT 0");
    }
    // Colonne historique : ancien forfait fixe par mission (supprimé le
    // 2026-10-02, la rémunération se limite à la commission d'apport).
    // Conservée pour la compatibilité du schéma et des anciennes factures.
    if ($fc && !in_array('frais_service', $fc, true)) {
        $pdo->exec("ALTER TABLE factures ADD COLUMN frais_service INTEGER DEFAULT 0");
    }
    // 1 lorsque la commission a déjà été prélevée par application_fee_amount
    // au moment du paiement direct Stripe. Cette trace interdit toute seconde
    // demande de règlement à l'expert et rend le justificatif explicite.
    if ($fc && !in_array('commission_prelevee_stripe', $fc, true)) {
        $pdo->exec("ALTER TABLE factures ADD COLUMN commission_prelevee_stripe INTEGER DEFAULT 0");
    }
    // Une mission ne produit qu'une facture de rémunération. L'index rend la
    // création idempotente même si un administrateur soumet deux fois le
    // changement de statut. Une base historique contenant déjà un doublon est
    // laissée intacte et doit être régularisée avant de créer l'index.
    try { $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_factures_projet_unique ON factures(projet_id)"); }
    catch (Throwable $e) { @error_log('[schema] factures unique projet : ' . $e->getMessage()); }

    // Table retraits : identifiant du transfert Stripe (historique).
    $rc = [];
    foreach ($pdo->query("PRAGMA table_info(retraits)") as $c) { $rc[] = $c['name']; }
    if ($rc && !in_array('transfert', $rc, true)) {
        $pdo->exec("ALTER TABLE retraits ADD COLUMN transfert TEXT DEFAULT ''");
    }

    // Paiement direct Connect : les champs historiques de Session restent
    // compatibles ; le PaymentIntent et son compte sont mémorisés pour
    // relire, rembourser et idempotenter dans le bon compte.
    $payc = [];
    foreach ($pdo->query("PRAGMA table_info(paiements)") as $c) { $payc[] = $c['name']; }
    foreach ([
        'stripe_session'      => "TEXT DEFAULT ''",
        'stripe_account'      => "TEXT DEFAULT ''",
        'stripe_checkout_key' => "TEXT DEFAULT ''",
        // Montant effectif de l'application_fee_amount Stripe, en centimes.
        // Il fige la commission même si le taux est modifié plus tard.
        'stripe_application_fee' => "INTEGER DEFAULT 0",
        // 1 uniquement pour les échéances créées sous le nouveau modèle
        // Connect direct ; les échéances historiques restent hors ligne.
        'stripe_direct'       => "INTEGER DEFAULT 0",
    ] as $col => $type) {
        if ($payc && !in_array($col, $payc, true)) {
            try { $pdo->exec("ALTER TABLE paiements ADD COLUMN {$col} {$type}"); }
            catch (Throwable $e) { @error_log('[schema] paiements.' . $col . ' : ' . $e->getMessage()); }
        }
    }

    $cols = [];
    foreach ($pdo->query("PRAGMA table_info(users)") as $c) { $cols[] = $c['name']; }

    // Colonnes de la table d'origine. Elles sont normalement créées par
    // install_schema(), mais une base restaurée depuis une sauvegarde
    // ancienne ou partielle peut en manquer : on les rétablit ici plutôt
    // que de laisser le site tomber en erreur.
    $base_users = [
        'prenom'          => "TEXT DEFAULT ''",
        'telephone'       => "TEXT DEFAULT ''",
        'societe'         => "TEXT DEFAULT ''",
        'siret'           => "TEXT DEFAULT ''",
        'secteur'         => "TEXT DEFAULT ''",
        'taille'          => "TEXT DEFAULT ''",
        'titre_pro'       => "TEXT DEFAULT ''",
        'bio'             => "TEXT DEFAULT ''",
        'competences'     => "TEXT DEFAULT ''",
        'tarif_projet'    => "INTEGER DEFAULT 0",
        'experience'      => "INTEGER DEFAULT 0",
        'disponibilite'   => "TEXT DEFAULT 'disponible'",
        'note_moyenne'    => "REAL DEFAULT 0",
        'nb_missions'     => "INTEGER DEFAULT 0",
        'gestionnaire_id' => "INTEGER DEFAULT 0",
        'payout_email'    => "TEXT DEFAULT ''",
        'payout_verifie'  => "INTEGER DEFAULT 0",
        'rgpd_consent'          => "INTEGER DEFAULT 1",
        // Les comptes historiques restent utilisables ; seules les nouvelles
        // inscriptions sont créées explicitement avec email_verifie = 0.
        'email_suivi_consent'   => "INTEGER DEFAULT 0",
        'email_verifie'         => "INTEGER DEFAULT 1",
        'email_verif_code_hash' => "TEXT DEFAULT ''",
        'email_verif_expire_at' => "TEXT DEFAULT ''",
        'email_verif_essais'    => "INTEGER DEFAULT 0",
        'email_verif_envoi_le'  => "TEXT DEFAULT ''",
        'email_verif_envois'    => "INTEGER DEFAULT 0",
        'email_verif_fenetre_le'=> "TEXT DEFAULT ''",
        // 0 = le guide de prise en main s'affiche lors de la prochaine connexion.
        'guide_premiere_connexion_vu' => "INTEGER DEFAULT 0",
        'actif'                 => "INTEGER DEFAULT 1",
        'created_at'      => "TEXT DEFAULT ''",   // ALTER TABLE n'accepte pas CURRENT_TIMESTAMP
    ];

    $ajouts = [
        'pays'             => "TEXT DEFAULT ''",
        'devise'           => "TEXT DEFAULT 'EUR'",
        'payout_methode'   => "TEXT DEFAULT ''",
        'payout_titulaire' => "TEXT DEFAULT ''",
        'payout_iban'      => "TEXT DEFAULT ''",
        'payout_manuel'    => "INTEGER DEFAULT 0",
        'google_id'        => "TEXT DEFAULT ''",
        'avatar_url'       => "TEXT DEFAULT ''",
        'delai_exempt'     => "INTEGER DEFAULT 0",
        'delai_exempt_le'  => "TEXT DEFAULT ''",
        'delai_exempt_par' => "TEXT DEFAULT ''",
        'stripe_bank_last4'=> "TEXT DEFAULT ''",
        'stripe_bank_nom'  => "TEXT DEFAULT ''",
        'stripe_bank_pays' => "TEXT DEFAULT ''",
        'derniere_vue'     => "TEXT DEFAULT ''",
        'page_actuelle'    => "TEXT DEFAULT ''",
        'payout_bic'       => "TEXT DEFAULT ''",
        'payout_email'     => "TEXT DEFAULT ''",
        'payout_verifie'   => "INTEGER DEFAULT 0",
        'gestionnaire_id'  => "INTEGER DEFAULT 0",
        'stripe_account'   => "TEXT DEFAULT ''",
        // Paiement direct : charges_enabled est la condition pour créer
        // Checkout sur le compte connecté ; payouts_enabled est informatif.
        'stripe_charges_ok'=> "INTEGER DEFAULT 0",
        'stripe_payouts_ok'=> "INTEGER DEFAULT 0",
        'stripe_details_ok'=> "INTEGER DEFAULT 0",
        // --- Données fiscales DAC7 ---
        'type_vendeur'     => "TEXT DEFAULT 'physique'",  // physique | entite
        'adresse'          => "TEXT DEFAULT ''",
        'code_postal'      => "TEXT DEFAULT ''",
        'ville'            => "TEXT DEFAULT ''",
        'date_naissance'   => "TEXT DEFAULT ''",
        'lieu_naissance'   => "TEXT DEFAULT ''",
        'nif'              => "TEXT DEFAULT ''",
        'nif_pays'         => "TEXT DEFAULT ''",
        'tva'              => "TEXT DEFAULT ''",
        'raison_sociale'   => "TEXT DEFAULT ''",
        'num_immat'        => "TEXT DEFAULT ''",
        'etab_stable'      => "TEXT DEFAULT ''",
        'dac7_verifie_le'  => "TEXT DEFAULT ''",
        'dac7_relances'    => "INTEGER DEFAULT 0",
        'dac7_relance_le'  => "TEXT DEFAULT ''",
    ];

    $ajouts = $base_users + $ajouts;
    foreach ($ajouts as $nom => $type) {
        if (in_array($nom, $cols, true)) { continue; }
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN {$nom} {$type}");
        } catch (Throwable $e) {
            // Une colonne qui refuse de s'ajouter ne doit pas empêcher
            // les suivantes, ni rendre le site inaccessible.
            @error_log('[schema] colonne users.' . $nom . ' non ajoutée : ' . $e->getMessage());
        }
    }
}

function install_schema(PDO $pdo) {
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT UNIQUE NOT NULL,
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL,              -- entreprise | freelance | admin
        nom TEXT NOT NULL,
        prenom TEXT DEFAULT '',
        telephone TEXT DEFAULT '',
        societe TEXT DEFAULT '',
        siret TEXT DEFAULT '',
        secteur TEXT DEFAULT '',
        taille TEXT DEFAULT '',
        titre_pro TEXT DEFAULT '',
        bio TEXT DEFAULT '',
        competences TEXT DEFAULT '',     -- liste séparée par virgules
        tarif_projet INTEGER DEFAULT 0,
        experience INTEGER DEFAULT 0,
        disponibilite TEXT DEFAULT 'disponible',
        note_moyenne REAL DEFAULT 0,
        nb_missions INTEGER DEFAULT 0,
        gestionnaire_id INTEGER DEFAULT 0,   -- admin en charge de ce client
        -- Paiements internationaux (freelances)
        pays TEXT DEFAULT '',                -- code ISO 2 lettres : FR, MA, SN...
        devise TEXT DEFAULT 'EUR',           -- devise de versement souhaitée
        payout_methode TEXT DEFAULT '',      -- virement | paypal | payoneer | wise
        payout_titulaire TEXT DEFAULT '',    -- nom exact du titulaire du compte
        payout_iban TEXT DEFAULT '',         -- IBAN ou numéro de compte local
        payout_bic TEXT DEFAULT '',          -- BIC / SWIFT
        payout_email TEXT DEFAULT '',        -- e-mail PayPal / Payoneer / Wise
        payout_verifie INTEGER DEFAULT 0,    -- coordonnées validées par l'équipe
        rgpd_consent INTEGER DEFAULT 1,
        email_suivi_consent INTEGER DEFAULT 0,
        email_verifie INTEGER DEFAULT 1,
        email_verif_code_hash TEXT DEFAULT '',
        email_verif_expire_at TEXT DEFAULT '',
        email_verif_essais INTEGER DEFAULT 0,
        email_verif_envoi_le TEXT DEFAULT '',
        email_verif_envois INTEGER DEFAULT 0,
        email_verif_fenetre_le TEXT DEFAULT '',
        guide_premiere_connexion_vu INTEGER DEFAULT 0,
        actif INTEGER DEFAULT 1,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS projets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        entreprise_id INTEGER NOT NULL REFERENCES users(id),
        freelance_id INTEGER REFERENCES users(id),
        titre TEXT NOT NULL,
        categorie TEXT NOT NULL,
        description TEXT NOT NULL,
        competences TEXT DEFAULT '',
        budget_min INTEGER DEFAULT 0,
        budget_max INTEGER DEFAULT 0,
        delai TEXT DEFAULT '',
        date_limite TEXT DEFAULT '',
        statut TEXT DEFAULT 'nouveau',   -- nouveau|analyse|attribue|en_cours|livraison|termine|annule
        avancement INTEGER DEFAULT 0,
        montant_final INTEGER DEFAULT 0,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        projet_id INTEGER REFERENCES projets(id),
        expediteur_id INTEGER NOT NULL REFERENCES users(id),
        destinataire_id INTEGER NOT NULL REFERENCES users(id),
        contenu TEXT NOT NULL,
        lu INTEGER DEFAULT 0,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS fichiers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        projet_id INTEGER NOT NULL REFERENCES projets(id),
        uploader_id INTEGER NOT NULL REFERENCES users(id),
        nom TEXT NOT NULL,
        chemin TEXT NOT NULL,
        taille INTEGER DEFAULT 0,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS portfolio (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id),
        titre TEXT DEFAULT '',
        description TEXT DEFAULT '',
        image TEXT NOT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS forfaits (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id),
        nom TEXT NOT NULL,               -- Essentiel, Standard, Premium...
        description TEXT DEFAULT '',
        prix INTEGER NOT NULL DEFAULT 0, -- ce que perçoit l'expert, en euros
        delai INTEGER DEFAULT 0,         -- en jours
        position INTEGER DEFAULT 0,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id),
        texte TEXT NOT NULL,
        lien TEXT DEFAULT '',
        lu INTEGER DEFAULT 0,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS paiements (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        projet_id INTEGER NOT NULL REFERENCES projets(id),
        type TEXT NOT NULL,              -- 'frais' (etape 1) ou 'projet' (etape 2)
        montant INTEGER DEFAULT 0,       -- ce que l'entreprise regle
        statut TEXT DEFAULT 'a_payer',   -- a_payer | en_suspens | libere | rembourse | paye
        reference TEXT DEFAULT '',
        stripe_ref TEXT DEFAULT '',      -- payment_intent, pour un remboursement
        stripe_account TEXT DEFAULT '',  -- compte connecté propriétaire de la charge
        stripe_checkout_key TEXT DEFAULT '', -- clé d'idempotence du PaymentIntent
        stripe_application_fee INTEGER DEFAULT 0, -- application fee Stripe, en centimes
        stripe_direct INTEGER DEFAULT 0,
        paye_le TEXT DEFAULT '',
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS demandes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        projet_id INTEGER NOT NULL REFERENCES projets(id),
        auteur_id INTEGER NOT NULL REFERENCES users(id),
        type TEXT NOT NULL,              -- 'annulation' ou 'remboursement'
        motif TEXT DEFAULT '',
        statut TEXT DEFAULT 'en_attente',-- en_attente | acceptee | refusee
        reponse TEXT DEFAULT '',
        traite_le TEXT DEFAULT '',
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS factures (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        projet_id INTEGER NOT NULL REFERENCES projets(id),
        numero TEXT NOT NULL,
        montant_ht INTEGER DEFAULT 0,
        commission INTEGER DEFAULT 0,
        montant_freelance INTEGER DEFAULT 0,
        statut TEXT DEFAULT 'en_attente',
        versement_direct INTEGER DEFAULT 0,
        frais_service INTEGER DEFAULT 0,
        commission_prelevee_stripe INTEGER DEFAULT 0,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS evaluations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        projet_id INTEGER NOT NULL REFERENCES projets(id),
        auteur_id INTEGER NOT NULL REFERENCES users(id),
        cible_id INTEGER NOT NULL REFERENCES users(id),
        note INTEGER NOT NULL,
        commentaire TEXT DEFAULT '',
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS retraits (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        freelance_id INTEGER NOT NULL REFERENCES users(id),
        montant INTEGER DEFAULT 0,        -- montant brut demandé (devise de base)
        frais INTEGER DEFAULT 0,          -- frais de traitement retenus
        net INTEGER DEFAULT 0,            -- montant réellement versé
        devise TEXT DEFAULT 'EUR',
        methode TEXT DEFAULT '',
        destination TEXT DEFAULT '',      -- IBAN masqué ou e-mail, pour la traçabilité
        statut TEXT DEFAULT 'demande',    -- demande | traite | paye | refuse
        reference TEXT DEFAULT '',
        note TEXT DEFAULT '',
        traite_le TEXT DEFAULT '',
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS contacts (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        nom        TEXT NOT NULL,
        email      TEXT NOT NULL,
        societe    TEXT DEFAULT '',
        sujet      TEXT DEFAULT '',
        message    TEXT NOT NULL,
        traite     INTEGER DEFAULT 0,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS parametres (
        cle TEXT PRIMARY KEY,
        valeur TEXT NOT NULL
    );
    ");

    // Coefficients de matching configurables
    $defaults = [
        'coef_competences' => '40',
        'coef_budget'      => '25',
        'coef_dispo'       => '15',
        'coef_experience'  => '10',
        'coef_note'        => '10',
        'commission'       => '20',
    ];
    $st = $pdo->prepare("INSERT OR IGNORE INTO parametres (cle, valeur) VALUES (?, ?)");
    foreach ($defaults as $k => $v) { $st->execute([$k, $v]); }
}

function param($cle, $defaut = 0) {
    $st = db()->prepare("SELECT valeur FROM parametres WHERE cle = ?");
    $st->execute([$cle]);
    $r = $st->fetchColumn();
    return $r === false ? $defaut : $r;
}

/**
 * Amorçage d'une installation neuve.
 * Crée UNIQUEMENT le compte administrateur Missiona.
 * Aucune donnée fictive : les tableaux de bord démarrent à zéro et se
 * remplissent avec les vrais comptes, projets et messages.
 */
function seed_data(PDO $pdo) {
    $cols = "email,password_hash,role,nom,prenom,telephone,societe,siret,secteur,taille,"
          . "titre_pro,bio,competences,tarif_projet,experience,disponibilite,note_moyenne,nb_missions";
    $st = $pdo->prepare("INSERT INTO users ($cols) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

    // --- Compte administrateur (toujours créé) ---
    $st->execute([
        ADMIN_EMAIL, password_hash(ADMIN_PASSWORD, PASSWORD_DEFAULT),
        'admin', 'Administrateur', 'Missiona', '', 'Missiona', '', '', '',
        'Responsable de compte', '', '', 0, 0, '', 0, 0,
    ]);

    // --- Comptes de test (désactivables dans config.php) ---
    if (!defined('COMPTES_TEST') || !COMPTES_TEST) { return; }

    // Entreprise de test
    $st->execute([
        TEST_ENTREPRISE_EMAIL, password_hash(TEST_ENTREPRISE_PASSWORD, PASSWORD_DEFAULT),
        'entreprise', 'Martin', 'Julien', '0611223344',
        'Atelier Martin', '81234567800021', 'Artisanat', '10-50',
        '', "Atelier d'ébénisterie sur mesure.", '', 0, 0, '', 0, 0,
    ]);

    // Freelance de test
    $st->execute([
        TEST_FREELANCE_EMAIL, password_hash(TEST_FREELANCE_PASSWORD, PASSWORD_DEFAULT),
        'freelance', 'Leroy', 'Marie', '0633445566', '', '', '', '',
        'Développeuse Web', "Développeuse web spécialisée sites vitrines et e-commerce.",
        'PHP,JavaScript,HTML,CSS,WordPress,SEO', 2500, 6, 'disponible', 0, 0,
    ]);
}
