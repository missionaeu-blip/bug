<?php
$titre = "Tarifs";
$page  = 'tarifs';
require_once __DIR__ . '/includes/header.php';
// Valeurs réellement appliquées, et non les constantes de configuration :
// annoncer 20 % alors que le réglage est à 0 serait trompeur.
$com = (int) round(taux_frais());
?>

<nav class="container" aria-label="Fil d'Ariane" style="padding:18px 0 0">
  <ol class="breadcrumb">
    <li><a href="/">Accueil</a></li>
    <li aria-current="page">Tarifs</li>
  </ol>
</nav>

<section class="hero" style="padding:48px 0 56px">
  <div class="container-sm text-center">
    <span class="eyebrow">Tarification</span>
    <h1 class="mt-2">Un prix clair avant chaque règlement</h1>
    <p class="lead mt-3" style="margin:16px auto 0">
      Déposer un projet et recevoir une estimation est gratuit, sans engagement.
      Hors paiement carte, vous réglez l'expert <strong>directement</strong>, du
      montant convenu avec lui. Notre rémunération — une commission d'apport
      d'affaires<?php if ($com > 0): ?> de <?= $com ?> %<?php endif; ?> — est alors
      facturée séparément à l'expert. Avec le paiement carte Stripe, le total
      affiché <strong>avant confirmation</strong> détaille la rémunération de l'expert
      et la commission Missiona : aucun montant n'est ajouté ensuite. Aucun frais
      d'inscription, aucun abonnement, aucun frais fixe.
    </p>
  </div>
</section>

<section class="section bg-white">
  <div class="container">
    <div class="section-head">
      <h2>Qui paie quoi, étape par étape</h2>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr><th>Étape</th><th>Pour l'entreprise cliente</th><th>Pour l'expert</th></tr>
        </thead>
        <tbody>
          <tr>
            <td><strong>Dépôt du projet et estimation</strong></td>
            <td>Gratuit — réponse sous 48 h ouvrées, sans engagement</td>
            <td>Candidature gratuite, sans abonnement</td>
          </tr>
          <tr>
            <td><strong>Mission attribuée</strong></td>
            <td>Hors carte : rien à payer à Missiona. Par carte : le détail du total est affiché avant validation.</td>
            <td>Aucun frais fixe</td>
          </tr>
          <tr>
            <td><strong>Règlement du projet</strong></td>
            <td>Hors carte : le devis de l'expert, réglé directement. Par carte : total affiché = rémunération de l'expert + commission Missiona.</td>
            <td>Hors carte : commission d'apport d'affaires<?= $com > 0 ? ' de ' . $com . ' %' : '' ?>, facturée après le règlement du client. Par carte : commission prélevée une fois par Stripe.</td>
          </tr>
          <tr>
            <td><strong>Programme récurrent</strong></td>
            <td colspan="2">Sur devis — volume régulier, équipe dédiée, engagement de service</td>
          </tr>
        </tbody>
      </table>
    </div>
    <p class="small muted" style="margin-top:14px">
      Aucun abonnement ni frais de dossier. Le règlement par carte affiche le
      détail complet avant confirmation ; hors carte, la commission est facturée
      à l'expert une fois le client réglé.
    </p>
  </div>
</section>

<section class="section">
  <div class="container-sm">
    <div class="section-head">
      <h2>Comment se construit le prix d'un projet</h2>
      <p>
        Le montant dépend de trois facteurs, évalués pendant le cadrage : l'ampleur
        fonctionnelle du besoin, le niveau d'expertise requis et le délai souhaité.
        Un projet urgent mobilise un profil disponible immédiatement, ce qui pèse
        sur le budget.
      </p>
    </div>
    <div class="card">
      <h3 style="font-size:1.0625rem;margin-bottom:10px">Pourquoi nous n'affichons pas de grille de prix fixe</h3>
      <p class="muted" style="margin:0">
        Annoncer « un site à partir de X € » n'aurait pas de sens : deux sites vitrines
        peuvent varier du simple au quintuple selon le nombre de pages, les intégrations
        à prévoir et la reprise éventuelle de l'existant. Nous préférons chiffrer votre
        besoin réel sous 48 heures plutôt que d'afficher un prix d'appel qui ne sera pas tenu.
      </p>
    </div>
  </div>
</section>

<section class="section bg-white">
  <div class="container-sm text-center">
    <h2>Obtenir une estimation pour votre projet</h2>
    <p class="muted" style="margin:12px 0 24px">
      Décrivez votre besoin en quelques lignes. Réponse sous 48 heures ouvrées, sans engagement.
    </p>
    <a href="/inscription?role=entreprise" class="btn btn-primary btn-lg">Déposer mon projet</a>
    <a href="/faq" class="btn btn-ghost btn-lg">Lire la FAQ</a>
  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
