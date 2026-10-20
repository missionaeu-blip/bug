<?php
/**
 * Missiona — Mesure d'audience interne
 * ------------------------------------------------------------------
 * Aucun service externe : tout est enregistré dans la base du site.
 *
 * Choix imposés par le RGPD et la position de la CNIL sur la mesure
 * d'audience (exemption de consentement) :
 *   - l'adresse IP n'est JAMAIS stockée en clair, seulement un condensé
 *     salé et tronqué, qui sert uniquement à distinguer deux visiteurs ;
 *   - le sel change chaque jour : impossible de suivre quelqu'un d'un
 *     jour sur l'autre, donc pas de profilage ;
 *   - aucune donnée n'est transmise à un tiers, aucun recoupement entre
 *     sites, pas de publicité ;
 *   - les données sont purgées automatiquement (13 mois par défaut).
 *
 * Ces conditions réunies, la CNIL dispense la mesure d'audience de
 * bandeau de consentement. Elles doivent le rester.
 */

/** Durée de conservation, en jours. */
function stats_retention() { return (int) param('stats_retention', 395); }

/** La mesure est-elle activée ? */
function stats_actif() { return (int) param('stats_actif', 1) === 1; }

/**
 * Adresse du visiteur telle que transmise par le proxy/CDN de confiance.
 * Sur Hostinger, REMOTE_ADDR peut désigner un nœud CDN différent à chaque
 * rechargement : on privilégie donc l'adresse client transmise en amont.
 * Cette valeur n'est jamais écrite en base, elle sert uniquement au condensé.
 */
function stats_adresse_client_transmise() {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $entete) {
        $brut = (string) ($_SERVER[$entete] ?? '');
        foreach (explode(',', $brut) as $candidat) {
            $ip = trim($candidat);
            if (filter_var($ip, FILTER_VALIDATE_IP)) { return $ip; }
        }
    }
    return '';
}

function stats_adresse_visiteur() {
    $client = stats_adresse_client_transmise();
    if ($client !== '') { return $client; }
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}

/**
 * Identifiant de visiteur : condensé quotidien. Quand Hostinger transmet la
 * vraie adresse client, le couple appareil/IP est employé. Sinon, le CDN peut
 * changer REMOTE_ADDR à chaque rechargement : un jeton de session nécessaire
 * au site prend le relais pour éviter tout faux visiteur supplémentaire.
 */
function stats_visiteur_id() {
    static $id = null;
    if ($id !== null) { return $id; }

    $sel = param('stats_sel', '');
    if ($sel === '') { $sel = bin2hex(random_bytes(16)); set_param('stats_sel', $sel); }
    $ip_client = stats_adresse_client_transmise();
    if ($ip_client !== '') {
        $origine = 'ip:' . $ip_client . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? '');
    } else {
        if (empty($_SESSION['stats_visiteur_anonyme'])) {
            $_SESSION['stats_visiteur_anonyme'] = bin2hex(random_bytes(16));
        }
        $origine = 'session:' . $_SESSION['stats_visiteur_anonyme'];
    }
    return $id = substr(hash('sha256', $origine . '|' . date('Y-m-d') . '|' . $sel), 0, 24);
}

/** Type d'appareil, déduit de façon grossière mais suffisante. */
function stats_appareil() {
    $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua === '') { return 'inconnu'; }
    if (preg_match('/ipad|tablet|playbook|silk/', $ua))                 { return 'tablette'; }
    if (preg_match('/mobile|iphone|android|ipod|blackberry/', $ua))     { return 'mobile'; }
    return 'ordinateur';
}

/** Informations techniques déclarées par le navigateur, sans identifiant personnel. */
function stats_navigateur() {
    $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua === '') { return 'Inconnu'; }
    if (strpos($ua, 'edg/') !== false || strpos($ua, 'edge/') !== false) { return 'Edge'; }
    if (strpos($ua, 'opr/') !== false || strpos($ua, 'opera') !== false) { return 'Opera'; }
    if (strpos($ua, 'brave') !== false) { return 'Brave'; }
    if (strpos($ua, 'firefox/') !== false) { return 'Firefox'; }
    if (strpos($ua, 'safari/') !== false && strpos($ua, 'chrome/') === false && strpos($ua, 'crios/') === false) {
        return stats_appareil() === 'mobile' ? 'Safari Mobile' : 'Safari';
    }
    if (strpos($ua, 'chrome/') !== false || strpos($ua, 'crios/') !== false) { return 'Chrome'; }
    return 'Autre';
}

function stats_os() {
    $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (strpos($ua, 'windows') !== false) { return 'Windows'; }
    if (strpos($ua, 'android') !== false) { return 'Android'; }
    if (preg_match('/iphone|ipad|ipod/', $ua)) { return 'iOS'; }
    if (strpos($ua, 'mac os') !== false || strpos($ua, 'macintosh') !== false) { return 'macOS'; }
    if (strpos($ua, 'linux') !== false) { return 'Linux'; }
    return 'Autre';
}

