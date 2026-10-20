<?php
/** Répertoire interne des experts : lecture réservée à l'administration. */
require_once __DIR__ . '/includes/functions.php';
require_role('admin');

$q = trim((string) ($_GET['q'] ?? ''));
$tri = (string) ($_GET['tri'] ?? 'recent');
$tris = [
    'recent' => 'f.created_at DESC, f.id DESC',
    'note'   => 'f.note_moyenne DESC, f.nb_missions DESC, f.nom, f.prenom',
    'prix'   => "CASE WHEN f.tarif_projet > 0 THEN 0 ELSE 1 END, f.tarif_projet ASC, f.nom, f.prenom",
    'nom'    => 'f.nom, f.prenom',
];
if (!isset($tris[$tri])) { $tri = 'recent'; }

$where = "f.role = 'freelance'";
$args = [];
if ($q !== '') {
    $where .= " AND (f.prenom LIKE ? OR f.nom LIKE ? OR f.email LIKE ? OR f.titre_pro LIKE ? OR f.competences LIKE ?)";
    $like = '%' . $q . '%';
    $args = [$like, $like, $like, $like, $like];
}

$st = db()->prepare("SELECT f.* FROM users f WHERE $where ORDER BY " . $tris[$tri]);
$st->execute($args);
$freelances = $st->fetchAll();

/* Chargement groupé : le répertoire reste fluide, même avec de nombreux experts. */
$portfolios = [];
$forfaits = [];
if ($freelances) {
    $ids = array_map(static fn($f) => (int) $f['id'], $freelances);
    $marqueurs = implode(',', array_fill(0, count($ids), '?'));

    $st = db()->prepare("SELECT * FROM portfolio WHERE user_id IN ($marqueurs) ORDER BY user_id, id DESC");
    $st->execute($ids);
    foreach ($st->fetchAll() as $realisation) {
        $portfolios[(int) $realisation['user_id']][] = $realisation;
    }

    $st = db()->prepare("SELECT * FROM forfaits WHERE user_id IN ($marqueurs) ORDER BY user_id, prix, id");
    $st->execute($ids);
    foreach ($st->fetchAll() as $forfait) {
        $forfaits[(int) $forfait['user_id']][] = $forfait;
    }
}

$total = count($freelances);
$actifs = count(array_filter($freelances, static fn($f) => !empty($f['actif'])));
$avec_portfolio = count(array_filter($freelances, static fn($f) => !empty($portfolios[(int) $f['id']])));

