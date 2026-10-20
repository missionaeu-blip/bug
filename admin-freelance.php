<?php
/** Fiche interne détaillée d'un freelance, réservée à l'administration. */
require_once __DIR__ . '/includes/functions.php';
require_role('admin');

$id = (int) ($_GET['id'] ?? 0);
$st = db()->prepare("SELECT * FROM users WHERE id = ? AND role = 'freelance'");
$st->execute([$id]);
$freelance = $st->fetch();
if (!$freelance) {
    flash('Expert introuvable.', 'error');
    header('Location: ' . u('/admin-freelances'));
    exit;
}

$forfaits = forfaits_de($id);
$portfolio = portfolio_de($id);
$competences = array_values(array_filter(array_map('trim', explode(',', (string) $freelance['competences']))));
$st = db()->prepare("SELECT
  COUNT(*) AS total,
  SUM(CASE WHEN statut = 'termine' THEN 1 ELSE 0 END) AS terminees,
  SUM(CASE WHEN statut IN ('attribue','en_cours','livraison') THEN 1 ELSE 0 END) AS en_cours
  FROM projets WHERE freelance_id = ?");
$st->execute([$id]);
$missions = $st->fetch() ?: ['total' => 0, 'terminees' => 0, 'en_cours' => 0];
$prix_reference = $forfaits ? (int) $forfaits[0]['prix'] : (int) $freelance['tarif_projet'];

$titre = trim($freelance['prenom'] . ' ' . $freelance['nom']) . ' — Expert';
require_once __DIR__ . '/includes/header.php';
?>
<div class="app">
<?php require __DIR__ . '/includes/sidebar.php'; ?>
<main class="main">
  <div class="page-head">
    <div>
      <p class="small muted mb-1"><a href="/admin-freelances" style="color:var(--blue)">← Répertoire des experts</a></p>
      <h1><?= e(trim($freelance['prenom'] . ' ' . $freelance['nom'])) ?></h1>
      <p><?= e($freelance['titre_pro'] ?: 'Expert Missiona') ?></p>
    </div>
    <div class="flex gap-1 wrap">
      <span class="badge <?= $freelance['actif'] ? 'badge-green' : 'badge-red' ?>"><?= $freelance['actif'] ? 'Compte actif' : 'Compte suspendu' ?></span>
      <a href="/admin-utilisateurs?r=freelance" class="btn btn-ghost btn-sm">Gérer le compte</a>
    </div>
  </div>

  <div class="admin-expert-layout">
    <div>
      <section class="panel mb-3">
        <div class="panel-body admin-expert-intro">
          <span class="avatar admin-expert-avatar"><?= initiales($freelance) ?></span>
          <div>
            <h2><?= e(trim($freelance['prenom'] . ' ' . $freelance['nom'])) ?></h2>
            <p class="muted mb-1"><?= e($freelance['email']) ?><?= $freelance['telephone'] ? ' · ' . e($freelance['telephone']) : '' ?></p>
            <?php if ($freelance['siret']): ?><p class="small muted mb-0">SIRET&nbsp;: <?= e($freelance['siret']) ?></p><?php endif; ?>
          </div>
        </div>
        <?php if ($freelance['bio']): ?>
          <div class="panel-body" style="padding-top:0">
            <h3 class="admin-expert-section-title">Présentation</h3>
            <p class="admin-expert-bio"><?= nl2br(e($freelance['bio'])) ?></p>
          </div>
        <?php endif; ?>
        <div class="panel-body" style="padding-top:0">
          <h3 class="admin-expert-section-title">Compétences</h3>
          <?php if ($competences): ?>
            <div class="crit expert-skills expert-skills-full">
              <?php foreach ($competences as $competence): ?><span><?= e($competence) ?></span><?php endforeach; ?>
            </div>
          <?php else: ?><p class="small muted mb-0">Aucune compétence renseignée.</p><?php endif; ?>
        </div>
      </section>

      <section class="panel admin-expert-portfolio">
        <div class="panel-head">
          <h3>Portfolio</h3>
          <span class="badge badge-blue"><?= count($portfolio) ?> réalisation<?= count($portfolio) > 1 ? 's' : '' ?></span>
        </div>
        <div class="panel-body">
          <?php if (!$portfolio): ?>
            <div class="empty" style="padding:30px"><div class="ico">🖼️</div><h3>Portfolio non renseigné</h3><p>Cet expert n’a pas encore ajouté de réalisation.</p></div>
          <?php else: ?>
            <div class="pf-grid admin-expert-portfolio-grid">
              <?php foreach ($portfolio as $realisation): ?>
                <figure class="pf-card">
                  <img src="<?= e(portfolio_image_url($realisation['id'])) ?>" alt="<?= e($realisation['titre'] ?: 'Réalisation du portfolio') ?>" loading="lazy">
                  <figcaption>
                    <strong><?= e($realisation['titre'] ?: 'Réalisation') ?></strong>
                    <?php if ($realisation['description']): ?><span><?= nl2br(e($realisation['description'])) ?></span><?php endif; ?>
                  </figcaption>
                </figure>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </section>
    </div>

    <aside class="admin-expert-side">
      <section class="panel">
        <div class="panel-head"><h3>Prix & disponibilité</h3></div>
        <div class="panel-body">
          <div class="admin-expert-price">
            <span>Référence actuelle</span>
            <strong><?= $prix_reference > 0 ? euros($prix_reference) : 'À définir' ?></strong>
            <small><?= $forfaits ? 'forfait le moins cher' : 'tarif indiqué par projet' ?></small>
          </div>
          <div class="recap admin-expert-recap">
            <div class="recap-row"><span>Disponibilité</span><strong><?= e(ucfirst((string) $freelance['disponibilite'])) ?></strong></div>
            <div class="recap-row"><span>Expérience</span><strong><?= (int) $freelance['experience'] ?> ans</strong></div>
          </div>
        </div>
      </section>

      <section class="panel">
        <div class="panel-head"><h3>Forfaits</h3></div>
        <div class="panel-body">
          <?php if (!$forfaits): ?>
            <p class="small muted mb-0">Aucun forfait renseigné. Le tarif par projet sert de référence.</p>
          <?php else: ?>
            <div class="admin-expert-offers">
              <?php foreach ($forfaits as $forfait): ?>
                <div>
                  <div class="flex-between gap-1"><strong><?= e($forfait['nom']) ?></strong><strong><?= euros($forfait['prix']) ?></strong></div>
                  <?php if ($forfait['delai']): ?><small><?= (int) $forfait['delai'] ?> jour<?= (int) $forfait['delai'] > 1 ? 's' : '' ?></small><?php endif; ?>
                  <?php if ($forfait['description']): ?><p><?= e($forfait['description']) ?></p><?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </section>

      <section class="panel">
        <div class="panel-head"><h3>Activité Missiona</h3></div>
        <div class="panel-body">
          <div class="admin-expert-score"><span class="stars"><?= str_repeat('★', (int) round((float) $freelance['note_moyenne'])) ?></span><strong><?= number_format((float) $freelance['note_moyenne'], 1, ',', '') ?>/5</strong><small><?= (int) $freelance['nb_missions'] ?> mission<?= (int) $freelance['nb_missions'] > 1 ? 's' : '' ?> évaluée<?= (int) $freelance['nb_missions'] > 1 ? 's' : '' ?></small></div>
          <div class="recap admin-expert-recap">
            <div class="recap-row"><span>Missions confiées</span><strong><?= (int) $missions['total'] ?></strong></div>
            <div class="recap-row"><span>En cours</span><strong><?= (int) $missions['en_cours'] ?></strong></div>
            <div class="recap-row"><span>Terminées</span><strong><?= (int) $missions['terminees'] ?></strong></div>
            <div class="recap-row"><span>Inscrit le</span><strong><?= date_fr($freelance['created_at']) ?></strong></div>
          </div>
        </div>
      </section>
    </aside>
  </div>
</main>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
