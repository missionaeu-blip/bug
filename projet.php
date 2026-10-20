<?php
require_once __DIR__ . '/includes/functions.php';
if (!is_logged()) { header('Location: ' . u('/connexion')); exit; }
$u = user();
$id = (int)($_GET['id'] ?? 0);

$st = db()->prepare("SELECT p.*, e.societe, e.nom AS e_nom, e.prenom AS e_prenom,
                            f.nom AS f_nom, f.prenom AS f_prenom, f.titre_pro, f.note_moyenne, f.tarif_projet
                     FROM projets p
                     LEFT JOIN users e ON e.id = p.entreprise_id
                     LEFT JOIN users f ON f.id = p.freelance_id
                     WHERE p.id = ?");
$st->execute([$id]);
$p = $st->fetch();
if (!$p) { header('Location: ' . dashboard_url(null, true)); exit; }

// Contrôle d'accès strict : seuls le client, l'expert affecté et l'admin peuvent voir
$autorise = ($u['role']==='admin') || ($p['entreprise_id']==$u['id']) || ($p['freelance_id']==$u['id']);
if (!$autorise) { header('Location: ' . dashboard_url(null, true)); exit; }

// Marqueur de confirmation à usage unique posé après INSERT dans
// nouveau-projet.php. Il ne s'affiche jamais lors d'une simple consultation.
$afficher_confirmation_projet = !empty($_SESSION['projet_soumis_popup'])
    && (int) $_SESSION['projet_soumis_popup'] === $id
    && (int) $p['entreprise_id'] === (int) $u['id'];
if ($afficher_confirmation_projet) {
    unset($_SESSION['projet_soumis_popup']);
}

// Téléversement d'un livrable (tous les intervenants du projet)
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['form_fichier'])) {
    csrf_check();
    $r = traiter_upload($_FILES['fichier'] ?? [], 'projets', ext_doc_autorisees());
    if ($r['ok']) {
        // Un livrable déposé par un expert n'est pas remis au client tant
        // que l'équipe ne l'a pas vérifié. Ce que dépose le client ou
        // l'équipe est immédiatement disponible.
        $en_attente = ($u['role'] === 'freelance');
        $empreinte  = @hash_file('sha256', __DIR__ . '/uploads/' . $r['chemin']) ?: '';

        db()->prepare("INSERT INTO fichiers (projet_id,uploader_id,nom,chemin,taille,statut,empreinte)
                       VALUES (?,?,?,?,?,?,?)")
            ->execute([$id, $u['id'], $r['nom'], $r['chemin'], $r['taille'],
                       $en_attente ? 'en_attente' : 'valide', $empreinte]);

        $qui = $u['role']==='admin' ? 'Missiona' : trim($u['prenom'].' '.$u['nom']);
        if ($en_attente) {
            // Le client n'est pas prévenu : le document ne lui est pas encore remis
            foreach (db()->query("SELECT id FROM users WHERE role='admin' AND actif=1") as $ad) {
                notify((int) $ad['id'], "Livrable à vérifier sur « ".$p['titre']." » — déposé par ".$qui.".", 'projet.php?id='.$id);
            }
            flash("Fichier « ".$r['nom']." » déposé. Il sera transmis au client après vérification par notre équipe.");
        } else {
            foreach ([$p['entreprise_id'], $p['freelance_id'], 1] as $dest) {
                if ($dest && (int)$dest !== (int)$u['id']) {
                    notify($dest, "Nouveau fichier déposé sur « ".$p['titre']." » par ".$qui.".", 'projet.php?id='.$id);
                }
            }
            flash("Fichier « ".$r['nom']." » déposé.");
        }
    } else {
        flash($r['erreur'], 'error');
    }
    header('Location: ' . u('projet.php?id=' . $id)); exit;
}

// Vérification d'un livrable avant remise au client (équipe uniquement)
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['verifier_fichier'])) {
    csrf_check();
    if ($u['role'] !== 'admin') {
        flash("Seule l'équipe peut valider un livrable.", 'error');
        header('Location: ' . u('projet.php?id=' . $id)); exit;
    }
    $fid    = (int) $_POST['verifier_fichier'];
    $action = ($_POST['decision'] ?? '') === 'refuser' ? 'refuse' : 'valide';
    $motif  = trim((string) ($_POST['motif'] ?? ''));

    $q = db()->prepare("SELECT * FROM fichiers WHERE id=? AND projet_id=?");
    $q->execute([$fid, $id]);
    if ($fi = $q->fetch()) {
        // On recalcule l'empreinte au moment de valider : c'est bien le
        // fichier présent sur le disque que l'on approuve, pas celui déposé.
        $emp = @hash_file('sha256', __DIR__ . '/uploads/' . $fi['chemin']) ?: '';

        db()->prepare("UPDATE fichiers SET statut=?, motif=?, empreinte=?,
                              verifie_par=?, verifie_le=datetime('now') WHERE id=?")
            ->execute([$action, $motif, $emp, (int) $u['id'], $fid]);

        if ($action === 'valide') {
            notify((int) $p['entreprise_id'], "Un livrable vérifié est disponible sur « ".$p['titre']." ».", 'projet.php?id='.$id);
            if ($p['freelance_id']) {
                notify((int) $p['freelance_id'], "Votre livrable « ".$fi['nom']." » a été validé et transmis au client.", 'projet.php?id='.$id);
            }
            flash("Livrable validé et transmis au client.");
        } else {
            if ($p['freelance_id']) {
                notify((int) $p['freelance_id'], "Votre livrable « ".$fi['nom']." » n'a pas été retenu."
                    . ($motif !== '' ? " Motif : " . $motif : ''), 'projet.php?id='.$id);
            }
            flash("Livrable refusé. Le client n'y a pas accès.", 'error');
        }
    }
    header('Location: ' . u('projet.php?id=' . $id)); exit;
}

// Suppression d'un fichier (son auteur ou l'administrateur)
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['suppr_fichier'])) {
    csrf_check();
    $fid = (int)$_POST['suppr_fichier'];
    $q = db()->prepare("SELECT * FROM fichiers WHERE id=? AND projet_id=?");
    $q->execute([$fid, $id]);
    if ($fi = $q->fetch()) {
        if ($u['role']==='admin' || (int)$fi['uploader_id'] === (int)$u['id']) {
            @unlink(__DIR__.'/uploads/'.$fi['chemin']);
            db()->prepare("DELETE FROM fichiers WHERE id=?")->execute([$fid]);
            flash("Fichier supprimé.");
        }
    }
    header('Location: ' . u('projet.php?id=' . $id)); exit;
}

