<?php
/**
 * Missiona — Fréquentation du site
 * ------------------------------------------------------------------
 * Réservé à l'administrateur principal : ces données concernent
 * l'ensemble des visiteurs, y compris non inscrits.
 *
 * Les graphiques sont produits en SVG côté serveur : ils s'affichent
 * même sans JavaScript et ne dépendent d'aucune bibliothèque.
 */
require_once __DIR__ . '/includes/functions.php';
require_admin_principal();

/* ---------- Actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (isset($_POST['purger'])) {
        stats_purge();
        flash("Données antérieures à la durée de conservation supprimées.", 'success');
        header('Location: ' . u('/admin-statistiques?vue=reglages')); exit;
    }
    if (isset($_POST['tout_effacer'])) {
        db()->exec("DELETE FROM visites");
        db()->exec("DELETE FROM evenements");
        flash("Historique de fréquentation entièrement effacé.", 'success');
        header('Location: ' . u('/admin-statistiques?vue=reglages')); exit;
    }
    if (isset($_POST['reglages'])) {
        set_param('stats_actif', isset($_POST['stats_actif']) ? 1 : 0);
        set_param('stats_retention', max(30, min(1200, (int) ($_POST['stats_retention'] ?? 395))));
        flash("Réglages enregistrés.", 'success');
        header('Location: ' . u('/admin-statistiques?vue=reglages')); exit;
    }
}

/* ---------- Export CSV ---------- */
if (isset($_GET['export'])) {
    $j = max(1, (int) ($_GET['jours'] ?? 30));
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="frequentation-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF");            // BOM, pour qu'Excel lise les accents
    fputcsv($out, ['Date', 'Pages vues', 'Visiteurs'], ';');
    foreach (stats_par_jour($j) as $p) {
        fputcsv($out, [$p['date'], $p['pages'], $p['visiteurs']], ';');
    }
    fclose($out);
    exit;
}

$jours = (int) ($_GET['jours'] ?? 30);
if (!in_array($jours, [7, 30, 90, 365], true)) { $jours = 30; }
$vue = (string) ($_GET['vue'] ?? 'audience');
if (!in_array($vue, ['vue', 'audience', 'comportement', 'temps', 'acquisition', 'reglages'], true)) { $vue = 'audience'; }

$resume   = stats_resume($jours);
$serie    = stats_par_jour($jours);
$pages    = stats_top_pages($jours);
$actions  = stats_top_actions($jours);
$appareil = stats_repartition('appareil', $jours);
$langues  = stats_repartition('langue', $jours, 5);
$navigateur = stats_repartition('navigateur', $jours, 8);
$os       = stats_repartition('os', $jours, 8);
$heures   = stats_par_heure(min($jours, 30));
$heatmap  = stats_heatmap($jours);
$temps_pages = stats_temps_pages($jours);
$temps_resume = stats_temps_resume($jours);
$visiteurs_anonymes = $vue === 'temps' ? stats_visiteurs_anonymes($jours) : [];
$visiteur_detail = $vue === 'temps' && isset($_GET['visiteur'])
    ? stats_visiteur_anonyme_detail((string) $_GET['visiteur'], $jours) : null;
$parcours = stats_parcours_conversion($jours);
$sources_performance = stats_sources_performance($jours);
$evenements_recents = stats_evenements_recents($jours);
$pays_performance = stats_pays_performance($jours);
$heatmap_max = 1;
foreach ($heatmap as $colonne) { foreach ($colonne as $n) { $heatmap_max = max($heatmap_max, (int) $n); } }
$navigateur_max = 1; foreach ($navigateur as $ligne) { $navigateur_max = max($navigateur_max, (int) $ligne['n']); }
$os_max = 1; foreach ($os as $ligne) { $os_max = max($os_max, (int) $ligne['n']); }
$appareil_max = 1; foreach ($appareil as $ligne) { $appareil_max = max($appareil_max, (int) $ligne['n']); }