$titre = 'Répertoire des experts';
require_once __DIR__ . '/includes/header.php';
?>
<div class="app">
<?php require __DIR__ . '/includes/sidebar.php'; ?>
<main class="main">
  <div class="page-head">
    <div>
      <p class="small muted mb-1"><a href="/admin" style="color:var(--blue)">← Retour au back-office</a></p>
      <h1>Répertoire des experts</h1>
      <p>Consultez les profils, prix, compétences et réalisations des freelances.</p>
    </div>
    <a href="/admin-utilisateurs?r=freelance" class="btn btn-ghost">👥 Gérer les comptes</a>
  </div>

  <div class="grid grid-3 mb-3 admin-expert-stats">
    <div class="stat"><div class="lbl">Freelances affichés</div><div class="val"><?= $total ?></div><div class="sub">selon les filtres actifs</div></div>
    <div class="stat"><div class="lbl">Comptes actifs</div><div class="val" style="color:var(--green)"><?= $actifs ?></div><div class="sub">disponibles dans le réseau</div></div>
    <div class="stat"><div class="lbl">Avec portfolio</div><div class="val" style="color:var(--blue)"><?= $avec_portfolio ?></div><div class="sub">réalisations consultables</div></div>
  </div>

  <form class="admin-expert-filters panel mb-3" method="get">
    <div class="field">
      <label for="expert-q">Rechercher un expert</label>
      <input class="input" id="expert-q" name="q" value="<?= e($q) ?>" placeholder="Nom, métier, compétence…">
    </div>
    <div class="field">
      <label for="expert-tri">Trier par</label>
      <select class="input" id="expert-tri" name="tri">
        <option value="recent" <?= $tri === 'recent' ? 'selected' : '' ?>>Plus récents</option>
        <option value="note" <?= $tri === 'note' ? 'selected' : '' ?>>Meilleure note</option>
        <option value="prix" <?= $tri === 'prix' ? 'selected' : '' ?>>Tarif de base</option>
        <option value="nom" <?= $tri === 'nom' ? 'selected' : '' ?>>Nom</option>
      </select>
    </div>
    <button class="btn btn-primary" type="submit">Filtrer</button>
    <?php if ($q !== '' || $tri !== 'recent'): ?><a href="/admin-freelances" class="btn btn-ghost">Réinitialiser</a><?php endif; ?>
  </form>

  <?php if (!$freelances): ?>
    <div class="empty panel" style="padding:48px"><div class="ico">🧑‍💻</div><h3>Aucun freelance trouvé</h3><p>Modifiez votre recherche ou attendez la prochaine inscription d’un expert.</p></div>
  <?php else: ?>
    <div class="expert-directory-grid">
      <?php foreach ($freelances as $f):
        $id = (int) $f['id'];
        $pf = $portfolios[$id] ?? [];
        $packs = $forfaits[$id] ?? [];
        $prix = $packs ? (int) $packs[0]['prix'] : (int) $f['tarif_projet'];
        $competences = array_values(array_filter(array_map('trim', explode(',', (string) $f['competences']))));
      ?>
        <article class="expert-directory-card <?= $f['actif'] ? '' : 'is-suspended' ?>">
          <div class="expert-card-head">
            <span class="avatar expert-avatar"><?= initiales($f) ?></span>
            <div>
              <h2><?= e(trim($f['prenom'] . ' ' . $f['nom'])) ?></h2>
              <p><?= e($f['titre_pro'] ?: 'Expert Missiona') ?></p>
            </div>
            <span class="badge <?= $f['actif'] ? 'badge-green' : 'badge-red' ?>"><?= $f['actif'] ? 'Actif' : 'Suspendu' ?></span>
          </div>

          <div class="expert-rate">
            <span><?= $packs ? 'À partir de' : 'Tarif par projet' ?></span>
            <strong><?= $prix > 0 ? euros($prix) : 'À définir' ?></strong>
            <?php if ($packs): ?><small><?= count($packs) ?> forfait<?= count($packs) > 1 ? 's' : '' ?> proposé<?= count($packs) > 1 ? 's' : '' ?></small><?php endif; ?>
          </div>

          <div class="expert-card-meta">
            <span><strong><?= (int) $f['experience'] ?></strong> ans d’expérience</span>
            <span><strong><?= ucfirst((string) $f['disponibilite']) ?></strong></span>
            <span><strong><?= number_format((float) $f['note_moyenne'], 1, ',', '') ?>/5</strong> · <?= (int) $f['nb_missions'] ?> missions</span>
          </div>

          <?php if ($competences): ?>
            <div class="crit expert-skills" aria-label="Compétences">
              <?php foreach (array_slice($competences, 0, 5) as $competence): ?><span><?= e($competence) ?></span><?php endforeach; ?>
              <?php if (count($competences) > 5): ?><span>+<?= count($competences) - 5 ?></span><?php endif; ?>
            </div>
          <?php endif; ?>

          <?php if ($pf): ?>
            <div class="expert-portfolio-preview" aria-label="Aperçu du portfolio">
              <?php foreach (array_slice($pf, 0, 3) as $realisation): ?>
                <img src="<?= e(portfolio_image_url($realisation['id'])) ?>" alt="<?= e($realisation['titre'] ?: 'Réalisation du portfolio') ?>" loading="lazy">
              <?php endforeach; ?>
            </div>
            <p class="expert-portfolio-count">🖼️ <?= count($pf) ?> réalisation<?= count($pf) > 1 ? 's' : '' ?> à consulter</p>
          <?php else: ?>
            <p class="expert-portfolio-count muted">Portfolio non renseigné</p>
          <?php endif; ?>

          <a href="/admin-freelance?id=<?= $id ?>" class="btn btn-primary btn-block">Voir la fiche complète</a>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
