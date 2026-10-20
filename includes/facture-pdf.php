<?php
/**
 * Missiona — Édition des factures au format PDF
 * ------------------------------------------------------------------
 * Mentions obligatoires : article 242 nonies A de l'annexe II au CGI,
 * complété par les mentions issues de la réforme de la facturation
 * électronique (n° SIREN du client, adresse de livraison, catégorie
 * d'opération, option éventuelle pour la TVA sur les débits).
 *
 * Modèle « paiement direct » : le client règle l'expert hors plateforme.
 * Missiona n'émet donc qu'un seul type de facture :
 *   - facture_remuneration_pdf() : Missiona facture à l'expert sa
 *     rémunération (commission d'apport d'affaires, sans aucun frais
 *     fixe), exigible une fois le client réglé (CGU, art. 4).
 * L'entreprise cliente ne reçoit aucune facture de Missiona ; c'est
 * l'expert qui la facture directement pour la prestation.
 */

/** Identité légale de la société, depuis les paramètres. */
function societe_emettrice() {
    return [
        'nom'      => param('dac7_nom', SITE_NOM),
        'forme'    => param('soc_forme', ''),
        'capital'  => param('soc_capital', ''),
        'adresse'  => param('dac7_adresse', ''),
        'cp'       => param('dac7_cp', ''),
        'ville'    => param('dac7_ville', ''),
        'pays'     => param('dac7_pays', 'FR'),
        'siret'    => param('dac7_nif', ''),
        'rcs'      => param('soc_rcs', ''),
        'tva'      => param('soc_tva', ''),
        'email'    => defined('SITE_EMAIL') ? SITE_EMAIL : '',
        'tel'      => defined('SITE_TEL') ? SITE_TEL : '',
    ];
}

/** Taux de TVA applicable (0 = franchise en base). */
function tva_taux()      { return (float) param('tva_taux', 20); }
function tva_franchise() { return (int) param('tva_franchise', 0) === 1; }

/** Délai de paiement et pénalités (mentions obligatoires B2B). */
function delai_paiement()  { return (int) param('delai_paiement', 30); }
function penalites_taux()  { return (float) param('penalites_taux', 10.5); }

/** Formatage monétaire français : 1 234,56 €. */
function pdf_euros($montant) {
    return number_format((float) $montant, 2, ',', ' ') . ' EUR';
}

function pdf_date($sql) {
    $t = strtotime((string) $sql);
    return $t ? date('d/m/Y', $t) : '';
}

/**
 * Charge une facture avec son projet, le client et l'expert.
 * @return array|null
 */
