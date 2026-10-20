<?php
/**
 * Missiona — Versements (page conservée pour compatibilité d'URL)
 * ------------------------------------------------------------------
 * Depuis le passage au « paiement direct », aucun fonds ne transite
 * par Missiona : le client règle l'expert directement. Il n'y a donc
 * plus de reversements à traiter côté plateforme.
 */
require_once __DIR__ . '/includes/functions.php';
if (!is_logged()) { header('Location: ' . u('/connexion')); exit; }
$u = user();
if ($u['role'] !== 'admin') { header('Location: ' . dashboard_url(null, true)); exit; }

$titre = "Versements";
require_once __DIR__ . '/includes/header.php';
?>
<div class="app">
<?php require __DIR__ . '/includes/sidebar.php'; ?>
<main class="main">
  <div class="page-head">
    <div><h1>Versements</h1><p>Modèle « paiement direct »</p></div>
  </div>
  <div class="panel">
    <div class="panel-body" style="text-align:center;padding:48px 24px">
      <div style="font-size:2.5rem">🤝</div>
      <h3 class="mt-2">Aucun fonds ne transite par Missiona</h3>
      <p class="muted" style="max-width:560px;margin:10px auto 0">
        Le client règle l'expert directement, hors plateforme. Il n'y a donc
        ni reversement à effectuer, ni solde à surveiller. La rémunération de
        Missiona (commission d'apport d'affaires) est facturée à l'expert
        et suivie dans <a href="<?= u('/factures') ?>">Facturation</a>.
      </p>
    </div>
  </div>
</main>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
