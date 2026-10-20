<?php
/**
 * Missiona — DAC7 (directive UE 2021/514)
 * ------------------------------------------------------------------
 * Obligation déclarative des opérateurs de plateforme.
 * En France : articles 1649 ter A à 1649 ter E du CGI, contenu fixé par
 * l'article 344 G terdecies de l'annexe III (décret n° 2023-422).
 *
 * RÈGLE CLÉ, souvent mal appliquée :
 * le seuil d'exclusion « moins de 30 opérations ET au plus 2 000 € »
 * ne concerne QUE la vente de biens. Missiona relève des
 * « services personnels » : tout expert payé est déclarable
 * DÈS LE PREMIER EURO, sans aucun seuil.
 *
 * Échéances :
 *   - 31 décembre année N : diligences achevées (collecte + vérification)
 *   - 31 janvier année N+1 : dépôt du fichier XML (Télé-TD) et envoi
 *     de son récapitulatif à chaque expert déclaré.
 * Manquement sanctionné jusqu'à 50 000 €.
 *
 * PHP pur, aucune dépendance.
 */

/** Année de déclaration par défaut (l'exercice clos). */
function dac7_annee_defaut() {
    return (int) date('Y') - 1;
}

/** Date limite de dépôt pour une année d'opérations donnée. */
function dac7_echeance($annee) {
    return '31 janvier ' . ((int) $annee + 1);
}

/** États membres de l'Union européenne (résidence = déclencheur). */
function dac7_pays_ue() {
    return ['AT','BE','BG','CY','CZ','DE','DK','EE','ES','FI','FR','GR','HR',
            'HU','IE','IT','LT','LU','LV','MT','NL','PL','PT','RO','SE','SI','SK'];
}

/** L'expert est-il dans le champ de la déclaration ? */
function dac7_dans_le_champ($u) {
    $pays = strtoupper(trim((string) ($u['pays'] ?? '')));
    // Résident d'un État membre : déclarable. Hors UE : hors champ pour
    // la déclaration française (sauf immobilier situé en UE, sans objet ici).
    return in_array($pays, dac7_pays_ue(), true);
}

/* ------------------------------------------------------------------
 *  Données à collecter (art. 344 G terdecies annexe III)
 * ------------------------------------------------------------------ */

/**
 * Liste des informations manquantes pour un expert donné.
 * @return array<string,string> clé technique => libellé lisible
 */
function dac7_champs_manquants($u) {
    $m = [];
    $entite = (($u['type_vendeur'] ?? 'physique') === 'entite');

    if (trim((string) $u['adresse']) === '')     { $m['adresse']     = 'Adresse'; }
    if (trim((string) $u['code_postal']) === '') { $m['code_postal'] = 'Code postal'; }
    if (trim((string) $u['ville']) === '')       { $m['ville']       = 'Ville'; }
    if (trim((string) $u['pays']) === '')        { $m['pays']        = 'Pays de résidence'; }

    if ($entite) {
        if (trim((string) $u['raison_sociale']) === '') { $m['raison_sociale'] = 'Dénomination sociale'; }
        if (trim((string) $u['num_immat']) === '')      { $m['num_immat']      = "Numéro d'immatriculation"; }
    } else {
        if (trim((string) $u['nom']) === '')    { $m['nom']    = 'Nom'; }
        if (trim((string) $u['prenom']) === '') { $m['prenom'] = 'Prénom'; }
        // Le NIF prime ; à défaut seulement, le lieu de naissance est admis.
        if (trim((string) $u['date_naissance']) === '') { $m['date_naissance'] = 'Date de naissance'; }
    }

    if (trim((string) $u['nif']) === '') {
        // Sans NIF, la directive exige le lieu de naissance en substitution.
        if (trim((string) $u['lieu_naissance']) === '') {
            $m['nif'] = 'Numéro fiscal (NIF) ou lieu de naissance';
        }
    }
    return $m;
}

