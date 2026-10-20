<?php
/**
 * Missiona — Outil de secours : accès administrateur
 * ==================================================================
 *  À N'UTILISER QUE SI VOUS NE POUVEZ PLUS VOUS CONNECTER.
 *
 *  Cet outil est INACTIF par défaut. Pour l'activer, ajoutez une ligne
 *  dans config.local.php, avec une phrase que vous seul connaissez :
 *
 *      define('CLE_SECOURS', 'une-phrase-longue-et-unique');
 *
 *  Puis ouvrez  https://votre-site/secours-admin.php
 *
 *  ⚠️ SUPPRIMEZ CETTE LIGNE dès que vous avez retrouvé l'accès :
 *     tant qu'elle existe, quiconque devine la phrase peut
 *     réinitialiser le mot de passe administrateur.
 * ==================================================================
 */
require_once __DIR__ . '/includes/functions.php';

$active = defined('CLE_SECOURS') && CLE_SECOURS !== '';

/* Sans clé configurée, l'outil n'existe pas. */
if (!$active) {
    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8">'
       . '<title>Introuvable</title></head><body style="font-family:system-ui;padding:40px">'
       . '<h1>Page introuvable</h1>'
       . "<p>L'outil de secours n'est pas activé. Pour l'utiliser, ajoutez dans "
       . "<code>config.local.php</code> :</p>"
       . "<pre style=\"background:#f4f5f7;padding:12px;border-radius:6px\">"
       . "define('CLE_SECOURS', 'une-phrase-longue-et-unique');</pre>"
       . '</body></html>';
    exit;
}