// Évaluation du projet livré (par le client)
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['form_eval'])) {
    csrf_check();
    $note = max(1, min(5, (int)($_POST['note'] ?? 0)));
    if ($p['statut']==='termine' && $p['freelance_id'] && (int)$p['entreprise_id'] === (int)$u['id']) {
        $dej = db()->prepare("SELECT COUNT(*) FROM evaluations WHERE projet_id=? AND auteur_id=?");
        $dej->execute([$id, $u['id']]);
        if (!$dej->fetchColumn()) {
            db()->prepare("INSERT INTO evaluations (projet_id,auteur_id,cible_id,note,commentaire) VALUES (?,?,?,?,?)")
                ->execute([$id, $u['id'], $p['freelance_id'], $note, trim($_POST['commentaire'] ?? '')]);
            // Moyenne pondérée : l'historique déjà acquis est conservé
            $fq = db()->prepare("SELECT note_moyenne, nb_missions FROM users WHERE id=?");
            $fq->execute([$p['freelance_id']]);
            $fl = $fq->fetch();
            $nb_av  = max(0, (int)$fl['nb_missions']);
            $moy_av = (float)$fl['note_moyenne'];
            $moy = $nb_av > 0 ? round((($moy_av * $nb_av) + $note) / ($nb_av + 1), 2) : (float)$note;
            db()->prepare("UPDATE users SET note_moyenne=?, nb_missions=nb_missions+1 WHERE id=?")
                ->execute([$moy, $p['freelance_id']]);
            notify(admin_id(), "Nouvelle évaluation (".$note."/5) sur « ".$p['titre']." ».", 'projet.php?id='.$id);
            flash("Merci, votre évaluation a bien été enregistrée.");
        }
    }
    header('Location: ' . u('projet.php?id=' . $id)); exit;
}

// Mise à jour (admin uniquement)
/* Demande d'annulation ou de remboursement par le client. */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['form_demande'])) {
    csrf_check();
    if (!est_client($u['role']) || $p['entreprise_id'] != $u['id']) {
        header('Location: ' . u('projet.php?id=' . $id)); exit;
    }
    $type  = in_array($_POST['type_demande'] ?? '', ['annulation','remboursement'], true) ? $_POST['type_demande'] : '';
    $motif = trim($_POST['motif'] ?? '');
    if (!$type) {
        flash("Veuillez préciser le type de demande.");
    } elseif (mb_strlen($motif) < 10) {
        flash("Merci d'indiquer un motif d'au moins 10 caractères.");
    } elseif (demande_active($id)) {
        flash("Une demande est déjà en cours d'examen sur ce projet.");
    } elseif (!peut_demander_annulation($p)) {
        flash("Ce projet est clos, aucune demande n'est possible.");
    } else {
        db()->prepare("INSERT INTO demandes (projet_id,auteur_id,type,motif) VALUES (?,?,?,?)")
            ->execute([$id, $u['id'], $type, $motif]);
        notify(admin_id(), "Demande de " . $type . " sur « " . $p['titre'] . " ».", 'projet.php?id='.$id);
        flash("Votre demande a été transmise. Notre équipe revient vers vous sous 48 h.");
    }
    header('Location: ' . u('projet.php?id=' . $id)); exit;
}

/* Traitement de la demande par l'administrateur. */
if ($_SERVER['REQUEST_METHOD']==='POST' && $u['role']==='admin' && isset($_POST['form_traiter'])) {
    csrf_check();
    if (!peut_gerer_projet($p)) { flash(refus_gestion(), 'error'); header('Location: ' . u('projet.php?id=' . $id)); exit; }
    $did     = (int)($_POST['demande_id'] ?? 0);
    $action  = $_POST['action_demande'] ?? '';
    $reponse = trim($_POST['reponse'] ?? '');
    $ds = db()->prepare("SELECT * FROM demandes WHERE id=? AND projet_id=? AND statut='en_attente'");
    $ds->execute([$did, $id]);
    if ($d = $ds->fetch()) {
        if ($action === 'accepter') {
            $annulation = annuler_projet($id, true);
            if (!$annulation['rembourse']) {
                flash("Impossible de clôturer le projet de façon sûre : " . implode(' ', $annulation['erreurs']), 'error');
            } else {
                db()->prepare("UPDATE demandes SET statut='acceptee', reponse=?, traite_le=CURRENT_TIMESTAMP WHERE id=?")
                    ->execute([$reponse, $did]);
                notify($d['auteur_id'], "Votre demande de " . $d['type'] . " sur « " . $p['titre'] . " » a été acceptée. Le projet est annulé"
                    . ($d['type'] === 'remboursement' ? " et le remboursement Stripe a été demandé." : "."),
                    'projet.php?id='.$id);
                if ($p['freelance_id']) notify($p['freelance_id'], "La mission « " . $p['titre'] . " » a été annulée.", 'projet.php?id='.$id);
                flash("Demande acceptée : le projet est annulé.");
            }
        } elseif ($action === 'refuser') {
            db()->prepare("UPDATE demandes SET statut='refusee', reponse=?, traite_le=CURRENT_TIMESTAMP WHERE id=?")
                ->execute([$reponse, $did]);
            notify($d['auteur_id'], "Votre demande de " . $d['type'] . " sur « " . $p['titre'] . " » a été refusée.", 'projet.php?id='.$id);
            flash("Demande refusée.");
        }
    }
    header('Location: ' . u('projet.php?id=' . $id)); exit;
}

/* Annulation directe par l'administrateur, sans demande préalable. */
if ($_SERVER['REQUEST_METHOD']==='POST' && $u['role']==='admin' && isset($_POST['form_annuler'])) {
    csrf_check();
    if (!peut_gerer_projet($p)) { flash(refus_gestion(), 'error'); header('Location: ' . u('projet.php?id=' . $id)); exit; }
    $remb = !empty($_POST['rembourser']);
    // Une direct charge confirmée impose son remboursement ; la case laissée
    // décochée ne doit jamais permettre d'annuler un projet avec un client débité.
    $paiement_annulation = paiements_projet($id)['projet'] ?? null;
    if ($paiement_annulation && !empty($paiement_annulation['stripe_direct'])
        && ($paiement_annulation['statut'] ?? '') === 'paye') {
        $remb = true;
    }
    $annulation = annuler_projet($id, $remb);
    if (!$annulation['rembourse']) {
        flash("Projet non annulé : " . implode(' ', $annulation['erreurs']), 'error');
    } else {
        notify($p['entreprise_id'], "Votre projet « " . $p['titre'] . " » a été annulé"
            . ($remb ? " ; le remboursement Stripe a été demandé lorsque nécessaire." : "."),
            'projet.php?id='.$id);
        if ($p['freelance_id']) notify($p['freelance_id'], "La mission « " . $p['titre'] . " » a été annulée.", 'projet.php?id='.$id);
        flash("Projet annulé.");
    }
    header('Location: ' . u('projet.php?id=' . $id)); exit;
}

/* Suppression définitive par l'administrateur. */
if ($_SERVER['REQUEST_METHOD']==='POST' && $u['role']==='admin' && isset($_POST['form_supprimer'])) {
    csrf_check();
    if (!peut_gerer_projet($p)) { flash(refus_gestion(), 'error'); header('Location: ' . u('projet.php?id=' . $id)); exit; }
    if (($_POST['confirmation'] ?? '') === 'SUPPRIMER') {
        supprimer_projet($id);
        flash("Projet supprimé définitivement.");
        header('Location: ' . u('/admin-projets')); exit;
    }
    flash("Saisissez SUPPRIMER en majuscules pour confirmer.");
    header('Location: ' . u('projet.php?id=' . $id)); exit;
}

/* Paiement direct Stripe Connect avec Payment Element.
   La page locale crée/récupère un PaymentIntent sur le compte connecté de
   l'expert. Le webhook signé reste l'autorité de confirmation finale. */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['form_paiement_element'])) {
    csrf_check();
    if (!est_client($u['role']) || (int) $p['entreprise_id'] !== (int) $u['id']) {
        header('Location: ' . u('projet.php?id=' . $id)); exit;
    }
    header('Location: ' . u('/paiement?projet=' . $id)); exit;
}

