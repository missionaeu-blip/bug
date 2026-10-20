<?php
/**
 * Missiona — Suivi de l'équipe d'administration
 * ------------------------------------------------------------------
 * Trois usages :
 *   - savoir qui est connecté en ce moment ;
 *   - mesurer ce que chaque administrateur a réellement traité ;
 *   - se connecter à sa place pour reproduire un problème.
 *
 * Cette dernière possibilité est puissante et intrusive : elle est
 * réservée à l'administrateur principal, intégralement journalisée,
 * et signalée en permanence à l'écran pendant qu'elle est active.
 */

/** Délai au-delà duquel on considère quelqu'un déconnecté (minutes). */
function equipe_delai_presence() { return max(1, (int) param('presence_minutes', 5)); }

/**
 * Enregistre le passage de l'utilisateur courant.
 * Limité à une écriture par minute pour ne pas alourdir chaque page.
 */
function equipe_marquer_presence() {
    if (empty($_SESSION['uid'])) { return; }

    // Page consultée : notée UNIQUEMENT pour les administrateurs.
    // Suivre à la trace un client ou un expert serait disproportionné ;
    // l'équipe, elle, sait qu'elle est supervisée.
    $page = '';
    // Les appels techniques ne sont pas des pages consultées : sans
    // cette exclusion, le rafraîchissement du suivi en direct
    // s'afficherait lui-même comme la page en cours de chacun.
    $script    = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $technique = in_array($script, ['admin-presence.php', 'suivi.php',
                                    'push-message.php', 'push-abonner.php'], true);

    $_moi = function_exists('user') ? user() : null;
    if (!$technique && ($_moi['role'] ?? '') === 'admin') {
        $page = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
        if (strlen($page) > 120) { $page = substr($page, 0, 120); }
    }

    $now     = time();
    $ancien  = $_SESSION['page_vue'] ?? null;
    $ecoule  = $now - (int) ($_SESSION['vu_le'] ?? 0);

    // On écrit si la page a changé, ou toutes les 20 secondes : assez
    // réactif pour un suivi en direct, sans marteler la base.
    if ($ecoule < 20 && ($technique || $ancien === $page)) { return; }

    $_SESSION['vu_le'] = $now;
    if (!$technique) { $_SESSION['page_vue'] = $page; }

    try {
        if ($technique) {
            db()->prepare("UPDATE users SET derniere_vue = datetime('now') WHERE id = ?")
                ->execute([(int) $_SESSION['uid']]);
        } else {
            db()->prepare("UPDATE users SET derniere_vue = datetime('now'), page_actuelle = ? WHERE id = ?")
                ->execute([$page, (int) $_SESSION['uid']]);
        }
    } catch (Throwable $e) {}
}

/**
 * Nom lisible d'une page, à partir de son adresse.
 * « /admin-dac7 » devient « Déclaration DAC7 ».
 */
function equipe_nom_page($chemin) {
    $c = trim((string) $chemin);
    if ($c === '' || $c === '/') { return 'Accueil'; }
    $c = preg_replace('/\?.*$/', '', $c);
    $c = preg_replace('/\.php$/', '', trim($c, '/'));

    $noms = [
        'admin' => 'Tableau de bord', 'admin-projets' => 'Projets',
        'admin-utilisateurs' => 'Utilisateurs', 'admin-equipe' => 'Équipe',
        'admin-suivi-equipe' => 'Suivi équipe', 'admin-matching' => 'Matching',
        'admin-demandes' => 'Demandes', 'admin-retraits' => 'Versements',
        'admin-dac7' => 'Déclaration DAC7', 'admin-parametres' => 'Paramètres',
        'admin-sauvegarde' => 'Sauvegarde', 'admin-statistiques' => 'Fréquentation',
        'messages' => 'Messagerie', 'profil' => 'Son profil',
        'projets' => 'Projets', 'projet' => 'Fiche projet',
        'factures' => 'Facturation', 'connexion' => "Page de connexion",
        'nouveau-projet' => 'Nouveau projet',
    ];
    return $noms[$c] ?? ('/' . $c);
}

/** Cette personne est-elle active en ce moment ? */
function equipe_en_ligne($u) {
    if (empty($u['derniere_vue'])) { return false; }
    $t = strtotime($u['derniere_vue'] . ' UTC');
    return $t && (time() - $t) <= equipe_delai_presence() * 60;
}