/** Le dossier fiscal de l'expert est-il complet ? */
function dac7_complet($u) {
    return count(dac7_champs_manquants($u)) === 0;
}

/**
 * Contrôle de cohérence du NIF (obligation de « vérifier la fiabilité »).
 * France : 13 chiffres pour un particulier, 14 (SIRET) en professionnel.
 * @return array{ok:bool,message:string}
 */
function dac7_verifier_nif($nif, $pays, $type = 'physique') {
    $nif  = strtoupper(preg_replace('/\s+/', '', (string) $nif));
    $pays = strtoupper(trim((string) $pays));

    if ($nif === '') {
        return ['ok' => false, 'message' => 'NIF absent.'];
    }
    if ($pays === 'FR') {
        if ($type === 'entite' || strlen($nif) === 14) {
            if (!preg_match('/^\d{14}$/', $nif)) {
                return ['ok' => false, 'message' => 'Un SIRET français comporte 14 chiffres.'];
            }
            return ['ok' => true, 'message' => 'Format SIRET valide.'];
        }
        if (!preg_match('/^\d{13}$/', $nif)) {
            return ['ok' => false, 'message' => 'Un numéro fiscal français comporte 13 chiffres.'];
        }
        // Le premier chiffre d'un NIF particulier vaut 0, 1, 2 ou 3.
        if (!in_array($nif[0], ['0', '1', '2', '3'], true)) {
            return ['ok' => false, 'message' => 'Le numéro fiscal doit commencer par 0, 1, 2 ou 3.'];
        }
        return ['ok' => true, 'message' => 'Format valide.'];
    }
    if (strlen($nif) < 6 || strlen($nif) > 20) {
        return ['ok' => false, 'message' => 'Longueur inhabituelle pour un NIF.'];
    }
    return ['ok' => true, 'message' => 'Format plausible (contrôle limité hors France).'];
}

/** Contrôle du format d'un numéro de TVA intracommunautaire. */
function dac7_verifier_tva($tva) {
    $tva = strtoupper(preg_replace('/\s+/', '', (string) $tva));
    if ($tva === '') { return ['ok' => true, 'message' => 'Non renseigné (facultatif).']; }
    if (!preg_match('/^[A-Z]{2}[0-9A-Z]{2,13}$/', $tva)) {
        return ['ok' => false, 'message' => 'Format attendu : code pays puis identifiant (ex. FR12345678901).'];
    }
    return ['ok' => true, 'message' => 'Format valide.'];
}

/* ------------------------------------------------------------------
 *  Agrégation financière — par TRIMESTRE (exigence de la directive)
 * ------------------------------------------------------------------ */

/**
 * Montants crédités à un expert, ventilés par trimestre.
 * La contrepartie est rattachée à la date de règlement du projet par
 * l'entreprise (fait générateur du crédit au profit de l'expert).
 *
 * @return array{
 *   trimestres:array<int,array{contrepartie:int,activites:int,frais:int}>,
 *   total_contrepartie:int, total_activites:int, total_frais:int
 * }
 */
function dac7_donnees_vendeur($freelance_id, $annee) {
    $annee = (int) $annee;
    $t = [1 => ['contrepartie' => 0, 'activites' => 0, 'frais' => 0],
          2 => ['contrepartie' => 0, 'activites' => 0, 'frais' => 0],
          3 => ['contrepartie' => 0, 'activites' => 0, 'frais' => 0],
          4 => ['contrepartie' => 0, 'activites' => 0, 'frais' => 0]];

    $sql = "SELECT f.montant_freelance, f.commission,
                   CAST(strftime('%m', pa.paye_le) AS INTEGER) AS mois
            FROM factures f
            JOIN projets  p  ON p.id = f.projet_id
            JOIN paiements pa ON pa.projet_id = p.id
                              AND pa.type='projet' AND pa.statut='paye'
            WHERE p.freelance_id = ?
              AND strftime('%Y', pa.paye_le) = ?";
    $st = db()->prepare($sql);
    $st->execute([(int) $freelance_id, (string) $annee]);

    foreach ($st as $r) {
        $q = (int) ceil(((int) $r['mois']) / 3);
        if ($q < 1 || $q > 4) { continue; }
        $t[$q]['contrepartie'] += (int) $r['montant_freelance'];
        $t[$q]['frais']        += (int) $r['commission'];
        $t[$q]['activites']    += 1;
    }

    return [
        'trimestres'         => $t,
        'total_contrepartie' => array_sum(array_column($t, 'contrepartie')),
        'total_activites'    => array_sum(array_column($t, 'activites')),
        'total_frais'        => array_sum(array_column($t, 'frais')),
    ];
}