/* Limite les essais : 8 tentatives par session. */
$essais = (int) ($_SESSION['secours_essais'] ?? 0);
$ouvert = !empty($_SESSION['secours_ok']);
$msg    = '';
$type   = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cle'])) {
    if ($essais >= 8) {
        $msg = "Trop de tentatives. Rechargez la page dans quelques minutes.";
        $type = 'error';
    } elseif (hash_equals(CLE_SECOURS, (string) $_POST['cle'])) {
        $_SESSION['secours_ok'] = true;
        $ouvert = true;
    } else {
        $_SESSION['secours_essais'] = $essais + 1;
        $msg = "Phrase incorrecte.";
        $type = 'error';
        @error_log('[secours] tentative échouée depuis ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    }
}

/* ---------- Actions ---------- */
if ($ouvert && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'motdepasse') {
        $id  = (int) ($_POST['uid'] ?? 0);
        $mdp = (string) ($_POST['mdp'] ?? '');
        if (strlen($mdp) < 8) {
            $msg = "Le mot de passe doit faire au moins 8 caractères.";
            $type = 'error';
        } else {
            $st = db()->prepare("UPDATE users SET password_hash = ?, actif = 1 WHERE id = ? AND role = 'admin'");
            $st->execute([password_hash($mdp, PASSWORD_DEFAULT), $id]);
            $msg = $st->rowCount() > 0
                ? "Mot de passe modifié. Connectez-vous, puis retirez la clé de secours."
                : "Compte administrateur introuvable.";
            $type = $st->rowCount() > 0 ? 'success' : 'error';
            @error_log('[secours] mot de passe administrateur #' . $id . ' réinitialisé');
        }
    }

    if ($_POST['action'] === 'creer') {
        $mail = strtolower(trim((string) ($_POST['email'] ?? '')));
        $mdp  = (string) ($_POST['mdp'] ?? '');
        if (!filter_var($mail, FILTER_VALIDATE_EMAIL) || strlen($mdp) < 8) {
            $msg = "Adresse invalide ou mot de passe trop court (8 caractères minimum).";
            $type = 'error';
        } else {
            $st = db()->prepare("SELECT id FROM users WHERE email = ?");
            $st->execute([$mail]);
            if ($st->fetch()) {
                $msg = "Cette adresse est déjà utilisée.";
                $type = 'error';
            } else {
                db()->prepare("INSERT INTO users (nom, prenom, email, password_hash, role, actif, created_at)
                               VALUES ('Administrateur','', ?, ?, 'admin', 1, datetime('now'))")
                    ->execute([$mail, password_hash($mdp, PASSWORD_DEFAULT)]);
                $msg = "Administrateur créé. Connectez-vous, puis retirez la clé de secours.";
                $type = 'success';
                @error_log('[secours] administrateur créé : ' . $mail);
            }
        }
    }

    /* Récupération d'une base laissée dans l'ancien emplacement */
    if ($_POST['action'] === 'reprendre') {
        // Le chemin vient du tableau affiché : on n'accepte que les
        // emplacements connus, jamais un chemin libre envoyé par le client.
        $demande = (string) ($_POST['src'] ?? '');
        $src = '';
        // Le chemin doit désigner un vrai fichier SQLite situé sous le
        // compte d'hébergement : on n'accepte jamais un chemin arbitraire.
        $racine = dirname(dirname(__DIR__));
        $reel   = realpath($demande);
        if ($reel !== false && is_file($reel)
            && preg_match('/\.sqlite(\.repris-[0-9\-]+)?$/i', $reel)
            && strpos($reel, rtrim(realpath($racine) ?: $racine, '/') . '/') === 0
            && $reel !== realpath(DB_FILE)) {
            $src = $reel;
        }
        if ($src === '') {
            $msg = "Base introuvable ou emplacement non reconnu.";
            $type = 'error';
        } else {
            @copy(DB_FILE, dirname(DB_FILE) . '/avant-reprise-' . date('Ymd-His') . '.sqlite');
            $msg = @copy($src, DB_FILE)
                ? "Ancienne base restaurée. Reconnectez-vous avec vos identifiants habituels."
                : "La copie a échoué (droits d'écriture ?).";
            $type = strpos($msg, 'restaurée') !== false ? 'success' : 'error';
        }
    }
}

/**
 * Recherche toute base SQLite présente sur l'hébergement.
 * ------------------------------------------------------------------
 * Quand aucun des emplacements connus ne contient de données, il faut
 * élargir : la base peut être restée dans le dossier d'un ancien
 * domaine, dans une sauvegarde, ou à un endroit inattendu.
 *
 * Exploration bornée (profondeur et nombre de dossiers) pour ne pas
 * épuiser le temps d'exécution sur un hébergement chargé.
 */
function secours_scanner($depart, $profondeur_max = 5, $limite = 4000) {
    $trouves = [];
    $vus     = 0;
    $file    = [[$depart, 0]];

    // Dossiers sans intérêt, souvent volumineux
    $ignores = ['node_modules', '.git', 'cache', '.cache', 'tmp', 'vendor',
                'wp-content', '.trash', '.well-known', 'logs'];

    while ($file && $vus < $limite) {
        [$dir, $prof] = array_shift($file);
        $vus++;
        $h = @opendir($dir);
        if (!$h) { continue; }
        while (($e = readdir($h)) !== false) {
            if ($e === '.' || $e === '..') { continue; }
            $p = $dir . '/' . $e;
            if (@is_dir($p)) {
                if ($prof < $profondeur_max && !in_array(strtolower($e), $ignores, true)) {
                    $file[] = [$p, $prof + 1];
                }
                continue;
            }
            // .sqlite, mais aussi les copies horodatées et les sauvegardes
            if (preg_match('/\.sqlite(\.repris-[0-9\-]+)?$/i', $e)
                || preg_match('/^(auto|avant-restauration|avant-reprise)-.*\.sqlite$/i', $e)) {
                $t = @filesize($p);
                if ($t > 0) { $trouves[] = $p; }
            }
        }
        closedir($h);
    }
    return $trouves;
}

/* ---------- Diagnostic : où sont les bases ? ---------- */
function secours_inspecter($chemin) {
    $r = ['chemin' => $chemin, 'existe' => is_file($chemin), 'taille' => 0,
          'comptes' => '—', 'admins' => [], 'date' => '—'];
    if (!$r['existe']) { return $r; }
    $r['taille'] = filesize($chemin);
    $r['date']   = date('d/m/Y H:i', filemtime($chemin));
    try {
        $p = new PDO('sqlite:' . $chemin);
        $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $r['comptes'] = (int) $p->query("SELECT COUNT(*) FROM users")->fetchColumn();
        foreach ($p->query("SELECT id, email FROM users WHERE role='admin' ORDER BY id") as $a) {
            $r['admins'][] = $a;
        }
    } catch (Throwable $e) { $r['comptes'] = 'illisible'; }
    return $r;
}

$bases   = [];
$vus     = [];
$scannes = [];

// Recherche élargie, déclenchée par l'utilisateur (elle prend du temps)
if ($ouvert && isset($_POST['scanner'])) {
    @set_time_limit(120);
    // On remonte jusqu'à la racine du compte d'hébergement : chez la
    // plupart des hébergeurs mutualisés, le site est à
    // /home/compte/domains/mon-domaine/public_html. Trois niveaux plus
    // haut, on couvre donc les autres domaines et les sauvegardes.
    $depart = __DIR__;
    for ($i = 0; $i < 3; $i++) {
        $p = dirname($depart);
        if ($p === $depart || !is_dir($p) || !is_readable($p)) { break; }
        $depart = $p;
    }
    $scannes = secours_scanner($depart, 6);
}
foreach (array_merge([DB_FILE,
          dirname(__DIR__) . '/missiona-data/missiona.sqlite',
          __DIR__ . '/data/missiona.sqlite',
          // Emplacements de l'époque où la plateforme s'appelait WorkConnects
          dirname(__DIR__) . '/workconnects-data/workconnects.sqlite',
          __DIR__ . '/data/workconnects.sqlite'], $scannes) as $c) {
    $cle = realpath($c) ?: $c;
    if (isset($vus[$cle])) { continue; }
    $vus[$cle] = true;
    $bases[] = secours_inspecter($c);
}
$admins_actifs = secours_inspecter(DB_FILE)['admins'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Secours — accès administrateur</title>
<style>
  body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f6f7f9;color:#23282e;
       margin:0;padding:32px 16px;line-height:1.55}
  .box{max-width:780px;margin:0 auto}
  .card{background:#fff;border:1px solid #e3e6ea;border-radius:10px;padding:20px;margin-bottom:18px}
  h1{font-size:1.4rem;margin:0 0 6px} h2{font-size:1rem;margin:0 0 12px}
  .muted{color:#6b7280;font-size:.875rem}
  input{width:100%;padding:9px 11px;border:1px solid #d7dbe0;border-radius:7px;font-size:.92rem;
        box-sizing:border-box;margin-bottom:10px}
  button{background:#2f6df6;color:#fff;border:0;padding:9px 16px;border-radius:7px;
         font-weight:600;cursor:pointer;font-size:.9rem}
  button.gris{background:#eef0f3;color:#2b3138}
  table{width:100%;border-collapse:collapse;font-size:.85rem}
  th,td{text-align:left;padding:7px 8px;border-bottom:1px solid #eef0f2;vertical-align:top}
  code{background:#f4f5f7;padding:1px 5px;border-radius:4px;font-size:.82rem;word-break:break-all}
  .a{padding:11px 13px;border-radius:8px;margin-bottom:14px;font-size:.9rem}
  .a-error{background:#FEF2F2;color:#B42318;border:1px solid #FECDCA}
  .a-success{background:#ECFDF3;color:#067647;border:1px solid #ABEFC6}
  .a-warn{background:#FFFAEB;color:#B54708;border:1px solid #FEDF89}
  .ok{color:#067647;font-weight:600} .no{color:#B42318}
</style>
</head>
<body>
<div class="box">

  <div class="card">
    <h1>Secours — accès administrateur</h1>
    <p class="muted" style="margin:0">
      Outil de dépannage. Retirez <code>CLE_SECOURS</code> de
      <code>config.local.php</code> dès que l'accès est rétabli.
    </p>
  </div>

  <?php if ($msg !== ''): ?>
    <div class="a a-<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>

  <?php if (!$ouvert): ?>
    <div class="card">
      <h2>Phrase de secours</h2>
      <form method="post">
        <input type="password" name="cle" placeholder="Phrase définie dans config.local.php" autofocus>
        <button type="submit">Ouvrir</button>
      </form>
    </div>
  <?php else: ?>

    <div class="card">
      <h2>Où sont vos données ?</h2>
      <form method="post" style="margin-bottom:12px">
        <input type="hidden" name="cle" value="<?= htmlspecialchars(CLE_SECOURS) ?>">
        <button type="submit" name="scanner" value="1" class="gris">
          Rechercher toutes les bases sur l'hébergement
        </button>
        <span class="muted" style="margin-left:8px">
          <?= $scannes ? count($scannes) . ' fichier(s) trouvé(s)' : 'inclut les anciens domaines et les sauvegardes' ?>
        </span>
      </form>
      <p class="muted">
        Si vos comptes figurent dans une base qui n'est pas celle utilisée,
        c'est l'explication : le site lit une base vide.
      </p>
      <table>
        <thead><tr><th>Emplacement</th><th>Comptes</th><th>Modifiée</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($bases as $b): $active_ci = (realpath($b['chemin']) === realpath(DB_FILE)); ?>
          <tr>
            <td>
              <code><?= htmlspecialchars($b['chemin']) ?></code>
              <?php if ($active_ci): ?><br><span class="ok">← base utilisée par le site</span><?php endif; ?>
            </td>
            <td><?= $b['existe'] ? htmlspecialchars((string) $b['comptes']) : '<span class="no">absente</span>' ?></td>
            <td class="muted"><?= htmlspecialchars($b['date']) ?></td>
            <td>
              <?php if ($b['existe'] && !$active_ci && is_int($b['comptes']) && $b['comptes'] > 0): ?>
                <form method="post" onsubmit="return confirm('Remplacer la base utilisée par celle-ci ?');">
                  <input type="hidden" name="cle" value="<?= htmlspecialchars(CLE_SECOURS) ?>">
                  <input type="hidden" name="action" value="reprendre">
                  <input type="hidden" name="scanner" value="1">
                  <input type="hidden" name="src" value="<?= htmlspecialchars($b['chemin']) ?>">
                  <button type="submit" class="gris">Utiliser celle-ci</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="card">
      <h2>Comptes administrateurs de la base utilisée</h2>
      <?php if (!$admins_actifs): ?>
        <div class="a a-warn" style="margin:0 0 12px">
          Aucun administrateur dans cette base. Créez-en un ci-dessous.
        </div>
      <?php else: ?>
        <?php foreach ($admins_actifs as $a): ?>
          <form method="post" style="border-bottom:1px solid #eef0f2;padding-bottom:12px;margin-bottom:12px">
            <input type="hidden" name="cle" value="<?= htmlspecialchars(CLE_SECOURS) ?>">
            <input type="hidden" name="action" value="motdepasse">
            <input type="hidden" name="uid" value="<?= (int) $a['id'] ?>">
            <div class="muted" style="margin-bottom:6px">
              #<?= (int) $a['id'] ?> — <strong><?= htmlspecialchars($a['email']) ?></strong>
            </div>
            <input type="password" name="mdp" placeholder="Nouveau mot de passe (8 caractères minimum)">
            <button type="submit">Changer le mot de passe</button>
          </form>
        <?php endforeach; ?>
      <?php endif; ?>

      <h2 style="margin-top:18px">Créer un administrateur</h2>
      <form method="post">
        <input type="hidden" name="cle" value="<?= htmlspecialchars(CLE_SECOURS) ?>">
        <input type="hidden" name="action" value="creer">
        <input type="email" name="email" placeholder="votre@adresse.fr">
        <input type="password" name="mdp" placeholder="Mot de passe (8 caractères minimum)">
        <button type="submit">Créer</button>
      </form>
    </div>

    <div class="card">
      <h2>Ensuite</h2>
      <ol class="muted" style="margin:0;padding-left:20px">
        <li>Connectez-vous sur <code>/connexion</code>.</li>
        <li>Retirez la ligne <code>CLE_SECOURS</code> de <code>config.local.php</code>.</li>
        <li>Rechargez cette page : elle doit afficher « Page introuvable ».</li>
      </ol>
    </div>

  <?php endif; ?>
</div>
</body>
</html>