function facture_complete($facture_id) {
    $st = db()->prepare(
        "SELECT f.*, p.titre AS projet_titre, p.id AS pid, p.created_at AS projet_debut,
                p.entreprise_id, p.freelance_id
         FROM factures f
         JOIN projets p ON p.id = f.projet_id
         WHERE f.id = ?");
    $st->execute([(int) $facture_id]);
    $f = $st->fetch();
    if (!$f) { return null; }

    $su = db()->prepare("SELECT * FROM users WHERE id = ?");
    $su->execute([(int) $f['entreprise_id']]);
    $f['client'] = $su->fetch() ?: [];

    $su->execute([(int) $f['freelance_id']]);
    $f['expert'] = $su->fetch() ?: [];

    return $f;
}

/* ------------------------------------------------------------------
 *  Bandeau commun
 * ------------------------------------------------------------------ */
function pdf_entete(PdfSimple $pdf, $soc, $titre, $numero, $date, $statut) {
    // Émetteur
    $pdf->police(true, 17);
    $pdf->texte(20, 24, $soc['nom'] ?: 'Missiona');

    $pdf->police(false, 8.5);
    $y = 30;
    $lignes = array_filter([
        trim($soc['forme'] . ($soc['capital'] ? ' au capital de ' . $soc['capital'] . ' EUR' : '')),
        $soc['adresse'],
        trim($soc['cp'] . ' ' . $soc['ville']),
        $soc['siret'] ? 'SIRET ' . $soc['siret'] : '',
        $soc['rcs'] ? 'RCS ' . $soc['rcs'] : '',
        $soc['tva'] ? 'TVA ' . $soc['tva'] : '',
        $soc['email'],
    ]);
    foreach ($lignes as $l) { $pdf->texte(20, $y, $l, 'g', [0.35, 0.40, 0.45]); $y += 4; }

    // Bloc titre à droite
    $pdf->police(true, 22);
    $pdf->texte(190, 26, $titre, 'd');
    $pdf->police(false, 9.5);
    $pdf->texte(190, 33, 'N° ' . $numero, 'd');
    $pdf->texte(190, 38, 'Émise le ' . $date, 'd');
    if ($statut !== '') {
        $pdf->police(true, 9.5);
        $pdf->texte(190, 44, $statut, 'd',
            $statut === 'PAYÉE' ? [0.11, 0.48, 0.29] : [0.64, 0.42, 0.0]);
    }
    return max($y, 50);
}

function pdf_pied(PdfSimple $pdf, $soc, $page = 1) {
    $pdf->trait(20, 276, 190, 276, 0.2, 0.85);
    $pdf->police(false, 7.2);
    $id = array_filter([$soc['nom'], $soc['siret'] ? 'SIRET ' . $soc['siret'] : '',
                        $soc['rcs'] ? 'RCS ' . $soc['rcs'] : '']);
    $pdf->texte(20, 281, implode(' — ', $id), 'g', [0.5, 0.54, 0.58]);
    $pdf->texte(190, 281, 'Page ' . $page, 'd', [0.5, 0.54, 0.58]);
}

/** Encadré « destinataire ». */
function pdf_destinataire(PdfSimple $pdf, $y, $libelle, array $lignes) {
    $pdf->bloc(115, $y - 5, 75, 6 + count($lignes) * 4.4, [0.96, 0.97, 0.98]);
    $pdf->police(true, 7.5);
    $pdf->texte(119, $y - 0.5, $libelle, 'g', [0.35, 0.40, 0.45]);
    $pdf->police(false, 9);
    $yy = $y + 4.5;
    foreach ($lignes as $i => $l) {
        if ($i === 0) { $pdf->police(true, 9.5); } else { $pdf->police(false, 8.7); }
        $pdf->texte(119, $yy, $l);
        $yy += 4.4;
    }
    return $yy + 2;
}

/* ------------------------------------------------------------------
 *  Facture de rémunération : Missiona facture l'expert
 * ------------------------------------------------------------------ */
function facture_remuneration_pdf($f) {
    $soc = societe_emettrice();
    $x   = $f['expert'];
    $pdf = new PdfSimple();

    $paye = ($f['statut'] === 'payee');
    $remboursee = ($f['statut'] === 'remboursee');
    pdf_entete($pdf, $soc, 'FACTURE', $f['numero'], pdf_date($f['created_at']),
               $remboursee ? 'REMBOURSÉE' : ($paye ? 'PAYÉE' : 'À RÉGLER'));

    // Destinataire : l'expert
    $lignes = array_values(array_filter([
        trim(($x['prenom'] ?? '') . ' ' . ($x['nom'] ?? '')),
        $x['adresse'] ?? '',
        trim(($x['code_postal'] ?? '') . ' ' . ($x['ville'] ?? '')),
        !empty($x['nif'])  ? 'SIREN / SIRET : ' . $x['nif'] : '',
        !empty($x['tva']) ? 'TVA : ' . $x['tva'] : '',
    ]));
    pdf_destinataire($pdf, 62, 'FACTURÉ À', $lignes);

    // Catégorie d'opération : mention issue de la réforme
    $pdf->police(false, 8.5);
    $pdf->texte(20, 68, 'Catégorie d\'opération : prestation de services', 'g', [0.35, 0.40, 0.45]);
    $pdf->texte(20, 72.5, 'Mission concernée : ' . $f['projet_titre'] . ' (projet n° ' . $f['pid'] . ')', 'g', [0.35, 0.40, 0.45]);

    // Tableau des prestations
    $y = 100;
    $pdf->bloc(20, $y - 5.5, 170, 8, [0.93, 0.94, 0.96]);
    $pdf->police(true, 8.5);
    $pdf->texte(23,  $y, 'DÉSIGNATION');
    $pdf->texte(128, $y, 'QTÉ', 'd');
    $pdf->texte(158, $y, 'P.U. HT', 'd');
    $pdf->texte(187, $y, 'TOTAL HT', 'd');
    $y += 9;

    $taux = (int) $f['montant_freelance'] > 0
        ? round(((int) $f['commission'] / (int) $f['montant_freelance']) * 100, 2)
        : 0;
    $taux_libelle = rtrim(rtrim(number_format($taux, 2, ',', ' '), '0'), ',');
    $pdf->police(false, 9.5);
    $pdf->texte(23, $y, 'Commission d\'apport d\'affaires (' . $taux_libelle . ' % de la mission)');
    $pdf->texte(128, $y, '1', 'd');
    $pdf->texte(158, $y, pdf_euros($f['commission']), 'd');
    $pdf->texte(187, $y, pdf_euros($f['commission']), 'd');
    $y += 5;
    $pdf->police(false, 8);
    $pdf->texte(23, $y, 'Montant de la prestation : ' . pdf_euros($f['montant_freelance'])
                . ', réglé directement par le client.', 'g', [0.45, 0.50, 0.55]);
    $y += 8;

    $y += 8;
    $pdf->trait(20, $y, 190, $y);
    $y += 8;

    // Totaux
    $ht  = (float) $f['montant_ht'];
    $tva = tva_franchise() ? 0.0 : round($ht * tva_taux() / 100, 2);
    $ttc = $ht + $tva;

    $pdf->police(false, 9.5);
    $pdf->texte(155, $y, 'Total HT', 'd');
    $pdf->texte(187, $y, pdf_euros($ht), 'd');
    $y += 5.5;

    if (tva_franchise()) {
        $pdf->texte(155, $y, 'TVA', 'd');
        $pdf->texte(187, $y, 'Non applicable', 'd');
        $y += 5.5;
    } else {
        $pdf->texte(155, $y, 'TVA ' . rtrim(rtrim(number_format(tva_taux(), 2, ',', ' '), '0'), ',') . ' %', 'd');
        $pdf->texte(187, $y, pdf_euros($tva), 'd');
        $y += 5.5;
    }

    $pdf->bloc(120, $y - 4.5, 70, 9, [0.93, 0.94, 0.96]);
    $pdf->police(true, 11);
    $pdf->texte(155, $y + 1, 'TOTAL TTC', 'd');
    $pdf->texte(187, $y + 1, pdf_euros($ttc), 'd');
    $y += 16;

    if (tva_franchise()) {
        $pdf->police(false, 8.5);
        $pdf->texte(20, $y, 'TVA non applicable, article 293 B du CGI.', 'g', [0.35, 0.40, 0.45]);
        $y += 6;
    }

    $commission_stripe = !empty($f['commission_prelevee_stripe']);
    $pdf->police(true, 9);
    $pdf->texte(20, $y, 'Conditions de règlement');
    $y += 5.5;
    $pdf->police(false, 8.5);
    if ($remboursee) {
        $y += $pdf->paragraphe(20, $y, 170,
            'Commission remboursée avec le paiement par carte Stripe. Aucun règlement complémentaire '
            . 'n\'est attendu de l\'expert.');
    } elseif ($commission_stripe) {
        $y += $pdf->paragraphe(20, $y, 170,
            'Commission acquittée automatiquement lors du paiement par carte Stripe, '
            . 'au titre des frais d\'application Missiona. Aucun règlement complémentaire '
            . 'n\'est attendu de l\'expert.');
    } else {
        // Mentions de paiement applicables seulement aux factures encore dues.
        $echeance = date('d/m/Y', strtotime($f['created_at'] . ' +' . delai_paiement() . ' days'));
        $y += $pdf->paragraphe(20, $y, 170,
            'Paiement à ' . delai_paiement() . ' jours, soit au plus tard le ' . $echeance . '. '
            . 'En cas de retard de paiement, application de pénalités au taux annuel de '
            . number_format(penalites_taux(), 2, ',', ' ') . ' %, exigibles sans rappel préalable, '
            . 'ainsi qu\'une indemnité forfaitaire pour frais de recouvrement de 40 EUR '
            . '(articles L. 441-10 et D. 441-5 du code de commerce). Aucun escompte n\'est accordé '
            . 'pour paiement anticipé.');
    }
    $y += 4;

    if ($paye || $remboursee) {
        $pdf->police(true, 9.5);
        $pdf->texte(20, $y,
                    $remboursee
                        ? 'Commission remboursée — aucun règlement complémentaire n\'est attendu.'
                        : 'Facture acquittée — aucun règlement complémentaire n\'est attendu.',
                    'g', [0.11, 0.48, 0.29]);
    }

    pdf_pied($pdf, $soc);
    return $pdf->rendu();
}

/* ------------------------------------------------------------------
 *  Relevé d'honoraires destiné à l'expert
 * ------------------------------------------------------------------ */
function releve_expert_pdf($f) {
    $soc = societe_emettrice();
    $x   = $f['expert'];
    $pdf = new PdfSimple();

    pdf_entete($pdf, $soc, 'RELEVÉ', 'R-' . $f['numero'], pdf_date($f['created_at']), '');

    $lignes = array_values(array_filter([
        trim(($x['prenom'] ?? '') . ' ' . ($x['nom'] ?? '')),
        $x['adresse'] ?? '',
        trim(($x['code_postal'] ?? '') . ' ' . ($x['ville'] ?? '')),
        !empty($x['nif']) ? 'NIF : ' . $x['nif'] : '',
        !empty($x['tva']) ? 'TVA : ' . $x['tva'] : '',
    ]));
    pdf_destinataire($pdf, 62, 'EXPERT', $lignes);

    $pdf->police(false, 8.5);
    $pdf->texte(20, 68, 'Mission : ' . $f['projet_titre'], 'g', [0.35, 0.40, 0.45]);
    $pdf->texte(20, 72.5, 'Projet n° ' . $f['pid'], 'g', [0.35, 0.40, 0.45]);

    $y = 100;
    $pdf->bloc(20, $y - 5.5, 170, 8, [0.93, 0.94, 0.96]);
    $pdf->police(true, 8.5);
    $pdf->texte(23, $y, 'DÉTAIL');
    $pdf->texte(187, $y, 'MONTANT', 'd');
    $y += 9;

    $pdf->police(false, 9.5);
    $pdf->texte(23, $y, 'Montant réglé par le client');
    $pdf->texte(187, $y, pdf_euros($f['montant_ht']), 'd');
    $y += 6;
    $pdf->texte(23, $y, 'Commission Missiona');
    $pdf->texte(187, $y, '- ' . pdf_euros($f['commission']), 'd');
    $y += 7;
    $pdf->trait(20, $y, 190, $y);
    $y += 8;

    $pdf->bloc(120, $y - 4.5, 70, 9, [0.93, 0.94, 0.96]);
    $pdf->police(true, 11);
    $pdf->texte(155, $y + 1, 'NET À VERSER', 'd');
    $pdf->texte(187, $y + 1, pdf_euros($f['montant_freelance']), 'd');
    $y += 18;

    $pdf->police(false, 8.5);
    $y += $pdf->paragraphe(20, $y, 170,
        'Ce relevé récapitule les sommes qui vous sont dues au titre de cette mission. '
        . 'Il ne constitue pas une facture émise en votre nom : il vous appartient, si votre '
        . 'statut l\'exige, d\'établir votre propre facture à destination de ' . ($soc['nom'] ?: SITE_NOM) . '. '
        . 'Les montants indiqués sont exprimés hors taxes.');

    pdf_pied($pdf, $soc);
    return $pdf->rendu();
}

/**
 * Contrôle d'accès : qui a le droit de télécharger ce document ?
 * Les factures sont émises par Missiona à destination de l'expert :
 * seul l'expert concerné (et l'équipe) y accède. L'entreprise cliente
 * ne reçoit aucune facture de Missiona.
 * @return bool
 */
function facture_accessible($f, $u) {
    if (!$u || !$f) { return false; }
    if ($u['role'] === 'admin') { return true; }
    return (int) $f['freelance_id'] === (int) $u['id'];
}