/* Repli sans clés Stripe : le comportement historique de règlement direct
   est conservé. Dès que Stripe de test est activé, seul le Payment Element
   sécurisé ci-dessus peut démarrer la mission. */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['form_paiement'])) {
    csrf_check();
    if (!est_client($u['role']) || $p['entreprise_id'] != $u['id']) {
        header('Location: ' . u('projet.php?id=' . $id)); exit;
    }
    $action = $_POST['action_paiement'] ?? '';

    $paiement_direct = paiements_projet($id)['projet'] ?? null;
    if (stripe_actif() && stripe_cle_publique() !== '' && !empty($paiement_direct['stripe_direct'])) {
        flash("Utilisez le paiement sécurisé par carte pour démarrer la mission.", 'info');
    } elseif ($action === 'valider' && $p['statut'] === 'attribue') {
        db()->prepare("UPDATE projets SET statut='en_cours' WHERE id=? AND statut='attribue'")->execute([$id]);
        email_suivi_projet_client($id, 'en_cours');
        notify(admin_id(), "Proposition validée par le client sur « ".$p['titre']." ». Règlement direct à l'expert.", 'projet.php?id='.$id);
        if ($p['freelance_id']) notify($p['freelance_id'], "La mission « ".$p['titre']." » est confirmée : convenez avec le client des modalités de règlement, puis démarrez.", 'projet.php?id='.$id);
        flash("Proposition validée. La mission démarre : réglez l'expert directement, selon les modalités convenues avec lui.");
    } elseif ($action === 'confirmer') {
        regler_paiement($id, 'projet');
        notify(admin_id(), "Le client confirme avoir réglé l'expert sur « ".$p['titre']." ». La rémunération Missiona devient exigible.", 'projet.php?id='.$id);
        if ($p['freelance_id']) notify($p['freelance_id'], "Règlement confirmé par le client sur « ".$p['titre']." ». Merci.", 'projet.php?id='.$id);
        flash("Merci, votre règlement est confirmé.");
    }
    header('Location: ' . u('projet.php?id=' . $id)); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $u['role']==='admin' && isset($_POST['form_suivi'])) {
    csrf_check();
    if (!peut_gerer_projet($p)) { flash(refus_gestion(), 'error'); header('Location: ' . u('projet.php?id=' . $id)); exit; }
    // Une annulation ne passe jamais par le simple champ de statut : la même
    // opération doit annuler/rembourser le PaymentIntent avant d'annoncer la
    // clôture du projet.
    if (($_POST['statut'] ?? '') === 'annule') {
        $annulation = annuler_projet($id, true);
        flash($annulation['rembourse']
            ? "Projet annulé et règlement traité selon son mode de paiement."
            : "Projet non annulé : " . implode(' ', $annulation['erreurs']),
            $annulation['rembourse'] ? 'success' : 'error');
        header('Location: ' . u('projet.php?id=' . $id)); exit;
    }
    // La barre suit le statut par défaut ; l'équipe peut la forcer en décochant.
    $av = isset($_POST['avancement_auto'])
        ? avancement_auto($_POST['statut'])
        : max(0, min(100, (int)$_POST['avancement']));

    // Une fois la carte capturée, le montant et la commission sont ceux du
    // PaymentIntent. Ne jamais les réécrire depuis un POST administratif :
    // cela créerait un écart comptable avec application_fee_amount.
    $pp_suivi = paiements_projet($id)['projet'] ?? null;
    $intent_direct_ouvert = $pp_suivi
        && !empty($pp_suivi['stripe_direct'])
        && ($pp_suivi['statut'] ?? '') === 'a_payer'
        && !empty($pp_suivi['stripe_ref']);
    // Une fois le PaymentIntent créé, son montant est figé côté Stripe. Le
    // suivi administratif ne doit ni modifier le devis ni faire avancer la
    // mission : annulez d'abord le projet/intent, puis créez une nouvelle
    // échéance tracée si le montant doit changer.
    if ($intent_direct_ouvert) {
        flash("Un paiement Stripe est déjà en attente. Annulez-le avant de modifier le montant ou le statut du projet.", 'error');
        header('Location: ' . u('projet.php?id=' . $id)); exit;
    }
    $stripe_regle = $pp_suivi
        && !empty($pp_suivi['stripe_direct'])
        && ($pp_suivi['statut'] ?? '') === 'paye'
        && !empty($pp_suivi['stripe_ref'])
        && !empty($pp_suivi['stripe_account']);
    $commission_stripe_centimes = $stripe_regle ? max(0, (int) ($pp_suivi['stripe_application_fee'] ?? 0)) : 0;
    $commission_stripe_euros = (int) round($commission_stripe_centimes / 100);
    // montant_final représente toujours le prix de l'expert hors commission.
    // Le paiement enregistré est le total client (base + application fee).
    $montant_final = $stripe_regle
        ? max(0, (int) $pp_suivi['montant'] - $commission_stripe_euros)
        : max(0, (int) ($_POST['montant_final'] ?? 0));

    db()->prepare("UPDATE projets SET statut=?, avancement=?, montant_final=? WHERE id=?")
        ->execute([$_POST['statut'], $av, $montant_final, $id]);
    // Le montant arrêté par l'équipe fait foi uniquement tant que l'échéance
    // n'a pas déjà été réglée par carte.
    if (!$stripe_regle && $montant_final > 0) { maj_montant_projet($id, $montant_final); }
    if ($_POST['statut']==='termine') {
        $d = $stripe_regle
            ? ['base' => $montant_final, 'part' => $commission_stripe_euros, 'total' => (int) $pp_suivi['montant']]
            : ['base' => $montant_final, 'part' => commission_reglement_direct($montant_final), 'total' => $montant_final];
        // Pour une direct charge payée, la commission est déjà arrivée chez
        // Missiona dans application_fee_amount. La facture est un justificatif
        // acquitté, pas une seconde somme à réclamer à l'expert.
        $statut_facture = $stripe_regle ? 'payee' : 'en_attente';
        $commission_stripe = $stripe_regle ? 1 : 0;
        db()->prepare("INSERT OR IGNORE INTO factures (projet_id,numero,montant_ht,commission,montant_freelance,statut,versement_direct,frais_service,commission_prelevee_stripe) VALUES (?,?,?,?,?,?,1,0,?)")
            ->execute([$id, 'FA-'.date('Y').'-'.str_pad($id,4,'0',STR_PAD_LEFT), $d['part'], $d['part'], $d['base'], $statut_facture, $commission_stripe]);
    }
    notify($p['entreprise_id'], "Mise à jour du projet « ".$p['titre']." » : ".statut_label($_POST['statut'])." (".$av." %).", 'projet.php?id='.$id);
    email_suivi_projet_client($id, $_POST['statut']);
    if ($p['freelance_id']) notify($p['freelance_id'], "Mise à jour de la mission « ".$p['titre']." ».", 'projet.php?id='.$id);
    flash("Projet mis à jour.");
    header('Location: ' . u('projet.php?id=' . $id)); exit;
}

/* Ouverture / fermeture du canal d'échange direct entre le client et
   l'expert (administrateur uniquement, projet par projet). */
