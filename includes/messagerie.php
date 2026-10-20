<?php
/**
 * Missiona — Périmètre de la messagerie
 * ------------------------------------------------------------------
 * Deux règles :
 *
 *  1. Un administrateur qui n'est pas l'administrateur principal ne
 *     converse qu'avec les comptes dont il a la charge : les clients
 *     qui lui sont rattachés, et les experts intervenant sur leurs
 *     projets. L'administrateur principal, lui, voit tout le monde.
 *
 *  2. Les conversations sont regroupées par projet, pour retrouver
 *     d'un coup d'œil les deux interlocuteurs d'une même mission.
 *
 * Le filtrage est appliqué à l'envoi, pas seulement à l'affichage :
 * masquer un contact dans une liste n'empêche personne de poster un
 * identifiant à la main.
 */

/** L'administrateur voit-il l'ensemble des comptes ? */
function msg_admin_global($u = null) {
    $u = $u ?: user();
    return $u && ($u['role'] ?? '') === 'admin' && est_admin_principal($u);
}

/**
 * Identifiants des comptes avec lesquels cet administrateur peut
 * échanger. Renvoie null lorsqu'il n'y a aucune restriction.
 * @return array<int>|null
 */
function msg_perimetre_admin($u = null) {
    $u = $u ?: user();
    if (!$u || ($u['role'] ?? '') !== 'admin') { return []; }
    if (msg_admin_global($u)) { return null; }

    $id  = (int) $u['id'];
    $ids = [];

    // Les clients qui lui sont rattachés
    $st = db()->prepare("SELECT id FROM users
                         WHERE role IN ('entreprise','particulier') AND gestionnaire_id = ?");
    $st->execute([$id]);
    foreach ($st as $r) { $ids[] = (int) $r['id']; }

    // Les experts intervenant sur les projets de ces clients
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        foreach (db()->query("SELECT DISTINCT p.freelance_id FROM projets p
                              WHERE p.entreprise_id IN ($in) AND p.freelance_id IS NOT NULL") as $r) {
            if ((int) $r['freelance_id'] > 0) { $ids[] = (int) $r['freelance_id']; }
        }
    }
    return array_values(array_unique($ids));
}

/** Cet administrateur a-t-il le droit d'écrire à ce compte ? */
function msg_peut_ecrire($u, $cible_id) {
    $cible_id = (int) $cible_id;
    if ($cible_id <= 0) { return false; }

    $st = db()->prepare("SELECT role FROM users WHERE id = ?");
    $st->execute([$cible_id]);
    $role = (string) $st->fetchColumn();
    if (!in_array($role, ['entreprise', 'particulier', 'freelance'], true)) { return false; }

    $perimetre = msg_perimetre_admin($u);
    if ($perimetre === null) { return true; }
    return in_array($cible_id, $perimetre, true);
}

/** Contacts affichables, éventuellement restreints à un projet. */
function msg_contacts($u, $projet_id = 0) {
    $perimetre = msg_perimetre_admin($u);
    $projet_id = (int) $projet_id;

    if ($projet_id > 0) {
        $p = msg_projet($projet_id);
        if (!$p) { return []; }
        $ids = array_filter([(int) $p['entreprise_id'], (int) $p['freelance_id']]);
        if ($perimetre !== null) { $ids = array_intersect($ids, $perimetre); }
        if (!$ids) { return []; }
        $in = implode(',', array_map('intval', $ids));
        return db()->query("SELECT * FROM users WHERE id IN ($in) ORDER BY role, nom")->fetchAll();
    }

    if ($perimetre === null) {
        return db()->query("SELECT * FROM users
                            WHERE role IN ('entreprise','particulier','freelance')
                            ORDER BY role, nom")->fetchAll();
    }
    if (!$perimetre) { return []; }
    $in = implode(',', array_map('intval', $perimetre));
    return db()->query("SELECT * FROM users WHERE id IN ($in) ORDER BY role, nom")->fetchAll();
}

/** Un projet, avec ses deux interlocuteurs. */
function msg_projet($projet_id) {
    $st = db()->prepare(
        "SELECT p.id, p.titre, p.statut, p.entreprise_id, p.freelance_id,
                c.societe AS c_societe, c.nom AS c_nom, c.prenom AS c_prenom,
                f.nom AS f_nom, f.prenom AS f_prenom
         FROM projets p
         LEFT JOIN users c ON c.id = p.entreprise_id
         LEFT JOIN users f ON f.id = p.freelance_id
         WHERE p.id = ?");
    $st->execute([(int) $projet_id]);
    return $st->fetch() ?: null;
}

/**
 * Projets visibles dans la messagerie de cet administrateur,
 * du plus récent au plus ancien.
 */
function msg_projets($u) {
    $perimetre = msg_perimetre_admin($u);

    $sql = "SELECT p.id, p.titre, p.statut, p.entreprise_id, p.freelance_id,
                   c.societe AS c_societe, c.nom AS c_nom, c.prenom AS c_prenom,
                   f.nom AS f_nom, f.prenom AS f_prenom
            FROM projets p
            LEFT JOIN users c ON c.id = p.entreprise_id
            LEFT JOIN users f ON f.id = p.freelance_id";

    if ($perimetre === null) {
        $sql .= " ORDER BY p.id DESC LIMIT 60";
        return db()->query($sql)->fetchAll();
    }
    if (!$perimetre) { return []; }
    $in = implode(',', array_map('intval', $perimetre));
    $sql .= " WHERE p.entreprise_id IN ($in) OR p.freelance_id IN ($in)
              ORDER BY p.id DESC LIMIT 60";
    return db()->query($sql)->fetchAll();
}

/** Messages non lus provenant d'un compte donné. */
function msg_non_lus($moi_id, $de_id) {
    $st = db()->prepare("SELECT COUNT(*) FROM messages
                         WHERE expediteur_id=? AND destinataire_id=? AND lu=0");
    $st->execute([(int) $de_id, (int) $moi_id]);
    return (int) $st->fetchColumn();
}

/** Dernier message échangé avec un compte. */
function msg_dernier($moi_id, $avec_id) {
    $st = db()->prepare("SELECT contenu FROM messages
                         WHERE (expediteur_id=? AND destinataire_id=?)
                            OR (expediteur_id=? AND destinataire_id=?)
                         ORDER BY id DESC LIMIT 1");
    $st->execute([(int) $moi_id, (int) $avec_id, (int) $avec_id, (int) $moi_id]);
    return (string) $st->fetchColumn();
}

/** Libellé court d'un projet pour la liste de gauche. */
function msg_projet_libelle($p) {
    $client = $p['c_societe'] ?: trim(($p['c_prenom'] ?? '') . ' ' . ($p['c_nom'] ?? ''));
    return $client !== '' ? $client : 'Client supprimé';
}

/* ------------------------------------------------------------------
 * Contact direct entreprise↔expert
 * ------------------------------------------------------------------
 * Par défaut, tout passe par Missiona. L'équipe peut ouvrir un canal
 * direct sur un projet précis : les deux parties échangent alors en
 * direct, messages et pièces jointes compris.
 *
 * Règles de sécurité :
 *  - l'ouverture est réservée à l'administrateur (voir projet.php) ;
 *  - l'autorisation d'écrire est recalculée À CHAQUE ENVOI : fermer
 *    le canal coupe immédiatement la conversation, même formulaire
 *    forcé à la main ;
 *  - un compte ne peut parler qu'à son homologue du projet : ni le
 *    paramètre de formulaire, ni un identifiant saisi dans l'adresse
 *    ne permettent de viser quelqu'un d'autre.
 * ------------------------------------------------------------------ */

/**
 * Projets sur lesquels ce compte dispose d'un canal direct ouvert,
 * avec l'homologue en face. Uniquement pour entreprise / freelance :
 * l'administrateur passe par la vue de surveillance.
 */
function contact_direct_projets($u) {
    if (!$u || !in_array($u['role'] ?? '', ['entreprise', 'particulier', 'freelance'], true)) {
        return [];
    }
    $st = db()->prepare(
        "SELECT p.id, p.titre, p.entreprise_id, p.freelance_id,
                h.id AS h_id, h.nom AS h_nom, h.prenom AS h_prenom,
                h.titre_pro AS h_titre, h.societe AS h_societe, h.role AS h_role
         FROM projets p
         JOIN users h ON h.id = CASE WHEN p.entreprise_id = ? THEN p.freelance_id ELSE p.entreprise_id END
         WHERE (p.entreprise_id = ? OR p.freelance_id = ?)
           AND p.contact_direct = 1
           AND p.statut <> 'annule'
           AND p.entreprise_id > 0 AND p.freelance_id > 0
         ORDER BY p.id DESC");
    $st->execute([(int) $u['id'], (int) $u['id'], (int) $u['id']]);
    return $st->fetchAll();
}

/**
 * Homologue autorisé pour un échange direct sur ce projet, sinon 0.
 * C'est LE garde-fou appliqué à l'envoi : seule une paire
 * entreprise↔expert d'un projet au canal ouvert peut correspondre.
 */
function contact_direct_homologue($u, $projet_id) {
    if (!$u || (int) $projet_id <= 0) { return 0; }
    if (!in_array($u['role'] ?? '', ['entreprise', 'particulier', 'freelance'], true)) { return 0; }

    $st = db()->prepare(
        "SELECT entreprise_id, freelance_id FROM projets
         WHERE id = ? AND contact_direct = 1 AND statut <> 'annule'");
    $st->execute([(int) $projet_id]);
    $p = $st->fetch();
    if (!$p) { return 0; }

    $moi = (int) $u['id'];
    if ((int) $p['entreprise_id'] === $moi) { return (int) $p['freelance_id']; }
    if ((int) $p['freelance_id']  === $moi) { return (int) $p['entreprise_id']; }
    return 0; // ni client ni expert de ce projet : personne
}

/**
 * Ce compte peut-il écrire en direct à cette cible ? Vérifié à
 * l'envoi : il faut un projet commun au canal ouvert entre les deux.
 */
function contact_direct_autorise($u, $cible_id) {
    foreach (contact_direct_projets($u) as $p) {
        if ((int) $p['h_id'] === (int) $cible_id) { return (int) $p['id']; }
    }
    return 0;
}

function msg_titre_projet($projet_id) {
    $st = db()->prepare("SELECT titre FROM projets WHERE id = ?");
    $st->execute([(int) $projet_id]);
    return (string) $st->fetchColumn() ?: 'projet';
}

/**
 * Surveillance équipe : projets au canal direct ouvert, dans le
 * périmètre de l'administrateur. Sert à la lecture seule des
 * échanges directs (messages.php?lecture=…).
 */
function contact_direct_surveillance($u) {
    if (!$u || ($u['role'] ?? '') !== 'admin') { return []; }
    $perimetre = msg_perimetre_admin($u);
    if ($perimetre === []) { return []; }

    $sql = "SELECT p.id, p.titre, p.entreprise_id, p.freelance_id,
                   c.societe AS c_societe, c.nom AS c_nom, c.prenom AS c_prenom,
                   f.nom AS f_nom, f.prenom AS f_prenom,
                   (SELECT COUNT(*) FROM messages m
                     WHERE m.projet_id = p.id
                       AND ((m.expediteur_id = p.entreprise_id AND m.destinataire_id = p.freelance_id)
                         OR (m.expediteur_id = p.freelance_id AND m.destinataire_id = p.entreprise_id))) AS nb
            FROM projets p
            LEFT JOIN users c ON c.id = p.entreprise_id
            LEFT JOIN users f ON f.id = p.freelance_id
            WHERE p.contact_direct = 1";
    if ($perimetre !== null) {
        $in = implode(',', array_map('intval', $perimetre));
        $sql .= " AND (p.entreprise_id IN ($in) OR p.freelance_id IN ($in))";
    }
    $sql .= " ORDER BY p.id DESC LIMIT 60";
    return db()->query($sql)->fetchAll();
}
