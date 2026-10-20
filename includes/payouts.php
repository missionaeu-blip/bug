<?php
/**
 * Versements aux experts — modèle international.
 *
 * Principe repris des grandes plateformes (Upwork, Fiverr) :
 *   1. le client paie Missiona, jamais l'expert directement ;
 *   2. les sommes sont séquestrées jusqu'à la livraison ;
 *   3. un délai de sécurité court avant que le solde devienne retirable ;
 *   4. l'expert déclenche lui-même son versement vers le moyen de son choix ;
 *   5. l'équipe exécute le versement et le marque comme payé.
 *
 * Les montants sont stockés en euros entiers (devise de base de la plateforme).
 * La devise choisie par l'expert indique dans quelle monnaie il souhaite être
 * payé : la conversion est faite par le prestataire au moment du virement.
 */

/** Délai de sécurité, en jours, avant qu'une somme devienne retirable. */
function delai_securite() {
    $j = (int) param('delai_securite', 7);
    return max(0, min(60, $j));
}

/** Montant minimum d'une demande de versement (euros). */
function retrait_minimum() {
    return max(1, (int) param('retrait_minimum', 50));
}

/**
 * Moyens de versement proposés.
 * 'zones' limite la disponibilité ; 'monde' = partout.
 */
function payout_methodes() {
    return [
        'stripe' => [
            'nom'    => 'Virement bancaire (via Stripe)',
            'aide'   => "Coordonnées enregistrées chez Stripe. Délai habituel : 1 à 3 jours ouvrés.",
            'frais'  => 0,
            'champs' => [],
            'zones'  => 'monde',
        ],
        'virement' => [
            'nom'    => 'Virement bancaire (IBAN / SEPA)',
            'aide'   => "Zone euro et SEPA. Délai habituel : 1 à 3 jours ouvrés.",
            'frais'  => 0,
            'champs' => ['titulaire', 'iban', 'bic'],
            'zones'  => 'sepa',
        ],
        'swift' => [
            'nom'    => 'Virement international (SWIFT)',
            'aide'   => "Hors zone SEPA. Délai : 3 à 7 jours ouvrés. Frais bancaires possibles côté banque réceptrice.",
            'frais'  => 15,
            'champs' => ['titulaire', 'iban', 'bic'],
            'zones'  => 'monde',
        ],
        'payoneer' => [
            'nom'    => 'Payoneer',
            'aide'   => "Recommandé hors zone euro : versement en USD ou devise locale, puis retrait vers votre banque.",
            'frais'  => 2,
            'champs' => ['email'],
            'zones'  => 'monde',
        ],
        'wise' => [
            'nom'    => 'Wise',
            'aide'   => "Bon taux de change pour les versements hors zone euro.",
            'frais'  => 2,
            'champs' => ['titulaire', 'email'],
            'zones'  => 'monde',
        ],
        'paypal' => [
            'nom'    => 'PayPal',
            'aide'   => "Rapide, mais PayPal applique sa propre marge sur le change (3 à 4 %).",
            'frais'  => 2,
            'champs' => ['email'],
            'zones'  => 'monde',
        ],
    ];
}

/** Pays de la zone SEPA (virement en euros sans frais). */
function pays_sepa() {
    return ['FR','BE','LU','DE','ES','IT','PT','NL','AT','IE','FI','GR','MC','MT',
            'SK','SI','EE','LV','LT','CY','PL','CZ','HU','RO','BG','HR','SE','DK',
            'NO','IS','LI','CH','GB'];
}

/** Devises proposées au versement. */
function devises_payout() {
    return ['EUR' => 'Euro (€)', 'USD' => 'Dollar US ($)', 'GBP' => 'Livre sterling (£)',
            'CHF' => 'Franc suisse', 'CAD' => 'Dollar canadien', 'MAD' => 'Dirham marocain',
            'XOF' => 'Franc CFA (UEMOA)', 'XAF' => 'Franc CFA (CEMAC)', 'TND' => 'Dinar tunisien'];
}

/** Liste de pays (code ISO => nom), volontairement courte et extensible. */
function pays_liste() {
    // Pays vers lesquels notre prestataire de paiement sait effectuer un
    // versement depuis une plateforme européenne : l'Espace économique
    // européen, le Royaume-Uni, la Suisse, le Canada et les États-Unis.
    // Hors de cette zone, aucun versement automatique n'est possible.
    return [
        'DE'=>'Allemagne','AT'=>'Autriche','BE'=>'Belgique','BG'=>'Bulgarie',
        'CA'=>'Canada','CY'=>'Chypre','HR'=>'Croatie','DK'=>'Danemark',
        'ES'=>'Espagne','EE'=>'Estonie','US'=>'États-Unis','FI'=>'Finlande',
        'FR'=>'France','GR'=>'Grèce','HU'=>'Hongrie','IE'=>'Irlande',
        'IS'=>'Islande','IT'=>'Italie','LV'=>'Lettonie','LI'=>'Liechtenstein',
        'LT'=>'Lituanie','LU'=>'Luxembourg','MT'=>'Malte','NO'=>'Norvège',
        'NL'=>'Pays-Bas','PL'=>'Pologne','PT'=>'Portugal','CZ'=>'Tchéquie',
        'RO'=>'Roumanie','GB'=>'Royaume-Uni','SK'=>'Slovaquie','SI'=>'Slovénie',
        'SE'=>'Suède','CH'=>'Suisse',
    ];
}