$titre = "Fréquentation";
require_once __DIR__ . '/includes/header.php';
?>
<div class="app">
<?php require __DIR__ . '/includes/sidebar.php'; ?>
<main class="main">

    <div class="page-head traffic-head">
      <div>
        <p class="traffic-eyebrow">Pilotage interne</p>
        <h1>Trafic &amp; conversion</h1>
        <p class="muted">Audience, interactions et dépôts de projets, mesurés par Missiona sans service externe.</p>
      </div>
      <div class="traffic-head-status" title="Les données proviennent uniquement de la base Missiona.">
        <span class="traffic-pulse" aria-hidden="true"></span>
        Données internes
      </div>
    </div>

    <?php if ($f = flash()): ?>
      <div class="alert alert-<?= e($f['t']) ?>"><?= e($f['m']) ?></div>
    <?php endif; ?>

    <?php if (!stats_actif()): ?>
      <div class="alert alert-amber mb-3">
        La mesure est <strong>désactivée</strong> : aucune nouvelle visite n'est enregistrée.
      </div>
    <?php endif; ?>

    <!-- Période -->
    <div class="traffic-toolbar mb-3">
      <div class="traffic-periods" aria-label="Choisir la période">
        <?php foreach ([7 => '7 jours', 30 => '30 jours', 90 => '3 mois', 365 => '1 an'] as $k => $lib): ?>
          <a class="btn btn-sm <?= $jours === $k ? 'btn-primary' : 'btn-ghost' ?>"
             href="<?= u('/admin-statistiques?jours=' . $k . ($vue !== 'audience' ? '&vue=' . $vue : '')) ?>" data-suivi-non><?= $lib ?></a>
        <?php endforeach; ?>
      </div>
      <a class="btn btn-sm btn-ghost" href="<?= u('/admin-statistiques?export=csv&jours=' . $jours) ?>">Exporter en CSV</a>
    </div>

    <nav class="traffic-page-nav mb-3" aria-label="Sections trafic">
      <?php foreach (['audience' => 'Trafic', 'vue' => 'Conversions', 'comportement' => 'Comportement', 'temps' => 'Temps de trafic', 'acquisition' => 'Acquisition', 'reglages' => 'Réglages'] as $cle => $libelle): ?>
        <a class="<?= $vue === $cle ? 'active' : '' ?>" href="<?= u('/admin-statistiques?jours=' . $jours . '&vue=' . $cle) ?>" data-suivi-non><?= e($libelle) ?></a>
      <?php endforeach; ?>
    </nav>

    <?php if ($vue === 'vue'): ?>
    <!-- Indicateurs de pilotage : lisibles d'un coup d'œil, sans reprendre
         la charte ou les éléments visuels d'un service tiers. -->
    <div class="traffic-kpis mb-3">
      <article class="traffic-kpi traffic-kpi-main">
        <span class="traffic-kpi-icon" aria-hidden="true">◌</span>
        <div><div class="traffic-kpi-label">Visiteurs uniques</div><strong><?= number_format($resume['visiteurs'], 0, ',', ' ') ?></strong><small>un appareil / IP ne compte qu’une fois par jour</small></div>
      </article>
      <article class="traffic-kpi">
        <span class="traffic-kpi-icon" aria-hidden="true">↗</span>
        <div><div class="traffic-kpi-label">Pages vues</div><strong><?= number_format($resume['pages'], 0, ',', ' ') ?></strong><small><?= $resume['par_visiteur'] ?> par visiteur</small></div>
      </article>
      <article class="traffic-kpi">
        <span class="traffic-kpi-icon" aria-hidden="true">⌁</span>
        <div><div class="traffic-kpi-label">Interactions</div><strong><?= number_format($resume['actions'], 0, ',', ' ') ?></strong><small>clics et envois</small></div>
      </article>
      <article class="traffic-kpi">
        <span class="traffic-kpi-icon" aria-hidden="true">◆</span>
        <div><div class="traffic-kpi-label">Projets déposés</div><strong><?= number_format($resume['projets'], 0, ',', ' ') ?></strong><small><?= stats_taux($resume['projets'], $resume['visiteurs']) ?> % des visiteurs</small></div>
      </article>
    </div>

    <div class="traffic-layout mb-3">
      <section class="panel traffic-funnel">
        <div class="panel-head"><div><h3>Parcours de conversion</h3><p class="small muted">Du visiteur au projet déposé</p></div><span class="badge badge-blue">Période sélectionnée</span></div>
        <div class="panel-body">
          <div class="traffic-funnel-steps">
            <?php foreach ($parcours as $i => $etape): ?>
              <article class="traffic-stage traffic-stage-<?= e($etape['cle']) ?>">
                <div class="traffic-stage-top"><span><?= (int) ($i + 1) ?></span><strong><?= e($etape['libelle']) ?></strong></div>
                <div class="traffic-stage-number"><?= number_format($etape['n'], 0, ',', ' ') ?></div>
                <div class="traffic-stage-bar"><i style="width:<?= max(3, min(100, (float) $etape['taux'])) ?>%"></i></div>
                <small><?= e($etape['aide']) ?><?php if ($i > 0): ?> · <?= $etape['taux'] ?> %<?php endif; ?></small>
              </article>
            <?php endforeach; ?>
          </div>
          <p class="traffic-note">Une interaction correspond à une action mesurée ; un formulaire correspond à un clic sur un bouton d’envoi. Les projets déposés proviennent de la base Missiona.</p>
        </div>
      </section>

      <section class="panel traffic-recent">
        <div class="panel-head"><div><h3>Dernières interactions</h3><p class="small muted">Anonymisées, sans données personnelles</p></div></div>
        <div class="panel-body">
          <?php if (!$evenements_recents): ?>
            <p class="muted small">Aucune interaction enregistrée pour cette période.</p>
          <?php else: ?><ul class="traffic-event-list">
            <?php foreach ($evenements_recents as $evt): ?>
              <li>
                <span class="traffic-event-dot" aria-hidden="true"></span>
                <div><strong><?= e($evt['cible'] ?: 'Interaction') ?></strong><small><?= e($evt['type']) ?><?= $evt['chemin'] ? ' · ' . e($evt['chemin']) : '' ?></small></div>
                <time datetime="<?= e($evt['created_at']) ?>"><?= date('d/m H:i', strtotime($evt['created_at'])) ?></time>
              </li>
            <?php endforeach; ?>
          </ul><?php endif; ?>
        </div>
      </section>
    </div>

    <!-- Évolution -->
    <div class="panel mb-3">
      <div class="panel-head">
        <h3>Évolution</h3>
        <span class="small muted">
          <span class="lg lg-bleu"></span> pages vues
          <span class="lg lg-vert"></span> visiteurs
        </span>
      </div>
      <div class="panel-body"><?= svg_courbe($serie) ?></div>
    </div>
    <?php endif; ?>

    <?php if ($vue === 'audience'): ?>
    <!-- Vue d’audience détaillée : organisation dense inspirée des tableaux
         de trafic modernes, adaptée à la charte et aux données Missiona. -->
    <section class="traffic-studio mb-3" aria-label="Détail de l’audience">
      <article class="panel traffic-language-card">
        <div class="panel-head"><h3>Langues</h3><span class="traffic-card-menu" aria-hidden="true">⠿</span></div>
        <div class="panel-body traffic-language-content">
          <div class="traffic-language-legend">
            <?php if (!$langues): ?><p class="muted small">Les nouvelles visites renseigneront cette répartition.</p>
            <?php else: foreach ($langues as $i => $langue): ?>
              <div><i class="traffic-swatch traffic-swatch-<?= (int) ($i % 6) ?>"></i><span><?= e($langue['k']) ?></span><strong><?= number_format($langue['n'], 0, ',', ' ') ?></strong></div>
            <?php endforeach; endif; ?>
          </div>
          <div class="traffic-donut-wrap"><?= svg_donut($langues, 'visites') ?></div>
        </div>
      </article>

      <article class="panel traffic-device-card">
        <div class="panel-head"><h3>Appareils</h3><span class="traffic-card-menu" aria-hidden="true">⠿</span></div>
        <div class="traffic-device-tabs" role="tablist" aria-label="Répartition technique">
          <button type="button" class="active" data-traffic-device-tab="navigateur" role="tab" aria-selected="true">Navigateur</button>
          <button type="button" data-traffic-device-tab="os" role="tab" aria-selected="false">OS</button>
          <button type="button" data-traffic-device-tab="appareil" role="tab" aria-selected="false">Appareil</button>
        </div>
        <div class="panel-body traffic-device-body">
          <div data-traffic-device-panel="navigateur">
            <?php if (!$navigateur): ?><p class="muted small">Les nouvelles visites renseigneront les navigateurs.</p>
            <?php else: foreach ($navigateur as $ligne): ?><div class="traffic-rank-row"><span><?= e($ligne['k']) ?></span><i><b style="width:<?= round($ligne['n'] / $navigateur_max * 100, 1) ?>%"></b></i><strong><?= (int) $ligne['n'] ?></strong></div><?php endforeach; endif; ?>
          </div>
          <div data-traffic-device-panel="os" hidden>
            <?php if (!$os): ?><p class="muted small">Les nouvelles visites renseigneront les systèmes.</p>
            <?php else: foreach ($os as $ligne): ?><div class="traffic-rank-row"><span><?= e($ligne['k']) ?></span><i><b style="width:<?= round($ligne['n'] / $os_max * 100, 1) ?>%"></b></i><strong><?= (int) $ligne['n'] ?></strong></div><?php endforeach; endif; ?>
          </div>
          <div data-traffic-device-panel="appareil" hidden>
            <?php if (!$appareil): ?><p class="muted small">Les nouvelles visites renseigneront les appareils.</p>
            <?php else: foreach ($appareil as $ligne): ?><div class="traffic-rank-row"><span><?= e(ucfirst($ligne['k'])) ?></span><i><b style="width:<?= round($ligne['n'] / $appareil_max * 100, 1) ?>%"></b></i><strong><?= (int) $ligne['n'] ?></strong></div><?php endforeach; endif; ?>
          </div>
        </div>
      </article>

      <article class="panel traffic-heat-card">
        <div class="panel-head"><h3>Trafic</h3><span class="traffic-card-menu" aria-hidden="true">⠿</span></div>
        <div class="panel-body">
          <div class="traffic-heat-grid" role="img" aria-label="Répartition des visites par jour et par heure">
            <span></span><?php foreach ([1=>'Lu', 2=>'Ma', 3=>'Me', 4=>'Je', 5=>'Ve', 6=>'Sa', 7=>'Di'] as $label): ?><strong><?= $label ?></strong><?php endforeach; ?>
            <?php for ($heure = 0; $heure < 24; $heure++): ?><span class="traffic-heat-hour"><?= $heure ?> h</span><?php foreach ([1,2,3,4,5,6,7] as $jour): $n = (int) $heatmap[$jour][$heure]; $niveau = $n ? max(1, min(5, (int) ceil($n / $heatmap_max * 5))) : 0; ?>
              <i class="traffic-heat-dot lvl-<?= $niveau ?>" title="<?= ['Lu','Ma','Me','Je','Ve','Sa','Di'][$jour - 1] ?> <?= $heure ?> h : <?= $n ?> visite<?= $n > 1 ? 's' : '' ?>"></i>
            <?php endforeach; endfor; ?>
          </div>
          <p class="traffic-heat-note">Plus le point est intense, plus cette tranche reçoit de visites.</p>
        </div>
      </article>

      <article class="panel traffic-map-card">
        <div class="panel-head"><h3>Arrivées dans le monde</h3><span class="traffic-card-menu" aria-hidden="true">⠿</span></div>
        <div class="panel-body traffic-map-canvas">
          <?= svg_carte_monde($pays_performance) ?>
          <?php if (!$pays_performance): ?><p class="traffic-map-empty">La carte est prête : les premiers pays apparaîtront dès les nouvelles visites publiques. La localisation est déterminée localement, sans appel externe.</p><?php endif; ?>
        </div>
      </article>

      <article class="panel traffic-location-card">
        <div class="panel-head"><h3>Localisations</h3><span class="traffic-card-menu" aria-hidden="true">⠿</span></div>
        <div class="traffic-location-tabs"><strong>Top pays</strong><span>Visiteurs uniques / jour</span></div>
        <div class="panel-body traffic-location-list">
          <?php if (!$pays_performance): ?><p class="muted small">Aucune arrivée géolocalisée pour cette période.</p>
          <?php else: foreach ($pays_performance as $pays): [$nom_pays] = stats_pays_infos($pays['pays']); $pct = max(6, round($pays['visiteurs'] / max(1, $pays_performance[0]['visiteurs']) * 100, 1)); ?>
            <div class="traffic-location-row"><span class="traffic-country-code"><?= e($pays['pays']) ?></span><div><strong><?= e($nom_pays) ?></strong><i><b style="width:<?= $pct ?>%"></b></i></div><em><?= number_format($pays['visiteurs'], 0, ',', ' ') ?></em></div>
          <?php endforeach; endif; ?>
        </div>
      </article>
    </section>
    <?php endif; ?>

    <?php if ($vue === 'acquisition'): ?>
    <section class="panel traffic-sources mb-3">
      <div class="panel-head"><div><h3>Performance par source</h3><p class="small muted">Audience et interactions associées</p></div></div>
      <div class="panel-body">
        <?php if (!$sources_performance): ?>
          <p class="muted small">Aucune source enregistrée pour cette période.</p>
        <?php else: ?><div class="traffic-source-table" role="table" aria-label="Performance par source">
          <div class="traffic-source-row traffic-source-heading" role="row"><span>Source</span><span>Visiteurs</span><span>Pages</span><span>Actions</span></div>
          <?php foreach ($sources_performance as $ligne): ?>
            <div class="traffic-source-row" role="row">
              <strong title="<?= e($ligne['source']) ?>"><?= e(stats_source_libelle($ligne['source'])) ?></strong>
              <span><?= number_format($ligne['visiteurs'], 0, ',', ' ') ?></span>
              <span><?= number_format($ligne['pages'], 0, ',', ' ') ?></span>
              <span><?= number_format($ligne['actions'], 0, ',', ' ') ?></span>
            </div>
          <?php endforeach; ?>
        </div><?php endif; ?>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($vue === 'temps'): ?>
    <section class="traffic-time-overview mb-3" aria-label="Résumé du temps actif">
      <article class="traffic-time-metric traffic-time-metric-main">
        <span aria-hidden="true">◷</span>
        <div><small>Temps actif moyen</small><strong><?= e(stats_duree_lisible($temps_resume['moyenne'])) ?></strong><p>par visiteur avec mesure de durée</p></div>
      </article>
      <article class="traffic-time-metric">
        <span aria-hidden="true">⌛</span>
        <div><small>Temps actif cumulé</small><strong><?= e(stats_duree_lisible($temps_resume['secondes'])) ?></strong><p>sur la période sélectionnée</p></div>
      </article>
      <article class="traffic-time-metric">
        <span aria-hidden="true">◉</span>
        <div><small>Visiteurs mesurés</small><strong><?= number_format($temps_resume['visiteurs'], 0, ',', ' ') ?></strong><p>durée visible reçue</p></div>
      </article>
      <article class="traffic-time-metric">
        <span aria-hidden="true">▣</span>
        <div><small>Pages avec durée</small><strong><?= number_format($temps_resume['pages'], 0, ',', ' ') ?></strong><p>pages publiques uniquement</p></div>
      </article>
    </section>

    <section class="panel traffic-time-panel mb-3">
      <div class="panel-head"><div><h3>Temps actif moyen par page</h3><p class="small muted">Moyenne calculée sur les visiteurs mesurés, uniquement lorsque l’onglet est visible.</p></div><span class="badge badge-blue">Données fraîches</span></div>
      <div class="panel-body">
        <?php if (!$temps_pages): ?><p class="muted small">Aucune durée reçue pour l’instant. Les premières mesures arrivent automatiquement après au moins 3 secondes sur une page publique ; elles sont aussi envoyées toutes les 15 secondes lorsque la page reste ouverte.</p>
        <?php else: ?><div class="traffic-time-table" role="table" aria-label="Temps actif par page">
          <div class="traffic-time-row traffic-time-heading" role="row"><span>Page</span><span>Visiteurs</span><span>Temps cumulé</span><span>Moyenne</span></div>
          <?php foreach ($temps_pages as $temps): ?><div class="traffic-time-row" role="row"><strong><?= e($temps['chemin']) ?></strong><span><?= number_format($temps['visiteurs'], 0, ',', ' ') ?></span><span><?= e(stats_duree_lisible($temps['secondes'])) ?></span><span><?= e(stats_duree_lisible($temps['moyenne'])) ?></span></div><?php endforeach; ?>
        </div><?php endif; ?>
      </div>
    </section>

    <?php if (isset($_GET['visiteur'])): ?>
    <section class="panel traffic-anonymous-profile mb-3">
      <?php if (!$visiteur_detail): ?>
        <div class="panel-body"><p class="muted small">Ce visiteur anonyme n’est plus disponible pour cette période.</p></div>
      <?php else: $etiquette = 'V-' . strtoupper(substr($visiteur_detail['visiteur'], 0, 6)); ?>
        <div class="panel-head"><div><h3><?= e($etiquette) ?> · session anonyme du <?= e($visiteur_detail['jour']) ?></h3><p class="small muted"><?= e($visiteur_detail['appareil']) ?> · <?= e($visiteur_detail['navigateur']) ?> · <?= e($visiteur_detail['os']) ?> · <?= (int) $visiteur_detail['pages_vues'] ?> pages vues</p></div><a class="btn btn-sm btn-ghost" href="<?= u('/admin-statistiques?jours=' . $jours . '&vue=temps') ?>" data-suivi-non>Fermer le détail</a></div>
        <div class="panel-body">
          <div class="traffic-profile-total"><span>Temps actif total de cette session anonyme</span><strong><?= e(stats_duree_lisible($visiteur_detail['secondes'])) ?></strong></div>
          <div class="traffic-profile-pages" role="table" aria-label="Parcours des pages">
            <div class="traffic-profile-row traffic-profile-heading" role="row"><span>Page consultée</span><span>Vues</span><span>Temps actif</span><span>Première vue</span></div>
            <?php foreach ($visiteur_detail['pages'] as $page): ?><div class="traffic-profile-row" role="row"><strong><?= e($page['chemin']) ?></strong><span><?= (int) $page['vues'] ?></span><span><?= e(stats_duree_lisible($page['secondes'])) ?></span><time><?= e($page['premiere_vue']) ?></time></div><?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="panel traffic-anonymous-list mb-3">
      <div class="panel-head"><div><h3>Sessions anonymes récentes</h3><p class="small muted">Cliquez pour consulter les pages et le temps actif de la journée. Il ne s’agit pas d’adresses IP.</p></div></div>
      <div class="panel-body">
        <?php if (!$visiteurs_anonymes): ?><p class="muted small">Les sessions publiques apparaîtront ici après les premières visites.</p>
        <?php else: ?><div class="traffic-visitor-table" role="table" aria-label="Sessions anonymes">
          <div class="traffic-visitor-row traffic-visitor-heading" role="row"><span>Session anonyme</span><span>Appareil</span><span>Pages</span><span>Temps actif</span><span>Dernière activité</span><span></span></div>
          <?php foreach ($visiteurs_anonymes as $visiteur): $etiquette = 'V-' . strtoupper(substr($visiteur['visiteur'], 0, 6)); ?><div class="traffic-visitor-row" role="row">
            <strong><?= e($etiquette) ?><small><?= e($visiteur['jour']) ?></small></strong>
            <span><?= e($visiteur['appareil']) ?></span><span><?= (int) $visiteur['pages_vues'] ?></span><span><?= e(stats_duree_lisible($visiteur['secondes'])) ?></span><time><?= e($visiteur['derniere_vue']) ?></time>
            <a class="btn btn-sm btn-ghost" href="<?= u('/admin-statistiques?jours=' . $jours . '&vue=temps&visiteur=' . rawurlencode($visiteur['visiteur'])) ?>" data-suivi-non>Voir</a>
          </div><?php endforeach; ?>
        </div><?php endif; ?>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($vue === 'comportement'): ?>
    <div class="grid grid-2 mb-3">
      <div class="panel">
        <div class="panel-head"><h3>Pages les plus consultées</h3></div>
        <div class="panel-body"><?= svg_barres($pages, 'chemin', 'n') ?></div>
      </div>
      <div class="panel">
        <div class="panel-head"><h3>Boutons et liens cliqués</h3></div>
        <div class="panel-body">
          <?php if (!$actions): ?>
            <p class="muted small">Aucune action pour l'instant. Les clics sont enregistrés dès qu'un visiteur utilise le site avec JavaScript actif.</p>
          <?php else: ?>
            <?= svg_barres($actions, 'cible', 'n') ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="panel mb-3">
      <div class="panel-head"><h3>Heures de fréquentation</h3></div>
      <div class="panel-body"><?= svg_heures($heures) ?></div>
    </div>

    <?php if ($actions): ?>
    <div class="panel mb-3">
      <div class="panel-head"><h3>Détail des actions</h3></div>
      <div class="panel-body">
        <table class="table">
          <thead><tr><th>Type</th><th>Élément</th><th class="right">Nombre</th></tr></thead>
          <tbody>
            <?php foreach ($actions as $a): ?>
              <tr>
                <td><span class="badge badge-gray"><?= e($a['type']) ?></span></td>
                <td class="small"><?= e($a['cible']) ?></td>
                <td class="right strong"><?= (int) $a['n'] ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($vue === 'reglages'): ?>
    <!-- Réglages et vie privée -->
    <div class="panel">
      <div class="panel-head"><h3>Réglages et vie privée</h3></div>
      <div class="panel-body">
        <p class="small mb-3">
          Aucune adresse IP n'est conservée : seul un condensé quotidien permet de
          distinguer un appareil et une adresse réseau. Un même appareil/IP compte
          donc une seule fois dans les visiteurs jusqu'au lendemain, même s'il ouvre
          plusieurs pages. Il est impossible de le suivre d'un jour sur l'autre, ni
          de l'identifier. Le pays affiché sur la carte provient soit d'un code pays
          anonyme fourni par l'hébergeur, soit d'une base pays locale embarquée ; aucune
          IP n'est envoyée vers un service de géolocalisation. Données pays locales :
          <a href="https://www.iplocate.io/" target="_blank" rel="noopener noreferrer">IPLocate.io</a>
          (CC BY-SA 4.0). Aucune donnée ne sort du serveur.
        </p>

        <form method="post" class="mb-3">
          <?= csrf_field() ?>
          <input type="hidden" name="reglages" value="1">
          <label class="check mb-2">
            <input type="checkbox" name="stats_actif" value="1" <?= stats_actif() ? 'checked' : '' ?>>
            <span>Mesurer la fréquentation</span>
          </label>
          <div class="field" style="max-width:280px">
            <label for="stats_retention">Conservation (jours)</label>
            <input type="number" name="stats_retention" id="stats_retention" class="input"
                   min="30" max="1200" step="1" value="<?= (int) stats_retention() ?>">
            <div class="hint">La CNIL recommande de ne pas dépasser 25 mois (760 jours).</div>
          </div>
          <button class="btn btn-primary btn-sm mt-2" type="submit">Enregistrer</button>
        </form>

        <div class="sep"></div>

        <form method="post" style="display:inline">
          <?= csrf_field() ?>
          <button class="btn btn-ghost btn-sm" type="submit" name="purger" value="1">
            Purger au-delà de la durée de conservation
          </button>
        </form>
        <form method="post" style="display:inline"
              onsubmit="return confirm('Effacer définitivement tout l\'historique de fréquentation ?');">
          <?= csrf_field() ?>
          <button class="btn btn-ghost btn-sm" type="submit" name="tout_effacer" value="1">
            Tout effacer
          </button>
        </form>
      </div>
    </div>
    <?php endif; ?>

</main>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
