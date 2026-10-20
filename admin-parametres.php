<?php
require_once __DIR__ . '/includes/functions.php';
require_role('admin');
// Réglages structurants (algorithme, commission, paiement, maintenance) :
// réservés au seul administrateur principal.
require_admin_principal();

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['form_gratuit'])) {
    csrf_check();
    if (!est_admin_principal()) {
        flash("Seul l'administrateur principal peut modifier ce réglage.", 'error');
        header('Location: ' . u('/admin-parametres')); exit;
    }
    $on = isset($_POST['mode_gratuit']) ? 1 : 0;
    set_param('mode_gratuit', $on);
    equipe_journaliser($on ? 'monetisation_off' : 'monetisation_on', 0,
        $on ? 'Commission et frais supprimés, paiement direct' : 'Rétablissement de la commission');
    flash($on
        ? "Facturation désactivée : aucune commission ne sera facturée aux experts."
        : "Facturation rétablie : la commission d'apport d'affaires est de nouveau facturée à l'expert après paiement du client.");
    header('Location: ' . u('/admin-parametres')); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['form_appmobile'])) {
    csrf_check();
    set_param('app_mobile_public', isset($_POST['app_mobile_public']) ? 1 : 0);
    flash(isset($_POST['app_mobile_public'])
        ? "L'application mobile est désormais proposée à tous les visiteurs."
        : "L'application mobile est réservée à l'administration.");
    header('Location: ' . u('/admin-parametres')); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['form_beta'])) {
    csrf_check();
    if (!est_admin_principal()) {
        flash("Seul l'administrateur principal peut modifier le mode bêta.", 'error');
        header('Location: ' . u('/admin-parametres')); exit;
    }
    $on = isset($_POST['beta']) ? 1 : 0;
    set_param('beta', $on);
    set_param('beta_message', trim($_POST['beta_message'] ?? ''));
    flash($on
        ? "Mode bêta activé : le site est en préinscription, les circuits d'argent sont fermés."
        : "Mode bêta désactivé : la plateforme est pleinement ouverte.");
    header('Location: ' . u('/admin-parametres')); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['form_maintenance'])) {
    csrf_check();
    // Seul l'administrateur principal peut couper ou rétablir le site
    if (!est_admin_principal()) {
        flash("Seul l'administrateur principal peut activer le mode maintenance.", 'error');
        header('Location: ' . u('/admin-parametres')); exit;
    }
    $actif = isset($_POST['maintenance']) ? 1 : 0;
    set_param('maintenance', $actif);
    set_param('maintenance_message', trim($_POST['maintenance_message'] ?? ''));
    flash($actif
        ? "Mode maintenance activé : le site est fermé aux visiteurs. Vous seul y avez encore accès."
        : "Mode maintenance désactivé : le site est de nouveau accessible au public.");
    header('Location: ' . u('/admin-parametres')); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_check();
    $st = db()->prepare("INSERT INTO parametres (cle,valeur) VALUES (?,?) ON CONFLICT(cle) DO UPDATE SET valeur=excluded.valeur");
    foreach (['coef_competences','coef_budget','coef_dispo','coef_experience','coef_note','commission'] as $k) {
        $st->execute([$k, max(0, (int)($_POST[$k] ?? 0))]);
    }
    // Versements aux experts
    if (isset($_POST['delai_securite']))  { $st->execute(['delai_securite',  max(0, min(60, (int)$_POST['delai_securite']))]); }
    if (isset($_POST['push_palier_inscrits'])) {
        $st->execute(['push_palier_inscrits', max(1, min(1000, (int) $_POST['push_palier_inscrits']))]);
    }
    if (isset($_POST['retrait_minimum'])) { $st->execute(['retrait_minimum', max(1, (int)$_POST['retrait_minimum'])]); }
    if (isset($_POST['connect_pays'])) {
        $l = strtoupper(preg_replace('/[^A-Za-z,\s]/', '', $_POST['connect_pays']));
        $l = implode(',', array_filter(array_map('trim', preg_split('/[,\s]+/', $l))));
        $st->execute(['connect_pays', $l]);
    }
    // Facturation : identité légale et mentions obligatoires
    foreach (['soc_forme','soc_capital','soc_rcs','soc_tva'] as $k) {
        if (isset($_POST[$k])) { $st->execute([$k, trim($_POST[$k])]); }
    }
    if (isset($_POST['tva_taux']))       { $st->execute(['tva_taux', max(0, (float)$_POST['tva_taux'])]); }
    $st->execute(['tva_franchise', isset($_POST['tva_franchise']) ? 1 : 0]);
    if (isset($_POST['delai_paiement']))  { $st->execute(['delai_paiement', max(1, min(90, (int)$_POST['delai_paiement']))]); }
    if (isset($_POST['penalites_taux']))  { $st->execute(['penalites_taux', max(0, (float)$_POST['penalites_taux'])]); }
    flash("Paramètres enregistrés. Le moteur de matching utilise désormais ces coefficients.");
    header('Location: ' . u('/admin-parametres')); exit;
}