if ($_SERVER['REQUEST_METHOD']==='POST' && $u['role']==='admin' && isset($_POST['form_contact_direct'])) {
    csrf_check();
    if (!peut_gerer_projet($p)) { flash(refus_gestion(), 'error'); header('Location: ' . u('projet.php?id=' . $id)); exit; }
    $action_cd = $_POST['contact_direct'] ?? '';
    if (!$p['freelance_id']) {
        flash("Impossible d'ouvrir un canal direct : aucun expert n'est encore affecté à ce projet.", 'error');
    } elseif ($p['statut'] === 'annule') {
        flash("Projet annulé : aucun échange n'est possible.", 'error');
    } else {
        $ouvrir = ($action_cd === 'ouvrir');
        db()->prepare("UPDATE projets SET contact_direct=? WHERE id=?")->execute([$ouvrir ? 1 : 0, $id]);
        $annonce = $ouvrir
            ? "Un canal d'échange direct est ouvert sur « " . $p['titre'] . " ». Vous pouvez désormais dialoguer et partager des documents avec votre interlocuteur depuis la messagerie."
            : "Le canal d'échange direct sur « " . $p['titre'] . " » a été refermé. Vos échanges repassent par votre chargé de compte Missiona.";
        notify((int) $p['entreprise_id'], $annonce, 'projet.php?id=' . $id);
        if ($p['freelance_id']) notify((int) $p['freelance_id'], $annonce, 'projet.php?id=' . $id);
        flash($ouvrir
            ? "Contact direct ouvert : le client et l'expert peuvent échanger en direct. Vous conservez la lecture du fil."
            : "Contact direct refermé : les échanges repassent par Missiona.");
    }
    header('Location: ' . u('projet.php?id=' . $id)); exit;
}

