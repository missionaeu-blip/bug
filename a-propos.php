<?php
$titre = "Qui sommes-nous";
$page  = 'a-propos';
require_once __DIR__ . '/includes/header.php';
$com   = defined('COMMISSION_DEFAUT') ? (int) COMMISSION_DEFAUT : 20;
?>

<nav class="container" aria-label="Fil d'Ariane" style="padding:18px 0 0">
  <ol class="breadcrumb">
    <li><a href="/">Accueil</a></li>
    <li aria-current="page">Qui sommes-nous</li>
  </ol>
</nav>

<section class="hero" style="padding:48px 0 56px">
  <div class="container-sm text-center">
    <span class="eyebrow">À propos</span>
    <h1 class="mt-2">Un partenaire d'exécution, pas un annuaire</h1>
    <p class="lead mt-3" style="margin:16px auto 0">
      Missiona est une plateforme française de délégation de projets digitaux.
      Nous ne vous mettons pas en relation avec une liste de freelances : nous
      sélectionnons l'expert, pilotons la mission et portons la responsabilité du
      livrable devant vous.
    </p>
  </div>
</section>

<section class="section bg-white">
  <div class="container-sm">
    <h2>Pourquoi nous avons créé Missiona</h2>
    <p>
      Les places de marché classiques ont déplacé le problème plutôt que de le résoudre.
      Elles donnent accès à des milliers de profils, mais laissent à l'entreprise la
      charge la plus lourde : rédiger le besoin, trier les candidatures, évaluer des
      compétences qu'elle ne maîtrise pas toujours, puis manager un prestataire externe
      en plus de son propre travail. Pour une PME sans direction technique, ce coût
      caché dépasse souvent le prix de la prestation.
    </p>
    <p>
      Nous avons pris le problème par l'autre bout. Vous décrivez le résultat attendu,
      nous nous occupons de tout le reste. C'est un engagement plus exigeant pour nous,
      mais c'est le seul qui a du sens quand on n'a pas d'équipe technique en interne.
    </p>

    <hr class="sep">

    <h2>Comment nous sélectionnons les experts</h2>
    <p>
      Chaque freelance qui rejoint la plateforme est examiné par notre équipe avant
      de recevoir la moindre mission. Nous nous appuyons sur des critères mesurables
      plutôt que sur une impression :
    </p>
    <ul class="list-check">
      <li><strong>Compétences déclarées et vérifiées</strong> au regard des réalisations présentées</li>
      <li><strong>Expérience effective</strong> dans le domaine concerné, pas une liste de mots-clés</li>
      <li><strong>Cohérence tarifaire</strong> entre le niveau annoncé et le marché</li>
      <li><strong>Historique des missions</strong> réalisées via la plateforme : respect des délais et qualité des livrables</li>
    </ul>
    <p>
      Ce dernier point est déterminant dans la durée. Un expert qui ne tient pas ses
      engagements ne se voit plus attribuer de mission — c'est ce qui nous permet de
      nous engager sur un résultat.
    </p>

    <hr class="sep">

    <h2>Nos engagements</h2>
    <div class="grid grid-2" style="gap:20px;margin-top:16px">
      <div class="card">
        <h3 style="font-size:1rem;margin-bottom:6px">Un interlocuteur unique</h3>
        <p class="muted" style="margin:0">
          Un gestionnaire de projet dédié suit votre dossier du cadrage à la livraison.
          Vous ne gérez jamais plusieurs prestataires en parallèle.
        </p>
      </div>
      <div class="card">
        <h3 style="font-size:1rem;margin-bottom:6px">Un budget connu d'avance</h3>
        <p class="muted" style="margin:0">
          Notre part de <?= $com ?> % est incluse dans le devis de l'expert. Aucun
          supplément n'apparaît en cours de projet.
        </p>
      </div>
      <div class="card">
        <h3 style="font-size:1rem;margin-bottom:6px">Une confidentialité réelle</h3>
        <p class="muted" style="margin:0">
          Entreprises et freelances n'échangent pas directement par défaut. Les données
          de votre projet restent limitées aux personnes qui y travaillent.
        </p>
      </div>
      <div class="card">
        <h3 style="font-size:1rem;margin-bottom:6px">Une sortie possible</h3>
        <p class="muted" style="margin:0">
          Vous pouvez demander l'annulation depuis votre espace. Si elle est acceptée,
          les sommes versées vous sont remboursées.
        </p>
      </div>
    </div>

    <hr class="sep">

    <h2>Transparence sur notre modèle</h2>
    <p>
      Nous nous rémunérons auprès des experts, et non des entreprises clientes :
      une part de <?= $com ?> % incluse dans le montant du projet. Nous ne vendons pas de
      mise en avant payante aux
      freelances, et le classement des experts ne dépend d'aucune contribution
      financière de leur part : il repose sur l'adéquation au besoin et sur leur
      historique. C'est ce qui garantit que l'expert proposé est celui qui convient
      à votre projet, et non celui qui a le plus payé.
    </p>
  </div>
</section>

<section class="section">
  <div class="container-sm text-center">
    <h2>Discutons de votre projet</h2>
    <p class="muted" style="margin:12px 0 24px">Réponse sous 48 heures ouvrées, sans engagement.</p>
    <a href="/contact" class="btn btn-primary btn-lg">Nous contacter</a>
    <a href="/services" class="btn btn-ghost btn-lg">Voir nos expertises</a>
  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