function stats_langue() {
    $accepte = strtolower((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    if (!preg_match('/^([a-z]{2,3})/i', $accepte, $m)) { return 'Autre'; }
    return ['fr'=>'Français', 'en'=>'Anglais', 'es'=>'Espagnol', 'de'=>'Allemand', 'it'=>'Italien',
            'pt'=>'Portugais', 'nl'=>'Néerlandais', 'ar'=>'Arabe', 'zh'=>'Chinois', 'ja'=>'Japonais'][$m[1]] ?? strtoupper($m[1]);
}

/** Robots d'indexation : comptés à part, jamais mélangés aux humains. */
function stats_est_robot() {
    $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua === '') { return true; }
    return (bool) preg_match(
        '/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|preview|monitor|curl|wget|python|headless|lighthouse/',
        $ua);
}

/** Origine du visiteur, réduite au nom de domaine. */
function stats_source() {
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    if ($ref === '') { return 'direct'; }
    $h = parse_url($ref, PHP_URL_HOST);
    if (!$h) { return 'direct'; }
    $h = preg_replace('/^www\./', '', strtolower($h));
    $site = preg_replace('/^www\./', '', strtolower($_SERVER['HTTP_HOST'] ?? ''));
    if ($h === $site) { return 'interne'; }
    if (preg_match('/google|bing|duckduckgo|qwant|ecosia|yahoo/', $h)) { return 'recherche'; }
    return $h;
}

/**
 * Pays d'arrivée : en-tête anonyme fourni par l'infrastructure en priorité,
 * puis recherche entièrement locale dans la base pays embarquée. La seconde
 * voie ne contacte jamais Internet : l'adresse est utilisée en mémoire pour
 * la recherche et immédiatement abandonnée ; seul le code ISO-2 est stocké.
 */
function stats_pays_local() {
    static $reader = null;
    static $indisponible = false;
    if ($indisponible) { return ''; }
    try {
        $ip = stats_adresse_visiteur();
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) { return ''; }
        if ($reader === null) {
            $autoload = __DIR__ . '/lib/maxmind-db/autoload.php';
            $base = __DIR__ . '/../data/iplocate-ip-to-country.mmdb';
            if (!is_file($autoload) || !is_readable($base)) { $indisponible = true; return ''; }
            require_once $autoload;
            $reader = new \MaxMind\Db\Reader($base);
        }
        $enregistrement = $reader->get($ip);
        $pays = strtoupper(trim((string) ($enregistrement['country_code'] ?? '')));
        return preg_match('/^[A-Z]{2}$/', $pays) && !in_array($pays, ['XX', 'T1'], true) ? $pays : '';
    } catch (Throwable $e) {
        // Une base pays indisponible ne doit jamais ralentir ni casser le site.
        $indisponible = true;
        return '';
    }
}

function stats_pays() {
    foreach (['HTTP_CF_IPCOUNTRY', 'HTTP_X_COUNTRY_CODE', 'HTTP_X_COUNTRY', 'HTTP_X_GEO_COUNTRY',
              'HTTP_X_GEOIP_COUNTRY_CODE', 'HTTP_FASTLY_CLIENT_COUNTRY_CODE',
              'HTTP_CLOUDFRONT_VIEWER_COUNTRY', 'HTTP_X_VERCEL_IP_COUNTRY',
              'HTTP_X_APPENGINE_COUNTRY', 'GEOIP_COUNTRY_CODE'] as $entete) {
        $pays = strtoupper(trim((string) ($_SERVER[$entete] ?? '')));
        if (preg_match('/^[A-Z]{2}$/', $pays) && !in_array($pays, ['XX', 'T1'], true)) { return $pays; }
    }
    return stats_pays_local();
}

/**
 * Enregistre une page vue. Appelé automatiquement par l'en-tête.
 * Silencieux par construction : la mesure ne doit jamais casser le site.
 */
function stats_visite($chemin = null) {
    if (!stats_actif()) { return; }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { return; }

    try {
        $chemin = $chemin ?? parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $chemin = '/' . ltrim((string) $chemin, '/');
        if (strlen($chemin) > 200) { $chemin = substr($chemin, 0, 200); }

        $uid = null;
        $x = null;
        if (function_exists('user')) { $x = user(); $uid = $x['id'] ?? null; }
        // Les espaces privés d'administration et leurs administrateurs ne sont
        // jamais comptés dans l'audience publique.
        if (preg_match('#^/(?:admin(?:-|/|$)|connexion(?:/|$)|deconnexion(?:/|$))#', $chemin)
            || (($x['role'] ?? '') === 'admin')) { return; }

        db()->prepare(
            "INSERT INTO visites (visiteur, user_id, chemin, source, appareil, pays, langue, navigateur, os, robot, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,datetime('now'))"
        )->execute([
            stats_visiteur_id(), $uid, $chemin,
            substr(stats_source(), 0, 100), stats_appareil(), stats_pays(), stats_langue(),
            stats_navigateur(), stats_os(), stats_est_robot() ? 1 : 0,
        ]);

        stats_purge_occasionnelle();
    } catch (Throwable $e) {
        // Une mesure d'audience ne justifie jamais une page en erreur.
    }
}