$fs = db()->prepare("SELECT f.*, u.nom AS u_nom, u.prenom AS u_prenom, u.role AS u_role
                     FROM fichiers f LEFT JOIN users u ON u.id=f.uploader_id
                     WHERE f.projet_id=? ORDER BY f.id DESC"); $fs->execute([$id]);
$fichiers = $fs->fetchAll();
$fa = db()->prepare("SELECT * FROM factures WHERE projet_id=?"); $fa->execute([$id]);
$factures = $fa->fetchAll();

$ev = db()->prepare("SELECT e.*, a.nom AS a_nom, a.prenom AS a_prenom, a.societe
                     FROM evaluations e LEFT JOIN users a ON a.id=e.auteur_id
                     WHERE e.projet_id=?");
$ev->execute([$id]);
$evaluations = $ev->fetchAll();
$peut_evaluer = (est_client($u['role']) && (int)$p['entreprise_id']===(int)$u['id']
                 && $p['statut']==='termine' && $p['freelance_id'] && !$evaluations);

$etapes = ['nouveau'=>0,'analyse'=>1,'attribue'=>2,'en_cours'=>3,'livraison'=>4,'termine'=>5];
$cur_etape = $etapes[$p['statut']] ?? 0;
$noms_etapes = [
  ["Projet reçu","Votre demande a été enregistrée."],
  ["Analyse du besoin","Cadrage par votre chargé de compte."],
  ["Expert sélectionné","Profil retenu et mission engagée."],
  ["Production en cours","Réalisation et suivi d'avancement."],
  ["Livraison &amp; recette","Contrôle qualité puis validation."],
  ["Projet terminé","Livrables validés et facturés."],
];

$titre = $p['titre'];
require_once __DIR__ . '/includes/header.php';
?>
<div class="app">
<?php require __DIR__ . '/includes/sidebar.php'; ?>
<main class="main">

  <div class="page-head">
    <div>
      <p class="small muted mb-1"><a href="<?= dashboard_url() ?>" style="color:var(--blue)">← Retour au tableau de bord</a></p>
      <h1><?= e($p['titre']) ?></h1>
      <p><?= e($p['categorie']) ?> · Créé le <?= date_fr($p['created_at']) ?></p>
    </div>
    <div class="flex gap-1 wrap">
      <span class="badge <?= statut_classe($p['statut']) ?>" style="padding:8px 14px;font-size:.875rem"><?= statut_label($p['statut']) ?></span>
      <?= beta_etiquette($p) ?>
      <?php if ($u['role'] !== 'admin' && !empty($p['contact_direct']) && $p['freelance_id'] && $p['statut'] !== 'annule'): ?>
        <a href="/messages?d=<?= (int) $p['id'] ?>" class="btn btn-primary" style="background:var(--green)">💬 Discussion directe</a>
      <?php endif; ?>
      <a href="/messages" class="btn btn-primary">💬 Contacter Missiona</a>
    </div>
  </div>

  <?php if ($f = flash()): ?><div class="alert alert-<?= e($f['t']) ?>"><?= e($f['m']) ?></div><?php endif; ?>

  <?php if (beta_projet_en_attente($p)): ?>
    <div class="alert alert-bleu mb-3">
      <strong>Projet enregistré pendant la préinscription.</strong>
      Missiona n'a pas encore ouvert : votre demande est conservée telle quelle et
      sera étudiée en priorité dès le lancement. Vous serez prévenu par courriel,
      et aucun règlement ne vous sera demandé d'ici là.
    </div>
  <?php endif; ?>

  <div class="panel mb-4">
    <div class="panel-body">
      <div class="flex-between mb-2">
        <h3>Avancement global</h3>
        <strong style="font-size:1.25rem"><?= (int)$p['avancement'] ?> %</strong>
      </div>
      <div class="bar <?= $p['avancement']>=100?'green':'' ?>" style="height:10px"><i style="width:<?= (int)$p['avancement'] ?>%"></i></div>
    </div>
  </div>

  <div class="grid" style="grid-template-columns:1.6fr 1fr;gap:24px;align-items:start">
    <div class="grid" style="gap:24px">

      <div class="panel">
        <div class="panel-head"><h3>Suivi du projet</h3></div>
        <div class="panel-body">
          <div class="timeline">
            <?php foreach ($noms_etapes as $i => $et): ?>
              <div class="tl-item <?= $i < $cur_etape ? 'done' : ($i == $cur_etape ? 'current' : '') ?>">
                <h4><?= $et[0] ?></h4>
                <p><?= $et[1] ?></p>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div class="panel">
        <div class="panel-head"><h3>Description du besoin</h3></div>
        <div class="panel-body">
          <p><?= nl2br(e($p['description'])) ?></p>
          <?php if ($p['competences']): ?>
            <h4 class="mt-3 mb-1" style="font-size:.8125rem;text-transform:uppercase;color:var(--muted)">Compétences requises</h4>
            <div class="crit">
              <?php foreach (array_filter(array_map('trim', explode(',', $p['competences']))) as $c): ?>
                <span><?= e($c) ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="panel">
        <div class="panel-head">
          <h3>Fichiers &amp; livrables</h3>
          <span class="badge badge-gray"><?= count($fichiers) ?> fichier<?= count($fichiers)>1?'s':'' ?></span>
        </div>
        <div class="panel-body">
          <form method="post" enctype="multipart/form-data" class="upload-zone" id="upProjet">
            <?= csrf_field() ?><input type="hidden" name="form_fichier" value="1">
            <input type="file" name="fichier" id="fichierProjet" hidden
                   onchange="document.getElementById('upProjet').submit()">
            <label for="fichierProjet" class="upload-label">
              <span class="upload-ico">📤</span>
              <strong>Déposer un fichier</strong>
              <span class="small muted">PDF, Word, Excel, images, ZIP, vidéo — 8 Mo maximum</span>
            </label>
          </form>

          <?php if (!$fichiers): ?>
            <div class="empty" style="padding:26px 0 6px">
              <div class="ico">📎</div>
              <h3>Aucun fichier pour l'instant</h3>
              <p>Les livrables déposés apparaîtront ici.</p>
            </div>
          <?php else: ?>
            <div class="mt-3">
            <?php foreach ($fichiers as $fi): ?>
              <div class="file-row">
                <span class="file-ico"><?= icone_fichier($fi['nom']) ?></span>
                <div style="flex:1;min-width:0">
                  <strong class="small" style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($fi['nom']) ?></strong>
                  <div class="t-sub">
                    <?= taille_lisible($fi['taille']) ?> · <?= date_fr($fi['created_at']) ?>
                    · déposé par <?= $fi['u_role']==='admin' ? 'Missiona' : e(trim($fi['u_prenom'].' '.mb_substr($fi['u_nom'],0,1).'.')) ?>
                    <?php $stf = $fi['statut'] ?? 'valide'; ?>
                    <?php if ($stf === 'en_attente'): ?>
                      <span class="badge badge-amber">À vérifier</span>
                    <?php elseif ($stf === 'refuse'): ?>
                      <span class="badge badge-red">Refusé</span>
                      <?php if (!empty($fi['motif'])): ?>
                        <span class="muted">— <?= e($fi['motif']) ?></span>
                      <?php endif; ?>
                    <?php elseif (!empty($fi['verifie_le'])): ?>
                      <span class="badge badge-green">Vérifié</span>
                    <?php endif; ?>
                  </div>
                </div>

                <?php $stf = $fi['statut'] ?? 'valide'; ?>
                <?php if ($stf === 'en_attente' && $u['role'] === 'admin'): ?>
                  <form method="post" style="margin:0;display:flex;gap:6px;align-items:center">
                    <?= csrf_field() ?>
                    <input type="hidden" name="verifier_fichier" value="<?= (int) $fi['id'] ?>">
                    <input type="text" name="motif" class="input" style="width:150px;padding:6px 9px;font-size:.8rem"
                           placeholder="Motif si refus">
                    <button name="decision" value="valider" class="btn btn-primary btn-sm">Valider</button>
                    <button name="decision" value="refuser" class="btn btn-ghost btn-sm"
                            onclick="return confirm('Refuser ce livrable ?')">Refuser</button>
                  </form>
                <?php endif; ?>

                <?php if ($stf !== 'valide' && est_client($u['role'])): ?>
                  <span class="small muted" style="white-space:nowrap">
                    <?= $stf === 'refuse' ? 'Non retenu' : 'En cours de vérification' ?>
                  </span>
                <?php else: ?>
                  <?php if (fichier_previsualisable($fi['nom'])): ?>
                    <button type="button" class="btn btn-ghost btn-sm" data-apercu="<?= (int) $fi['id'] ?>"
                            data-nom="<?= e($fi['nom']) ?>" data-suivi-non>👁 Aperçu</button>
                  <?php endif; ?>
                  <?php /* Livrable remis au client : consultation seule, pas de ⬇. */ ?>
                  <?php if (!( est_client($u['role']) && (int) $fi['uploader_id'] !== (int) $u['id'] )): ?>
                    <a href="/download?id=<?= $fi['id'] ?>" class="btn btn-ghost btn-sm">⬇ Télécharger</a>
                  <?php endif; ?>
                <?php endif; ?>
                <?php if ($u['role']==='admin' || (int)$fi['uploader_id'] === (int)$u['id']): ?>
                  <form method="post" style="margin:0" onsubmit="return confirm('Supprimer ce fichier ?')">
                    <?= csrf_field() ?>
                    <button name="suppr_fichier" value="<?= $fi['id'] ?>" class="btn btn-ghost btn-sm" title="Supprimer">🗑</button>
                  </form>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($peut_evaluer): ?>
      <div class="panel" style="border-color:var(--green)">
        <div class="panel-head"><h3>⭐ Évaluer la prestation</h3><span class="badge badge-green">Projet livré</span></div>
        <div class="panel-body">
          <p class="small muted mb-3">Votre retour nous aide à sélectionner les meilleurs experts pour vos prochains projets.</p>
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="form_eval" value="1">
            <div class="field">
              <label>Votre note</label>
              <div class="star-pick" id="starPick">
                <?php for ($i=5; $i>=1; $i--): ?>
                  <input type="radio" name="note" id="st<?= $i ?>" value="<?= $i ?>" <?= $i===5?'checked':'' ?>>
                  <label for="st<?= $i ?>" title="<?= $i ?> sur 5">★</label>
                <?php endfor; ?>
              </div>
            </div>
            <div class="field">
              <label for="commentaire">Commentaire (facultatif)</label>
              <textarea id="commentaire" name="commentaire" class="textarea" style="min-height:90px"
                        placeholder="Qualité du livrable, respect des délais, communication…"></textarea>
            </div>
            <button type="submit" class="btn btn-primary">Envoyer mon évaluation</button>
          </form>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($evaluations): ?>
      <div class="panel">
        <div class="panel-head"><h3>Évaluation</h3></div>
        <div class="panel-body">
          <?php foreach ($evaluations as $ev1): ?>
            <div class="flex-between mb-1">
              <strong class="small"><?= e($ev1['societe'] ?: trim($ev1['a_prenom'].' '.$ev1['a_nom'])) ?></strong>
              <span class="stars"><?= str_repeat('★',(int)$ev1['note']).str_repeat('☆',5-(int)$ev1['note']) ?></span>
            </div>
            <?php if ($ev1['commentaire']): ?><p class="small">« <?= e($ev1['commentaire']) ?> »</p><?php endif; ?>
            <p class="t-sub mt-1"><?= date_fr($ev1['created_at']) ?></p>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($u['role']==='admin'): ?>
      <div class="panel" style="border-color:var(--blue)">
        <div class="panel-head"><h3>🛠️ Gestion Missiona</h3><span class="badge badge-blue">Back-office</span></div>
        <div class="panel-body">
<?php $peut = peut_gerer_projet($p); ?>
<?php if (!$peut): ?>
          <div class="alert-warn mb-2">
            <strong>Lecture seule.</strong>
            <?php $g = gestionnaire_de(['gestionnaire_id' => (int)(db()->query("SELECT gestionnaire_id FROM users WHERE id=".(int)$p['entreprise_id'])->fetchColumn())]); ?>
            Ce client est suivi par <?= $g ? e(trim($g['prenom'].' '.$g['nom'])) : 'un autre gestionnaire' ?>.
            Vous pouvez consulter le dossier, pas le modifier.
          </div>
<?php endif; ?>
          <form method="post" <?= $peut ? '' : 'onsubmit="return false"' ?>>
            <?= csrf_field() ?><input type="hidden" name="form_suivi" value="1">
            <fieldset <?= $peut ? '' : 'disabled' ?> style="border:0;padding:0;margin:0;min-width:0">
            <div class="field-row">
              <div class="field">
                <label for="statut">Statut du projet</label>
                <select name="statut" id="statut" class="select">
                  <?php foreach (['nouveau','analyse','attribue','en_cours','livraison','termine','annule'] as $s): ?>
                    <option value="<?= $s ?>" <?= $p['statut']===$s?'selected':'' ?>><?= statut_label($s) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field">
                <label for="avancement">Avancement (%)</label>
                <input type="number" name="avancement" id="avancement" class="input" min="0" max="100" value="<?= (int)$p['avancement'] ?>">
                <label class="check mt-1" style="font-weight:400">
                  <input type="checkbox" name="avancement_auto" id="avancement_auto" value="1" checked>
                  <span class="small">Suivre automatiquement le statut</span>
                </label>
                <div class="hint">Décochez pour fixer vous-même le pourcentage.</div>
              </div>
            </div>
            <div class="field">
              <label for="montant_final">Rémunération de l'expert (€ HT)</label>
              <?php $pp_admin = paiements_projet($id)['projet'] ?? null; $carte_reglee_admin = $pp_admin && !empty($pp_admin['stripe_direct']) && ($pp_admin['statut'] ?? '') === 'paye'; ?>
              <input type="number" name="montant_final" id="montant_final" class="input" min="0" step="1" value="<?= (int)$p['montant_final'] ?>" <?= $carte_reglee_admin ? 'readonly aria-readonly="true"' : '' ?>>
              <?php if ($carte_reglee_admin): ?>
                <div class="hint">Montant verrouillé : le paiement par carte a été confirmé par Stripe. Toute correction doit passer par une annulation ou un remboursement tracé.</div>
              <?php else: ?>
                <div class="hint">Montant libre, à l'euro près : c'est la rémunération de l'expert. En règlement direct, le client lui règle cette base ; en carte Stripe, la commission Missiona s'ajoute au total affiché au client.</div>
              <?php endif; ?>
              <div class="hint">Le passage à « Terminé » génère le justificatif de commission Missiona ; si la carte Stripe a été réglée, cette commission est déjà acquittée automatiquement.</div>
            </div>
            <div class="flex gap-1 wrap">
              <button type="submit" class="btn btn-primary">Enregistrer</button>
              <?php if (!$p['freelance_id']): ?>
                <a href="/admin-matching?id=<?= $id ?>" class="btn btn-ghost">🎯 Lancer le matching</a>
              <?php endif; ?>
            </div>
            </fieldset>
          </form>
        </div>
      </div>
      <?php endif; ?>

    </div>

    <div class="grid" style="gap:24px">
      <div class="panel">
        <div class="panel-head"><h3>Informations</h3></div>
        <div class="panel-body">
          <div class="recap">
            <?php if ($u['role'] !== 'freelance'): ?>
              <div class="recap-row"><span>Client</span><strong><?= e($p['societe'] ?: $p['e_nom']) ?></strong></div>
            <?php endif; ?>
            <div class="recap-row"><span>Budget estimé</span><strong><?= euros($p['budget_min']) ?> – <?= euros_max($p['budget_max']) ?></strong></div>
            <?php if ($p['montant_final']): ?>
              <div class="recap-row"><span>Rémunération de l'expert</span><strong><?= euros($p['montant_final']) ?></strong></div>
            <?php endif; ?>
            <div class="recap-row"><span>Durée</span><strong><?= e($p['delai'] ?: '—') ?></strong></div>
            <div class="recap-row"><span>Échéance</span><strong><?= date_fr($p['date_limite']) ?></strong></div>
            <div class="recap-row"><span>Statut</span><strong><span class="badge <?= statut_classe($p['statut']) ?>"><?= statut_label($p['statut']) ?></span></strong></div>
          </div>
        </div>
      </div>

      <?php
      /* ── Règlement (entreprise uniquement) ─────────────────────────────
         Avec Stripe Connect de test, la carte est débitée directement sur le
         compte Stripe de l'expert ; Missiona perçoit seulement son application fee.
         Sans clés Stripe, le parcours direct historique reste inchangé. */
      $pay = paiements_projet($id);
      if (est_client($u['role']) && $pay):
        $pp = $pay['projet'] ?? null;
        // Les échéances créées avant Connect restent volontairement dans leur
        // circuit historique, afin de ne jamais modifier un montant convenu.
        $stripe_card = stripe_actif() && stripe_cle_publique() !== '' && !empty($pp['stripe_direct']);
        $expert_pret = false;
        if ($stripe_card && $p['freelance_id']) {
          $q_expert = db()->prepare("SELECT stripe_account,stripe_charges_ok FROM users WHERE id=?");
          $q_expert->execute([(int) $p['freelance_id']]);
          $expert_connect = $q_expert->fetch();
          $expert_pret = !empty($expert_connect['stripe_account']) && !empty($expert_connect['stripe_charges_ok']);
        }
      ?>
      <div class="panel">
        <div class="panel-head"><h3>Règlement</h3></div>
        <div class="panel-body">
          <?php if ($stripe_card): ?>
            <div class="alert alert-info" style="margin:0 0 14px">
              🔒 Paiement sécurisé par carte via Stripe. Le règlement est créé directement
              sur le compte Stripe de l'expert ; la commission Missiona est déjà incluse dans le total.
            </div>
          <?php else: ?>
            <div class="alert alert-info" style="margin:0 0 14px">
              💳 Vous réglez l'expert <strong>directement</strong>, selon les modalités convenues
              avec lui (virement, etc.). Missiona n'encaisse aucun paiement et n'émet
              aucune facture en votre nom.
            </div>
          <?php endif; ?>

          <?php if ($pp && (int) $pp['montant'] > 0): ?>
            <?php
              $montant_client = max(0, (int) $pp['montant']);
              if ($stripe_card) {
                  // Avant la création du PaymentIntent, on affiche exactement la
                  // même commission arrondie que Stripe recevra ensuite.
                  $frais_centimes = max(0, (int) ($pp['stripe_application_fee'] ?? 0));
                  if ($frais_centimes === 0) { $frais_centimes = stripe_frais_application_centimes($montant_client); }
                  $commission_missiona = (int) round($frais_centimes / 100);
                  $revenu_expert = max(0, $montant_client - $commission_missiona);
              } else {
                  $revenu_expert = $montant_client;
                  $commission_missiona = commission_reglement_direct($revenu_expert);
              }
              $net_expert = max(0, $revenu_expert - ($stripe_card ? 0 : $commission_missiona));
            ?>
            <div class="recap mb-2">
              <?php if ($stripe_card): ?>
                <div class="recap-row"><span>Total payé par l'entreprise</span><strong><?= euros($montant_client) ?></strong></div>
                <div class="recap-row"><span>Rémunération de l'expert</span><strong><?= euros($revenu_expert) ?></strong></div>
                <div class="recap-row"><span>Commission Missiona (incluse)</span><strong><?= euros($commission_missiona) ?></strong></div>
                <div class="recap-row"><span>Frais de traitement Stripe</span><strong>Prélevés séparément par Stripe, le cas échéant</strong></div>
              <?php else: ?>
                <div class="recap-row"><span>Réglé directement par l'entreprise à l'expert</span><strong><?= euros($montant_client) ?></strong></div>
                <div class="recap-row"><span>Commission Missiona (facturée séparément à l'expert)</span><strong><?= euros($commission_missiona) ?></strong></div>
                <div class="recap-row"><span>Revenu de l'expert après commission</span><strong><?= euros($net_expert) ?></strong></div>
              <?php endif; ?>
              <div class="recap-row"><span><?= $stripe_card ? 'Paiement' : 'À régler à' ?></span><strong><?= $stripe_card ? 'Par carte sécurisée' : "L'expert, directement" ?></strong></div>
            </div>
          <?php endif; ?>

          <?php if ($stripe_card && $p['statut'] === 'attribue'): ?>
            <div class="pay-step <?= $expert_pret ? '' : 'pending' ?>">
              <div class="pay-num">1</div>
              <div class="pay-body">
                <h4>Payer et démarrer la mission</h4>
                <?php if ($expert_pret && $pp && $pp['statut'] === 'a_payer'): ?>
                  <p class="small muted">Choisissez votre moyen de paiement dans le formulaire sécurisé Missiona, propulsé par Stripe.</p>
                  <form method="post" class="mt-1">
                    <?= csrf_field() ?><input type="hidden" name="form_paiement_element" value="1">
                    <button class="btn btn-primary btn-block">Continuer vers le paiement sécurisé</button>
                  </form>
                <?php else: ?>
                  <p class="small muted">Le compte de paiement de l'expert est encore en cours de vérification. Vous serez prévenu dès que la carte sera disponible.</p>
                <?php endif; ?>
              </div>
            </div>
          <?php elseif ($stripe_card && $pp && $pp['statut'] === 'paye'): ?>
            <div class="pay-step done">
              <div class="pay-num">✓</div>
              <div class="pay-body"><h4>Paiement confirmé</h4><p class="small muted">Votre paiement par carte a été confirmé<?= $pp['paye_le'] ? ' le ' . date_fr($pp['paye_le']) : '' ?>. La mission est en cours.</p></div>
            </div>
          <?php elseif (!$stripe_card && $p['statut'] === 'attribue'): ?>
            <div class="pay-step">
              <div class="pay-num">1</div>
              <div class="pay-body">
                <h4>Valider la proposition</h4>
                <p class="small muted">Votre accord lance la mission. Aucun paiement ne transite par Missiona.</p>
                <form method="post" class="mt-1">
                  <?= csrf_field() ?><input type="hidden" name="form_paiement" value="1"><input type="hidden" name="action_paiement" value="valider">
                  <button class="btn btn-primary btn-block">Valider et démarrer la mission</button>
                </form>
              </div>
            </div>
          <?php elseif (!$stripe_card && $pp && $pp['statut'] === 'paye'): ?>
            <div class="pay-step done"><div class="pay-num">✓</div><div class="pay-body"><h4>Règlement confirmé</h4><p class="small muted">Vous avez confirmé avoir réglé l'expert<?= $pp['paye_le'] ? ' le ' . date_fr($pp['paye_le']) : '' ?>. Merci.</p></div></div>
          <?php elseif (!$stripe_card && $pp && in_array($p['statut'], ['en_cours','livraison','termine'], true)): ?>
            <div class="pay-step <?= $p['statut'] === 'termine' ? '' : 'pending' ?>">
              <div class="pay-num">2</div><div class="pay-body"><h4>Régler l'expert</h4>
                <p class="small muted"><?= $p['statut'] === 'termine' ? 'Le livrable a été remis : réglez l\'expert du montant convenu, puis confirmez ci-dessous.' : 'Réglez l\'expert selon les modalités convenues avec lui, puis confirmez ci-dessous.' ?></p>
                <form method="post" class="mt-1" onsubmit="return confirm('Confirmer que vous avez réglé l\'expert ?')">
                  <?= csrf_field() ?><input type="hidden" name="form_paiement" value="1"><input type="hidden" name="action_paiement" value="confirmer">
                  <button class="btn btn-primary btn-block">Je confirme avoir réglé l'expert</button>
                </form>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php
      /* ── Demandes d'annulation / remboursement ─────────────────── */
      $dem_active = demande_active($id);
      $dem_hist   = demandes_projet($id);
      ?>

      <?php if ($dem_active): ?>
      <div class="panel">
        <div class="panel-head"><h3>Demande en cours</h3></div>
        <div class="panel-body">
          <div class="alert alert-warn" style="margin:0 0 14px">
            <strong>Demande de <?= e($dem_active['type']) ?></strong> déposée le <?= date_fr($dem_active['created_at']) ?>.
            <?= $u['role']==='admin' ? '' : ' Notre équipe vous répond sous 48 h.' ?>
          </div>
          <p class="small muted" style="white-space:pre-line"><?= e($dem_active['motif']) ?></p>

          <?php if ($u['role']==='admin'): ?>
            <form method="post" class="mt-2">
              <?= csrf_field() ?>
              <input type="hidden" name="form_traiter" value="1">
              <input type="hidden" name="demande_id" value="<?= (int)$dem_active['id'] ?>">
              <label class="field">
                <span>Réponse au client</span>
                <textarea name="reponse" class="input" rows="2" placeholder="Motif de la décision…"></textarea>
              </label>
              <div class="flex-gap mt-1">
                <button name="action_demande" value="accepter" class="btn btn-primary btn-sm">Accepter la demande</button>
                <button name="action_demande" value="refuser" class="btn btn-ghost btn-sm">Refuser</button>
              </div>
              <div class="hint">Accepter annule le projet. Le remboursement éventuel se règle directement entre le client et l'expert : pensez à le préciser dans votre réponse.</div>
            </form>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if (est_client($u['role']) && !$dem_active && peut_demander_annulation($p)): ?>
      <div class="panel">
        <div class="panel-head"><h3>Annulation</h3></div>
        <div class="panel-body">
          <p class="small muted mb-2">
            Vous pouvez demander l'annulation du projet. Votre demande est
            examinée par notre équipe sous 48&nbsp;h. Comme vous réglez
            l'expert directement, tout remboursement éventuel se traite entre
            vous et l'expert, avec notre médiation.
          </p>
          <details>
            <summary class="btn btn-ghost btn-sm btn-block">Demander une annulation</summary>
            <form method="post" class="mt-2">
              <?= csrf_field() ?>
              <input type="hidden" name="form_demande" value="1">
              <label class="field">
                <span>Type de demande</span>
                <select name="type_demande" class="input">
                  <option value="annulation">Annuler le projet</option>
                  <option value="remboursement">Demander un remboursement</option>
                </select>
              </label>
              <label class="field">
                <span>Motif</span>
                <textarea name="motif" class="input" rows="3" required minlength="10"
                          placeholder="Expliquez brièvement votre demande…"></textarea>
              </label>
              <button class="btn btn-primary btn-block btn-sm">Envoyer la demande</button>
            </form>
          </details>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($u['role']==='admin'): ?>
      <div class="panel">
        <div class="panel-head"><h3>Actions administrateur</h3></div>
        <div class="panel-body">
          <?php $peut_agir = peut_gerer_projet($p); ?>
          <form method="post" <?= $peut_agir ? '' : 'onsubmit="return false"' ?>>
            <?= csrf_field() ?><input type="hidden" name="form_contact_direct" value="1">
            <?php if (empty($p['contact_direct'])): ?>
              <button name="contact_direct" value="ouvrir" class="btn btn-ghost btn-block btn-sm"
                      <?= $p['freelance_id'] && $p['statut'] !== 'annule' && $peut_agir ? '' : 'disabled' ?>>
                🔓 Ouvrir le contact direct client ↔ expert
              </button>
              <div class="hint">
                Les deux parties pourront échanger messages et fichiers en direct,
                sur ce projet uniquement. Vous gardez la lecture du fil et pouvez
                le refermer à tout moment.
              </div>
              <?php if (!$p['freelance_id']): ?>
                <div class="hint">Disponible dès qu'un expert sera affecté.</div>
              <?php endif; ?>
            <?php else: ?>
              <span class="badge badge-green mb-1">Canal direct ouvert</span>
              <a href="/messages?lecture=<?= (int) $p['id'] ?>" class="btn btn-ghost btn-block btn-sm">👁 Lire les échanges directs</a>
              <button name="contact_direct" value="fermer" class="btn btn-ghost btn-block btn-sm"
                      onclick="return confirm('Refermer le canal direct ? Les deux parties repasseront par Missiona.')"
                      <?= $peut_agir ? '' : 'disabled' ?>>
                🔒 Refermer le contact direct
              </button>
            <?php endif; ?>
          </form>
          <hr class="sep">

          <?php if ($p['statut'] !== 'annule'): ?>
            <form method="post" onsubmit="return confirm('Annuler définitivement ce projet ?')">
              <?= csrf_field() ?>
              <input type="hidden" name="form_annuler" value="1">
              <label class="check mb-1">
                <input type="checkbox" name="rembourser" value="1" checked>
                <span>Des sommes ont déjà été réglées à l'expert (organiser leur restitution)</span>
              </label>
              <button class="btn btn-ghost btn-block btn-sm">Annuler le projet</button>
            </form>
            <hr class="sep">
          <?php endif; ?>

          <details>
            <summary class="btn btn-ghost btn-sm btn-block" style="color:#b42318">Supprimer définitivement</summary>
            <form method="post" class="mt-2" onsubmit="return confirm('Cette suppression est irréversible. Continuer ?')">
              <?= csrf_field() ?>
              <input type="hidden" name="form_supprimer" value="1">
              <p class="small muted mb-1">
                Efface le projet, ses messages, fichiers, paiements et factures.
                Cette action est irréversible.
              </p>
              <label class="field">
                <span>Saisissez SUPPRIMER pour confirmer</span>
                <input type="text" name="confirmation" class="input" placeholder="SUPPRIMER" autocomplete="off">
              </label>
              <button class="btn btn-block btn-sm" style="background:#b42318;color:#fff">Supprimer ce projet</button>
            </form>
          </details>
        </div>
      </div>
      <?php endif; ?>

      <?php if (count($dem_hist) > ($dem_active ? 1 : 0)): ?>
      <div class="panel">
        <div class="panel-head"><h3>Historique des demandes</h3></div>
        <div class="panel-body">
          <?php foreach ($dem_hist as $dh): if ($dh['statut']==='en_attente') continue; ?>
            <div class="recap-row">
              <span><?= ucfirst(e($dh['type'])) ?><br><small class="muted"><?= date_fr($dh['created_at']) ?></small></span>
              <strong class="badge <?= $dh['statut']==='acceptee'?'badge-green':'badge-red' ?>">
                <?= $dh['statut']==='acceptee'?'Acceptée':'Refusée' ?>
              </strong>
            </div>
            <?php if ($dh['reponse']): ?><p class="small muted mb-2"><?= e($dh['reponse']) ?></p><?php endif; ?>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <div class="panel">
        <div class="panel-head"><h3>Expert affecté</h3></div>
        <div class="panel-body">
          <?php if ($p['freelance_id']): ?>
            <div class="flex-center mb-2">
              <span class="avatar lg"><?= strtoupper(mb_substr($p['f_prenom'],0,1).mb_substr($p['f_nom'],0,1)) ?></span>
              <div>
                <h4><?= est_client($u['role'])
                      ? e($p['f_prenom'].' '.mb_substr($p['f_nom'],0,1).'.')
                      : e($p['f_prenom'].' '.$p['f_nom']) ?></h4>
                <p class="small muted"><?= e($p['titre_pro']) ?></p>
                <p class="small stars"><?= str_repeat('★',(int)round($p['note_moyenne'])) ?> <span class="muted"><?= number_format($p['note_moyenne'],1,',','') ?>/5</span></p>
              </div>
            </div>
            <?php if (!empty($p['contact_direct']) && $p['statut'] !== 'annule'): ?>
              <div class="alert alert-success" style="margin:0;font-size:.8125rem">
                🔓 Un canal d'échange direct est ouvert sur ce projet.
                <?php if ($u['role'] !== 'admin'): ?>
                  <a href="/messages?d=<?= (int) $p['id'] ?>" style="color:inherit;text-decoration:underline">Ouvrir la discussion</a>.
                <?php else: ?>
                  <a href="/messages?lecture=<?= (int) $p['id'] ?>" style="color:inherit;text-decoration:underline">Lire le fil</a>.
                <?php endif; ?>
                Missiona conserve la consultation de ces échanges.
              </div>
            <?php else: ?>
              <div class="alert alert-info" style="margin:0;font-size:.8125rem">
                🔒 Les échanges passent par votre chargé de compte Missiona pour garantir la traçabilité et la qualité du suivi.
              </div>
            <?php endif; ?>
          <?php else: ?>
            <div class="empty" style="padding:24px 0">
              <div class="ico">🔎</div>
              <p class="small">Sélection de l'expert en cours par notre équipe.</p>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($factures): ?>
      <div class="panel">
        <div class="panel-head"><h3>Facturation</h3></div>
        <div class="panel-body">
          <?php foreach ($factures as $fac): ?>
            <div class="recap">
              <div class="recap-row"><span>N° facture</span><strong><?= e($fac['numero']) ?></strong></div>
              <div class="recap-row"><span>Montant HT</span><strong><?= euros($fac['montant_ht']) ?></strong></div>
              <?php if ($u['role']==='admin'): ?>
                <div class="recap-row"><span>Commission d'apport</span><strong><?= euros($fac['commission']) ?></strong></div>
                <div class="recap-row"><span>Net expert</span><strong><?= euros($fac['montant_freelance']) ?></strong></div>
              <?php endif; ?>
              <div class="recap-row"><span>Statut</span><strong><span class="badge <?= $fac['statut']==='payee'?'badge-green':'badge-amber' ?>"><?= $fac['statut']==='payee'?'Payée':'En attente' ?></span></strong></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>

</main>
</div>

<?php if ($afficher_confirmation_projet): ?>
<div class="project-success-modal" id="projectSuccessModal" role="dialog" aria-modal="true"
     aria-labelledby="projectSuccessTitle" aria-describedby="projectSuccessText">
  <div class="project-success-dialog" role="document">
    <div class="project-success-check" aria-hidden="true">✓</div>
    <h2 id="projectSuccessTitle">Projet envoyé</h2>
    <p id="projectSuccessText">Votre projet est en cours de traitement.</p>
    <!-- Un vrai lien : même sans JavaScript, il recharge la page sans le
         marqueur de session déjà consommé, donc la confirmation disparaît. -->
    <a class="btn btn-primary" data-project-success-close autofocus
       href="<?= e(u('projet.php?id=' . $id)) ?>">Continuer</a>
  </div>
</div>
<?php endif; ?>

<!-- Fenêtre d'aperçu protégée -->
<div class="apercu" id="apercu" hidden aria-hidden="true" role="dialog" aria-modal="true" aria-label="Aperçu du document">
  <div class="apercu-barre">
    <strong id="apercuNom"></strong>
    <span class="apercu-mention">Document confidentiel — diffusion interdite</span>
    <button type="button" class="apercu-fermer" id="apercuFermer" aria-label="Fermer l'aperçu" data-suivi-non>&times;</button>
  </div>
  <div class="apercu-scene" id="apercuScene">
    <div class="apercu-contenu" id="apercuContenu"></div>
    <!-- Filigrane : posé au-dessus du document, non sélectionnable -->
    <div class="apercu-filigrane" aria-hidden="true"
         data-texte="<?= e(SITE_NOM) ?>"
         data-qui="<?= e(trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? '')) . ' · ' . date('d/m/Y')) ?>"></div>
    <div class="apercu-voile" id="apercuVoile" hidden>
      <div>Aperçu masqué<br><span>Revenez sur cette fenêtre pour afficher le document.</span></div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
