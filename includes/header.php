<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/seo.php';
require_once __DIR__ . '/jsonld.php';
// Empêche le navigateur de réafficher une version périmée de la page
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
$u = user();
// Mesure d'audience interne (voir includes/stats.php). Volontairement
// placée après user() pour rattacher la visite à un compte si connecté.
stats_visite();
$page = $page ?? '';
$titre = $titre ?? 'Missiona';

// SEO : même directive dans le HTML et dans l'en-tête HTTP, utile aux robots
// qui ne rendent pas la page. Les pages d'action et espaces privés sont noindex.
$seo = seo_meta($page);
$seo_indexable = seo_est_indexable($page);
$seo_robots = seo_directive_robots($page);
$seo_canon = $seo_indexable ? seo_url($seo['path']) : null;
header('X-Robots-Tag: ' . $seo_robots);

// L'accueil public n'utilise aucun puits HTML dynamique. Une seconde politique
// CSP, combinée à celle d'Apache, active Trusted Types pour ce premier écran
// sans risquer de bloquer les espaces authentifiés historiques qui en utilisent.
if ($page === 'accueil') {
    header("Content-Security-Policy: script-src 'self' https://js.stripe.com https://*.js.stripe.com; require-trusted-types-for 'script'; trusted-types missiona");
}

// Guide très court, affiché seulement juste après la première connexion.
// Le champ en base est la source de vérité : ainsi le guide apparaît aussi
// pour une session déjà ouverte au moment du déploiement, puis plus jamais
// dès que la personne clique sur « J’ai compris ».
$guide_premiere_connexion = $u && empty($u['guide_premiere_connexion_vu']);
$guide_titre = '';
$guide_intro = '';
$guide_etapes = [];
if ($guide_premiere_connexion) {
    if (est_client($u['role'])) {
        $guide_titre = 'Votre espace projet';
        $guide_intro = 'Un projet, un seul endroit : du besoin à la livraison.';
        $guide_etapes = [
            ['Créer', 'Nouveau projet : besoin, budget et délai.'],
            ['Suivre', 'Dans le projet : étapes, expert, messages et fichiers.'],
            ['Décider', 'Validez proposition, livraison et règlement direct.'],
        ];
    } elseif (($u['role'] ?? '') === 'freelance') {
        $guide_titre = 'Votre espace missions';
        $guide_intro = 'Tout est centralisé, de votre profil au livrable.';
        $guide_etapes = [
            ['Profil', 'Ajoutez compétences, tarif et disponibilité.'],
            ['Mission', 'Ouvrez-la pour le brief, les messages et les fichiers.'],
            ['Livrer', 'Déposez le livrable dans la mission : Missiona le vérifie.'],
        ];
    } else {
        $guide_titre = 'Votre espace de pilotage';
        $guide_intro = 'Les priorités et chaque projet sont réunis au même endroit.';
        $guide_etapes = [
            ['Priorités', 'Le tableau de bord regroupe les actions à traiter.'],
            ['Projets', 'Qualifiez, attribuez un expert et actualisez le statut.'],
            ['Qualité', 'Contrôlez messages et livrables depuis chaque projet.'],
        ];
    }
}
$guide_retour = $_SERVER['REQUEST_URI'] ?? ('/' . dashboard_url($u['role'] ?? null));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php
$seo_titre = $seo['title'] !== '' ? $seo['title'] . ' | Missiona'
           : (strpos($titre, 'Missiona') === 0 ? $titre : $titre . ' — Missiona');
?>
<title><?= e($seo_titre) ?></title>
<meta name="description" content="<?= e($seo['description']) ?>">
<meta name="robots" content="<?= e($seo_robots) ?>">
<?php if ($seo_canon): ?>
<link rel="canonical" href="<?= e($seo_canon) ?>">
<?php endif; ?>