/**
 * Enregistre une action : clic sur un bouton, envoi d'un formulaire…
 * @param string $type   famille d'action (clic, formulaire, telechargement…)
 * @param string $cible  identifiant lisible de l'élément
 */
function stats_action($type, $cible, $detail = '') {
    if (!stats_actif()) { return; }
    try {
        $uid = null;
        $x = null;
        if (function_exists('user')) { $x = user(); $uid = $x['id'] ?? null; }
        $page = (string) (parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_PATH) ?: '');
        if (($x['role'] ?? '') === 'admin' || preg_match('#^/admin(?:-|/|$)#', $page)) { return; }

        db()->prepare(
            "INSERT INTO evenements (visiteur, user_id, type, cible, detail, chemin, created_at)
             VALUES (?,?,?,?,?,?,datetime('now'))"
        )->execute([
            stats_visiteur_id(), $uid,
            substr((string) $type, 0, 40),
            substr((string) $cible, 0, 120),
            substr((string) $detail, 0, 200),
            substr($page, 0, 200),
        ]);
    } catch (Throwable $e) {
        // idem : jamais bloquant
    }
}

/** Purge des données trop anciennes, déclenchée de loin en loin. */
function stats_purge_occasionnelle() {
    if (random_int(1, 200) !== 1) { return; }
    stats_purge();
}

function stats_purge() {
    $j = max(30, stats_retention());
    try {
        db()->exec("DELETE FROM visites    WHERE created_at < datetime('now','-" . $j . " days')");
        db()->exec("DELETE FROM evenements WHERE created_at < datetime('now','-" . $j . " days')");
    } catch (Throwable $e) {}
}

/* ==================================================================
 *  Lectures pour le tableau de bord
 * ================================================================== */

/** Filtre SQL commun : humains uniquement, sur N jours. */
function stats_ou($jours, $table = 'visites') {
    $j = max(1, (int) $jours);
    $robot = ($table === 'visites') ? " AND robot = 0" : "";
    return " WHERE created_at >= datetime('now','-" . $j . " days')" . $robot;
}

