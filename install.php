<?php
/* ==========================================================================
   PANNACOTTACLUB — installation (à ouvrir UNE SEULE FOIS)
   Crée les tables, le catalogue et les comptes de départ.
   Ouvre ce fichier dans le navigateur : https://votre-domaine.fr/install.php
   Puis SUPPRIME-LE (ou renomme-le) — c'est plus sûr.
   ========================================================================== */

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/auth.php';

$force = isset($_GET['force']);
$done = false;
$messages = [];
$error = null;

/* ------------------------------------------------------------------ schéma */
const SCHEMA = [
    "CREATE TABLE IF NOT EXISTS settings (
        k VARCHAR(40) NOT NULL PRIMARY KEY,
        v TEXT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS users (
        id VARCHAR(32) NOT NULL PRIMARY KEY,
        role ENUM('admin','courier') NOT NULL,
        name VARCHAR(60) NOT NULL,
        email VARCHAR(120) NOT NULL,
        phone VARCHAR(30) NOT NULL DEFAULT '',
        pass_hash VARCHAR(255) NOT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        online TINYINT(1) NOT NULL DEFAULT 0,
        last_lat DECIMAL(10,6) NULL,
        last_lng DECIMAL(10,6) NULL,
        last_accuracy INT NULL,
        last_seen_at BIGINT NULL,
        created_at BIGINT NOT NULL,
        UNIQUE KEY uniq_email (email),
        KEY role_idx (role)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS sessions (
        token VARCHAR(64) NOT NULL PRIMARY KEY,
        user_id VARCHAR(32) NOT NULL,
        remember TINYINT(1) NOT NULL DEFAULT 1,
        created_at BIGINT NOT NULL,
        expires_at BIGINT NOT NULL,
        ua VARCHAR(160) NOT NULL DEFAULT '',
        KEY user_idx (user_id),
        KEY exp_idx (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS categories (
        id VARCHAR(20) NOT NULL PRIMARY KEY,
        name VARCHAR(40) NOT NULL,
        icon VARCHAR(20) NOT NULL DEFAULT '',
        sort INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS products (
        id VARCHAR(64) NOT NULL PRIMARY KEY,
        cat_id VARCHAR(20) NOT NULL,
        name VARCHAR(60) NOT NULL,
        description VARCHAR(160) NOT NULL DEFAULT '',
        price INT NOT NULL,
        img VARCHAR(120) NOT NULL DEFAULT '',
        available TINYINT(1) NOT NULL DEFAULT 1,
        sort INT NOT NULL DEFAULT 0,
        created_at BIGINT NOT NULL,
        updated_at BIGINT NOT NULL,
        KEY cat_idx (cat_id),
        KEY avail_idx (available)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS orders (
        id VARCHAR(16) NOT NULL PRIMARY KEY,
        track_token VARCHAR(64) NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'received',
        payment_status VARCHAR(12) NOT NULL DEFAULT 'pending',
        subtotal INT NOT NULL DEFAULT 0,
        delivery_fee INT NOT NULL DEFAULT 0,
        total INT NOT NULL DEFAULT 0,
        first_name VARCHAR(40) NOT NULL,
        phone VARCHAR(20) NOT NULL,
        note VARCHAR(160) NOT NULL DEFAULT '',
        lat DECIMAL(10,6) NULL,
        lng DECIMAL(10,6) NULL,
        accuracy INT NULL,
        location_at BIGINT NULL,
        courier_id VARCHAR(32) NULL,
        auto_assigned TINYINT(1) NOT NULL DEFAULT 0,
        out_of_zone TINYINT(1) NOT NULL DEFAULT 0,
        created_at BIGINT NOT NULL,
        updated_at BIGINT NOT NULL,
        assigned_at BIGINT NULL,
        delivered_at BIGINT NULL,
        paid_at BIGINT NULL,
        UNIQUE KEY uniq_track (track_token),
        KEY status_idx (status),
        KEY courier_idx (courier_id),
        KEY created_idx (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS order_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id VARCHAR(16) NOT NULL,
        product_id VARCHAR(64) NOT NULL,
        name VARCHAR(60) NOT NULL,
        price INT NOT NULL,
        qty INT NOT NULL,
        img VARCHAR(120) NOT NULL DEFAULT '',
        KEY order_idx (order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS order_events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id VARCHAR(16) NOT NULL,
        status VARCHAR(16) NOT NULL,
        at BIGINT NOT NULL,
        KEY order_idx (order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS courier_positions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id VARCHAR(16) NOT NULL,
        lat DECIMAL(10,6) NOT NULL,
        lng DECIMAL(10,6) NOT NULL,
        accuracy INT NOT NULL DEFAULT 0,
        at BIGINT NOT NULL,
        KEY order_idx (order_id),
        KEY at_idx (at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

const CATEGORIES = [
    ['glaces',   'Glaces',   'ice',  0],
    ['boissons', 'Boissons', 'cup',  1],
    ['encas',    'Encas',    'food', 2],
];

const PRODUITS = [
    // catégorie, nom, description, prix en centimes, illustration
    ['glaces', 'Glace Vanille', 'Crème glacée vanille de Madagascar', 350, 'glace-corne.svg'],
    ['glaces', 'Glace Chocolat', 'Chocolat noir intense, cacao 70 %', 350, 'glace-pot.svg'],
    ['glaces', 'Glace Fraise', 'Fraise de saison, texture onctueuse', 350, 'glace-pot.svg'],
    ['glaces', 'Caramel Beurre Salé', 'Le goût normand, caramel au beurre salé', 400, 'glace-corne.svg'],
    ['glaces', 'Sorbet Citron', 'Sorbet citron de Menton, sans lait', 350, 'glace-pot.svg'],
    ['glaces', 'Coupe 2 boules', 'Deux boules au choix, chantilly maison', 550, 'coupe.svg'],
    ['glaces', 'Bâtonnet Chocolat', 'Bâtonnet glacé enrobé de chocolat', 400, 'batonnet.svg'],
    ['boissons', 'Eau minérale 50 cl', 'Eau plate bien fraîche', 200, 'eau.svg'],
    ['boissons', 'Perrier 33 cl', 'Eau pétillante, citron vert', 300, 'eau.svg'],
    ['boissons', 'Coca-Cola 33 cl', 'Canette glacée', 300, 'soda.svg'],
    ['boissons', 'Ice Tea Pêche 33 cl', 'Thé glacé à la pêche', 300, 'soda.svg'],
    ['boissons', 'Limonade artisanale', 'Limonade de Normandie, peu sucrée', 350, 'limonade.svg'],
    ['boissons', 'Jus de pomme AOP', 'Jus de pomme du Pays d’Auge', 400, 'jus.svg'],
    ['boissons', 'Bière blonde locale', 'Blonde artisanale 33 cl, 5 %', 500, 'biere.svg'],
    ['boissons', 'Café glacé', 'Espresso allongé, glace, lait froid', 400, 'cafe.svg'],
    ['encas', 'Salade César', 'Poulet, parmesan, croûtons, sauce César', 950, 'salade.svg'],
    ['encas', 'Panini Jambon-Fromage', 'Pain grillé, jambon, emmental', 750, 'panini.svg'],
    ['encas', 'Croque-Monsieur', 'Béchamel maison, jambon, gruyère', 700, 'panini.svg'],
    ['encas', 'Frites maison', 'Pommes de terre fraîches, sel de Guérande', 450, 'frites.svg'],
    ['encas', 'Chips artisanales', 'Chips de pomme de terre, sel fin', 300, 'frites.svg'],
    ['encas', 'Crêpe sucre', 'Crêpe fine, sucre, citron en option', 400, 'crepe.svg'],
    ['encas', 'Cookie maison', 'Chocolat noir, fleur de sel', 350, 'cookie.svg'],
    ['encas', 'Fruits frais découpés', 'Melon, pastèque, ananas, menthe', 500, 'fruits.svg'],
];

/* ------------------------------------------------------------ installation */
if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['run'])) {
    try {
        $d = cfg('db');
        if (strpos((string) $d['name'], 'u000000000') !== false) {
            throw new RuntimeException("Les identifiants de la base ne sont pas renseignés dans config.php.");
        }

        $existing = (int) q_val("SELECT COUNT(*) FROM information_schema.tables
                                 WHERE table_schema = ? AND table_name = 'orders'", [$d['name']], 0);

        if ($existing && !$force) {
            throw new RuntimeException("Le site semble déjà installé (table « orders » présente). "
                . "Pour réinstaller malgré tout, ajoutez ?force=1 à l'adresse.");
        }

        // 1. tables
        foreach (SCHEMA as $sql) db()->exec($sql);
        $messages[] = 'Tables créées (' . count(SCHEMA) . ').';

        // 2. catégories
        foreach (CATEGORIES as $c) {
            q('INSERT INTO categories (id, name, icon, sort) VALUES (?,?,?,?)
               ON DUPLICATE KEY UPDATE name = VALUES(name), icon = VALUES(icon), sort = VALUES(sort)',
                [$c[0], $c[1], $c[2], $c[3]]);
        }
        $messages[] = count(CATEGORIES) . ' catégories prêtes.';

        // 3. produits (identifiants stables : un panier client ne se casse jamais)
        $slug = static function (string $txt): string {
            $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $txt) ?: $txt;
            $s = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $s) ?? '');
            return trim($s, '-');
        };
        $n = 0;
        foreach (PRODUITS as $i => $p) {
            $pid = 'p_' . substr($slug($p[1]), 0, 32);
            q('INSERT INTO products (id, cat_id, name, description, price, img, available, sort, created_at, updated_at)
               VALUES (?,?,?,?,?,?,1,?,?,?)
               ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description),
                                       price = VALUES(price), img = VALUES(img), cat_id = VALUES(cat_id),
                                       sort = VALUES(sort), updated_at = VALUES(updated_at)', [
                $pid, $p[0], $p[1], $p[2], $p[3], $p[4], $i, now_ms(), now_ms(),
            ]);
            $n++;
        }
        $messages[] = $n . ' produits au catalogue.';

        // 4. réglages
        foreach (cfg('defaults') as $k => $v) {
            if (setting_get($k, null) === null) setting_set($k, is_bool($v) ? ($v ? 1 : 0) : $v);
        }
        if (setting_get('zone', null) === null) setting_set('zone', json_encode(cfg('zone'), JSON_UNESCAPED_UNICODE));
        if (setting_get('seq', null) === null) setting_set('seq', 1041);
        $messages[] = 'Réglages enregistrés.';

        // 5. comptes
        $seedAdmin = cfg('seed.admin');
        $seedCourier = cfg('seed.courier');
        $adminId = (string) q_val('SELECT id FROM users WHERE role = ? LIMIT 1', ['admin'], '');
        if ($adminId === '') {
            q('INSERT INTO users (id, role, name, email, phone, pass_hash, active, created_at) VALUES (?,?,?,?,?,?,1,?)',
                ['u_admin', 'admin', $seedAdmin['name'], strtolower($seedAdmin['email']), '',
                 hash_password($seedAdmin['password']), now_ms()]);
            $messages[] = 'Compte administrateur créé : ' . $seedAdmin['email'];
        } else {
            $messages[] = 'Compte administrateur déjà présent (mot de passe inchangé).';
        }

        if (!q_val("SELECT id FROM users WHERE role = 'courier' LIMIT 1")) {
            q('INSERT INTO users (id, role, name, email, phone, pass_hash, active, online, created_at) VALUES (?,?,?,?,?,?,1,0,?)',
                ['u_courier', 'courier', $seedCourier['name'], strtolower($seedCourier['email']), '06 12 34 56 78',
                 hash_password($seedCourier['password']), now_ms()]);
            $messages[] = 'Compte livreur créé : ' . $seedCourier['email'];
        } else {
            $messages[] = 'Compte livreur déjà présent.';
        }

        // 6. dossier data protégé (limitation de débit, journaux)
        $dataDir = PC_ROOT . '/data';
        if (!is_dir($dataDir)) @mkdir($dataDir, 0755, true);
        @file_put_contents($dataDir . '/.htaccess', "Require all denied\n");
        @file_put_contents($dataDir . '/index.html', '');

        $done = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Installation — PANNACOTTACLUB</title>
<style>
  body { margin:0; background:#EEF0FF; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; color:#090A33; padding:26px 18px; }
  .card { max-width:640px; margin:0 auto; background:#fff; border-radius:22px; padding:28px; box-shadow:0 18px 50px rgba(6,12,212,.14); }
  h1 { color:#060CD4; font-size:1.6rem; margin:0 0 6px; }
  h2 { font-size:1.05rem; margin:24px 0 8px; }
  code { background:#F4F5FF; padding:2px 7px; border-radius:6px; font-size:.92em; }
  .ok { background:#F4F5FF; border:1px solid rgba(6,12,212,.18); border-radius:14px; padding:13px 16px; margin:9px 0; }
  .err { background:#FFF5F6; border:1px solid rgba(176,0,32,.25); color:#7A0016; border-radius:14px; padding:13px 16px; margin:9px 0; }
  .warn { background:#FFFBF0; border:1px solid rgba(180,140,0,.3); color:#6B5200; border-radius:14px; padding:13px 16px; margin:9px 0; }
  .btn { display:inline-block; background:#060CD4; color:#fff; text-decoration:none; font-weight:700; padding:14px 22px; border-radius:14px; border:0; cursor:pointer; font-size:1rem; }
  .btn-ghost { background:#fff; color:#060CD4; border:1.5px solid #060CD4; }
  a { color:#060CD4; }
  table { width:100%; border-collapse:collapse; margin-top:10px; font-size:.92rem; }
  td { padding:7px 4px; border-bottom:1px solid #EFF0F8; vertical-align:top; }
  td:first-child { color:#5A5E8C; width:44%; }
  img.logo { width:74px; display:block; margin:0 auto 10px; }
</style>
</head>
<body>
<div class="card">
  <img class="logo" src="assets/img/logo.png" alt="">
  <h1>PANNACOTTACLUB — installation</h1>
  <p style="color:#5A5E8C;margin:0">Mise en place de la base de données, du catalogue et des comptes.</p>

  <?php if ($error): ?>
    <div class="err"><strong>Installation interrompue.</strong><br><?= htmlspecialchars($error) ?></div>
    <h2>Que faire ?</h2>
    <ol style="color:#5A5E8C;font-size:.94rem;line-height:1.6">
      <li>Ouvrez <code>config.php</code> et renseignez les 4 valeurs de la base MySQL
          (hPanel → Bases de données → MySQL).</li>
      <li>Vérifiez que la base et l'utilisateur sont bien créés, et que l'utilisateur
          est associé à la base (bouton « Attribuer »).</li>
      <li>Rechargez cette page.</li>
    </ol>
    <p><a class="btn btn-ghost" href="install.php">Réessayer</a></p>

  <?php elseif ($done): ?>
    <div class="ok"><strong>Installation terminée ✔</strong></div>
    <?php foreach ($messages as $m): ?>
      <div class="ok" style="background:#fff">✔ <?= htmlspecialchars($m) ?></div>
    <?php endforeach; ?>

    <h2>Vos accès</h2>
    <table>
      <tr><td>Administration</td><td><code>/admin</code></td></tr>
      <tr><td>Email administrateur</td><td><code><?= htmlspecialchars(cfg('seed.admin.email')) ?></code></td></tr>
      <tr><td>Mot de passe administrateur</td><td><code><?= htmlspecialchars(cfg('seed.admin.password')) ?></code></td></tr>
      <tr><td>Espace livreur</td><td><code>/livreur</code></td></tr>
      <tr><td>Email livreur</td><td><code><?= htmlspecialchars(cfg('seed.courier.email')) ?></code></td></tr>
      <tr><td>Mot de passe livreur</td><td><code><?= htmlspecialchars(cfg('seed.courier.password')) ?></code></td></tr>
    </table>

    <div class="warn">
      <strong>Trois choses à faire maintenant :</strong>
      <ol style="margin:8px 0 0;padding-left:20px;line-height:1.6">
        <li><strong>Supprimez ce fichier</strong> <code>install.php</code> (Gestionnaire de fichiers Hostinger).</li>
        <li><strong>Changez les mots de passe</strong> ci-dessus (Admin → Livreurs).</li>
        <li>Activez le <strong>SSL gratuit</strong> (hPanel → Sécurité → SSL) : le GPS du navigateur
            exige HTTPS.</li>
      </ol>
    </div>

    <p style="margin-top:22px">
      <a class="btn" href="admin">Ouvrir l'administration</a>
      <a class="btn btn-ghost" href="commander">Voir le site client</a>
    </p>
    <p style="color:#5A5E8C;font-size:.86rem">Puis, dans Admin → Panneaux QR : imprimez le panneau de plage
       et vérifiez que le QR code pointe bien vers votre domaine.</p>

  <?php else: ?>
    <h2>Avant de commencer</h2>
    <p style="color:#5A5E8C;font-size:.94rem">Vérifiez que <code>config.php</code> contient bien les
       identifiants de votre base MySQL Hostinger. L'installation crée les tables, le catalogue
       (23 produits) et les deux comptes de départ.</p>
    <table>
      <tr><td>Base de données</td><td><code><?= htmlspecialchars(cfg('db.name')) ?></code></td></tr>
      <tr><td>Utilisateur MySQL</td><td><code><?= htmlspecialchars(cfg('db.user')) ?></code></td></tr>
      <tr><td>Hôte</td><td><code><?= htmlspecialchars(cfg('db.host')) ?></code></td></tr>
      <tr><td>Version de PHP</td><td><code><?= PHP_VERSION ?></code></td></tr>
    </table>
    <form method="post" style="margin-top:20px">
      <button class="btn" type="submit">Installer maintenant</button>
    </form>
    <p style="color:#5A5E8C;font-size:.86rem;margin-top:14px">
      Une réinstallation ne supprime ni les commandes ni les comptes existants.
    </p>
  <?php endif; ?>
</div>
</body>
</html>
