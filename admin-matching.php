<?php
require_once __DIR__ . '/includes/functions.php';
require_role('admin');

$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare("SELECT p.*, e.societe, e.nom AS e_nom FROM projets p LEFT JOIN users e ON e.id=p.entreprise_id WHERE p.id=?");
$st->execute([$id]);
$projet = $st->fetch();
if (!$projet) { header('Location: ' . u('/admin')); exit; }

// Attribution de la mission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!peut_gerer_projet($projet)) {
        flash(refus_gestion(), 'error');
        header('Location: ' . u('admin-matching.php?id=' . $id)); exit;
    }
    $fid = (int)($_POST['freelance_id'] ?? 0);
    if ($fid) {
        // Après attribution, l'échéance et éventuellement le PaymentIntent sont
        // liés à l'expert retenu. Une nouvelle affectation ne doit jamais les
        // remplacer silencieusement : annulez/régularisez d'abord la mission.
        if (!empty($projet['freelance_id'])) {
            flash("Cette mission est déjà attribuée. Annulez ou régularisez l'échéance avant de changer d'expert.", 'error');
            header('Location: ' . u('projet.php?id=' . $id)); exit;
        }
        $fs = db()->prepare("SELECT * FROM users WHERE id=? AND role='freelance'"); $fs->execute([$fid]); $fl = $fs->fetch();
        if (!$fl) {
            flash("Expert introuvable.", 'error');
            header('Location: ' . u('admin-matching.php?id=' . $id)); exit;
        }

        // Le tarif de l'expert est sa base. Lorsque Stripe Connect de test est
        // activé, le client règle le total (base + commission Missiona) sur le
        // compte connecté ; la commission devient une application fee. Les
        // échéances créées avant activation restent dans le parcours historique.
        $base = tarif_reference($fl) ?: (int) round(($projet['budget_min'] + $projet['budget_max']) / 2);
        db()->prepare("UPDATE projets SET freelance_id=?, statut='attribue', avancement=5, montant_final=? WHERE id=?")
            ->execute([$fid, $base, $id]);
        $direct_connect = stripe_actif();
        $montant = $direct_connect ? decomposer_montant($base)['total'] : $base;
        creer_paiement($id, 'projet', $montant, 'a_payer');
        if ($direct_connect) {
            db()->prepare("UPDATE paiements SET stripe_direct=1 WHERE projet_id=? AND type='projet' AND statut='a_payer'")
                ->execute([$id]);
        }

        notify($fid, "Nouvelle mission attribuée : « ".$projet['titre']." ».", 'projet.php?id='.$id);
        notify($projet['entreprise_id'], "Un expert a été sélectionné pour votre projet « ".$projet['titre']." ». Validez la proposition pour lancer la mission.", 'projet.php?id='.$id);
        db()->prepare("INSERT INTO messages (projet_id,expediteur_id,destinataire_id,contenu) VALUES (?,1,?,?)")
            ->execute([$id, $projet['entreprise_id'],
              "Bonne nouvelle : nous avons sélectionné l'expert pour votre projet « ".$projet['titre']." ». Validez la proposition depuis le détail du projet pour lancer la mission."]);
        db()->prepare("INSERT INTO messages (projet_id,expediteur_id,destinataire_id,contenu) VALUES (?,1,?,?)")
            ->execute([$id, $fid,
              "Bonjour ".($fl['prenom'] ?: $fl['nom']).", nous vous attribuons la mission « ".$projet['titre']." ». Le cahier des charges est disponible dans le détail du projet. Je reste votre interlocuteur unique."]);
        flash("Mission attribuée avec succès à ".trim($fl['prenom'].' '.$fl['nom']).".");
        header('Location: ' . u('projet.php?id=' . $id)); exit;
    }
}

$resultats = calculer_matching($projet);
$coefs = [
  'competences' => (int)param('coef_competences',40),
  'budget'      => (int)param('coef_budget',25),
  'dispo'       => (int)param('coef_dispo',15),
  'experience'  => (int)param('coef_experience',10),
  'note'        => (int)param('coef_note',10),
];
$labels = ['competences'=>'Compétences','budget'=>'Budget','dispo'=>'Disponibilité','experience'=>'Expérience','note'=>'Note'];

