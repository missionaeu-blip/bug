<?php
$titre = "Pour les entreprises";
$page = 'entreprises';
require_once __DIR__ . '/includes/header.php';
?>

<section class="hero" style="padding:72px 0 64px">
  <div class="container-sm text-center">
    <span class="eyebrow">Offre entreprise</span>
    <h1 class="mt-2">Confiez le projet.<br>Récupérez le résultat.</h1>
    <p class="lead mt-3" style="margin:16px auto 0">
      Vous n'avez ni le temps ni la vocation de recruter, trier et manager des prestataires.
      Missiona prend l'intégralité de la chaîne en charge.
    </p>
    <div class="hero-actions" style="justify-content:center">
      <a href="/inscription?role=entreprise" class="btn btn-primary btn-lg">Créer mon compte entreprise</a>
      <a href="#processus" class="btn btn-ghost btn-lg">Voir le processus</a>
    </div>
  </div>
</section>

<section class="section bg-white">
  <div class="container">
    <div class="section-head">
      <h2>Ce que nous prenons en charge</h2>
      <p>Tout ce qui se trouve entre votre besoin et votre livrable.</p>
    </div>
    <div class="grid grid-3">
      <?php
      $items = [
        ['📝','Cadrage du besoin',"Un chargé de compte reformule votre demande en un cahier des charges exploitable, avec critères de réussite mesurables."],
        ['🔍','Sélection de l\'expert',"Scoring objectif sur compétences, budget, disponibilité, expérience et historique de notation. Nous engageons le meilleur profil."],
        ['📅','Planification',"Jalons, échéances et livrables intermédiaires définis dès le départ et suivis dans votre tableau de bord."],
        ['🔄','Pilotage quotidien',"Nous animons la mission, débloquons les points durs et vous tenons informé sans que vous ayez à relancer."],
        ['✅','Contrôle qualité',"Chaque livrable est revu par notre équipe avant de vous être transmis pour recette."],
        ['🧾','Un seul interlocuteur',"Un interlocuteur unique du début à la fin du projet, quel que soit le nombre d'experts mobilisés. Chaque intervenant vous facture directement sa prestation."],
      ];
      foreach ($items as $i): ?>
        <div class="card card-hover">
          <div class="card-icon"><?= $i[0] ?></div>
          <h3><?= $i[1] ?></h3>
          <p><?= $i[2] ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section" id="processus">
  <div class="container-sm">
    <div class="section-head"><h2>Comment se déroule un projet</h2></div>
    <div class="panel">
      <div class="panel-body">
        <div class="timeline">
          <?php
          $tl = [
            ["Jour 0 — Vous décrivez le projet","Formulaire guidé en 5 étapes : contexte, périmètre, compétences, budget et délai.","done"],
            ["Jour 1 — Cadrage avec votre chargé de compte","Un échange écrit dans la messagerie pour valider le périmètre et lever les zones d'ombre.","done"],
            ["Jour 2 — Proposition d'expert et devis","Nous vous présentons le profil retenu, le score de matching, le planning et le budget ferme.","current"],
            ["Semaine 1 — Lancement","Contractualisation, réunion de démarrage et ouverture du suivi dans votre tableau de bord.",""],
            ["Pendant la mission — Suivi","Avancement mis à jour, livrables intermédiaires et point hebdomadaire.",""],
            ["Fin de mission — Livraison et recette","Contrôle qualité, transmission des livrables, validation puis facturation.",""],
          ];
          foreach ($tl as $t): ?>
            <div class="tl-item <?= $t[2] ?>">
              <h4><?= e($t[0]) ?></h4>
              <p><?= e($t[1]) ?></p>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="section bg-white">
  <div class="container">
    <div class="section-head"><h2>Ce que vous y gagnez</h2></div>
    <div class="grid grid-4">
      <div class="stat"><div class="lbl">Recherche de prestataire</div><div class="val">0</div><div class="sub">nous nous en chargeons</div></div>
      <div class="stat"><div class="lbl">Délai de proposition</div><div class="val">48 h</div><div class="sub">profil + devis</div></div>
      <div class="stat"><div class="lbl">Interlocuteurs</div><div class="val">1</div><div class="sub">quel que soit le projet</div></div>
      <div class="stat accent"><div class="lbl">Projets pilotés</div><div class="val">100 %</div><div class="sub">aucun projet laissé seul</div></div>
    </div>
  </div>
</section>

<section class="section-sm">
  <div class="container">
    <div class="cta-band">
      <h2>Décrivez votre projet dès maintenant</h2>
      <p>Gratuit et sans engagement. Vous recevez une proposition argumentée sous 48 heures.</p>
      <div class="cta-actions">
        <a href="/inscription?role=entreprise" class="btn btn-white btn-lg">Créer mon compte entreprise</a>
      </div>
    </div>
  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