<?php if ($seo_indexable): ?>
<!-- Open Graph / Twitter : seulement pour les pages publiques indexables. -->
<meta property="og:type" content="website">
<meta property="og:site_name" content="Missiona">
<meta property="og:locale" content="fr_FR">
<meta property="og:title" content="<?= e($seo_titre) ?>">
<meta property="og:description" content="<?= e($seo['description']) ?>">
<meta property="og:url" content="<?= e($seo_canon) ?>">
<meta property="og:image" content="<?= e(SITE_URL) ?><?= e(asset('assets/img/og-image.jpg')) ?>">
<meta property="og:image:type" content="image/jpeg">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="Missiona — vous décrivez le projet, on livre le résultat">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= e($seo_titre) ?>">
<meta name="twitter:description" content="<?= e($seo['description']) ?>">
<meta name="twitter:image" content="<?= e(SITE_URL) ?><?= e(asset('assets/img/og-image.jpg')) ?>">
<?php endif; ?>
<link rel="icon" type="image/svg+xml" href="<?= asset('assets/img/favicon.svg') ?>">
<link rel="icon" type="image/png" sizes="512x512" href="<?= asset('assets/img/icon-512.png') ?>">
<link rel="icon" type="image/x-icon" href="<?= asset('assets/img/favicon.ico') ?>">
<link rel="apple-touch-icon" href="<?= asset('assets/img/apple-touch-icon.png') ?>">
<meta name="theme-color" content="#12336e">
<?php if (app_mobile_visible()): ?>
<!-- Application installable : réservée à l'administration tant que le
     réglage « Application mobile » n'est pas ouvert à tous. Sans la
     balise manifest, aucun navigateur ne propose l'installation. -->
<link rel="manifest" href="/manifest.json">
<meta name="app-installable" content="1">
<?php if (($u['role'] ?? '') === 'admin' && push_disponible()): ?>
<meta name="push-cle" content="<?= e(push_cle_publique()) ?>">
<?php endif; ?>
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Missiona">
<meta name="application-name" content="Missiona">
<?php endif; ?>
<?php
// L'accueil n'a pas besoin du CSS des tableaux de bord, messageries et
// formulaires privés. Sa feuille purgée conserve strictement ses styles,
// ceux du header et du footer, et réduit le CSS inutile au premier affichage.
$style_public = $page === 'accueil' ? 'assets/css/home.css' : 'assets/css/style.css';
?>
<link rel="stylesheet" href="<?= asset($style_public) ?>">
<!-- Couche globale chargée en dernier : rétablit le contraste de toutes les pages,
     y compris les formulaires, tableaux de bord, cartes et curseurs de budget. -->
<link rel="stylesheet" href="<?= asset('assets/css/contrast.css') ?>">
<!-- Documents légaux et interface de consentement partagée. -->
<link rel="stylesheet" href="<?= asset('assets/css/legal.css') ?>">

<?php
/* Données structurées : uniquement sur les pages publiques indexables. */
if ($seo_indexable) {
    $fils = [
        'entreprises' => "Pour les entreprises",
        'freelances'  => "Pour les freelances",
        'services'    => "Nos expertises",
        'tarifs'      => "Tarifs",
        'a-propos'    => "Qui sommes-nous",
        'contact'     => "Contact",
        'faq'         => "Questions fréquentes",
        'mentions'    => "Mentions légales",
    ];
    echo jsonld_bloc(jsonld_page($seo)), "\n";
    if ($page === 'accueil') {
        echo jsonld_bloc(jsonld_organisation()), "\n";
        echo jsonld_bloc(jsonld_website()), "\n";
        echo jsonld_bloc(jsonld_service()), "\n";
    } elseif (isset($fils[$page])) {
        echo jsonld_bloc(jsonld_fil([['nom' => $fils[$page], 'path' => $seo['path']]])), "\n";
    }
    if ($page === 'faq') {
        require_once __DIR__ . '/contenu.php';
        echo jsonld_bloc(jsonld_faq(wc_faq())), "\n";
    }
}
?>
</head>
<body>
<?php if (emprunt_actif()): $_o = emprunt_origine(); ?>
  <div class="bandeau-alerte" role="alert">
    <strong>Session de dépannage.</strong>
    Vous agissez au nom de <?= e(trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? ''))) ?>
    <?php if ($_o): ?>(depuis le compte de <?= e(trim($_o['prenom'] . ' ' . $_o['nom'])) ?>)<?php endif; ?>.
    <a href="<?= u('/admin-rendre-la-main.php') ?>">Revenir à mon compte</a>
  </div>
<?php endif; ?>
<?php if (mot_de_passe_par_defaut()): ?>
  <div class="bandeau-alerte" role="alert">
    <strong>Mot de passe administrateur par défaut.</strong>
    Changez-le sans attendre dans
    <a href="<?= u('/profil') ?>">votre profil</a> : tant qu'il reste en place,
    n'importe qui peut accéder à votre back-office.
  </div>
<?php endif; ?>
<?php if (beta_actif()): ?>
  <div class="bandeau-beta" role="status">
    <span class="beta-tag">BÊTA</span>
    <span><?= e(beta_message()) ?></span>
    <?php if (!user()): ?>
      <a href="<?= u('/inscription') ?>" class="beta-cta">Je m'inscris</a>
    <?php endif; ?>
  </div>
