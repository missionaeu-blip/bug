<?php
require_once __DIR__ . '/includes/functions.php';
if (!is_logged()) { header('Location: ' . u('/connexion')); exit; }
$u = user();
// Interlocuteur Missiona du client : son gestionnaire assigné, sinon
// l'administrateur principal.
$ADMIN_ID = admin_id();
if ($u['role'] !== 'admin' && !empty($u['gestionnaire_id'])) {
    if ($g = gestionnaire_de($u)) { $ADMIN_ID = (int) $g['id']; }
}

/* L'admin discute avec un compte de son périmètre ; les autres
   discutent uniquement avec Missiona. Les conversations sont
   regroupées par projet (voir includes/messagerie.php). */
$pid = 0; $projets = []; $projet = null;
if ($u['role'] === 'admin') {
    $projets = msg_projets($u);
    $pid     = (int) ($_GET['p'] ?? 0);
    if ($pid > 0) {
        $projet = msg_projet($pid);
        // Un projet hors périmètre est ignoré plutôt que refusé
        if (!$projet || !in_array($pid, array_column($projets, 'id'), true)) {
            $pid = 0; $projet = null;
        }
    }
    $contacts = msg_contacts($u, $pid);
    $ids  = array_column($contacts, 'id');
    $cid  = (int) ($_GET['c'] ?? 0);
    if (!in_array($cid, $ids, true)) { $cid = (int) ($contacts[0]['id'] ?? 0); }
    $did  = 0;
} else {
    $contacts = [];
    // Canaux directs ouverts par l'équipe sur certains projets :
    // recalculés à chaque affichage, jamais lus depuis un paramètre libre.
    $directs = contact_direct_projets($u);
    $uniques = [];
    foreach ($directs as $dp) { $uniques[(int) $dp['h_id']] = $dp; } // un fil par interlocuteur
    $directs   = array_values($uniques);
    $did       = (int) ($_GET['d'] ?? 0);
    $homologue = contact_direct_homologue($u, $did);
    if ($homologue > 0) { $cid = $homologue; } else { $cid = $ADMIN_ID; $did = 0; }
}

/* ------------------------------------------------------------------
 * Surveillance équipe : lecture seule du fil direct entreprise↔expert
 * d'un projet. Réservée à l'administrateur, et uniquement pour les
 * projets de son périmètre.
 * ------------------------------------------------------------------ */
