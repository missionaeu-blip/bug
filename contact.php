<?php
require_once __DIR__ . '/includes/functions.php';

$erreur = '';
$envoye = isset($_GET['ok']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $nom     = trim($_POST['nom'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $societe = trim($_POST['societe'] ?? '');
    $sujet   = trim($_POST['sujet'] ?? '');
    $message = trim($_POST['message'] ?? '');

    // Piège à robots : un champ invisible que seul un automate remplit
    $piege = trim($_POST['site_web'] ?? '');

    if ($nom === '' || $email === '' || $message === '') {
        $erreur = "Merci de renseigner votre nom, votre e-mail et votre message.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erreur = "Cette adresse e-mail ne semble pas valide.";
    } elseif (mb_strlen($message) < 20) {
        $erreur = "Décrivez votre demande en quelques phrases (20 caractères minimum).";
    } elseif ($piege !== '') {
        // Robot détecté : on simule le succès sans rien enregistrer
        header('Location: /contact?ok=1'); exit;
    } else {
        db()->prepare("INSERT INTO contacts (nom,email,societe,sujet,message) VALUES (?,?,?,?,?)")
            ->execute([$nom, $email, $societe, $sujet, $message]);
        $admin = function_exists('admin_id') ? admin_id() : 1;
        notify($admin, "Nouveau message de contact : " . $nom . ($societe ? " ($societe)" : ""), '/admin');
        header('Location: /contact?ok=1'); exit;
    }
}

$titre = "Contact";
$page  = 'contact';
require_once __DIR__ . '/includes/header.php';
?>

<nav class="container" aria-label="Fil d'Ariane" style="padding:18px 0 0">
  <ol class="breadcrumb">
    <li><a href="/">Accueil</a></li>
    <li aria-current="page">Contact</li>
  </ol>
</nav>

<section class="hero" style="padding:48px 0 40px">
  <div class="container-sm text-center">
    <span class="eyebrow">Contact</span>
    <h1 class="mt-2">Parlons de votre projet</h1>
    <p class="lead mt-3" style="margin:16px auto 0">
      Décrivez votre besoin en quelques lignes : notre équipe revient vers vous sous
      48 heures ouvrées avec une première estimation de budget et de délai. Sans engagement.
    </p>
  </div>
</section>

<section class="section bg-white">
  <div class="container-sm">
    <?php if ($envoye): ?>
      <div class="alert alert-success" style="margin-bottom:24px">
        <strong>Message bien reçu.</strong> Notre équipe vous répond sous 48 heures ouvrées
        à l'adresse que vous avez indiquée.
      </div>
    <?php endif; ?>
    <?php if ($erreur): ?>
      <div class="alert alert-error" style="margin-bottom:24px"><?= e($erreur) ?></div>
    <?php endif; ?>

    <div class="grid grid-2" style="gap:32px;align-items:start">
      <form method="post" class="card">
        <?= csrf_field() ?>
        <div class="field">
          <label for="nom">Votre nom <span aria-hidden="true">*</span></label>
          <input type="text" name="nom" id="nom" class="input" required
                 value="<?= e($_POST['nom'] ?? '') ?>" autocomplete="name">
        </div>
        <div class="field">
          <label for="email">E-mail professionnel <span aria-hidden="true">*</span></label>
          <input type="email" name="email" id="email" class="input" required
                 value="<?= e($_POST['email'] ?? '') ?>" autocomplete="email">
        </div>
        <div class="field">
          <label for="societe">Société</label>
          <input type="text" name="societe" id="societe" class="input"
                 value="<?= e($_POST['societe'] ?? '') ?>" autocomplete="organization">
        </div>
        <div class="field">
          <label for="sujet">Sujet</label>
          <select name="sujet" id="sujet" class="select">
            <option value="Nouveau projet">J'ai un projet à confier</option>
            <option value="Devis">Demande de devis</option>
            <option value="Freelance">Rejoindre le réseau d'experts</option>
            <option value="Partenariat">Partenariat</option>
            <option value="Autre">Autre demande</option>
          </select>
        </div>
        <div class="field">
          <label for="message">Votre message <span aria-hidden="true">*</span></label>
          <textarea name="message" id="message" class="input" rows="6" required
                    placeholder="Décrivez votre besoin, le contexte et si possible votre échéance."><?= e($_POST['message'] ?? '') ?></textarea>
        </div>

        <div aria-hidden="true" style="position:absolute;left:-9999px">
          <label for="site_web">Ne pas remplir</label>
          <input type="text" name="site_web" id="site_web" tabindex="-1" autocomplete="off">
        </div>

        <button type="submit" class="btn btn-primary btn-lg">Envoyer le message</button>
        <p class="small muted" style="margin-top:12px">
          Vos informations servent uniquement à traiter votre demande.
          Voir notre <a href="/politique-confidentialite">politique de confidentialité</a>.
        </p>
      </form>

      <aside>
        <div class="card mb-2">
          <h2 style="font-size:1.0625rem;margin-bottom:10px">Par e-mail</h2>
          <p class="muted" style="margin:0">
            <a href="mailto:<?= e(SITE_EMAIL) ?>"><?= e(SITE_EMAIL) ?></a>
          </p>
        </div>
        <div class="card mb-2">
          <h2 style="font-size:1.0625rem;margin-bottom:10px">Délai de réponse</h2>
          <p class="muted" style="margin:0">
            48 heures ouvrées pour toute demande de projet. Les demandes déposées
            le week-end sont traitées le lundi.
          </p>
        </div>
        <div class="card">
          <h2 style="font-size:1.0625rem;margin-bottom:10px">Vous avez déjà un projet en cours ?</h2>
          <p class="muted" style="margin:0 0 12px">
            Passez par votre espace : votre gestionnaire vous répond directement dans la messagerie.
          </p>
          <a href="/connexion" class="btn btn-ghost btn-sm">Accéder à mon espace</a>
        </div>
      </aside>
    </div>
  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