<?php endif; ?>

<header class="header">
  <div class="container header-inner">
    <a href="/" class="logo">
      <img class="logo-mark" src="<?= asset('assets/img/mark.svg') ?>" alt="" width="32" height="32"> Missiona
    </a>

    <nav class="nav" id="nav" aria-label="Navigation principale">
      <?php if (!$u): ?>
        <a href="/" class="<?= $page==='accueil'?'active':'' ?>">Accueil</a>
        <a href="/entreprises" class="<?= $page==='entreprises'?'active':'' ?>">Entreprises</a>
        <a href="/freelances" class="<?= $page==='freelances'?'active':'' ?>">Freelances</a>
        <a href="/services" class="<?= $page==='services'?'active':'' ?>">Expertises</a>
        <?php if (!beta_lien_masque('/tarifs')): ?><a href="/tarifs" class="<?= $page==='tarifs'?'active':'' ?>">Tarifs</a><?php endif; ?>
        <a href="/contact" class="<?= $page==='contact'?'active':'' ?>">Contact</a>
      <?php else: ?>
        <a href="<?= dashboard_url() ?>">Tableau de bord</a>
        <a href="/messages">Messagerie</a>
        <?php if (est_client($u['role']) && !beta_lien_masque('/nouveau-projet')): ?><a href="/nouveau-projet">Nouveau projet</a><?php endif; ?>
      <?php endif; ?>
    </nav>

    <div class="header-cta">
      <?php if ($u): ?>
        <?php $nb = nb_notifs_non_lues($u['id']); ?>
        <div class="notif-wrap">
          <button class="notif-btn" id="notifToggle" type="button" aria-label="Notifications">
            🔔<?php if ($nb): ?><span class="dot-red"></span><?php endif; ?>
          </button>
          <div class="notif-panel" id="notifPanel">
            <div class="hd">Notifications</div>
            <?php $ns = notifications($u['id'], 6); ?>
            <?php if (!$ns): ?>
              <div style="padding:22px;text-align:center;color:var(--muted);font-size:.8125rem">Aucune notification</div>
            <?php else: foreach ($ns as $n): ?>
              <a class="notif-item <?= $n['lu']?'':'unread' ?>" href="<?= e($n['lien'] ?: '#') ?>">
                <?= e($n['texte']) ?><time><?= date_fr($n['created_at']) ?></time>
              </a>
            <?php endforeach; endif; ?>
          </div>
        </div>
        <a href="/profil" class="avatar" title="<?= e($u['prenom'].' '.$u['nom']) ?>"><?= initiales($u) ?></a>
        <a href="/deconnexion" class="btn btn-ghost btn-sm">Déconnexion</a>
      <?php else: ?>
        <a href="/connexion" class="btn btn-ghost">Connexion</a>
        <a href="/inscription" class="btn btn-primary">Inscription</a>
      <?php endif; ?>
      <button class="burger" id="navToggle" type="button" aria-label="Menu" aria-controls="nav" aria-expanded="false">
        <span></span><span></span><span></span>
      </button>
    </div>
  </div>
</header>

<?php if ($guide_premiere_connexion): ?>
<div class="onboarding-guide" id="onboardingGuide" role="dialog" aria-modal="true"
     aria-labelledby="onboardingTitle" aria-describedby="onboardingIntro">
  <section class="onboarding-guide-card" role="document">
    <div class="onboarding-kicker">👋 Première connexion</div>
    <h2 id="onboardingTitle"><?= e($guide_titre) ?></h2>
    <p id="onboardingIntro"><?= e($guide_intro) ?></p>
    <ol class="onboarding-steps">
      <?php foreach ($guide_etapes as $numero => $etape): ?>
        <li>
          <span aria-hidden="true"><?= (int) $numero + 1 ?></span>
          <div><strong><?= e($etape[0]) ?></strong><small><?= e($etape[1]) ?></small></div>
        </li>
      <?php endforeach; ?>
    </ol>
    <div class="onboarding-essentials">
      <span>🔔 Notifications : ce qui attend votre action</span>
      <span>💬 Messages : tous vos échanges au même endroit</span>
      <span>👤 Profil : gardez vos informations à jour</span>
    </div>
    <form method="post" action="<?= e(u('/guide-demarrage')) ?>" class="onboarding-close">
      <?= csrf_field() ?>
      <input type="hidden" name="retour" value="<?= e($guide_retour) ?>">
      <button type="submit" class="btn btn-primary btn-block btn-lg" autofocus>J’ai compris, continuer</button>
    </form>
  </section>
</div>
<?php endif; ?>