/** Chiffres clés de la période. */
function stats_resume($jours = 30) {
    $o = stats_ou($jours);
    $r = db()->query("SELECT COUNT(*) pages, COUNT(DISTINCT visiteur) visiteurs,
                             COUNT(DISTINCT user_id) connectes
                      FROM visites" . $o)->fetch() ?: [];
    $r['actions'] = (int) db()->query("SELECT COUNT(*) FROM evenements" . stats_ou($jours, 'evenements'))->fetchColumn();
    // Les projets déposés sont une conversion métier réelle ; ils complètent
    // les interactions de navigation sans utiliser de service tiers.
    $r['projets'] = (int) db()->query("SELECT COUNT(*) FROM projets
                                       WHERE created_at >= datetime('now','-" . max(1, (int) $jours) . " days')")->fetchColumn();
    $r['robots']  = (int) db()->query("SELECT COUNT(*) FROM visites
                                       WHERE created_at >= datetime('now','-" . max(1, (int) $jours) . " days')
                                       AND robot = 1")->fetchColumn();
    $r['pages']     = (int) ($r['pages'] ?? 0);
    $r['visiteurs'] = (int) ($r['visiteurs'] ?? 0);
    $r['connectes'] = (int) ($r['connectes'] ?? 0);
    $r['par_visiteur'] = $r['visiteurs'] > 0 ? round($r['pages'] / $r['visiteurs'], 1) : 0;
    return $r;
}

/** Pourcentage lisible, sans division par zéro. */
function stats_taux($nombre, $total) {
    return $total > 0 ? round(((float) $nombre / (float) $total) * 100, 1) : 0;
}

/**
 * Parcours mesurable dans Missiona : audience, visiteurs qui ont interagi,
 * intentions de formulaire et dépôts de projets. Ce n'est pas une attribution
 * publicitaire : les chiffres proviennent uniquement de la base interne.
 */
function stats_parcours_conversion($jours = 30) {
    $j = max(1, (int) $jours);
    $visiteurs = (int) db()->query("SELECT COUNT(DISTINCT visiteur) FROM visites" . stats_ou($j))->fetchColumn();
    $engages = (int) db()->query("SELECT COUNT(DISTINCT visiteur) FROM evenements" . stats_ou($j, 'evenements'))->fetchColumn();
    $formulaires = (int) db()->query("SELECT COUNT(DISTINCT visiteur) FROM evenements"
        . stats_ou($j, 'evenements') . " AND type='formulaire'")->fetchColumn();
    $projets = (int) db()->query("SELECT COUNT(*) FROM projets
                                  WHERE created_at >= datetime('now','-" . $j . " days')")->fetchColumn();

    return [
        ['cle' => 'visiteurs', 'libelle' => 'Visiteurs', 'aide' => 'audience distincte', 'n' => $visiteurs, 'taux' => 100],
        ['cle' => 'engages', 'libelle' => 'Interactions', 'aide' => 'au moins une action', 'n' => $engages, 'taux' => stats_taux($engages, $visiteurs)],
        ['cle' => 'formulaires', 'libelle' => 'Formulaires', 'aide' => 'clic sur un envoi', 'n' => $formulaires, 'taux' => stats_taux($formulaires, $visiteurs)],
        ['cle' => 'projets', 'libelle' => 'Projets déposés', 'aide' => 'créations enregistrées', 'n' => $projets, 'taux' => stats_taux($projets, $visiteurs)],
    ];
}

/** Libellés utiles pour les sources techniques les plus fréquentes. */
function stats_source_libelle($source) {
    $source = (string) $source;
    return [
        'direct' => 'Accès direct',
        'interne' => 'Navigation interne',
        'recherche' => 'Moteurs de recherche',
        'inconnu' => 'Source inconnue',
    ][$source] ?? $source;
}

/**
 * Rendement par source : pages, visiteurs et interactions. Les actions sont
 * rattachées à la première source connue de la session journalière afin de ne
 * pas les compter plusieurs fois lorsqu'un visiteur consulte plusieurs pages.
 */
function stats_sources_performance($jours = 30, $limite = 8) {
    $j = max(1, (int) $jours);
    $sql = "SELECT visiteur, source, created_at FROM visites" . stats_ou($j) . " ORDER BY created_at ASC";
    $par_source = [];
    $source_visiteur = [];

    foreach (db()->query($sql) as $ligne) {
        $visiteur = (string) $ligne['visiteur'];
        $source = trim((string) $ligne['source']) ?: 'direct';
        if (!isset($par_source[$source])) {
            $par_source[$source] = ['source' => $source, 'visiteurs' => [], 'pages' => 0, 'actions' => 0];
        }
        $par_source[$source]['pages']++;
        $par_source[$source]['visiteurs'][$visiteur] = true;

        // Une source externe est plus informative qu'un retour interne/direct.
        if (!isset($source_visiteur[$visiteur])
            || (in_array($source_visiteur[$visiteur], ['direct', 'interne'], true)
                && !in_array($source, ['direct', 'interne'], true))) {
            $source_visiteur[$visiteur] = $source;
        }
    }

    foreach (db()->query("SELECT visiteur, COUNT(*) n FROM evenements" . stats_ou($j, 'evenements') . " GROUP BY visiteur") as $ligne) {
        $source = $source_visiteur[(string) $ligne['visiteur']] ?? 'inconnu';
        if (!isset($par_source[$source])) {
            $par_source[$source] = ['source' => $source, 'visiteurs' => [], 'pages' => 0, 'actions' => 0];
        }
        $par_source[$source]['actions'] += (int) $ligne['n'];
    }

    $sortie = [];
    foreach ($par_source as $ligne) {
        $ligne['visiteurs'] = count($ligne['visiteurs']);
        $ligne['actions_par_visiteur'] = $ligne['visiteurs'] > 0
            ? round($ligne['actions'] / $ligne['visiteurs'], 1) : 0;
        $sortie[] = $ligne;
    }
    usort($sortie, fn($a, $b) => $b['visiteurs'] <=> $a['visiteurs'] ?: $b['pages'] <=> $a['pages']);
    return array_slice($sortie, 0, max(1, min(20, (int) $limite)));
}

/** Dernières interactions anonymes utiles au suivi éditorial. */
function stats_evenements_recents($jours = 30, $limite = 8) {
    $st = db()->prepare("SELECT type, cible, chemin, created_at FROM evenements"
        . stats_ou(max(1, (int) $jours), 'evenements') . " ORDER BY id DESC LIMIT ?");
    $st->execute([max(1, min(30, (int) $limite))]);
    return $st->fetchAll();
}

/** Performances pays, déterminées par un code ISO anonyme. */
function stats_pays_performance($jours = 30, $limite = 12) {
    $st = db()->prepare("SELECT pays, COUNT(DISTINCT visiteur) visiteurs, COUNT(*) pages
                         FROM visites" . stats_ou(max(1, (int) $jours)) . " AND pays != ''
                         GROUP BY pays ORDER BY visiteurs DESC, pages DESC LIMIT ?");
    $st->execute([max(1, min(40, (int) $limite))]);
    return $st->fetchAll();
}

/** Coordonnées de centres pays ISO-2 pour placer les arrivées sur la carte locale. */
function stats_pays_infos($code) {
    static $pays = null;
    if ($pays === null) {
        $fichier = __DIR__ . '/country-centroids.php';
        $pays = is_file($fichier) ? require $fichier : [];
    }
    $code = strtoupper((string) $code);
    return $pays[$code] ?? [$code, null, null];
}

/** Carte schématique interne : aucune tuile, police ou donnée externe chargée. */
function svg_carte_monde(array $lignes) {
    $max = 1;
    foreach ($lignes as $ligne) { $max = max($max, (int) $ligne['visiteurs']); }
    $html = '<div class="traffic-world-image">'
        . '<img src="/assets/img/world-map-simple.svg" alt="Carte mondiale simplifiée des arrivées">';
    foreach ($lignes as $ligne) {
        [$nom, $lon, $lat] = stats_pays_infos($ligne['pays']);
        if ($lon === null || $lat === null) { continue; }
        // Même projection équirectangulaire que le rendu local : 5 % de
        // marge horizontale et une latitude répartie sur toute la hauteur.
        $x = round(5 + (($lon + 180) / 360) * 90, 2);
        $y = round(((90 - $lat) / 180) * 100, 2);
        $taille = round(7 + ((int) $ligne['visiteurs'] / $max) * 10, 1);
        $titre = htmlspecialchars($nom . ' : ' . (int) $ligne['visiteurs'] . ' visiteur(s)', ENT_QUOTES, 'UTF-8');
        $html .= '<span class="traffic-map-marker" style="left:' . $x . '%;top:' . $y . '%;--taille:' . $taille . 'px" title="' . $titre . '" aria-label="' . $titre . '"></span>';
    }
    return $html . '</div>';
}

/** Série journalière, sans trou : les jours sans visite valent zéro. */
function stats_par_jour($jours = 30) {
    $j = max(1, (int) $jours);
    $brut = [];
    foreach (db()->query("SELECT date(created_at) d, COUNT(*) pages,
                                 COUNT(DISTINCT visiteur) visiteurs
                          FROM visites" . stats_ou($j) . "
                          GROUP BY d") as $r) {
        $brut[$r['d']] = $r;
    }
    $out = [];
    for ($i = $j - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $out[] = [
            'date'      => $d,
            'pages'     => (int) ($brut[$d]['pages'] ?? 0),
            'visiteurs' => (int) ($brut[$d]['visiteurs'] ?? 0),
        ];
    }
    return $out;
}

function stats_top_pages($jours = 30, $n = 12) {
    $st = db()->prepare("SELECT chemin, COUNT(*) n, COUNT(DISTINCT visiteur) v
                         FROM visites" . stats_ou($jours) . "
                         GROUP BY chemin ORDER BY n DESC LIMIT ?");
    $st->execute([(int) $n]);
    return $st->fetchAll();
}

function stats_top_actions($jours = 30, $n = 15) {
    $st = db()->prepare("SELECT type, cible, COUNT(*) n
                         FROM evenements" . stats_ou($jours, 'evenements') . "
                         GROUP BY type, cible ORDER BY n DESC LIMIT ?");
    $st->execute([(int) $n]);
    return $st->fetchAll();
}

function stats_repartition($colonne, $jours = 30, $n = 8) {
    $col = in_array($colonne, ['appareil', 'source', 'langue', 'navigateur', 'os'], true) ? $colonne : 'appareil';
    $st = db()->prepare("SELECT $col AS k, COUNT(*) n FROM visites" . stats_ou($jours) . " AND $col != ''
                         GROUP BY k ORDER BY n DESC LIMIT ?");
    $st->execute([(int) $n]);
    return $st->fetchAll();
}

/** Répartition des visites par heure et jour, pour la carte de chaleur. */
function stats_heatmap($jours = 30) {
    $grille = array_fill(1, 7, array_fill(0, 24, 0)); // lundi = 1, dimanche = 7
    foreach (db()->query("SELECT CAST(strftime('%w', created_at) AS INTEGER) jour,
                                 CAST(strftime('%H', created_at) AS INTEGER) heure, COUNT(*) n
                          FROM visites" . stats_ou(max(1, (int) $jours)) . " GROUP BY jour, heure") as $r) {
        $jour = (int) $r['jour'];
        $jour = $jour === 0 ? 7 : $jour;
        $grille[$jour][(int) $r['heure']] = (int) $r['n'];
    }
    return $grille;
}

/**
 * Temps actif agrégé par page. Une même page peut envoyer plusieurs segments
 * de durée : la moyenne est donc calculée par visiteur, jamais par segment.
 */
function stats_temps_pages($jours = 30, $limite = 10) {
    $st = db()->prepare("SELECT cible AS chemin, COUNT(DISTINCT visiteur) visiteurs,
                                SUM(CAST(detail AS INTEGER)) secondes
                         FROM evenements" . stats_ou(max(1, (int) $jours), 'evenements') . "
                              AND type='duree' AND CAST(detail AS INTEGER) > 0
                         GROUP BY cible ORDER BY secondes DESC LIMIT ?");
    $st->execute([max(1, min(30, (int) $limite))]);
    $sortie = [];
    foreach ($st->fetchAll() as $ligne) {
        $ligne['secondes'] = (int) $ligne['secondes'];
        $ligne['visiteurs'] = (int) $ligne['visiteurs'];
        $ligne['moyenne'] = $ligne['visiteurs'] > 0 ? (int) round($ligne['secondes'] / $ligne['visiteurs']) : 0;
        $sortie[] = $ligne;
    }
    return $sortie;
}

/** Moyenne de temps actif, tous visiteurs mesurés confondus. */
function stats_temps_resume($jours = 30) {
    $r = db()->query("SELECT COALESCE(SUM(CAST(detail AS INTEGER)), 0) secondes,
                             COUNT(DISTINCT visiteur) visiteurs,
                             COUNT(DISTINCT cible) pages
                      FROM evenements" . stats_ou(max(1, (int) $jours), 'evenements') . "
                      AND type='duree' AND CAST(detail AS INTEGER) > 0")->fetch() ?: [];
    $r['secondes'] = (int) ($r['secondes'] ?? 0);
    $r['visiteurs'] = (int) ($r['visiteurs'] ?? 0);
    $r['pages'] = (int) ($r['pages'] ?? 0);
    $r['moyenne'] = $r['visiteurs'] > 0 ? (int) round($r['secondes'] / $r['visiteurs']) : 0;
    return $r;
}

/**
 * Liste d'identifiants anonymes de la journée. L'identifiant est un condensé
 * irréversible, renouvelé chaque jour : ce n'est ni une adresse IP, ni un
 * profil de personne, et il ne permet pas de relier plusieurs journées.
 */
function stats_visiteurs_anonymes($jours = 30, $limite = 20) {
    $j = max(1, (int) $jours);
    $st = db()->prepare("SELECT v.visiteur, date(MIN(v.created_at)) jour,
                                MIN(v.created_at) premiere_vue, MAX(v.created_at) derniere_vue,
                                COUNT(*) pages_vues, COUNT(DISTINCT v.chemin) pages_distinctes,
                                MAX(v.appareil) appareil, MAX(v.navigateur) navigateur,
                                MAX(v.os) os,
                                COALESCE((SELECT SUM(CAST(e.detail AS INTEGER))
                                          FROM evenements e
                                          WHERE e.visiteur=v.visiteur AND e.type='duree'
                                            AND e.created_at >= datetime('now','-" . $j . " days')), 0) secondes
                         FROM visites v
                         WHERE v.created_at >= datetime('now','-" . $j . " days') AND v.robot=0
                         GROUP BY v.visiteur
                         ORDER BY derniere_vue DESC LIMIT ?");
    $st->execute([max(1, min(100, (int) $limite))]);
    $sortie = $st->fetchAll() ?: [];
    foreach ($sortie as &$ligne) {
        $ligne['secondes'] = (int) $ligne['secondes'];
        $ligne['pages_vues'] = (int) $ligne['pages_vues'];
        $ligne['pages_distinctes'] = (int) $ligne['pages_distinctes'];
    }
    unset($ligne);
    return $sortie;
}

/** Détail d'un visiteur anonyme limité à la fenêtre statistique demandée. */
function stats_visiteur_anonyme_detail($visiteur, $jours = 30) {
    $visiteur = strtolower((string) $visiteur);
    if (!preg_match('/^[a-f0-9]{16,64}$/', $visiteur)) { return null; }
    $j = max(1, (int) $jours);
    $base = " FROM visites WHERE visiteur=? AND robot=0
              AND created_at >= datetime('now','-" . $j . " days')";
    $st = db()->prepare("SELECT visiteur, date(MIN(created_at)) jour,
                                MIN(created_at) premiere_vue, MAX(created_at) derniere_vue,
                                COUNT(*) pages_vues, COUNT(DISTINCT chemin) pages_distinctes,
                                MAX(appareil) appareil, MAX(navigateur) navigateur, MAX(os) os" . $base);
    $st->execute([$visiteur]);
    $profil = $st->fetch();
    if (!$profil || empty($profil['visiteur'])) { return null; }

    $pages = db()->prepare("SELECT v.chemin, MIN(v.created_at) premiere_vue,
                                   MAX(v.created_at) derniere_vue, COUNT(*) vues,
                                   COALESCE((SELECT SUM(CAST(e.detail AS INTEGER))
                                             FROM evenements e
                                             WHERE e.visiteur=v.visiteur AND e.type='duree'
                                               AND e.cible=v.chemin
                                               AND e.created_at >= datetime('now','-" . $j . " days')), 0) secondes
                            FROM visites v
                            WHERE v.visiteur=? AND v.robot=0
                              AND v.created_at >= datetime('now','-" . $j . " days')
                            GROUP BY v.chemin ORDER BY premiere_vue ASC");
    $pages->execute([$visiteur]);
    $profil['pages'] = $pages->fetchAll() ?: [];
    $total = 0;
    foreach ($profil['pages'] as &$page) { $page['secondes'] = (int) $page['secondes']; $total += $page['secondes']; }
    unset($page);
    $profil['secondes'] = $total;
    return $profil;
}

function stats_duree_lisible($secondes) {
    $s = max(0, (int) $secondes);
    return intdiv($s, 60) . ' min ' . str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT) . ' s';
}

function stats_par_heure($jours = 7) {
    $out = array_fill(0, 24, 0);
    foreach (db()->query("SELECT CAST(strftime('%H', created_at) AS INTEGER) h, COUNT(*) n
                          FROM visites" . stats_ou($jours) . " GROUP BY h") as $r) {
        $out[(int) $r['h']] = (int) $r['n'];
    }
    return $out;
}

/* ==================================================================
 *  Graphiques : SVG produit côté serveur
 *  Aucune bibliothèque, aucun script : le rendu fonctionne même si
 *  le JavaScript est désactivé.
 * ================================================================== */

/** Courbe d'évolution à deux séries. */
function svg_courbe(array $points, $cle1 = 'pages', $cle2 = 'visiteurs', $h = 220) {
    $n = count($points);
    if ($n === 0) { return '<p class="muted small">Aucune donnée.</p>'; }

    $L = 820; $pad_g = 46; $pad_d = 14; $pad_h = 16; $pad_b = 34;
    $iw = $L - $pad_g - $pad_d;
    $ih = $h - $pad_h - $pad_b;

    $max = 1;
    foreach ($points as $p) { $max = max($max, (int) $p[$cle1], (int) $p[$cle2]); }
    $max = (int) (ceil($max / 5) * 5) ?: 5;

    $x = fn($i) => $pad_g + ($n <= 1 ? $iw / 2 : $i * $iw / ($n - 1));
    $y = fn($v) => $pad_h + $ih - ($v / $max) * $ih;

    $s = '<svg viewBox="0 0 ' . $L . ' ' . $h . '" class="chart" role="img" '
       . 'aria-label="Évolution de la fréquentation" preserveAspectRatio="xMidYMid meet">';

    // Grille horizontale et échelle
    for ($g = 0; $g <= 4; $g++) {
        $v  = $max * $g / 4;
        $yy = $y($v);
        $s .= '<line x1="' . $pad_g . '" y1="' . round($yy, 1) . '" x2="' . ($L - $pad_d)
            . '" y2="' . round($yy, 1) . '" stroke="#e9ecef" stroke-width="1"/>';
        $s .= '<text x="' . ($pad_g - 8) . '" y="' . round($yy + 4, 1)
            . '" text-anchor="end" font-size="11" fill="#8a9099">' . round($v) . '</text>';
    }

    $chemin = function ($cle) use ($points, $x, $y) {
        $d = '';
        foreach ($points as $i => $p) {
            $d .= ($i === 0 ? 'M' : 'L') . round($x($i), 1) . ' ' . round($y((int) $p[$cle]), 1) . ' ';
        }
        return trim($d);
    };

    // Aire sous la courbe principale
    $aire = $chemin($cle1) . ' L' . round($x($n - 1), 1) . ' ' . round($y(0), 1)
          . ' L' . round($x(0), 1) . ' ' . round($y(0), 1) . ' Z';
    $s .= '<path d="' . $aire . '" fill="#2f6df6" fill-opacity="0.08"/>';
    $s .= '<path d="' . $chemin($cle1) . '" fill="none" stroke="#2f6df6" stroke-width="2.2" '
        . 'stroke-linejoin="round" stroke-linecap="round"/>';
    $s .= '<path d="' . $chemin($cle2) . '" fill="none" stroke="#12a150" stroke-width="2" '
        . 'stroke-dasharray="5 4" stroke-linejoin="round"/>';

    // Dates : quelques repères seulement, pour rester lisible
    $pas = max(1, (int) ceil($n / 8));
    foreach ($points as $i => $p) {
        if ($i % $pas !== 0 && $i !== $n - 1) { continue; }
        $s .= '<text x="' . round($x($i), 1) . '" y="' . ($h - 12)
            . '" text-anchor="middle" font-size="11" fill="#8a9099">'
            . date('d/m', strtotime($p['date'])) . '</text>';
    }

    // Valeur du dernier point
    $s .= '<circle cx="' . round($x($n - 1), 1) . '" cy="' . round($y((int) $points[$n - 1][$cle1]), 1)
        . '" r="3.5" fill="#2f6df6"/>';
    $s .= '</svg>';
    return $s;
}

/** Donut SVG compact pour les répartitions de langues / appareils. */
function svg_donut(array $lignes, $libelle_centre = 'visites') {
    if (!$lignes) { return '<p class="muted small">Aucune donnée pour cette période.</p>'; }
    $couleurs = ['#2A42C6', '#E95D5D', '#FFCA18', '#35B978', '#8B6BE8', '#269ACB'];
    $total = 0; foreach ($lignes as $l) { $total += (int) $l['n']; }
    if ($total <= 0) { return '<p class="muted small">Aucune donnée pour cette période.</p>'; }
    $r = 47; $c = 2 * M_PI * $r; $decalage = 0;
    $svg = '<svg class="traffic-donut" viewBox="0 0 150 150" role="img" aria-label="Répartition des ' . htmlspecialchars($libelle_centre, ENT_QUOTES, 'UTF-8') . '">'
         . '<circle cx="75" cy="75" r="47" fill="none" stroke="#EEF1F5" stroke-width="19"/>';
    foreach ($lignes as $i => $l) {
        $longueur = ((int) $l['n'] / $total) * $c;
        $ecart = min(4, $longueur / 3);
        $dessin = max(0, $longueur - $ecart);
        $svg .= '<circle cx="75" cy="75" r="47" fill="none" stroke="' . $couleurs[$i % count($couleurs)] . '" stroke-width="19"'
              . ' stroke-linecap="round" stroke-dasharray="' . $dessin . ' ' . ($c - $dessin) . '"'
              . ' stroke-dashoffset="' . (-$decalage) . '" transform="rotate(-90 75 75)"><title>'
              . htmlspecialchars((string) $l['k'] . ' : ' . (int) $l['n'], ENT_QUOTES, 'UTF-8') . '</title></circle>';
        $decalage += $longueur;
    }
    return $svg . '<text x="75" y="71" text-anchor="middle" class="traffic-donut-total">' . $total . '</text>'
        . '<text x="75" y="88" text-anchor="middle" class="traffic-donut-label">' . htmlspecialchars($libelle_centre, ENT_QUOTES, 'UTF-8') . '</text></svg>';
}

/**
 * Troncature sûre : mbstring n'est pas installé partout, et couper
 * un texte UTF-8 au milieu d'un caractère produit des losanges noirs.
 */
function stats_tronquer($texte, $max = 42) {
    $t = (string) $texte;
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($t, 'UTF-8') <= $max ? $t : mb_substr($t, 0, $max - 1, 'UTF-8') . '…';
    }
    if (strlen($t) <= $max) { return $t; }
    $c = substr($t, 0, $max - 1);
    // Ne pas laisser un octet de continuation orphelin en fin de chaîne.
    while ($c !== '' && (ord($c[strlen($c) - 1]) & 0xC0) === 0x80) { $c = substr($c, 0, -1); }
    if ($c !== '' && (ord($c[strlen($c) - 1]) & 0xC0) === 0xC0) { $c = substr($c, 0, -1); }
    return $c . '…';
}

/** Barres horizontales, pour les classements. */
function svg_barres(array $lignes, $cle_lib, $cle_val, $max_lignes = 10) {
    $lignes = array_slice($lignes, 0, $max_lignes);
    if (!$lignes) { return '<p class="muted small">Aucune donnée.</p>'; }

    $max = 1;
    foreach ($lignes as $l) { $max = max($max, (int) $l[$cle_val]); }

    $out = '<div class="barres">';
    foreach ($lignes as $l) {
        $pct = round((int) $l[$cle_val] / $max * 100, 1);
        $lib = (string) $l[$cle_lib];
        if ($lib === '') { $lib = '(vide)'; }
        $out .= '<div class="barre-l">'
              . '<div class="barre-t" title="' . htmlspecialchars($lib, ENT_QUOTES) . '">'
              . htmlspecialchars(stats_tronquer($lib, 42), ENT_QUOTES) . '</div>'
              . '<div class="barre-p"><span style="width:' . $pct . '%"></span></div>'
              . '<div class="barre-v">' . (int) $l[$cle_val] . '</div>'
              . '</div>';
    }
    return $out . '</div>';
}

/** Histogramme vertical compact (répartition par heure). */
function svg_heures(array $heures) {
    $max = max(1, max($heures));
    $L = 820; $h = 140; $pad_b = 22; $pad_h = 8;
    $lb = $L / 24;
    $s = '<svg viewBox="0 0 ' . $L . ' ' . $h . '" class="chart" role="img" '
       . 'aria-label="Fréquentation par heure" preserveAspectRatio="xMidYMid meet">';
    foreach ($heures as $hh => $v) {
        $bh = ($h - $pad_b - $pad_h) * ($v / $max);
        $x  = $hh * $lb + 3;
        $y  = $h - $pad_b - $bh;
        $s .= '<rect x="' . round($x, 1) . '" y="' . round($y, 1) . '" width="' . round($lb - 6, 1)
            . '" height="' . round(max($bh, 1), 1) . '" rx="2" fill="#2f6df6" fill-opacity="'
            . ($v > 0 ? '0.75' : '0.15') . '"><title>' . $hh . 'h : ' . $v . '</title></rect>';
        if ($hh % 3 === 0) {
            $s .= '<text x="' . round($x + $lb / 2 - 3, 1) . '" y="' . ($h - 7)
                . '" text-anchor="middle" font-size="10" fill="#8a9099">' . $hh . 'h</text>';
        }
    }
    return $s . '</svg>';
}