$titre = "Matching";
require_once __DIR__ . '/includes/header.php';
?>
<div class="app">
<?php require __DIR__ . '/includes/sidebar.php'; ?>
<main class="main">

  <div class="page-head">
    <div>
      <p class="small muted mb-1"><a href="/admin" style="color:var(--blue)">← Retour au back-office</a></p>
      <h1>Matching — <?= e($projet['titre']) ?></h1>
      <p>Classement des experts selon les critères mesurables configurés.</p>
    </div>
    <?php if (est_admin_principal()): ?><a href="/admin-parametres" class="btn btn-ghost">Ajuster les coefficients</a><?php endif; ?>
  </div>

  <div class="grid" style="grid-template-columns:1fr 320px;gap:24px;align-items:start">

    <div class="panel">
      <div class="panel-head">
        <h3>Experts classés</h3>
        <span class="badge badge-blue"><?= count($resultats) ?> profils analysés</span>
      </div>
      <div class="panel-body" style="padding:8px 22px">
        <?php foreach ($resultats as $i => $r): $f = $r['freelance']; $s = $r['score']; ?>
          <div class="match-row" style="align-items:flex-start;padding:20px 0">
            <div class="score <?= $s>=75?'high':($s>=50?'mid':'low') ?>"><?= $s ?>%</div>
            <div style="flex:1;min-width:0">
              <div class="flex-between wrap" style="gap:10px">
                <div>
                  <h4>
                    <?= e($f['prenom'].' '.$f['nom']) ?>
                    <?php if ($i===0): ?><span class="badge badge-green" style="margin-left:6px">Recommandé</span><?php endif; ?>
                  </h4>
                  <?php $ff = forfaits_de($f['id']); ?>
                  <p class="small muted"><?= e($f['titre_pro']) ?> · <?= $ff ? 'À partir de '.euros($ff[0]['prix']) : 'Tarif '.euros($f['tarif_projet']) ?> · <?= (int)$f['experience'] ?> ans ·
                    <span class="stars"><?= str_repeat('★',(int)round($f['note_moyenne'])) ?></span>
                    <?= number_format($f['note_moyenne'],1,',','') ?>/5
                  </p>
                  <?php if ($ff): ?>
                    <p class="small muted">
                      <?php foreach ($ff as $k=>$fo): ?><?= $k?' · ':'' ?><strong><?= e($fo['nom']) ?></strong> <?= euros($fo['prix']) ?><?php endforeach; ?>
                    </p>
                  <?php endif; ?>
                </div>
                <form method="post" style="margin:0">
                  <?= csrf_field() ?>
                  <input type="hidden" name="freelance_id" value="<?= $f['id'] ?>">
                  <button type="submit" class="btn <?= $i===0?'btn-primary':'btn-ghost' ?> btn-sm"
                          onclick="return confirm('Attribuer cette mission à <?= e($f['prenom'].' '.$f['nom']) ?> ?')">
                    Attribuer la mission
                  </button>
                </form>
              </div>

              <div class="crit">
                <?php foreach (array_filter(array_map('trim', explode(',', $f['competences']))) as $c): ?>
                  <span><?= e($c) ?></span>
                <?php endforeach; ?>
              </div>

              <?php $pfs = portfolio_de($f['id']); if ($pfs): ?>
                <div class="pf-mini" title="Portfolio du freelance">
                  <?php foreach (array_slice($pfs, 0, 6) as $pf): ?>
                    <img src="<?= e(portfolio_image_url($pf['id'])) ?>" alt="<?= e($pf['titre']) ?>" loading="lazy">
                  <?php endforeach; ?>
                  <?php if (count($pfs) > 6): ?><span class="small muted" style="align-self:center">+<?= count($pfs)-6 ?></span><?php endif; ?>
                </div>
              <?php endif; ?>

              <div class="grid" style="grid-template-columns:repeat(5,1fr);gap:10px;margin-top:14px">
                <?php foreach ($labels as $k => $lab): ?>
                  <div>
                    <div class="small muted" style="font-size:.6875rem;margin-bottom:4px"><?= $lab ?> (<?= $coefs[$k] ?>%)</div>
                    <div class="bar"><i style="width:<?= (int)$r['detail'][$k] ?>%"></i></div>
                    <div style="font-size:.6875rem;color:var(--muted);margin-top:3px"><?= (int)$r['detail'][$k] ?>%</div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="grid" style="gap:24px">
      <div class="panel">
        <div class="panel-head"><h3>Le projet</h3></div>
        <div class="panel-body">
          <div class="recap">
            <div class="recap-row"><span>Client</span><strong><?= e($projet['societe']) ?></strong></div>
            <div class="recap-row"><span>Catégorie</span><strong><?= e($projet['categorie']) ?></strong></div>
            <div class="recap-row"><span>Budget</span><strong><?= euros($projet['budget_min']) ?> – <?= euros_max($projet['budget_max']) ?></strong></div>
            <div class="recap-row"><span>Délai</span><strong><?= e($projet['delai']) ?></strong></div>
            <div class="recap-row"><span>Échéance</span><strong><?= date_fr($projet['date_limite']) ?></strong></div>
            <div class="recap-row"><span>Statut</span><strong><span class="badge <?= statut_classe($projet['statut']) ?>"><?= statut_label($projet['statut']) ?></span></strong></div>
          </div>
          <h4 class="mt-3 mb-1" style="font-size:.8125rem;text-transform:uppercase;color:var(--muted)">Compétences requises</h4>
          <div class="crit">
            <?php foreach (array_filter(array_map('trim', explode(',', $projet['competences']))) as $c): ?>
              <span><?= e($c) ?></span>
            <?php endforeach; ?>
          </div>
          <h4 class="mt-3 mb-1" style="font-size:.8125rem;text-transform:uppercase;color:var(--muted)">Description</h4>
          <p class="small"><?= nl2br(e($projet['description'])) ?></p>
        </div>
      </div>

      <div class="panel">
        <div class="panel-head"><h3>Coefficients appliqués</h3></div>
        <div class="panel-body">
          <?php foreach ($labels as $k => $lab): ?>
            <div class="flex-between mb-1" style="font-size:.875rem">
              <span><?= $lab ?></span><strong><?= $coefs[$k] ?> %</strong>
            </div>
          <?php endforeach; ?>
          <?php if (est_admin_principal()): ?><a href="/admin-parametres" class="btn btn-ghost btn-block mt-3 btn-sm">Modifier</a><?php endif; ?>
        </div>
      </div>
    </div>

  </div>
</main>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