/** Méthodes réellement disponibles pour un pays donné. */
function payout_methodes_pour($pays) {
    $sepa = in_array(strtoupper((string) $pays), pays_sepa(), true);
    $out  = [];
    foreach (payout_methodes() as $cle => $m) {
        if ($m['zones'] === 'sepa' && !$sepa) { continue; }
        if ($cle === 'swift' && $sepa)        { continue; } // inutile en SEPA
        $out[$cle] = $m;
    }
    return $out;
}

/** Frais retenus sur un versement, selon la méthode. */
function payout_frais($methode) {
    $m = payout_methodes();
    return isset($m[$methode]) ? (int) $m[$methode]['frais'] : 0;
}

/** Les coordonnées de versement de l'expert sont-elles complètes ? */
function payout_complet($u) {
    $meth = $u['payout_methode'] ?? '';
    if ($meth === '') { return false; }
    $def = payout_methodes()[$meth] ?? null;
    if (!$def) { return false; }
    foreach ($def['champs'] as $c) {
        $col = $c === 'email' ? 'payout_email' : 'payout_' . $c;
        if (trim((string) ($u[$col] ?? '')) === '') { return false; }
    }
    return true;
}

/** Destination lisible et masquée, pour l'affichage et la traçabilité. */
function payout_destination($u) {
    $meth = $u['payout_methode'] ?? '';
    if (in_array($meth, ['virement', 'swift'], true)) {
        $ib = preg_replace('/\s+/', '', (string) $u['payout_iban']);
        return $ib === '' ? '' : substr($ib, 0, 4) . ' •••• ' . substr($ib, -4);
    }
    return (string) ($u['payout_email'] ?? '');
}

/**
 * Somme gagnée et libérée : factures des projets terminés dont la date
 * de règlement dépasse le délai de sécurité.
 */
/**
 * Délai de sécurité applicable à UN expert donné.
 * ------------------------------------------------------------------
 * Le délai protège la plateforme : tant qu'il court, l'argent reste
 * mobilisable en cas de litige ou d'impayé du client. Un administrateur
 * peut en dispenser un expert de confiance, compte par compte.
 */
function delai_pour_expert($freelance_id) {
    static $cache = [];
    $id = (int) $freelance_id;
    if (isset($cache[$id])) { return $cache[$id]; }
    $st = db()->prepare("SELECT delai_exempt FROM users WHERE id = ?");
    $st->execute([$id]);
    return $cache[$id] = ((int) $st->fetchColumn() === 1) ? 0 : delai_securite();
}

