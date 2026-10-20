<?php
$titre = "Pour les freelances";
$page = 'freelances';
require_once __DIR__ . '/includes/header.php';
?>

<section class="hero" style="padding:72px 0 64px">
  <div class="container-sm text-center">
    <span class="eyebrow">Offre freelance</span>
    <h1 class="mt-2">Des missions qualifiées,<br>sans prospection.</h1>
    <p class="lead mt-3" style="margin:16px auto 0">
      Vous recevez uniquement des missions correspondant à vos compétences, votre tarif et votre disponibilité.
      Le reste — cadrage, relation client, facturation — est géré par notre équipe.
    </p>
    <div class="hero-actions" style="justify-content:center">
      <a href="/inscription?role=freelance" class="btn btn-primary btn-lg">Rejoindre le réseau</a>
      <a href="#criteres" class="btn btn-ghost btn-lg">Critères de sélection</a>
    </div>
  </div>
</section>

<section class="section bg-white">
  <div class="container">
    <div class="section-head">
      <h2>Concentrez-vous sur votre métier</h2>
      <p>Nous nous occupons de tout ce qui n'est pas votre expertise.</p>
    </div>
    <div class="grid grid-3">
      <?php
      $b = [
        ['🎯','Missions pré-qualifiées',"Chaque mission proposée a déjà été cadrée, budgétée et validée par le client. Vous savez exactement où vous allez."],
        ['🚫','Zéro prospection',"Plus de devis non signés, plus d'appels de découverte sans lendemain. Les missions viennent à vous."],
        ['💶','Budget validé d\'avance',"Le budget est validé par le client avant le démarrage. Vous facturez le client directement, selon les modalités convenues avec lui."],
        ['🗣️','Un seul interlocuteur',"Votre chargé de compte fait le lien avec le client, gère les arbitrages et absorbe les demandes hors périmètre."],
        ['📈','Un historique valorisé',"Vos notes et missions réalisées augmentent votre score de matching et la qualité des missions proposées."],
        ['⚖️','Un cadre clair',"Périmètre, jalons et critères de validation contractualisés dès le départ. Fini les projets sans fin."],
      ];
      foreach ($b as $i): ?>
        <div class="card card-hover">
          <div class="card-icon"><?= $i[0] ?></div>
          <h3><?= $i[1] ?></h3>
          <p><?= $i[2] ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section" id="criteres">
  <div class="container-sm">
    <div class="section-head">
      <h2>Comment nous sélectionnons les missions pour vous</h2>
      <p>Un scoring transparent, fondé sur des critères mesurables.</p>
    </div>
    <div class="panel">
      <div class="panel-body">
        <?php
        $c = [
          ['Correspondance des compétences', 40, 'Recoupement entre les compétences demandées et celles déclarées sur votre profil.'],
          ['Adéquation budgétaire', 25, 'Compatibilité entre votre tarif par projet et le budget alloué.'],
          ['Disponibilité', 15, 'Votre statut déclaré : disponible, partiellement disponible ou occupé.'],
          ['Années d\'expérience', 10, 'Expérience professionnelle sur le domaine concerné.'],
          ['Note moyenne', 10, 'Moyenne des évaluations obtenues sur vos missions précédentes.'],
        ];
        foreach ($c as $x): ?>
          <div class="mb-3">
            <div class="flex-between mb-1">
              <strong style="font-size:.9375rem"><?= e($x[0]) ?></strong>
              <span class="badge badge-blue"><?= $x[1] ?> %</span>
            </div>
            <div class="bar mb-1"><i style="width:<?= $x[1]*2.5 ?>%"></i></div>
            <p class="small muted"><?= e($x[2]) ?></p>
          </div>
        <?php endforeach; ?>
        <div class="alert alert-info" style="margin:0">
          Ces coefficients sont ajustables par l'équipe Missiona selon la nature du projet.
        </div>
      </div>
    </div>
  </div>
</section>

<section class="section bg-white">
  <div class="container-sm">
    <div class="section-head"><h2>Rejoindre le réseau</h2></div>
    <div class="grid" style="gap:16px">
      <?php
      $st = [
        ["Créez votre profil","Compétences, tarif par projet, disponibilité et expériences clés. Comptez 10 minutes."],
        ["Validation par notre équipe","Nous vérifions vos références et votre positionnement. Réponse sous 5 jours ouvrés."],
        ["Recevez vos premières missions","Dès qu'un projet correspond à votre profil, vous recevez une proposition détaillée."],
        ["Livrez et développez votre score","Chaque mission bien menée améliore votre classement et la qualité des propositions."],
      ];
      foreach ($st as $i => $s): ?>
        <div class="card flex-center" style="gap:18px">
          <span class="avatar dark"><?= $i+1 ?></span>
          <div><h4><?= e($s[0]) ?></h4><p class="small mt-1"><?= e($s[1]) ?></p></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section-sm">
  <div class="container">
    <div class="cta-band">
      <h2>Rejoignez le réseau Missiona</h2>
      <p>Candidature gratuite. Vous ne payez aucun frais d'inscription, abonnement ni frais fixe : notre rémunération se limite à une commission d'apport d'affaires, facturée après le règlement de votre client ou prélevée une seule fois comme frais d'application pour un paiement carte Stripe.</p>
      <div class="cta-actions">
        <a href="/inscription?role=freelance" class="btn btn-white btn-lg">Créer mon profil freelance</a>
      </div>
    </div>
  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
