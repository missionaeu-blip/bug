<?php
$r = role();
$cur = basename($_SERVER['PHP_SELF']);
$nb_msg = 0;
if ($u = user()) {
    $st = db()->prepare("SELECT COUNT(*) FROM messages WHERE destinataire_id = ? AND lu = 0");
    $st->execute([$u['id']]);
    $nb_msg = (int)$st->fetchColumn();
}
?>
<aside class="sidebar">
<?php if (est_client($r)): ?>
  <div class="side-label">Pilotage</div>
  <a href="/dashboard-entreprise" class="side-link <?= $cur==='dashboard-entreprise.php'?'active':'' ?>"><span class="ico">📊</span> Tableau de bord</a>
  <?php if (!beta_lien_masque('/projets')): ?>
  <a href="/projets" class="side-link <?= $cur==='projets.php'?'active':'' ?>"><span class="ico">📁</span> Mes projets</a>
  <?php endif; ?>
  <?php if (!beta_lien_masque('/nouveau-projet')): ?>
  <a href="/nouveau-projet" class="side-link <?= $cur==='nouveau-projet.php'?'active':'' ?>"><span class="ico">➕</span> Nouveau projet</a>
  <?php endif; ?>
  <div class="side-label">Échanges</div>
  <a href="/messages" class="side-link <?= $cur==='messages.php'?'active':'' ?>">
    <span class="ico">💬</span> Messagerie<?php if($nb_msg):?><span class="cnt"><?= $nb_msg ?></span><?php endif;?>
  </a>
  <?php if (!beta_lien_masque('/factures')): ?>
  <a href="/factures" class="side-link <?= $cur==='factures.php'?'active':'' ?>"><span class="ico">🧾</span> Facturation</a>
  <?php endif; ?>
  <div class="side-label">Compte</div>
  <a href="/profil" class="side-link <?= $cur==='profil.php'?'active':'' ?>"><span class="ico">⚙️</span> Mon profil</a>

<?php elseif ($r === 'freelance'): ?>
  <div class="side-label">Activité</div>
  <a href="/dashboard-freelance" class="side-link <?= $cur==='dashboard-freelance.php'?'active':'' ?>"><span class="ico">📊</span> Tableau de bord</a>
  <?php if (!beta_lien_masque('/projets')): ?>
  <a href="/projets" class="side-link <?= $cur==='projets.php'?'active':'' ?>"><span class="ico">💼</span> Mes missions</a>
  <?php endif; ?>
  <div class="side-label">Échanges</div>
  <a href="/messages" class="side-link <?= $cur==='messages.php'?'active':'' ?>">
    <span class="ico">💬</span> Messagerie<?php if($nb_msg):?><span class="cnt"><?= $nb_msg ?></span><?php endif;?>
  </a>
  <?php if (!beta_lien_masque('/factures')): ?>
  <a href="/factures" class="side-link <?= $cur==='factures.php'?'active':'' ?>"><span class="ico">🧾</span> Mes factures</a>
  <?php endif; ?>
  <div class="side-label">Compte</div>
  <a href="/profil" class="side-link <?= $cur==='profil.php'?'active':'' ?>"><span class="ico">⚙️</span> Mon profil</a>

<?php elseif ($r === 'admin'): ?>
  <div class="side-label">Back-office</div>
  <a href="/admin" class="side-link <?= $cur==='admin.php'?'active':'' ?>"><span class="ico">📊</span> Vue d'ensemble</a>
  <a href="/admin-projets" class="side-link <?= $cur==='admin-projets.php'?'active':'' ?>"><span class="ico">📁</span> Projets</a>
  <a href="/admin-utilisateurs" class="side-link <?= $cur==='admin-utilisateurs.php'?'active':'' ?>"><span class="ico">👥</span> Utilisateurs</a>
  <a href="/admin-freelances" class="side-link <?= in_array($cur, ['admin-freelances.php','admin-freelance.php'], true)?'active':'' ?>"><span class="ico">🧑‍💻</span> Experts</a>
  <?php $nb_dem = nb_demandes_en_attente(); ?>
  <a href="/admin-demandes" class="side-link <?= $cur==='admin-demandes.php'?'active':'' ?>">
    <span class="ico">⚠️</span> Demandes<?php if($nb_dem):?><span class="cnt"><?= $nb_dem ?></span><?php endif;?>
  </a>
  <div class="side-label">Opérations</div>
  <a href="/messages" class="side-link <?= $cur==='messages.php'?'active':'' ?>">
    <span class="ico">💬</span> Messagerie<?php if($nb_msg):?><span class="cnt"><?= $nb_msg ?></span><?php endif;?>
  </a>
  <?php if (!beta_lien_masque('/factures')): ?>
  <a href="/factures" class="side-link <?= $cur==='factures.php'?'active':'' ?>"><span class="ico">🧾</span> Facturation</a>
  <?php endif; ?>
  <a href="/admin-dac7" class="side-link <?= $cur==='admin-dac7.php'?'active':'' ?>"><span class="ico">📑</span> Déclaration DAC7</a>
  <?php if (est_admin_principal()): ?>
  <a href="/admin-equipe" class="side-link <?= $cur==='admin-equipe.php'?'active':'' ?>"><span class="ico">🛡️</span> Équipe</a>
  <?php if (est_admin_principal()): ?>
  <a href="/admin-suivi-equipe" class="side-link <?= $cur==='admin-suivi-equipe.php'?'active':'' ?>"><span class="ico">🩺</span> Suivi équipe</a>
  <?php endif; ?>
  <a href="/admin-parametres" class="side-link <?= $cur==='admin-parametres.php'?'active':'' ?>"><span class="ico">⚙️</span> Paramètres</a>
  <?php if (est_admin_principal()): ?>
  <a href="/admin-statistiques" class="side-link <?= $cur==='admin-statistiques.php'?'active':'' ?>"><span class="ico">📊</span>Fréquentation</a>
  <?php endif; ?>
  <a href="/admin-sauvegarde" class="side-link <?= $cur==='admin-sauvegarde.php'?'active':'' ?>"><span class="ico">💾</span>Sauvegarde</a>
  <?php endif; ?>
<?php endif; ?>

<a href="/deconnexion" class="side-link side-logout"><span class="ico">⏻</span> Déconnexion</a>
</aside>