if ($u['role'] !== 'admin' && isset($_GET['lecture'])) {
    header('Location: ' . u('/messages')); exit;   // ce lien n'existe pas hors équipe
}
if ($u['role'] === 'admin' && isset($_GET['lecture'])) {
    $lid = (int) $_GET['lecture'];
    $lp  = msg_projet($lid);
    $dans_perimetre = $lp && in_array($lid, array_column(msg_projets($u), 'id'), true);
    if (!$dans_perimetre) { header('Location: ' . u('/messages')); exit; }

    $e_id = (int) $lp['entreprise_id'];
    $f_id = (int) $lp['freelance_id'];
    $sl = db()->prepare("SELECT m.*, u.nom AS s_nom, u.prenom AS s_prenom, u.role AS s_role, u.societe AS s_societe
                         FROM messages m LEFT JOIN users u ON u.id = m.expediteur_id
                         WHERE (m.expediteur_id=? AND m.destinataire_id=?)
                            OR (m.expediteur_id=? AND m.destinataire_id=?)
                         ORDER BY m.id ASC");
    $sl->execute([$e_id, $f_id, $f_id, $e_id]);
    $lmsgs = $sl->fetchAll();

    $titre = "Contact direct — " . $lp['titre'];
    require_once __DIR__ . '/includes/header.php';
    ?>
    <div class="app app-chat">
    <?php require __DIR__ . '/includes/sidebar.php'; ?>
    <main class="main main-chat">
      <div class="page-head">
        <div>
          <h1>Contact direct — lecture seule</h1>
          <p><?= e($lp['titre']) ?> · <?= e(msg_projet_libelle($lp)) ?> ↔ <?= e(trim(($lp['f_prenom'] ?? '') . ' ' . ($lp['f_nom'] ?? ''))) ?></p>
        </div>
        <a href="<?= u('/messages') ?>" class="btn btn-ghost">← Retour à la messagerie</a>
      </div>

      <div class="chat">
        <div class="chat-main" style="max-width:none">
          <div class="chat-note">
            👁 Fil échangé en direct entre le client et l'expert
            <?= empty($lp['statut']) || $lp['statut'] !== 'annule' ? '' : ' (projet annulé)' ?>.
            Vous le consultez sans pouvoir y écrire : la réponse officielle passe par votre propre conversation.
          </div>
          <div class="chat-body" id="chatBody">
            <?php if (!$lmsgs): ?>
              <div class="empty" style="margin:auto">
                <div class="ico">💬</div>
                <h3>Aucun échange direct pour l'instant</h3>
                <p>Les messages envoyés sur ce canal apparaîtront ici.</p>
              </div>
            <?php else: foreach ($lmsgs as $m):
              $qui = $m['s_role'] === 'freelance'
                  ? trim(($m['s_prenom'] ?? '') . ' ' . ($m['s_nom'] ?? ''))
                  : (($m['s_societe'] ?? '') ?: trim(($m['s_prenom'] ?? '') . ' ' . ($m['s_nom'] ?? '')));
            ?>
              <div class="msg <?= ($m['s_role'] ?? '') === 'freelance' ? 'out' : 'in' ?>">
                <span class="badge badge-gray" style="font-size:.62rem;margin-bottom:4px"><?= e($qui ?: 'Compte supprimé') ?></span>
                <?= nl2br(e($m['contenu'])) ?>
                <?php if (!empty($m['fichier_nom'])): ?>
                  <div class="msg-pj">
                    <span class="msg-pj-ico"><?= icone_fichier($m['fichier_nom']) ?></span>
                    <span class="msg-pj-nom"><?= e($m['fichier_nom']) ?></span>
                    <span class="msg-pj-taille"><?= taille_lisible($m['fichier_taille']) ?></span>
                    <span class="msg-pj-actions">
                      <?php if (fichier_previsualisable($m['fichier_nom'])): ?>
                        <button type="button" data-apercu-msg="<?= (int) $m['id'] ?>"
                                data-nom="<?= e($m['fichier_nom']) ?>" data-suivi-non>👁</button>
                      <?php endif; ?>
                      <a href="<?= u('/message-fichier.php?id=' . (int) $m['id']) ?>" title="Télécharger">⬇</a>
                    </span>
                  </div>
                <?php endif; ?>
                <span class="time"><?= date('d/m/Y H:i', strtotime($m['created_at'])) ?></span>
              </div>
            <?php endforeach; endif; ?>
          </div>
          <div class="chat-foot" style="justify-content:center;color:var(--muted);font-size:.8125rem">
            🔒 Consultation seule — l'envoi de messages sur ce canal est réservé aux deux parties.
          </div>
        </div>
      </div>
    </main>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Envoi d'un message
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $txt = trim($_POST['contenu'] ?? '');
    $projet_msg = null; // projet associé au message (canal direct), sinon NULL
    if ($u['role'] === 'admin') {
        // Seul l'admin choisit son destinataire.
        $dest = (int)($_POST['dest'] ?? $cid);
        // Un administrateur non principal ne peut écrire qu'aux comptes dont
        // il a la charge, même s'il force l'identifiant dans le formulaire.
        if (!msg_peut_ecrire($u, $dest)) { $dest = 0; }
    } else {
        // Cloisonnement recalculé côté serveur : soit un canal direct ouvert
        // par l'équipe sur un projet (champ "d"), soit Missiona. Toute autre
        // valeur — y compris un identifiant forcé à la main — retombe sur le
        // chargé de compte. contact_direct_homologue() exige un projet commun
        // au canal ouvert : impossible de viser quelqu'un d'autre.
        $d_post = (int)($_POST['d'] ?? 0);
        $dest   = contact_direct_homologue($u, $d_post);
        if ($dest > 0) { $projet_msg = $d_post; }
        else           { $dest = $ADMIN_ID; $d_post = 0; }
    }
    // Pièce jointe éventuelle
    $pj = ['nom' => '', 'chemin' => '', 'taille' => 0, 'empreinte' => ''];
    if ($dest && !empty($_FILES['piece']['name'])) {
        $r = traiter_upload($_FILES['piece'], 'messages', ext_doc_autorisees());
        if ($r['ok']) {
            $pj = [
                'nom'       => $r['nom'],
                'chemin'    => $r['chemin'],
                'taille'    => (int) $r['taille'],
                'empreinte' => @hash_file('sha256', __DIR__ . '/uploads/' . $r['chemin']) ?: '',
            ];
            if ($txt === '') { $txt = 'Pièce jointe : ' . $r['nom']; }
        } else {
            flash($r['erreur'], 'error');
            header('Location: ' . u('messages.php' . ($u['role']==='admin' && $dest ? '?c='.$dest : ''))); exit;
        }
    }

    // Un document remis à une entreprise est protégé : consultation avec
    // filigrane, téléchargement contrôlé. Ce qui circule vers l'équipe ou
    // vers un expert n'a pas besoin de ce traitement.
    $protege = 0;
    if ($pj['nom'] !== '') {
        $dr = db()->prepare("SELECT role FROM users WHERE id = ?");
        $dr->execute([$dest]);
        $protege = in_array((string) $dr->fetchColumn(), ['entreprise', 'particulier'], true) ? 1 : 0;
    }

    if ($txt !== '' && $dest) {
        db()->prepare("INSERT INTO messages (projet_id,expediteur_id,destinataire_id,contenu,
                              fichier_nom,fichier_chemin,fichier_taille,fichier_empreinte,fichier_protege)
                       VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$projet_msg, $u['id'], $dest, $txt, $pj['nom'], $pj['chemin'], $pj['taille'], $pj['empreinte'], $protege]);

        $qui = ($u['role'] === 'admin') ? 'Missiona' : trim($u['prenom'] . ' ' . $u['nom']);
        if ($projet_msg) {
            // Canal direct : le destinataire est prévenu, et l'équipe garde
            // un œil sur le fil (lien de surveillance) pour la traçabilité.
            notify($dest, "Nouveau message de " . $qui . " (contact direct).", 'messages.php?d=' . (int) $projet_msg);
            $na = db()->prepare("SELECT id FROM users WHERE role='admin' AND actif=1");
            $na->execute();
            foreach ($na as $ad) {
                if ((int) $ad['id'] !== (int) $u['id']) {
                    notify((int) $ad['id'],
                        "Échange direct sur « " . msg_titre_projet($projet_msg) . " » : message de " . $qui . ".",
                        'messages.php?lecture=' . (int) $projet_msg);
                }
            }
        } else {
            notify($dest, "Nouveau message de " . $qui . ".", 'messages.php');
        }
    }
    $retour = 'messages.php';
    if ($u['role'] === 'admin' && $dest) {
        $retour .= '?c=' . $dest . (!empty($_POST['p']) ? '&p=' . (int) $_POST['p'] : '');
    } elseif ($u['role'] !== 'admin' && $projet_msg) {
        $retour .= '?d=' . (int) $projet_msg;
    }
    header('Location: ' . u($retour)); exit;
}