/**
 * Experts à déclarer pour une année : résidents UE ayant perçu au moins
 * un euro. Aucun seuil : c'est la règle des services personnels.
 */
function dac7_vendeurs_declarables($annee) {
    $annee = (int) $annee;
    $sql = "SELECT DISTINCT u.*
            FROM users u
            JOIN projets  p  ON p.freelance_id = u.id
            JOIN factures f  ON f.projet_id = p.id
            JOIN paiements pa ON pa.projet_id = p.id
                              AND pa.type='projet' AND pa.statut='paye'
            WHERE u.role='freelance'
              AND strftime('%Y', pa.paye_le) = ?
              AND f.montant_freelance > 0
            ORDER BY u.nom, u.prenom";
    $st = db()->prepare($sql);
    $st->execute([(string) $annee]);

    $out = [];
    foreach ($st->fetchAll() as $u) {
        if (dac7_dans_le_champ($u)) { $out[] = $u; }
    }
    return $out;
}

/** Experts hors UE : hors champ, mais listés pour justifier leur exclusion. */
function dac7_vendeurs_hors_champ($annee) {
    $annee = (int) $annee;
    $sql = "SELECT DISTINCT u.*
            FROM users u
            JOIN projets  p  ON p.freelance_id = u.id
            JOIN paiements pa ON pa.projet_id = p.id
                              AND pa.type='projet' AND pa.statut='paye'
            WHERE u.role='freelance' AND strftime('%Y', pa.paye_le) = ?";
    $st = db()->prepare($sql);
    $st->execute([(string) $annee]);
    $out = [];
    foreach ($st->fetchAll() as $u) {
        if (!dac7_dans_le_champ($u)) { $out[] = $u; }
    }
    return $out;
}

/* ------------------------------------------------------------------
 *  Registre des diligences (conservation 10 ans)
 * ------------------------------------------------------------------ */