$titre = "Paramètres";
require_once __DIR__ . '/includes/header.php';
$total = (int)param('coef_competences',40)+(int)param('coef_budget',25)+(int)param('coef_dispo',15)+(int)param('coef_experience',10)+(int)param('coef_note',10);
?>
<div class="app">
<?php require __DIR__ . '/includes/sidebar.php'; ?>
<main class="main">

  <div class="page-head">
    <div><h1>Paramètres de la plateforme</h1><p>Configuration du moteur de matching et du modèle économique.</p></div>
  </div>

  <?php if ($f = flash()): ?><div class="alert alert-<?= e($f['t']) ?>"><?= e($f['m']) ?></div><?php endif; ?>

  <?php if (est_admin_principal()): ?>
  <?php $mt = maintenance_active(); ?>
  <div class="panel mb-3" style="max-width:720px;border-color:<?= $mt ? 'var(--amber)' : 'var(--line)' ?>">
    <div class="panel-head">
      <h3>🚧 Mode maintenance</h3>
      <span class="badge <?= $mt ? 'badge-amber' : 'badge-gray' ?>">
        <?= $mt ? 'Site fermé au public' : 'Site en ligne' ?>
      </span>
    </div>
    <div class="panel-body">
      <?php if ($mt): ?>
        <div class="alert-warn mb-3">
          <strong>Le site est actuellement fermé aux visiteurs.</strong>
          Ils voient une page d'attente. Vous êtes le seul à pouvoir naviguer normalement.
        </div>
      <?php endif; ?>

      <p class="small muted mb-3">
        Coupe l'accès public le temps d'une mise à jour. Les visiteurs reçoivent une page
        d'attente en HTTP 503 : Google comprend qu'il s'agit d'une interruption temporaire
        et ne déréférence pas vos pages. Vous conservez l'accès complet au site et au
        back-office, et un lien discret en bas de la page d'attente vous permet de vous
        reconnecter si vous vous déconnectez.
      </p>

      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="form_maintenance" value="1">

        <label class="flex-gap" style="align-items:center;margin-bottom:14px">
          <input type="checkbox" name="maintenance" value="1" <?= $mt ? 'checked' : '' ?>>
          <span><strong>Activer le mode maintenance</strong></span>
        </label>

        <div class="field">
          <label for="maintenance_message">Message affiché aux visiteurs</label>
          <textarea name="maintenance_message" id="maintenance_message" class="input" rows="3"
            placeholder="<?= e(maintenance_message()) ?>"><?= e(param('maintenance_message','')) ?></textarea>
          <p class="hint">Laissez vide pour utiliser le message par défaut.</p>
        </div>

        <button type="submit" class="btn <?= $mt ? 'btn-primary' : 'btn-ghost' ?>">
          <?= $mt ? 'Remettre le site en ligne' : 'Fermer le site' ?>
        </button>
      </form>
    </div>
  </div>

  <?php $bt = beta_actif(); ?>
  <div class="panel mb-3" style="max-width:720px;border-color:<?= $bt ? 'var(--amber)' : 'var(--line)' ?>">
    <div class="panel-head">
      <h3>🧪 Mode bêta — préinscription</h3>
      <span class="badge <?= $bt ? 'badge-amber' : 'badge-gray' ?>">
        <?= $bt ? 'Préinscription' : 'Plateforme ouverte' ?>
      </span>
    </div>
    <div class="panel-body">
      <?php if ($bt): ?>
        <div class="alert-warn mb-3">
          <strong>Le site est en préinscription.</strong>
          Les visiteurs consultent la vitrine et créent leur compte, mais aucun
          projet, aucune facture et aucun versement n'est possible.
        </div>
      <?php endif; ?>

      <p class="small muted mb-3">
        À la différence de la maintenance, le site reste ouvert et indexé par Google.
        Seules les fonctions qui engagent de l'argent ou une prestation sont fermées,
        le temps de constituer une base d'inscrits. Vous gardez, en tant
        qu'administrateur principal, un accès complet pour préparer la plateforme.
      </p>

      <div class="recap mb-3">
        <div class="recap-row"><span>Reste accessible</span>
          <strong>Accueil, services, entreprises, experts, FAQ, contact, mentions</strong></div>
        <div class="recap-row"><span>Comptes</span>
          <strong>Inscription et connexion ouvertes</strong></div>
        <div class="recap-row"><span>Expert</span>
          <strong>Profil, portfolio, forfaits — sans versement ni fiscalité</strong></div>
        <div class="recap-row"><span>Entreprise</span>
          <strong>Profil — sans dépôt de projet</strong></div>
        <div class="recap-row"><span>Fermé</span>
          <strong><?= implode(', ', array_map(fn($k) => '/' . $k, array_keys(beta_pages_fermees()))) ?></strong></div>
      </div>

      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="form_beta" value="1">

        <label class="flex-gap" style="align-items:center;margin-bottom:14px">
          <input type="checkbox" name="beta" value="1" <?= $bt ? 'checked' : '' ?>>
          <span><strong>Activer le mode bêta (préinscription)</strong></span>
        </label>

        <div class="field">
          <label for="beta_message">Message du bandeau</label>
          <textarea name="beta_message" id="beta_message" class="input" rows="3"
            placeholder="<?= e(beta_message()) ?>"><?= e(param('beta_message','')) ?></textarea>
          <p class="hint">Laissez vide pour utiliser le message par défaut.</p>
        </div>

        <button type="submit" class="btn <?= $bt ? 'btn-primary' : 'btn-ghost' ?>">
          <?= $bt ? 'Ouvrir la plateforme' : 'Passer en préinscription' ?>
        </button>
      </form>
    </div>
  </div>

  <?php $ap = (int) param('app_mobile_public', 0) === 1; ?>
  <div class="panel mb-3" style="max-width:720px">
    <div class="panel-head">
      <h3>📱 Application mobile</h3>
      <span class="badge <?= $ap ? 'badge-green' : 'badge-gray' ?>">
        <?= $ap ? 'Ouverte à tous' : 'Administration seulement' ?>
      </span>
    </div>
    <div class="panel-body">
      <p class="small muted mb-3">
        Missiona peut s'installer sur un téléphone comme une application :
        icône sur l'écran d'accueil, ouverture en plein écran, écran d'attente
        hors connexion. Tant que cette option reste fermée, seuls les comptes
        administrateurs voient la proposition d'installation — les visiteurs
        n'y ont pas accès, même depuis le menu de leur navigateur.
      </p>
      <p class="small muted mb-3">
        Si un visiteur avait déjà installé l'application, la fermeture de
        l'option la désactive chez lui au chargement suivant.
      </p>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="form_appmobile" value="1">
        <label class="flex-gap" style="align-items:center;margin-bottom:14px">
          <input type="checkbox" name="app_mobile_public" value="1" <?= $ap ? 'checked' : '' ?>>
          <span><strong>Proposer l'application à tous les visiteurs</strong></span>
        </label>
        <button type="submit" class="btn <?= $ap ? 'btn-ghost' : 'btn-primary' ?>">
          <?= $ap ? 'Réserver à l\'administration' : 'Enregistrer' ?>
        </button>
      </form>

      <div class="sep"></div>

      <h4 style="margin:0 0 8px;font-size:.95rem">Notifications sur votre téléphone</h4>
      <p class="small muted mb-2">
        Recevez une alerte à chaque nouveau projet, et à chaque palier de
        <?= (int) param('push_palier_inscrits', 10) ?> inscrits. L'activation
        se fait appareil par appareil : répétez-la sur chaque téléphone.
      </p>
      <?php if (!push_disponible()): ?>
        <div class="alert alert-warn small">
          Les notifications ne sont pas disponibles : l'extension OpenSSL
          manque sur ce serveur.
        </div>
      <?php else: ?>
        <button type="button" class="btn btn-primary btn-sm" id="btn-notifs"
                data-csrf="<?= e(csrf_token()) ?>" data-suivi-non>
          Activer sur ce téléphone
        </button>
        <span id="etat-notifs" class="small" style="margin-left:10px"></span>
        <p class="hint mt-2">
          Appareils abonnés :
          <?= (int) db()->query("SELECT COUNT(*) FROM push_abonnes")->fetchColumn() ?>.
          À ouvrir depuis le téléphone lui-même, en HTTPS.
        </p>
    
  <?php $mg = mode_gratuit(); ?>
  <div class="panel mb-3" style="max-width:720px;border-color:<?= $mg ? 'var(--amber)' : 'var(--line)' ?>">
    <div class="panel-head">
      <h3>💶 Rémunération de Missiona</h3>
      <span class="badge <?= $mg ? 'badge-amber' : 'badge-green' ?>">
        <?= $mg ? 'Facturation suspendue' : 'Facturation active' ?>
      </span>
    </div>
    <div class="panel-body">
      <?php if ($mg): ?>
        <div class="alert-warn mb-3">
          <strong>Facturation suspendue.</strong>
          Les experts ne sont facturés d'aucune commission ni d'aucuns frais de
          service pour les missions attribuées.
        </div>
      <?php endif; ?>

      <p class="small muted mb-3">
        Le client règle l'expert <strong>directement</strong>, au prix convenu : aucun
        fonds ne transite par Missiona. La rémunération de la plateforme est facturée
        à l'expert, après paiement du client, sous forme de commission d'apport
        d'affaires, sans aucun frais fixe.
      </p>

      <div class="recap mb-3">
        <div class="recap-row"><span>Commission facturée à l'expert</span>
          <strong><?= $mg ? '0 %' : ((int) param('commission', 20) . ' %') ?></strong></div>
        <div class="recap-row"><span>Frais fixes</span>
          <strong><?= euros(0) ?> — aucun</strong></div>
        <div class="recap-row"><span>Circuit de l'argent</span>
          <strong>Client → expert, en direct</strong></div>
        <div class="recap-row"><span>Facturation Missiona → expert</span>
          <strong><?= $mg ? 'Suspendue' : 'Après paiement du client' ?></strong></div>
      </div>

      <div class="alert alert-info small mb-3">
        <strong>Pourquoi ce circuit.</strong> Missiona n'encaissant jamais pour le
        compte d'autrui, elle reste hors du champ réglementé des services de
        paiement. En contrepartie, il n'y a ni séquestre ni garantie de paiement :
        la facturation de l'expert par Missiona n'intervient qu'une fois le client
        réglé, et le recouvrement de la commission relève du lien contractuel
        Missiona → expert.
      </div>

      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="form_gratuit" value="1">
        <label class="flex-gap" style="align-items:center;margin-bottom:14px">
          <input type="checkbox" name="mode_gratuit" value="1" <?= $mg ? 'checked' : '' ?>>
          <span><strong>Suspendre la facturation des experts (aucune commission ni frais)</strong></span>
        </label>
        <button type="submit" class="btn <?= $mg ? 'btn-ghost' : 'btn-primary' ?>">
          <?= $mg ? 'Rétablir la facturation' : 'Suspendre la facturation' ?>
        </button>
      </form>
    </div>
  </div>
  <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <form method="post" style="max-width:720px">
    <?= csrf_field() ?>

    <div class="panel mb-3">
      <div class="panel-head">
        <h3>Coefficients du moteur de matching</h3>
        <span class="badge <?= $total===100?'badge-green':'badge-amber' ?>">Total : <?= $total ?> %</span>
      </div>
      <div class="panel-body">
        <p class="small muted mb-3">
          Ces coefficients pondèrent les critères mesurables utilisés pour classer les experts.
          Le score final est une moyenne pondérée normalisée sur 100.
        </p>
        <?php
        $ch = [
          ['coef_competences','Correspondance des compétences','Part des compétences requises présentes sur le profil.'],
          ['coef_budget','Adéquation budgétaire','Compatibilité du tarif habituel du freelance avec le budget du projet.'],
          ['coef_dispo','Disponibilité','Disponible = 100, partiel = 50, occupé = 10.'],
          ['coef_experience','Années d\'expérience','Normalisé sur 10 ans maximum.'],
          ['coef_note','Note moyenne','Moyenne des évaluations sur 5, ramenée sur 100.'],
        ];
        foreach ($ch as $c): ?>
          <div class="field">
            <label for="<?= $c[0] ?>"><?= e($c[1]) ?></label>
            <div class="flex-center">
              <input type="number" id="<?= $c[0] ?>" name="<?= $c[0] ?>" class="input" min="0" max="100"
                     value="<?= (int)param($c[0]) ?>" style="max-width:110px">
              <span class="small muted">%</span>
              <div class="bar" style="flex:1"><i style="width:<?= min(100,(int)param($c[0])*2) ?>%"></i></div>
            </div>
            <div class="hint"><?= e($c[2]) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="panel mb-3">
      <div class="panel-head"><h3>Modèle économique</h3></div>
      <div class="panel-body">
        <div class="field">
          <label for="commission">Taux de commission Missiona (%)</label>
          <input type="number" id="commission" name="commission" class="input" min="0" max="100"
                 value="<?= (int)param('commission',20) ?>" style="max-width:140px">
          <div class="hint">Appliqué automatiquement lors de la génération des factures à la clôture d'un projet.</div>
        </div>
        <div class="alert alert-info" style="margin:0">
          💳 <strong>Architecture de paiement</strong> — les points d'intégration sont prévus
          (séquestre, échéancier, reversement expert, génération de facture) mais aucun flux de paiement réel
          n'est activé dans cette version. Le branchement d'un prestataire de paiement se fait au niveau de la
          table <code>factures</code> sans modification du reste de l'application.
        </div>
      </div>
    </div>

    <button type="submit" class="btn btn-primary btn-lg">Enregistrer les paramètres</button>
    <div class="panel mb-3">
      <div class="panel-head"><h3>Facturation</h3></div>
      <div class="panel-body">
        <p class="small muted mb-3">
          Ces informations apparaissent sur les factures PDF. Elles sont
          obligatoires : article 242 nonies A de l'annexe II au code général des impôts.
        </p>
        <div class="field-row">
          <div class="field">
            <label for="soc_forme">Forme juridique</label>
            <input type="text" name="soc_forme" id="soc_forme" class="input"
                   value="<?= e(param('soc_forme','')) ?>" placeholder="SAS, SARL, EI…">
          </div>
          <div class="field">
            <label for="soc_capital">Capital social (EUR)</label>
            <input type="text" name="soc_capital" id="soc_capital" class="input"
                   value="<?= e(param('soc_capital','')) ?>" placeholder="10 000">
          </div>
        </div>
        <div class="field-row">
          <div class="field">
            <label for="soc_rcs">RCS</label>
            <input type="text" name="soc_rcs" id="soc_rcs" class="input"
                   value="<?= e(param('soc_rcs','')) ?>" placeholder="Rouen B 912 345 678">
          </div>
          <div class="field">
            <label for="soc_tva">N° TVA intracommunautaire</label>
            <input type="text" name="soc_tva" id="soc_tva" class="input"
                   value="<?= e(param('soc_tva','')) ?>" placeholder="FR12912345678">
          </div>
        </div>
        <div class="hint mb-3">
          La raison sociale, le SIRET et l'adresse se règlent dans l'écran
          <a href="<?= u('/admin-dac7') ?>">Déclaration DAC7</a> et servent aux deux usages.
        </div>

        <div class="sep"></div>

        <div class="field-row">
          <div class="field">
            <label for="tva_taux">Taux de TVA (%)</label>
            <input type="number" name="tva_taux" id="tva_taux" class="input"
                   min="0" max="30" step="0.1" value="<?= e(param('tva_taux','20')) ?>">
          </div>
          <div class="field">
            <label for="delai_paiement">Délai de paiement (jours)</label>
            <input type="number" name="delai_paiement" id="delai_paiement" class="input"
                   min="1" max="90" step="1" value="<?= e(param('delai_paiement','30')) ?>">
            <div class="hint">60 jours maximum entre professionnels.</div>
          </div>
          <div class="field">
            <label for="penalites_taux">Pénalités de retard (% annuel)</label>
            <input type="number" name="penalites_taux" id="penalites_taux" class="input"
                   min="0" step="0.01" value="<?= e(param('penalites_taux','10.5')) ?>">
          </div>
        </div>
        <label class="check">
          <input type="checkbox" name="tva_franchise" value="1"
                 <?= (int)param('tva_franchise',0) === 1 ? 'checked' : '' ?>>
          <span>Franchise en base de TVA (mention « TVA non applicable, article 293 B du CGI »)</span>
        </label>
      </div>
    </div>

    <div class="panel mb-3">
      <div class="panel-head"><h3>Versements aux experts</h3></div>
      <div class="panel-body">
        <div class="field">
          <label for="push_palier_inscrits">Alerte téléphone tous les … inscrits</label>
          <input type="number" name="push_palier_inscrits" id="push_palier_inscrits" class="input"
                 min="1" max="1000" step="1" value="<?= (int) param('push_palier_inscrits', 10) ?>">
          <div class="hint">Une notification est envoyée à chaque multiple atteint.</div>
        </div>
        <div class="field">
          <label for="connect_pays">Pays desservis par le paiement automatique</label>
          <input type="text" name="connect_pays" id="connect_pays" class="input"
                 value="<?= e(param('connect_pays', implode(',', connect_pays_supportes()))) ?>">
          <div class="hint">
            Codes pays séparés par des virgules. Un expert situé hors de cette liste
            se voit proposer le versement manuel (virement, Wise, PayPal…) au lieu
            d'une erreur. Si notre prestataire refuse malgré tout d'ouvrir le compte,
            le passage au manuel est automatique.
          </div>
        </div>

        <p class="small muted mb-3">
          Règles appliquées avant qu'un expert puisse récupérer son argent.
          Un délai de sécurité protège contre les litiges et les annulations tardives :
          les grandes plateformes appliquent entre 5 et 14 jours.
        </p>
        <div class="field-row">
          <div class="field">
            <label for="delai_securite">Délai de sécurité (jours)</label>
            <input type="number" name="delai_securite" id="delai_securite" class="input"
                   min="0" max="60" step="1" value="<?= (int)param('delai_securite',7) ?>">
            <div class="hint">Temps entre le règlement du projet et la mise à disposition des fonds.</div>
          </div>
          <div class="field">
            <label for="retrait_minimum">Montant minimum de versement (€)</label>
            <input type="number" name="retrait_minimum" id="retrait_minimum" class="input"
                   min="1" step="1" value="<?= (int)param('retrait_minimum',50) ?>">
            <div class="hint">Évite de multiplier les virements de faible montant.</div>
          </div>
        </div>
      </div>
    </div>

  </form>

  <div class="panel mt-3">
    <div class="panel-head">
      <h3>Paiement par carte Stripe Connect</h3>
      <span class="badge <?= stripe_actif() ? 'badge-blue' : 'badge-gray' ?>"><?= stripe_actif() ? 'Test configuré' : 'À configurer' ?></span>
    </div>
    <div class="panel-body">
      <p class="small muted mb-2">
        Le parcours retenu utilise des <strong>paiements directs</strong> : la carte du client est débitée
        sur le compte Stripe connecté de l'expert. Missiona reçoit uniquement sa commission via
        <code>application_fee_amount</code> ; aucun fonds de la prestation ne transite par Missiona.
      </p>
      <p class="small mb-2">
        Chaque expert effectue l'onboarding Stripe hébergé et gère son compte depuis le tableau de bord Stripe complet.
        La confirmation d'un paiement et l'état d'un compte sont synchronisés par des webhooks Stripe signés.
      </p>
      <hr class="sep">
      <p class="small muted mb-0">
        <strong>Mode actuel :</strong> l'intégration n'accepte que des clés <code>sk_test_…</code>.
        Les clés et le secret webhook restent exclusivement dans <code>../missiona-config/config.local.php</code>, hors de <code>public_html</code> et hors archive.
      </p>
    </div>
  </div>

</main>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