/** Active ou lève la dispense de délai pour un expert. */
function delai_exempter($freelance_id, $actif, $auteur = '') {
    db()->prepare("UPDATE users SET delai_exempt = ?, delai_exempt_le = ?, delai_exempt_par = ?
                   WHERE id = ?")
        ->execute([
            $actif ? 1 : 0,
            $actif ? date('Y-m-d H:i:s') : '',
            $actif ? substr((string) $auteur, 0, 120) : '',
            (int) $freelance_id,
        ]);
}

function gains_liberes($freelance_id) {
    $sql = "SELECT COALESCE(SUM(f.montant_freelance),0)
            FROM factures f
            JOIN projets p ON p.id = f.projet_id
            JOIN paiements pa ON pa.projet_id = p.id AND pa.type='projet' AND pa.statut='paye'
            WHERE p.freelance_id = ? AND p.statut = 'termine'
              AND COALESCE(f.versement_direct,0) = 0
              AND julianday('now') - julianday(pa.paye_le) >= " . (int) delai_pour_expert($freelance_id);
    $st = db()->prepare($sql);
    $st->execute([(int) $freelance_id]);
    return (int) $st->fetchColumn();
}

/** Somme gagnée mais encore dans le délai de sécurité. */
function gains_en_attente($freelance_id) {
    $sql = "SELECT COALESCE(SUM(f.montant_freelance),0)
            FROM factures f
            JOIN projets p ON p.id = f.projet_id
            JOIN paiements pa ON pa.projet_id = p.id AND pa.type='projet' AND pa.statut='paye'
            WHERE p.freelance_id = ? AND p.statut = 'termine'
              AND COALESCE(f.versement_direct,0) = 0
              AND julianday('now') - julianday(pa.paye_le) < " . (int) delai_pour_expert($freelance_id);
    $st = db()->prepare($sql);
    $st->execute([(int) $freelance_id]);
    return (int) $st->fetchColumn();
}

/** Total déjà retiré ou en cours de retrait (tout sauf les refus). */
function total_retire($freelance_id) {
    $st = db()->prepare("SELECT COALESCE(SUM(montant),0) FROM retraits
                         WHERE freelance_id=? AND statut IN ('demande','traite','paye')");
    $st->execute([(int) $freelance_id]);
    return (int) $st->fetchColumn();
}

/** Solde réellement disponible au retrait. */
function solde_disponible($freelance_id) {
    return max(0, gains_liberes($freelance_id) - total_retire($freelance_id));
}

/** Une demande est-elle déjà en cours ? */
function retrait_en_cours($freelance_id) {
    $st = db()->prepare("SELECT * FROM retraits WHERE freelance_id=? AND statut IN ('demande','traite')
                         ORDER BY id DESC LIMIT 1");
    $st->execute([(int) $freelance_id]);
    return $st->fetch() ?: null;
}

/** Historique des versements d'un expert. */
function retraits_de($freelance_id, $limite = 50) {
    $st = db()->prepare("SELECT * FROM retraits WHERE freelance_id=? ORDER BY id DESC LIMIT ?");
    $st->execute([(int) $freelance_id, (int) $limite]);
    return $st->fetchAll();
}

/**
 * Enregistre une demande de versement.
 * Renvoie ['ok'=>bool, 'erreur'=>string].
 */
function demander_retrait($u, $montant) {
    // Un paiement direct est crédité sur le compte connecté de l'expert.
    // Il n'existe donc aucun solde Missiona à retirer ni transfert plateforme
    // à déclencher : les virements sont gérés depuis Stripe Dashboard.
    if (connect_pour($u)) {
        return ['ok' => false, 'erreur' => "Vos paiements sont versés directement sur votre compte Stripe. Gérez vos virements dans Stripe Dashboard."];
    }

    $montant = (int) $montant;
    $dispo   = solde_disponible($u['id']);

    if (!payout_complet($u)) {
        return ['ok' => false, 'erreur' => "Renseignez d'abord vos coordonnées de versement."];
    }
    if (retrait_en_cours($u['id'])) {
        return ['ok' => false, 'erreur' => "Une demande est déjà en cours de traitement."];
    }
    if ($montant < retrait_minimum()) {
        return ['ok' => false, 'erreur' => "Le montant minimum est de " . retrait_minimum() . " €."];
    }
    if ($montant > $dispo) {
        return ['ok' => false, 'erreur' => "Montant supérieur à votre solde disponible (" . $dispo . " €)."];
    }

    $frais   = payout_frais($u['payout_methode']);
    $methode = $u['payout_methode'];
    $dest    = payout_destination($u);
    $net = max(0, $montant - $frais);

    db()->prepare("INSERT INTO retraits (freelance_id,montant,frais,net,devise,methode,destination,statut)
                   VALUES (?,?,?,?,?,?,?,'demande')")
        ->execute([
            $u['id'], $montant, $frais, $net,
            $u['devise'] ?: 'EUR', $methode, $dest,
        ]);

    $rid = (int) db()->lastInsertId();
    notify(admin_id(), "Demande de versement de " . $montant . " € — "
        . trim($u['prenom'] . ' ' . $u['nom']), '/admin-retraits');
    return ['ok' => true, 'id' => $rid];
}

/** Change le statut d'un versement (réservé à l'équipe). */
function traiter_retrait($id, $statut, $reference = '', $note = '') {
    if (!in_array($statut, ['traite', 'paye', 'refuse'], true)) { return false; }

    $st = db()->prepare("SELECT * FROM retraits WHERE id=?");
    $st->execute([(int) $id]);
    $r = $st->fetch();
    if (!$r) { return false; }

    // Les anciennes demandes marquées « stripe » ne doivent jamais appeler
    // Transfers : une direct charge ne crédite pas le solde de la plateforme.
    // Elles sont conservées pour l'historique et doivent être régularisées
    // manuellement, avec une trace comptable, avant tout marquage « payé ».
    if ($statut === 'paye' && $r['methode'] === 'stripe') {
        db()->prepare("UPDATE retraits SET note=? WHERE id=?")
            ->execute(["Aucun transfert Stripe automatique : les fonds d'une direct charge appartiennent au compte connecté.", (int) $id]);
        return false;
    }

    db()->prepare("UPDATE retraits SET statut=?, reference=?, note=?, traite_le=CURRENT_TIMESTAMP WHERE id=?")
        ->execute([$statut, $reference, $note, (int) $id]);

    $msg = [
        'traite' => "Votre versement de " . $r['montant'] . " € est en cours d'exécution.",
        'paye'   => "Votre versement de " . $r['net'] . " € a été effectué.",
        'refuse' => "Votre demande de versement n'a pas pu être traitée." . ($note ? " Motif : " . $note : ''),
    ][$statut];
    notify($r['freelance_id'], $msg, '/factures');
    return true;
}

/** Libellé lisible d'un statut de versement. */
function retrait_statut_label($s) {
    return [
        'demande' => 'Demande reçue',
        'traite'  => 'En cours',
        'paye'    => 'Versé',
        'refuse'  => 'Refusée',
    ][$s] ?? $s;
}

/** Classe de badge associée. */
function retrait_statut_badge($s) {
    return [
        'demande' => 'badge-amber',
        'traite'  => 'badge-blue',
        'paye'    => 'badge-green',
        'refuse'  => 'badge-gray',
    ][$s] ?? 'badge-gray';
}