function dac7_journal($user_id, $annee, $action, $detail = '') {
    $a = user();
    db()->prepare("INSERT INTO dac7_registre (user_id,annee,action,detail,auteur)
                   VALUES (?,?,?,?,?)")
        ->execute([(int) $user_id, (int) $annee, $action, $detail,
                   $a ? $a['email'] : 'système']);
}

function dac7_registre($annee, $limite = 200) {
    $st = db()->prepare("SELECT r.*, u.nom, u.prenom, u.email
                         FROM dac7_registre r
                         LEFT JOIN users u ON u.id = r.user_id
                         WHERE r.annee = ?
                         ORDER BY r.id DESC LIMIT ?");
    $st->execute([(int) $annee, (int) $limite]);
    return $st->fetchAll();
}

/**
 * Relance d'un expert dont le dossier est incomplet.
 * La directive prévoit la fermeture du compte après deux relances restées
 * sans réponse : on compte donc les relances.
 */
function dac7_relancer($u, $annee) {
    $manquants = dac7_champs_manquants($u);
    if (!$manquants) { return ['ok' => false, 'erreur' => 'Dossier déjà complet.']; }

    $n = (int) $u['dac7_relances'] + 1;
    db()->prepare("UPDATE users SET dac7_relances=?, dac7_relance_le=CURRENT_TIMESTAMP WHERE id=?")
        ->execute([$n, (int) $u['id']]);

    notify((int) $u['id'],
        "Informations fiscales incomplètes : " . implode(', ', $manquants)
        . ". Merci de compléter votre profil (obligation légale DAC7).",
        '/profil?onglet=fiscal');

    dac7_journal($u['id'], $annee, 'relance',
        'Relance n°' . $n . ' — manquants : ' . implode(', ', array_keys($manquants)));

    return ['ok' => true, 'relances' => $n];
}

/** Marque les diligences comme accomplies pour cet expert. */
function dac7_marquer_verifie($u, $annee) {
    db()->prepare("UPDATE users SET dac7_verifie_le=CURRENT_TIMESTAMP WHERE id=?")
        ->execute([(int) $u['id']]);
    dac7_journal($u['id'], $annee, 'verification',
        'Informations vérifiées et jugées fiables.');
    return true;
}

/* ------------------------------------------------------------------
 *  Exports
 * ------------------------------------------------------------------ */

/** Échappement XML. */
function dac7_x($s) {
    return htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/** Montant en euros → format décimal attendu par le schéma. */
function dac7_montant($euros) {
    return number_format((float) $euros, 2, '.', '');
}

/**
 * Génère le fichier XML DPI-DAC7.
 *
 * ⚠️ Ce fichier suit la structure de la norme OCDE DPI reprise par la
 * DGFiP. Le schéma XSD officiel évolue chaque année : avant tout dépôt
 * réel, validez le fichier contre le XSD publié sur impots.gouv.fr
 * (cahier des charges DPI-DAC7) et effectuez un dépôt à blanc.
 *
 * @param int    $annee
 * @param array  $operateur  ['nom','nif','adresse','cp','ville','pays','plateforme']
 */
function dac7_export_xml($annee, array $operateur) {
    $annee    = (int) $annee;
    $vendeurs = dac7_vendeurs_declarables($annee);
    $horo     = gmdate('Y-m-d\TH:i:s\Z');
    $refId    = 'WC' . $annee . '-' . date('YmdHis');

    $x  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $x .= '<dpi:DPI_OECD xmlns:dpi="urn:oecd:ties:dpi:v1"'
        . ' xmlns:stf="urn:oecd:ties:dpistf:v1"'
        . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" version="1.0">' . "\n";

    // --- En-tête du message
    $x .= "  <dpi:MessageSpec>\n";
    $x .= "    <dpi:SendingEntityIN>" . dac7_x($operateur['nif']) . "</dpi:SendingEntityIN>\n";
    $x .= "    <dpi:TransmittingCountry>FR</dpi:TransmittingCountry>\n";
    $x .= "    <dpi:ReceivingCountry>FR</dpi:ReceivingCountry>\n";
    $x .= "    <dpi:MessageType>DPI</dpi:MessageType>\n";
    $x .= "    <dpi:MessageRefId>" . dac7_x($refId) . "</dpi:MessageRefId>\n";
    $x .= "    <dpi:MessageTypeIndic>DPI401</dpi:MessageTypeIndic>\n";
    $x .= "    <dpi:ReportingPeriod>" . $annee . "-12-31</dpi:ReportingPeriod>\n";
    $x .= "    <dpi:Timestamp>" . $horo . "</dpi:Timestamp>\n";
    $x .= "  </dpi:MessageSpec>\n";

    $x .= "  <dpi:DPIBody>\n";

    // --- Opérateur de plateforme déclarant
    $x .= "    <dpi:PlatformOperator>\n";
    $x .= "      <dpi:ResCountryCode>FR</dpi:ResCountryCode>\n";
    $x .= '      <dpi:TIN issuedBy="FR">' . dac7_x($operateur['nif']) . "</dpi:TIN>\n";
    $x .= "      <dpi:Name>" . dac7_x($operateur['nom']) . "</dpi:Name>\n";
    $x .= "      <dpi:Address legalAddressType=\"OECD304\">\n";
    $x .= "        <dpi:CountryCode>" . dac7_x($operateur['pays']) . "</dpi:CountryCode>\n";
    $x .= "        <dpi:AddressFix>\n";
    $x .= "          <dpi:Street>" . dac7_x($operateur['adresse']) . "</dpi:Street>\n";
    $x .= "          <dpi:PostCode>" . dac7_x($operateur['cp']) . "</dpi:PostCode>\n";
    $x .= "          <dpi:City>" . dac7_x($operateur['ville']) . "</dpi:City>\n";
    $x .= "        </dpi:AddressFix>\n";
    $x .= "      </dpi:Address>\n";
    $x .= "      <dpi:Platform>\n";
    $x .= "        <dpi:Name>" . dac7_x($operateur['plateforme']) . "</dpi:Name>\n";
    $x .= "      </dpi:Platform>\n";
    $x .= "    </dpi:PlatformOperator>\n";

    // --- Un bloc par expert déclaré
    foreach ($vendeurs as $i => $u) {
        $d      = dac7_donnees_vendeur($u['id'], $annee);
        $entite = (($u['type_vendeur'] ?? 'physique') === 'entite');
        $pays   = strtoupper($u['pays'] ?: 'FR');
        $nifP   = strtoupper($u['nif_pays'] ?: $pays);

        $x .= "    <dpi:ReportableSeller>\n";
        $x .= "      <dpi:Identity>\n";
        $x .= $entite ? "        <dpi:EntitySeller>\n" : "        <dpi:IndividualSeller>\n";
        $x .= "          <dpi:Standard>\n";
        $x .= $entite ? "            <dpi:EntSellerID>\n" : "            <dpi:IndSellerID>\n";
        $x .= "              <dpi:ResCountryCode>" . dac7_x($pays) . "</dpi:ResCountryCode>\n";
        if (trim((string) $u['nif']) !== '') {
            $x .= '              <dpi:TIN issuedBy="' . dac7_x($nifP) . '">'
                . dac7_x($u['nif']) . "</dpi:TIN>\n";
        }
        if (trim((string) $u['tva']) !== '') {
            $x .= "              <dpi:VAT>" . dac7_x($u['tva']) . "</dpi:VAT>\n";
        }
        if ($entite) {
            $x .= "              <dpi:Name>" . dac7_x($u['raison_sociale'] ?: $u['societe']) . "</dpi:Name>\n";
        } else {
            $x .= "              <dpi:Name>\n";
            $x .= "                <dpi:FirstName>" . dac7_x($u['prenom']) . "</dpi:FirstName>\n";
            $x .= "                <dpi:LastName>" . dac7_x($u['nom']) . "</dpi:LastName>\n";
            $x .= "              </dpi:Name>\n";
        }
        $x .= "              <dpi:Address legalAddressType=\"OECD304\">\n";
        $x .= "                <dpi:CountryCode>" . dac7_x($pays) . "</dpi:CountryCode>\n";
        $x .= "                <dpi:AddressFix>\n";
        $x .= "                  <dpi:Street>" . dac7_x($u['adresse']) . "</dpi:Street>\n";
        $x .= "                  <dpi:PostCode>" . dac7_x($u['code_postal']) . "</dpi:PostCode>\n";
        $x .= "                  <dpi:City>" . dac7_x($u['ville']) . "</dpi:City>\n";
        $x .= "                </dpi:AddressFix>\n";
        $x .= "              </dpi:Address>\n";
        if (!$entite && trim((string) $u['date_naissance']) !== '') {
            $x .= "              <dpi:BirthInfo>\n";
            $x .= "                <dpi:BirthDate>" . dac7_x($u['date_naissance']) . "</dpi:BirthDate>\n";
            if (trim((string) $u['lieu_naissance']) !== '') {
                $x .= "                <dpi:BirthPlace>\n";
                $x .= "                  <dpi:City>" . dac7_x($u['lieu_naissance']) . "</dpi:City>\n";
                $x .= "                </dpi:BirthPlace>\n";
            }
            $x .= "              </dpi:BirthInfo>\n";
        }
        $x .= $entite ? "            </dpi:EntSellerID>\n" : "            </dpi:IndSellerID>\n";
        $x .= "          </dpi:Standard>\n";
        $x .= $entite ? "        </dpi:EntitySeller>\n" : "        </dpi:IndividualSeller>\n";
        $x .= "      </dpi:Identity>\n";

        // --- Services personnels, ventilés par trimestre
        $x .= "      <dpi:RelevantActivities>\n";
        $x .= "        <dpi:PersonalServices>\n";
        foreach ([1, 2, 3, 4] as $q) {
            $tr = $d['trimestres'][$q];
            $x .= "          <dpi:Consideration>\n";
            $x .= '            <dpi:ConsQ' . $q . ' currCode="EUR">'
                . dac7_montant($tr['contrepartie']) . '</dpi:ConsQ' . $q . ">\n";
            $x .= "          </dpi:Consideration>\n";
            $x .= "          <dpi:NumberOfActivities>\n";
            $x .= '            <dpi:NumbQ' . $q . '>' . (int) $tr['activites']
                . '</dpi:NumbQ' . $q . ">\n";
            $x .= "          </dpi:NumberOfActivities>\n";
            $x .= "          <dpi:Fees>\n";
            $x .= '            <dpi:FeesQ' . $q . ' currCode="EUR">'
                . dac7_montant($tr['frais']) . '</dpi:FeesQ' . $q . ">\n";
            $x .= "          </dpi:Fees>\n";
        }
        $x .= "        </dpi:PersonalServices>\n";
        $x .= "      </dpi:RelevantActivities>\n";
        $x .= "    </dpi:ReportableSeller>\n";
    }

    $x .= "  </dpi:DPIBody>\n";
    $x .= "</dpi:DPI_OECD>\n";
    return $x;
}

/** Export CSV de contrôle — pour relire les chiffres avant dépôt. */
function dac7_export_csv($annee) {
    $annee = (int) $annee;
    $out = "Nom;Prenom;Email;Type;Pays;NIF;TVA;Date naissance;Adresse;CP;Ville;"
         . "T1 contrepartie;T1 activites;T1 frais;"
         . "T2 contrepartie;T2 activites;T2 frais;"
         . "T3 contrepartie;T3 activites;T3 frais;"
         . "T4 contrepartie;T4 activites;T4 frais;"
         . "Total contrepartie;Total activites;Total frais;Dossier complet\n";

    foreach (dac7_vendeurs_declarables($annee) as $u) {
        $d = dac7_donnees_vendeur($u['id'], $annee);
        $l = [$u['nom'], $u['prenom'], $u['email'],
              ($u['type_vendeur'] === 'entite' ? 'Entite' : 'Personne physique'),
              $u['pays'], $u['nif'], $u['tva'], $u['date_naissance'],
              $u['adresse'], $u['code_postal'], $u['ville']];
        foreach ([1, 2, 3, 4] as $q) {
            $l[] = $d['trimestres'][$q]['contrepartie'];
            $l[] = $d['trimestres'][$q]['activites'];
            $l[] = $d['trimestres'][$q]['frais'];
        }
        $l[] = $d['total_contrepartie'];
        $l[] = $d['total_activites'];
        $l[] = $d['total_frais'];
        $l[] = dac7_complet($u) ? 'oui' : 'NON';

        $out .= implode(';', array_map(function ($v) {
            return '"' . str_replace('"', '""', (string) $v) . '"';
        }, $l)) . "\n";
    }
    return $out;
}

/**
 * Récapitulatif individuel à communiquer à l'expert avant le 31 janvier
 * (art. 1649 ter D). Son absence est sanctionnée jusqu'à 50 000 €.
 */
function dac7_attestation_texte($u, $annee) {
    $d = dac7_donnees_vendeur($u['id'], $annee);
    $t = "RÉCAPITULATIF FISCAL " . (int) $annee . " — " . SITE_NOM . "\n";
    $t .= str_repeat('=', 58) . "\n\n";
    $t .= "Destinataire : " . trim($u['prenom'] . ' ' . $u['nom']) . "\n";
    $t .= "Adresse      : " . $u['adresse'] . ', ' . $u['code_postal'] . ' ' . $u['ville'] . "\n";
    $t .= "NIF déclaré  : " . ($u['nif'] ?: 'non communiqué') . "\n\n";
    $t .= "Informations transmises à l'administration fiscale en application\n";
    $t .= "des articles 1649 ter A à 1649 ter E du code général des impôts\n";
    $t .= "(directive UE 2021/514, dite DAC7).\n\n";
    $t .= "Activité concernée : prestation de services personnels\n\n";
    $t .= sprintf("%-12s %16s %12s %14s\n", 'Trimestre', 'Contrepartie', 'Missions', 'Commissions');
    $t .= str_repeat('-', 58) . "\n";
    foreach ([1, 2, 3, 4] as $q) {
        $tr = $d['trimestres'][$q];
        $t .= sprintf("%-12s %14s € %12d %12s €\n", 'T' . $q,
            number_format($tr['contrepartie'], 0, ',', ' '),
            $tr['activites'],
            number_format($tr['frais'], 0, ',', ' '));
    }
    $t .= str_repeat('-', 58) . "\n";
    $t .= sprintf("%-12s %14s € %12d %12s €\n", 'TOTAL',
        number_format($d['total_contrepartie'], 0, ',', ' '),
        $d['total_activites'],
        number_format($d['total_frais'], 0, ',', ' '));
    $t .= "\nLa contrepartie correspond aux sommes qui vous ont été créditées,\n";
    $t .= "nettes de la commission de la plateforme.\n\n";
    $t .= "Ce document est une mention d'information : il ne constitue ni un\n";
    $t .= "bilan fiscal, ni une déclaration établie en votre nom, ni un avis\n";
    $t .= "d'imposition. Votre déclaration de revenus reste à votre charge.\n";
    return $t;
}

/** Envoi du récapitulatif annuel à un expert (notification + e-mail + journal). */
function dac7_envoyer_attestation($u, $annee) {
    notify((int) $u['id'],
        "Votre récapitulatif fiscal " . (int) $annee . " est disponible "
        . "(informations transmises à l'administration au titre de DAC7).",
        '/profil?onglet=fiscal');
    // E-mail : la remise du récapitulatif au vendeur est une obligation DAC7.
    $envoi = email_recap_dac7($u, (int) $annee);
    dac7_journal($u['id'], $annee, 'attestation',
        'Récapitulatif annuel communiqué à l\'expert'
        . ($envoi['ok'] ? '' : ' (e-mail non transmis : ' . $envoi['erreur'] . ')') . '.');
    return $envoi['ok'];
}

/** Statistiques de pilotage de la campagne. */
function dac7_synthese($annee) {
    $v = dac7_vendeurs_declarables($annee);
    $complets = 0; $montant = 0;
    foreach ($v as $u) {
        if (dac7_complet($u)) { $complets++; }
        $d = dac7_donnees_vendeur($u['id'], $annee);
        $montant += $d['total_contrepartie'];
    }
    return [
        'declarables' => count($v),
        'complets'    => $complets,
        'incomplets'  => count($v) - $complets,
        'hors_champ'  => count(dac7_vendeurs_hors_champ($annee)),
        'montant'     => $montant,
    ];
}