/** « il y a 3 min », « hier », … */
function equipe_depuis($date) {
    if (empty($date)) { return 'jamais venu'; }
    $t = strtotime($date . ' UTC');
    if (!$t) { return '—'; }
    $d = max(0, time() - $t);
    if ($d < 60)     { return "à l'instant"; }
    if ($d < 3600)   { return 'il y a ' . (int) ($d / 60) . ' min'; }
    if ($d < 86400)  { return 'il y a ' . (int) ($d / 3600) . ' h'; }
    if ($d < 172800) { return 'hier'; }
    return 'il y a ' . (int) ($d / 86400) . ' jours';
}

/** Liste des administrateurs, avec leur état de présence. */
function equipe_admins() {
    $l = db()->query("SELECT * FROM users WHERE role = 'admin' ORDER BY id")->fetchAll();
    foreach ($l as &$a) {
        $a['en_ligne']  = equipe_en_ligne($a);
        $a['principal'] = est_admin_principal($a);
    }
    return $l;
}

/**
 * Chiffres d'activité d'un administrateur.
 * Le rattachement se fait par les clients dont il a la charge :
 * leurs projets sont les siens.
 */
function equipe_chiffres($admin_id) {
    $id = (int) $admin_id;
    $r  = [];

    $r['clients'] = (int) db()->query(
        "SELECT COUNT(*) FROM users WHERE role IN ('entreprise','particulier')
         AND gestionnaire_id = " . $id)->fetchColumn();

    $base = "FROM projets p
             JOIN users c ON c.id = p.entreprise_id AND c.gestionnaire_id = " . $id;

    $r['projets']  = (int) db()->query("SELECT COUNT(*) $base")->fetchColumn();
    $r['attribues'] = (int) db()->query("SELECT COUNT(*) $base AND p.freelance_id IS NOT NULL
                                         AND p.statut <> 'nouveau'")->fetchColumn();
    $r['en_cours'] = (int) db()->query("SELECT COUNT(*) $base
                                        AND p.statut IN ('attribue','en_cours','livraison')")->fetchColumn();
    $r['termines'] = (int) db()->query("SELECT COUNT(*) $base AND p.statut = 'termine'")->fetchColumn();
    $r['a_traiter'] = (int) db()->query("SELECT COUNT(*) $base
                                         AND p.statut IN ('nouveau','analyse')")->fetchColumn();

    $av = db()->query("SELECT AVG(p.avancement) $base
                       AND p.statut IN ('attribue','en_cours','livraison')")->fetchColumn();
    $r['avancement'] = $av === null ? null : (int) round((float) $av);

    $r['ca'] = (int) db()->query("SELECT COALESCE(SUM(p.montant_final),0) $base
                                  AND p.statut = 'termine'")->fetchColumn();

    $st = db()->prepare("SELECT COUNT(*) FROM messages WHERE expediteur_id = ?");
    $st->execute([$id]);
    $r['messages'] = (int) $st->fetchColumn();

    try {
        $st = db()->prepare("SELECT COUNT(*) FROM evenements WHERE user_id = ?");
        $st->execute([$id]);
        $r['actions'] = (int) $st->fetchColumn();
    } catch (Throwable $e) { $r['actions'] = 0; }

    return $r;
}

/** Projets suivis par cet administrateur. */
function equipe_projets($admin_id, $limite = 40) {
    $st = db()->prepare(
        "SELECT p.id, p.titre, p.statut, p.avancement, p.montant_final, p.created_at,
                c.societe, c.nom AS c_nom
         FROM projets p
         JOIN users c ON c.id = p.entreprise_id
         WHERE c.gestionnaire_id = ?
         ORDER BY p.id DESC LIMIT ?");
    $st->execute([(int) $admin_id, (int) $limite]);
    return $st->fetchAll();
}

/**
 * Dernières actions, toutes sources confondues.
 * On rassemble ce qui est réellement tracé : messages envoyés,
 * clics enregistrés et diligences fiscales.
 */
function equipe_activite($admin_id, $limite = 40) {
    $id = (int) $admin_id;
    $out = [];

    $st = db()->prepare("SELECT created_at, contenu, projet_id FROM messages
                         WHERE expediteur_id = ? ORDER BY id DESC LIMIT ?");
    $st->execute([$id, $limite]);
    foreach ($st as $m) {
        $out[] = ['quand' => $m['created_at'], 'type' => 'Message',
                  'quoi'  => 'Projet #' . (int) $m['projet_id'] . ' — '
                           . stats_tronquer(trim((string) $m['contenu']), 70)];
    }

    try {
        $st = db()->prepare("SELECT created_at, type, cible FROM evenements
                             WHERE user_id = ? ORDER BY id DESC LIMIT ?");
        $st->execute([$id, $limite]);
        foreach ($st as $e) {
            $out[] = ['quand' => $e['created_at'], 'type' => ucfirst((string) $e['type']),
                      'quoi'  => stats_tronquer((string) $e['cible'], 70)];
        }
    } catch (Throwable $e) {}

    try {
        $nom = db()->prepare("SELECT nom || ' ' || prenom FROM users WHERE id = ?");
        $nom->execute([$id]);
        $st = db()->prepare("SELECT created_at, action, detail FROM dac7_registre
                             WHERE auteur <> '' ORDER BY id DESC LIMIT ?");
        $st->execute([$limite]);
        foreach ($st as $d) {
            $out[] = ['quand' => $d['created_at'], 'type' => 'DAC7',
                      'quoi'  => $d['action'] . ' ' . stats_tronquer((string) $d['detail'], 50)];
        }
    } catch (Throwable $e) {}

    usort($out, fn($a, $b) => strcmp((string) $b['quand'], (string) $a['quand']));
    return array_slice($out, 0, $limite);
}

/* ==================================================================
 *  Journal des actions sensibles
 * ================================================================== */

function equipe_journaliser($action, $cible_id = 0, $detail = '') {
    try {
        $u = user();
        db()->prepare("INSERT INTO admin_journal (auteur_id, cible_id, action, detail, created_at)
                       VALUES (?,?,?,?,datetime('now'))")
            ->execute([(int) ($u['id'] ?? 0), (int) $cible_id,
                       substr((string) $action, 0, 60), substr((string) $detail, 0, 250)]);
    } catch (Throwable $e) {}
}

function equipe_journal($limite = 30) {
    try {
        $st = db()->prepare(
            "SELECT j.*, a.nom AS a_nom, a.prenom AS a_prenom, c.nom AS c_nom, c.prenom AS c_prenom
             FROM admin_journal j
             LEFT JOIN users a ON a.id = j.auteur_id
             LEFT JOIN users c ON c.id = j.cible_id
             ORDER BY j.id DESC LIMIT ?");
        $st->execute([(int) $limite]);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

/* ==================================================================
 *  Connexion à la place d'un administrateur
 * ------------------------------------------------------------------
 *  Sert à reproduire un problème que l'intéressé décrit mal. Ce n'est
 *  pas une prise de contrôle de son appareil : on ouvre simplement
 *  une session à son nom, depuis votre navigateur.
 * ================================================================== */

/** Une session d'emprunt est-elle en cours ? */
function emprunt_actif() {
    return !empty($_SESSION['emprunt_origine']);
}

/** Compte réel de la personne aux commandes. */
function emprunt_origine() {
    if (!emprunt_actif()) { return null; }
    $st = db()->prepare("SELECT * FROM users WHERE id = ?");
    $st->execute([(int) $_SESSION['emprunt_origine']]);
    return $st->fetch() ?: null;
}

/**
 * Peut-on emprunter ce compte ?
 * @return string '' si oui, sinon le motif du refus
 */
function emprunt_refus($cible) {
    if (!$cible)                        { return "Compte introuvable."; }
    if (emprunt_actif())                { return "Une session d'emprunt est déjà en cours."; }
    if (!est_admin_principal())         { return "Réservé à l'administrateur principal."; }
    $moi = user();
    if ((int) $cible['id'] === (int) ($moi['id'] ?? 0)) { return "C'est déjà votre compte."; }
    if (est_admin_principal($cible))    { return "Le compte principal ne peut pas être emprunté."; }
    if ((int) $cible['actif'] !== 1)    { return "Ce compte est désactivé."; }
    return '';
}

function emprunt_demarrer($cible) {
    $moi = user();
    equipe_journaliser('emprunt_debut', (int) $cible['id'],
        'Connexion à la place de ' . $cible['email']);
    $_SESSION['emprunt_origine'] = (int) $moi['id'];
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $cible['id'];
    user(true);
}

function emprunt_arreter() {
    if (!emprunt_actif()) { return; }
    $retour = (int) $_SESSION['emprunt_origine'];
    $cible  = (int) ($_SESSION['uid'] ?? 0);
    unset($_SESSION['emprunt_origine']);
    session_regenerate_id(true);
    $_SESSION['uid'] = $retour;
    user(true);
    equipe_journaliser('emprunt_fin', $cible, 'Retour au compte principal');
}