// Marquer comme lus
db()->prepare("UPDATE messages SET lu=1 WHERE destinataire_id=? AND expediteur_id=?")->execute([$u['id'], $cid]);

$st = db()->prepare("SELECT * FROM messages
                     WHERE (expediteur_id=? AND destinataire_id=?) OR (expediteur_id=? AND destinataire_id=?)
                     ORDER BY id ASC");
$st->execute([$u['id'], $cid, $cid, $u['id']]);
$msgs = $st->fetchAll();

$cs = db()->prepare("SELECT * FROM users WHERE id=?"); $cs->execute([$cid]);
$contact = $cs->fetch();

$titre = "Messagerie";
require_once __DIR__ . '/includes/header.php';
?>
<div class="app app-chat">
<?php require __DIR__ . '/includes/sidebar.php'; ?>
<main class="main main-chat">

  <div class="page-head">
    <div>
      <h1>Messagerie</h1>
      <p><?php if ($u['role']!=='admin'): ?>Échangez avec votre chargé de compte Missiona.
          <?php elseif (msg_admin_global($u)): ?>Échanges avec l'ensemble des entreprises et des experts.
          <?php else: ?>Échanges avec les comptes dont vous avez la charge.<?php endif; ?></p>
    </div>
  </div>

  <div class="chat">
    <div class="chat-list">
      <?php if ($u['role']==='admin'): ?>

        <?php if ($projet): ?>
          <!-- Un projet est ouvert : seuls ses deux interlocuteurs -->
          <a href="<?= u('/messages') ?>" class="chat-retour">← Tous les projets</a>
          <div class="chat-projet-titre">
            <strong><?= e($projet['titre']) ?></strong>
            <span class="small muted"><?= e(msg_projet_libelle($projet)) ?></span>
          </div>
        <?php elseif ($projets): ?>
          <div class="chat-section">Projets</div>
          <?php foreach ($projets as $p):
            $nbp = 0;
            foreach ([(int)$p['entreprise_id'], (int)$p['freelance_id']] as $x) {
              if ($x > 0) { $nbp += msg_non_lus($u['id'], $x); }
            }
          ?>
            <a href="<?= u('/messages?p=' . (int) $p['id']) ?>" class="chat-item">
              <span class="avatar">📁</span>
              <div class="meta">
                <strong><?= e(stats_tronquer($p['titre'], 26)) ?><?php if($nbp):?>
                  <span class="badge badge-red" style="font-size:.625rem"><?= $nbp ?></span><?php endif;?></strong>
                <p><?= e(msg_projet_libelle($p)) ?></p>
                <p style="font-size:.75rem"><?= e(statut_label($p['statut'])) ?>
                  · <?= $p['freelance_id'] ? '2 interlocuteurs' : '1 interlocuteur' ?></p>
              </div>
            </a>
          <?php endforeach; ?>
          <div class="chat-section">Tous les comptes</div>
        <?php endif; ?>

        <?php if ($surv = contact_direct_surveillance($u)): ?>
          <div class="chat-section">Contacts directs (lecture)</div>
          <?php foreach ($surv as $s): ?>
            <a href="<?= u('/messages?lecture=' . (int) $s['id']) ?>" class="chat-item">
              <span class="avatar">🔓</span>
              <div class="meta">
                <strong><?= e(stats_tronquer($s['titre'], 26)) ?></strong>
                <p><?= e(msg_projet_libelle($s)) ?> ↔ <?= e(trim(($s['f_prenom'] ?? '') . ' ' . mb_substr((string) ($s['f_nom'] ?? ''), 0, 1) . '.')) ?></p>
                <p style="font-size:.75rem"><?= (int) $s['nb'] ?> message<?= (int) $s['nb'] > 1 ? 's' : '' ?> · lecture seule</p>
              </div>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!$contacts): ?>
          <div style="padding:18px;font-size:.8125rem;color:var(--muted)">
            <?= $projet
                ? "Aucun interlocuteur joignable sur ce projet."
                : "Aucun compte ne vous est rattaché pour le moment." ?>
          </div>
        <?php endif; ?>

        <?php foreach ($contacts as $c):
          $last = msg_dernier($u['id'], $c['id']);
          $nb   = msg_non_lus($u['id'], $c['id']);
          $lien = '/messages?c=' . (int) $c['id'] . ($pid ? '&p=' . $pid : '');
        ?>
          <a href="<?= u($lien) ?>" class="chat-item <?= $c['id']==$cid?'active':'' ?>">
            <span class="avatar"><?= initiales($c) ?></span>
            <div class="meta">
              <strong><?= e(trim($c['prenom'].' '.$c['nom'])) ?><?php if($nb):?> <span class="badge badge-red" style="font-size:.625rem"><?= $nb ?></span><?php endif;?></strong>
              <p>
                <?= e(est_client($c['role']) ? ($c['societe'] ?: role_label($c['role'])) : $c['titre_pro']) ?>
                <?php if ($projet): ?>
                  <span class="badge badge-gray" style="font-size:.625rem"><?= est_client($c['role']) ? 'Client' : 'Expert' ?></span>
                <?php endif; ?>
              </p>
              <p style="font-size:.75rem"><?= e(mb_substr((string)$last, 0, 42)) ?><?= mb_strlen((string)$last)>42?'…':'' ?></p>
            </div>
          </a>
        <?php endforeach; ?>
      <?php else: ?>
        <a href="<?= u('/messages') ?>" class="chat-item <?= $did === 0 ? 'active' : '' ?>">
          <span class="avatar dark">WC</span>
          <div class="meta">
            <strong>Missiona <?php if (msg_non_lus($u['id'], $ADMIN_ID)): ?><span class="badge badge-red" style="font-size:.625rem"><?= msg_non_lus($u['id'], $ADMIN_ID) ?></span><?php endif; ?></strong>
            <p><?= e(charge_compte_nom()) ?> — <?= e(charge_compte_role()) ?></p>
          </div>
        </a>

        <?php if ($directs): ?>
          <div class="chat-section">Contact direct</div>
          <?php foreach ($directs as $dp):
            $nb_d = msg_non_lus($u['id'], (int) $dp['h_id']);
            $nom_d = est_client($dp['h_role'])
                ? ($dp['h_societe'] ?: trim($dp['h_prenom'] . ' ' . $dp['h_nom']))
                : trim($dp['h_prenom'] . ' ' . $dp['h_nom']);
          ?>
            <a href="<?= u('/messages?d=' . (int) $dp['id']) ?>" class="chat-item <?= (int)$dp['h_id'] === (int)$cid && $did > 0 ? 'active' : '' ?>">
              <span class="avatar"><?= initiales(['prenom' => $dp['h_prenom'], 'nom' => $dp['h_nom']]) ?></span>
              <div class="meta">
                <strong><?= e($nom_d) ?><?php if ($nb_d): ?> <span class="badge badge-red" style="font-size:.625rem"><?= $nb_d ?></span><?php endif; ?></strong>
                <p><?= e($dp['h_role'] === 'freelance' ? ($dp['h_titre'] ?: 'Expert') : 'Client') ?> · <?= e(stats_tronquer($dp['titre'], 30)) ?></p>
                <span class="badge badge-green" style="font-size:.6rem;margin-top:4px">Canal ouvert par Missiona</span>
              </div>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>

        <div style="padding:18px;font-size:.8125rem;color:var(--muted);border-top:1px solid var(--line-2)">
          🔒 Vos échanges passent par votre chargé de compte.
          <?php if ($directs): ?>
            Un canal direct a été ouvert par Missiona sur <?= count($directs) > 1 ? 'certains projets' : 'le projet' ?> ci-dessus.
          <?php else: ?>
            Aucun contact direct entre entreprise et freelance n'est ouvert pour le moment.
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="chat-main">
      <?php $en_direct = ($u['role'] !== 'admin' && $did > 0 && $contact); ?>
      <div class="chat-head">
        <span class="avatar <?= $u['role']==='admin'?'':'dark' ?>">
          <?= $u['role']==='admin' ? ($contact ? initiales($contact) : '?') : ($en_direct ? initiales($contact) : 'WC') ?>
        </span>
        <div>
          <strong>
            <?php if ($u['role']==='admin'): ?><?= $contact ? e(trim($contact['prenom'].' '.$contact['nom'])) : 'Aucun contact' ?>
            <?php elseif ($en_direct): ?><?= e(trim($contact['prenom'].' '.$contact['nom'])) ?>
            <?php else: ?>Missiona<?php endif; ?>
          </strong>
          <p class="small muted">
            <?php if ($u['role'] !== 'admin'): ?>
              <?php if ($en_direct): ?>
                <?= e(est_client($contact['role']) ? ($contact['societe'] ?: role_label($contact['role'])) : ($contact['titre_pro'] ?: 'Expert')) ?>
              <?php else: ?>
                <?= e(charge_compte_nom()) ?> — <?= e(charge_compte_role()) ?>
              <?php endif; ?>
            <?php elseif ($contact): ?>
              <?= e(est_client($contact['role']) ? ($contact['societe'] ?: role_label($contact['role'])) : $contact['titre_pro']) ?>
            <?php else: ?>
              Aucune entreprise ni freelance inscrit pour le moment.
            <?php endif; ?>
          </p>
        </div>
      </div>

      <?php if ($u['role'] !== 'admin'): ?>
        <?php if ($en_direct): ?>
          <div class="chat-note">🔓 Canal direct ouvert par Missiona sur « <?= e(msg_titre_projet($did)) ?> » — l'équipe Missiona peut consulter ces échanges.</div>
        <?php else: ?>
          <div class="chat-note">Conversation sécurisée avec votre chargé de compte Missiona</div>
        <?php endif; ?>
      <?php endif; ?>

      <div class="chat-body" id="chatBody">
        <?php if (!$msgs): ?>
          <div class="empty" style="margin:auto">
            <div class="ico">💬</div>
            <h3>Aucun message</h3>
            <p>Démarrez la conversation ci-dessous.</p>
          </div>
        <?php else: foreach ($msgs as $m): ?>
          <div class="msg <?= $m['expediteur_id']==$u['id'] ? 'out' : 'in' ?>">
            <?= nl2br(e($m['contenu'])) ?>
            <?php if (!empty($m['fichier_nom'])): ?>
              <div class="msg-pj">
                <span class="msg-pj-ico"><?= icone_fichier($m['fichier_nom']) ?></span>
                <span class="msg-pj-nom"><?= e($m['fichier_nom']) ?></span>
                <span class="msg-pj-taille"><?= taille_lisible($m['fichier_taille']) ?></span>
                <?php if (!empty($m['fichier_protege'])): ?>
                  <span class="badge badge-amber" style="font-size:.62rem">Protégé</span>
                <?php endif; ?>
                <span class="msg-pj-actions">
                  <?php if (fichier_previsualisable($m['fichier_nom'])): ?>
                    <button type="button" data-apercu-msg="<?= (int) $m['id'] ?>"
                            data-nom="<?= e($m['fichier_nom']) ?>" data-suivi-non>👁</button>
                  <?php endif; ?>
                  <?php /* Document protégé remis à une entreprise : consultation seule. */ ?>
                  <?php if (!( !empty($m['fichier_protege']) && est_client($u['role']) )): ?>
                    <a href="<?= u('/message-fichier.php?id=' . (int) $m['id']) ?>" title="Télécharger">⬇</a>
                  <?php endif; ?>
                </span>
              </div>
            <?php endif; ?>
            <span class="time"><?= date('d/m/Y H:i', strtotime($m['created_at'])) ?></span>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <form class="chat-foot" method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <?php if ($u['role'] === 'admin'): ?>
          <input type="hidden" name="dest" value="<?= $cid ?>">
          <input type="hidden" name="p" value="<?= (int) ($pid ?? 0) ?>">
        <?php else: ?>
          <!-- Seul un projet au canal ouvert valide ce champ ; le
               destinataire est de toute façon recalculé côté serveur. -->
          <input type="hidden" name="d" value="<?= (int) $did ?>">
        <?php endif; ?>
        <label class="chat-joindre" title="Joindre un document">
          📎
          <input type="file" name="piece" id="chatPiece" hidden
                 accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip,.jpg,.jpeg,.png,.gif,.webp,.html,.htm,.js,.css,.json,.md,.php,.py,.sql">
        </label>
        <input type="text" name="contenu" class="input" placeholder="Écrivez votre message…" autocomplete="off">
        <button type="submit" class="btn btn-primary">Envoyer</button>
      </form>
    </div>
  </div>

</main>
</div>

<!-- Aperçu protégé des pièces jointes -->
<div class="apercu" id="apercu" hidden aria-hidden="true" role="dialog" aria-modal="true" aria-label="Aperçu du document">
  <div class="apercu-barre">
    <strong id="apercuNom"></strong>
    <span class="apercu-mention">Document confidentiel — diffusion interdite</span>
    <button type="button" class="apercu-fermer" id="apercuFermer" aria-label="Fermer l'aperçu" data-suivi-non>&times;</button>
  </div>
  <div class="apercu-scene" id="apercuScene">
    <div class="apercu-contenu" id="apercuContenu"></div>
    <div class="apercu-filigrane" aria-hidden="true"
         data-texte="<?= e(SITE_NOM) ?>"
         data-qui="<?= e(trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? '')) . ' · ' . date('d/m/Y')) ?>"></div>
    <div class="apercu-voile" id="apercuVoile" hidden>
      <div>Aperçu masqué<br><span>Revenez sur cette fenêtre pour afficher le document.</span></div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
