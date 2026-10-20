<?php
/* ==========================================================================
   PANNACOTTACLUB — API (PHP/MySQL)
   --------------------------------------------------------------------------
   Le .htaccess réécrit les adresses de la forme /api/... vers ce fichier :
   par exemple /api/menu devient api.php?action=menu.
   Les réponses ont exactement le même format que la version Node : le site
   (HTML/CSS/JS) fonctionne à l'identique.
   ========================================================================== */

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/orders.php';

$action = (string) ($_GET['action'] ?? '');
$id     = isset($_GET['id']) ? (string) $_GET['id'] : '';
$M      = method();

// Site non installé : on répond proprement au lieu d'une erreur serveur
if (!tables_ready()) {
    api_fail(503, "Le site n'est pas encore installé. Ouvrez install.php dans votre navigateur.", ['code' => 'NOT_INSTALLED']);
}

housekeeping();

try {
    switch ($action) {

        /* ------------------------------------------------------------ public */
        case 'health':
            api_ok([
                'up'       => true,
                'php'      => PHP_VERSION,
                'orders'   => (int) q_val('SELECT COUNT(*) FROM orders', [], 0),
                'couriers' => (int) q_val("SELECT COUNT(*) FROM users WHERE role='courier'", [], 0),
                'at'       => now_ms(),
            ]);
            break;

        case 'config':
            api_ok([
                'brand'     => cfg('brand'),
                'settings'  => public_settings(),
                'statuses'  => PC_STATUSES,
                'timeline'  => PC_TIMELINE,
                'categories' => categories_all(),
                'publicUrl' => public_url(),
                'realtime'  => 'polling',   // hébergement mutualisé : interrogation régulière
                'couriers'  => live_couriers(),   // carte du livreur en direct (données arrondies, sans info client)
                'map'       => public_map(),        // repères de vente à dessiner sur le plan
            ]);
            break;

        // ------------------------------------------------ carte du livreur en direct
        // Lecture seule, aucune donnée personnelle : voir live_couriers().
        case 'couriers.live':
            api_ok([
                'couriers' => live_couriers(),
                'zone'     => zone(),
                'at'       => now_ms(),
            ]);
            break;

        case 'menu':
            api_ok([
                'categories' => categories_all(),
                'products'   => menu_products(false),
                'updatedAt'  => (int) q_val('SELECT COALESCE(MAX(updated_at), 0) FROM products', [], 0),
            ]);
            break;

        /* ------------------------------------------------------- commandes */
        case 'order.create':
            rate_limit('order', 25, 60);
            if (!bool_setting('accepting_orders', true)) {
                api_fail(503, 'Le service est momentanément fermé. Merci de réessayer dans quelques minutes.');
            }

            $customer  = body('customer', []);
            $firstName = clean($customer['firstName'] ?? '', 40);
            if (mb_strlen($firstName) < 2) api_fail(422, 'Merci d’indiquer votre prénom.');
            $phone = normalize_phone($customer['phone'] ?? '');
            if (!$phone) api_fail(422, 'Merci d’indiquer un numéro de téléphone valide (10 chiffres).');
            $note = clean($customer['note'] ?? '', 160);

            // Position du client. Si le navigateur refuse ou ne trouve pas la
            // position, on accepte la commande quand même : le livreur appellera
            // et le client peut écrire une indication libre. Seul un point
            // réellement mesuré et HORS zone est refusé.
            $rawLocation = body('location');
            $hasPosition = is_array($rawLocation)
                && ((isset($rawLocation['lat']) && $rawLocation['lat'] !== null)
                    || (isset($rawLocation['coords']['latitude']) && $rawLocation['coords']['latitude'] !== null));
            $location = null;
            if ($hasPosition) {
                $location = normalize_position($rawLocation);
                $inZone = within_zone($location, zone());
                // Une mesure à ± 150 m et plus ne permet pas de juger : on accepte
                // la commande (le livreur appellera), plutôt que de refuser une
                // vraie commande sur la plage à cause d'un mauvais GPS.
                if (bool_setting('require_in_zone', true) && !$inZone && (int) $location['accuracy'] < 150) {
                    api_fail(422, 'Vous semblez en dehors de la zone de livraison (plage de Cabourg, entre les postes de secours 1 et 2).', ['code' => 'OUT_OF_ZONE']);
                }
            } else {
                $inZone = (bool) setting_get('require_in_zone', true) === false; // sans mesure : pas de jugement
            }

            $rawItems = body('items', []);
            if (!is_array($rawItems) || !$rawItems) api_fail(422, 'Votre panier est vide.');
            $rawItems = array_slice($rawItems, 0, 40);

            $items = [];
            foreach ($rawItems as $it) {
                $pid = clean($it['productId'] ?? '', 64);
                $qty = max(1, min(20, (int) round((float) ($it['qty'] ?? 0))));
                $p = q_one('SELECT * FROM products WHERE id = ? AND available = 1', [$pid]);
                if (!$p) continue;
                if (isset($items[$pid])) $items[$pid]['qty'] = min(20, $items[$pid]['qty'] + $qty);
                else $items[$pid] = ['productId' => $p['id'], 'name' => $p['name'], 'price' => (int) $p['price'], 'qty' => $qty, 'img' => $p['img']];
            }
            if (!$items) api_fail(409, 'Ces produits ne sont plus disponibles. Merci de mettre à jour votre panier.');

            // Le prix est TOUJOURS recalculé ici : le client ne fixe jamais le montant.
            $subtotal = 0;
            foreach ($items as $i) $subtotal += $i['price'] * $i['qty'];
            $deliveryFee = 0;
            $total = $subtotal + $deliveryFee;
            if ($total > (int) cfg('max_order_eur', 300) * 100) api_fail(422, 'Commande trop importante : contactez-nous directement.');

            $orderId = next_order_number();
            $trackToken = new_token(24);
            $now = now_ms();
            q('INSERT INTO orders (id, track_token, status, payment_status, subtotal, delivery_fee, total,
                                   first_name, phone, note, lat, lng, accuracy, location_at,
                                   courier_id, auto_assigned, out_of_zone, created_at, updated_at)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NULL,0,?,?,?)', [
                $orderId, $trackToken, 'received', 'pending', $subtotal, $deliveryFee, $total,
                $firstName, $phone, $note,
                $location ? $location['lat'] : null,
                $location ? $location['lng'] : null,
                $location ? $location['accuracy'] : null,
                $location ? $location['at'] : null,
                $inZone ? 0 : 1, $now, $now,
            ]);
            foreach ($items as $i) {
                q('INSERT INTO order_items (order_id, product_id, name, price, qty, img) VALUES (?,?,?,?,?,?)',
                    [$orderId, $i['productId'], $i['name'], $i['price'], $i['qty'], $i['img']]);
            }
            order_add_event($orderId, 'received');

            $order = order_full($orderId);

            // --- assignation automatique : le livreur en service reçoit la commande tout de suite
            $assigned = false;
            if (bool_setting('auto_assign', true)) {
                $courier = pick_courier($location);
                if ($courier) { assign_order($order, $courier['id'], true); $assigned = true; }
            }

            $public = public_order($order);
            api_ok([
                'order'        => $public,
                'trackUrl'     => '/suivi?id=' . rawurlencode($orderId) . '&t=' . rawurlencode($trackToken),
                'token'        => $trackToken,
                'autoAssigned' => $assigned,
            ]);
            break;

        case 'order.get':
            $order = order_by_token((string) ($_GET['t'] ?? ''));
            if (!$order || ($id !== '' && $order['id'] !== $id)) api_fail(404, 'Commande introuvable.');
            api_ok(['order' => public_order($order), 'timeline' => PC_TIMELINE, 'statuses' => PC_STATUSES]);
            break;

        case 'order.location':
            // Le client peut envoyer (ou corriger) sa position après la commande
            // (« Je n'avais pas la 4G tout de suite »). Autorisation : jeton de
            // suivi uniquement — celui qui connaît le lien de suivi est le client.
            $order = order_by_token((string) ($_GET['t'] ?? body('trackToken') ?? ''));
            if (!$order) api_fail(404, 'Suivi introuvable.');
            $status = (string) $order['status'];
            if (in_array($status, ['delivered', 'cancelled'], true)) {
                api_fail(409, 'Cette commande est terminée : la position n\'est plus modifiable.');
            }
            $pos = normalize_position(body_json());
            q('UPDATE orders SET lat = ?, lng = ?, accuracy = ?, location_at = ?, out_of_zone = ?, updated_at = ? WHERE id = ?', [
                $pos['lat'], $pos['lng'], $pos['accuracy'], $pos['at'],
                (bool) within_zone($pos, zone()) ? 0 : 1, now_ms(), $order['id'],
            ]);
            api_ok(['order' => public_order(order_full($order['id']))]);
            break;

        case 'auth.login':
            rate_limit('login', 60, 300);
            $email = strtolower(clean(body('identifier', ''), 120));
            $password = (string) body('password', '');
            $wait = login_blocked($email);
            if ($wait > 0) api_fail(429, "Trop de tentatives. Réessayez dans $wait secondes.");

            $user = q_one('SELECT * FROM users WHERE LOWER(email) = ?', [$email]);
            if (!$user || !verify_password($password, $user['pass_hash'])) {
                login_note_fail($email);
                api_fail(401, 'Identifiants incorrects.');
            }
            if ((int) $user['active'] !== 1) api_fail(403, 'Ce compte est désactivé.');

            $role = clean(body('role', ''), 20);
            if ($role === 'courier' && $user['role'] !== 'courier' && $user['role'] !== 'admin') {
                api_fail(403, 'Compte livreur uniquement.');
            }
            login_clear_fail($email);

            $remember = body('remember', true) !== false;
            $token = session_create($user['id'], $remember);
            api_ok([
                'token'         => $token,
                'user'          => public_user($user),
                'expiresInDays' => $remember ? (int) cfg('session_days', 90) : 0.5,
            ]);
            break;

        case 'auth.logout':
            destroy_session();
            api_ok([]);
            break;

        case 'auth.me':
            $u = current_user();
            if (!$u) api_fail(401, 'Session absente.', ['code' => 'SESSION']);
            api_ok(['user' => public_user($u)]);
            break;

        /* ------------------------------------------------------- livreur */
        case 'courier.dashboard':
            $me = require_login();
            if ($me['role'] !== 'courier') api_fail(403, 'Espace réservé aux livreurs.');

            $mine = q_all("SELECT * FROM orders WHERE courier_id = ? AND status NOT IN ('delivered','cancelled')
                           ORDER BY created_at", [$me['id']]);
            $queue = bool_setting('auto_assign', true) ? [] : q_all(
                "SELECT * FROM orders WHERE courier_id IS NULL AND status IN ('received','preparing','ready') ORDER BY created_at");
            $done = q_all("SELECT * FROM orders WHERE courier_id = ? AND status = 'delivered' ORDER BY delivered_at DESC LIMIT 12", [$me['id']]);

            $cashToday = (int) q_val("SELECT COALESCE(SUM(total),0) FROM orders WHERE courier_id = ? AND payment_status = 'paid'
                                      AND paid_at >= ?", [$me['id'], strtotime('today') * 1000], 0);
            $unpaid = (int) q_val("SELECT COALESCE(SUM(total),0) FROM orders WHERE courier_id = ? AND status = 'delivered'
                                   AND payment_status <> 'paid'", [$me['id']], 0);

            api_ok([
                'courier'  => array_merge(public_user($me), [
                    'lastPos' => courier_position($me),
                ]),
                'settings' => ['autoAssign' => bool_setting('auto_assign', true), 'zone' => zone()],
                'orders'   => array_map('courier_order', array_map('order_full', array_column($mine, 'id'))),
                'queue'    => array_map('courier_order', array_map('order_full', array_column($queue, 'id'))),
                'recent'   => array_map('courier_order', array_map('order_full', array_column($done, 'id'))),
                'stats'    => [
                    'delivered' => (int) q_val("SELECT COUNT(*) FROM orders WHERE courier_id = ? AND status='delivered'", [$me['id']], 0),
                    'cashToday' => $cashToday,
                    'unpaid'    => $unpaid,
                ],
            ]);
            break;

        case 'courier.online':
            $me = require_login();
            if ($me['role'] !== 'courier') api_fail(403, 'Espace réservé aux livreurs.');
            $online = body('online', true) !== false ? 1 : 0;
            q('UPDATE users SET online = ? WHERE id = ?', [$online, $me['id']]);
            api_ok(['online' => (bool) $online]);
            break;

        case 'courier.accept':
            $me = require_login();
            if ($me['role'] !== 'courier') api_fail(403, 'Espace réservé aux livreurs.');
            $order = order_full($id);
            if (!$order) api_fail(404, 'Commande introuvable.');
            if ($order['courier_id'] && $order['courier_id'] !== $me['id']) api_fail(409, 'Cette commande est déjà prise en charge.');
            if (!$order['courier_id']) assign_order($order, $me['id'], false);
            api_ok(['order' => courier_order($order)]);
            break;

        case 'courier.status':
            $me = require_login();
            if ($me['role'] !== 'courier') api_fail(403, 'Espace réservé aux livreurs.');
            $order = order_full($id);
            if (!$order) api_fail(404, 'Commande introuvable.');
            if ($order['courier_id'] !== $me['id']) api_fail(403, 'Commande non assignée à ce livreur.');
            $status = clean(body('status', ''), 20);
            if (!isset(PC_STATUSES[$status]) || in_array($status, ['received', 'assigned'], true)) api_fail(422, 'Statut invalide.');
            if ($order['status'] === 'delivered') api_fail(409, 'Commande déjà livrée.');
            order_set_status($order, $status);
            api_ok(['order' => courier_order($order)]);
            break;

        case 'courier.paid':
            $me = require_login();
            if ($me['role'] !== 'courier') api_fail(403, 'Espace réservé aux livreurs.');
            $order = order_full($id);
            if (!$order) api_fail(404, 'Commande introuvable.');
            if ($order['courier_id'] !== $me['id']) api_fail(403, 'Accès refusé.');
            $paid = body('paid', true) !== false;
            q('UPDATE orders SET payment_status = ?, paid_at = ?, updated_at = ? WHERE id = ?',
                [$paid ? 'paid' : 'pending', $paid ? now_ms() : null, now_ms(), $order['id']]);
            api_ok(['order' => courier_order(order_full($order['id']))]);
            break;

        case 'courier.location':
            $me = require_login();
            if ($me['role'] !== 'courier') api_fail(403, 'Espace réservé aux livreurs.');
            rate_limit('pos', 600, 60);
            $pos = normalize_position(body_json());
            q('UPDATE users SET last_lat = ?, last_lng = ?, last_accuracy = ?, last_seen_at = ? WHERE id = ?',
                [$pos['lat'], $pos['lng'], $pos['accuracy'], now_ms(), $me['id']]);

            // Suivi automatique : « proche » puis « arrivé » selon la distance réelle
            $active = q_all("SELECT * FROM orders WHERE courier_id = ? AND status IN ('en_route','nearby','arrived')", [$me['id']]);
            foreach ($active as $row) {
                $o = order_full($row['id']);
                if (!$o['location']) continue;
                $d = distance_m($pos, $o['location']);
                // trace GPS (supprimée à la livraison — voir plus bas)
                q('INSERT INTO courier_positions (order_id, lat, lng, accuracy, at) VALUES (?,?,?,?,?)',
                    [$o['id'], $pos['lat'], $pos['lng'], $pos['accuracy'], now_ms()]);
                if ($o['status'] === 'en_route' && $d <= (float) setting_get('nearby_radius', 150)) order_set_status($o, 'nearby');
                elseif (in_array($o['status'], ['en_route', 'nearby'], true) && $d <= (float) setting_get('arrived_radius', 45)) order_set_status($o, 'arrived');
            }
            // Livraison terminée : on efface les traces GPS (confidentialité)
            q("DELETE FROM courier_positions WHERE order_id IN (SELECT id FROM orders WHERE status = 'delivered')");
            api_ok(['saved' => true, 'at' => now_ms()]);
            break;

        /* -------------------------------------------------------- admin */
        case 'admin.state':
            require_role('admin');
            $orders = q_all('SELECT id FROM orders ORDER BY created_at DESC LIMIT 200');
            $todayStart = strtotime('today') * 1000;
            $stats = [
                'ordersToday'    => (int) q_val('SELECT COUNT(*) FROM orders WHERE created_at >= ?', [$todayStart], 0),
                'revenueToday'   => (int) q_val("SELECT COALESCE(SUM(total),0) FROM orders WHERE created_at >= ? AND status <> 'cancelled'", [$todayStart], 0),
                'active'         => (int) q_val("SELECT COUNT(*) FROM orders WHERE status IN ('received','preparing','ready','assigned','en_route','nearby','arrived')", [], 0),
                'unassigned'     => (int) q_val("SELECT COUNT(*) FROM orders WHERE courier_id IS NULL AND status IN ('received','preparing','ready')", [], 0),
                'unpaid'         => (int) q_val("SELECT COUNT(*) FROM orders WHERE status='delivered' AND payment_status <> 'paid'", [], 0),
                'onlineCouriers' => (int) q_val("SELECT COUNT(*) FROM users WHERE role='courier' AND active=1 AND online=1", [], 0),
            ];
            $couriers = [];
            foreach (q_all("SELECT * FROM users WHERE role='courier' ORDER BY created_at") as $c) {
                $couriers[] = array_merge(public_user($c), [
                    'lastPos'      => courier_position($c),
                    'createdAt'    => (int) $c['created_at'],
                    'activeOrders' => courier_load($c['id']),
                    'delivered'    => (int) q_val("SELECT COUNT(*) FROM orders WHERE courier_id = ? AND status='delivered'", [$c['id']], 0),
                    'active'       => (int) $c['active'] === 1,
                ]);
            }
            api_ok([
                'products'   => menu_products(true),
                'categories' => categories_all(),
                'couriers'   => $couriers,
                'orders'     => array_map('admin_order', array_map('order_full', array_column($orders, 'id'))),
                'settings'   => admin_settings(),
                'publicUrl'  => public_url(),
                'stats'      => $stats,
                'statuses'   => PC_STATUSES,
                'timeline'   => PC_TIMELINE,
            ]);
            break;

        /* --- produits : création / modification / suppression / disponibilité */
        case 'admin.product.create':
            require_role('admin');
            $name = clean(body('name', ''), 60);
            $price = (int) round((float) body('price', 0));
            $catId = clean(body('catId', ''), 30);
            if (mb_strlen($name) < 2) api_fail(422, 'Nom trop court.');
            if ($price < 0 || $price > 50000) api_fail(422, 'Prix invalide.');
            if (!q_val('SELECT id FROM categories WHERE id = ?', [$catId])) api_fail(422, 'Catégorie inconnue.');
            $newId = 'p_' . substr(md5($name . microtime(true)), 0, 10);
            q('INSERT INTO products (id, cat_id, name, description, price, img, available, sort, created_at, updated_at)
               VALUES (?,?,?,?,?,?,?,?,?,?)', [
                $newId, $catId, $name, clean(body('description', ''), 160), $price,
                clean(body('img', ''), 200) ?: 'glace-corne.svg',
                body('available', true) !== false ? 1 : 0,
                (int) q_val('SELECT COALESCE(MAX(sort),0)+1 FROM products', [], 1),
                now_ms(), now_ms(),
            ]);
            api_ok(['product' => menu_products(true)[0] ?? null, 'id' => $newId]);
            break;

        case 'admin.product.item':    // PUT = modifier, DELETE = supprimer
            require_role('admin');
            $p = q_one('SELECT * FROM products WHERE id = ?', [$id]);
            if (!$p) api_fail(404, 'Produit introuvable.');

            if ($M === 'DELETE') {
                q('DELETE FROM products WHERE id = ?', [$id]);
                api_ok(['removed' => $id]);
            }
            $b = body_json();
            $fields = [];
            $vals = [];
            if (array_key_exists('name', $b)) {
                $n = clean($b['name'], 60);
                if (mb_strlen($n) < 2) api_fail(422, 'Nom trop court.');
                $fields[] = 'name = ?'; $vals[] = $n;
            }
            if (array_key_exists('description', $b)) { $fields[] = 'description = ?'; $vals[] = clean($b['description'], 160); }
            if (array_key_exists('price', $b)) {
                $price = (int) round((float) $b['price']);
                if ($price < 0 || $price > 50000) api_fail(422, 'Prix invalide.');
                $fields[] = 'price = ?'; $vals[] = $price;
            }
            if (array_key_exists('catId', $b)) {
                $cat = clean($b['catId'], 30);
                if (!q_val('SELECT id FROM categories WHERE id = ?', [$cat])) api_fail(422, 'Catégorie inconnue.');
                $fields[] = 'cat_id = ?'; $vals[] = $cat;
            }
            if (array_key_exists('img', $b)) { $fields[] = 'img = ?'; $vals[] = clean($b['img'], 200); }
            if (array_key_exists('available', $b)) { $fields[] = 'available = ?'; $vals[] = $b['available'] ? 1 : 0; }
            if (array_key_exists('sort', $b)) { $fields[] = 'sort = ?'; $vals[] = (int) $b['sort']; }
            if (!$fields) api_ok(['product' => menu_products(true)]);
            $fields[] = 'updated_at = ?'; $vals[] = now_ms();
            $vals[] = $id;
            q('UPDATE products SET ' . implode(', ', $fields) . ' WHERE id = ?', $vals);
            api_ok(['updated' => $id]);
            break;

        /* --- livreurs */
        case 'admin.courier.create':
            require_role('admin');
            $name = clean(body('name', ''), 40);
            $email = strtolower(clean(body('email', ''), 120));
            $password = (string) body('password', '');
            if (mb_strlen($name) < 2) api_fail(422, 'Nom trop court.');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) api_fail(422, 'Email invalide.');
            if (q_val('SELECT id FROM users WHERE LOWER(email) = ?', [$email])) api_fail(409, 'Cet email est déjà utilisé.');
            if (strlen($password) < 6) api_fail(422, 'Mot de passe : 6 caractères minimum.');
            $uid = new_id('u_');
            q('INSERT INTO users (id, role, name, email, phone, pass_hash, active, online, created_at)
               VALUES (?,?,?,?,?,?,1,0,?)', [
                $uid, 'courier', $name, $email, normalize_phone(body('phone', '')) ?? clean(body('phone', ''), 30),
                hash_password($password), now_ms(),
            ]);
            api_ok(['courier' => public_user(q_one('SELECT * FROM users WHERE id = ?', [$uid]))]);
            break;

        case 'admin.courier.item':    // PUT = modifier/désactiver, DELETE = supprimer
            require_role('admin');
            $c = q_one("SELECT * FROM users WHERE id = ? AND role = 'courier'", [$id]);
            if (!$c) api_fail(404, 'Livreur introuvable.');

            if ($M === 'DELETE') {
                $active = courier_load($id);
                if ($active > 0) {
                    // jamais de suppression avec des livraisons en cours : on désactive
                    q('UPDATE users SET active = 0, online = 0 WHERE id = ?', [$id]);
                    q('DELETE FROM sessions WHERE user_id = ?', [$id]);
                    api_ok(['removed' => $id, 'keptForActiveOrders' => $active]);
                }
                q('DELETE FROM sessions WHERE user_id = ?', [$id]);
                q('DELETE FROM users WHERE id = ?', [$id]);
                api_ok(['removed' => $id]);
            }

            $b = body_json();
            $fields = []; $vals = [];
            if (array_key_exists('name', $b)) { $fields[] = 'name = ?'; $vals[] = clean($b['name'], 40); }
            if (array_key_exists('phone', $b)) { $fields[] = 'phone = ?'; $vals[] = normalize_phone($b['phone']) ?? clean($b['phone'], 30); }
            if (array_key_exists('email', $b)) {
                $email = strtolower(clean($b['email'], 120));
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) api_fail(422, 'Email invalide.');
                $other = q_val('SELECT id FROM users WHERE LOWER(email) = ? AND id <> ?', [$email, $id]);
                if ($other) api_fail(409, 'Cet email est déjà utilisé.');
                $fields[] = 'email = ?'; $vals[] = $email;
            }
            if (!empty($b['password'])) {
                if (strlen((string) $b['password']) < 6) api_fail(422, 'Mot de passe : 6 caractères minimum.');
                $fields[] = 'pass_hash = ?'; $vals[] = hash_password((string) $b['password']);
            }
            if (array_key_exists('active', $b)) { $fields[] = 'active = ?'; $vals[] = $b['active'] ? 1 : 0; }
            if (array_key_exists('online', $b)) { $fields[] = 'online = ?'; $vals[] = $b['online'] ? 1 : 0; }
            if ($fields) { $vals[] = $id; q('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?', $vals); }
            if (array_key_exists('active', $b) && !$b['active']) q('DELETE FROM sessions WHERE user_id = ?', [$id]);
            api_ok(['courier' => public_user(q_one('SELECT * FROM users WHERE id = ?', [$id]))]);
            break;

        /* --- commandes (admin) */
        case 'admin.order.update':
            require_role('admin');
            $order = order_full($id);
            if (!$order) api_fail(404, 'Commande introuvable.');
            $b = body_json();
            if (!empty($b['status'])) {
                $st = clean($b['status'], 20);
                if (!isset(PC_STATUSES[$st])) api_fail(422, 'Statut invalide.');
                order_set_status($order, $st);
            }
            if (array_key_exists('courierId', $b)) {
                if (!$b['courierId']) {
                    q('UPDATE orders SET courier_id = NULL, assigned_at = NULL, updated_at = ? WHERE id = ?', [now_ms(), $order['id']]);
                    if ($order['status'] === 'assigned') { q("UPDATE orders SET status='received' WHERE id = ?", [$order['id']]); order_add_event($order['id'], 'received'); }
                } else {
                    $courier = q_one("SELECT id FROM users WHERE id = ? AND role = 'courier'", [clean($b['courierId'], 32)]);
                    if (!$courier) api_fail(422, 'Livreur inconnu.');
                    assign_order($order, $courier['id'], false);
                }
                $order = order_full($order['id']);
            }
            if (!empty($b['paymentStatus'])) {
                $paid = $b['paymentStatus'] === 'paid';
                q('UPDATE orders SET payment_status = ?, paid_at = ?, updated_at = ? WHERE id = ?',
                    [$paid ? 'paid' : 'pending', $paid ? now_ms() : null, now_ms(), $order['id']]);
                $order = order_full($order['id']);
            }
            api_ok(['order' => admin_order($order)]);
            break;

        /* --- réglages */
        case 'admin.settings':
            require_role('admin');
            $b = body_json();
            if (array_key_exists('autoAssign', $b))         setting_set('auto_assign', $b['autoAssign'] ? 1 : 0);
            if (array_key_exists('autoAssignCourierId', $b)) setting_set('auto_assign_courier_id', clean($b['autoAssignCourierId'], 32));
            if (array_key_exists('acceptingOrders', $b))    setting_set('accepting_orders', $b['acceptingOrders'] ? 1 : 0);
            if (array_key_exists('requireInZone', $b))      setting_set('require_in_zone', $b['requireInZone'] ? 1 : 0);
            if (array_key_exists('liveMapPublic', $b))      setting_set('live_map_public', $b['liveMapPublic'] ? 1 : 0);
            if (array_key_exists('googleKey', $b))          setting_set('google_maps_key', clean($b['googleKey'], 200));
            if (array_key_exists('avgSpeed', $b))           setting_set('avg_speed', max(0.5, min(6, (float) $b['avgSpeed'])));
            if (!empty($b['zone']) && is_array($b['zone'])) {
                $z = $b['zone'];
                setting_set('zone', json_encode([
                    'label'  => clean($z['label'] ?? cfg('zone.label'), 80),
                    'lat'    => (float) ($z['lat'] ?? cfg('zone.lat')),
                    'lng'    => (float) ($z['lng'] ?? cfg('zone.lng')),
                    'radius' => max(50, min(20000, (float) ($z['radius'] ?? cfg('zone.radius')))),
                ], JSON_UNESCAPED_UNICODE));
            }
            api_ok(['settings' => admin_settings()]);
            break;

        /* --- commande de démonstration (entraînement) */
        case 'admin.demo':
            require_role('admin');
            $z = zone();
            $lat = $z['lat'] + (mt_rand(-11, 11) / 10000);
            $lng = $z['lng'] + (mt_rand(-11, 11) / 10000);
            $pool = q_all("SELECT * FROM products WHERE available = 1 AND cat_id IN ('glaces','boissons')");
            if (!$pool) api_fail(409, 'Aucun produit disponible.');
            $picks = []; $subtotal = 0;
            for ($i = 0; $i < 2; $i++) {
                $p = $pool[array_rand($pool)];
                if (isset($picks[$p['id']])) continue;
                $picks[$p['id']] = $p;
                $subtotal += (int) $p['price'];
            }
            $firstNames = ['Camille', 'Julien', 'Sophie', 'Marc', 'Élise', 'Hugo'];
            $orderId = next_order_number();
            $token = new_token(24);
            q('INSERT INTO orders (id, track_token, status, payment_status, subtotal, delivery_fee, total,
                                   first_name, phone, note, lat, lng, accuracy, location_at,
                                   courier_id, auto_assigned, out_of_zone, created_at, updated_at)
               VALUES (?,?,\'received\',\'pending\',?,0,?,?,?,?,?,?,12,?,NULL,0,0,?,?)', [
                $orderId, $token, $subtotal, $subtotal,
                $firstNames[array_rand($firstNames)], '06' . mt_rand(10000000, 99999999), 'À côté du parasol bleu',
                round($lat, 6), round($lng, 6), now_ms(), now_ms(), now_ms(),
            ]);
            foreach ($picks as $p) {
                q('INSERT INTO order_items (order_id, product_id, name, price, qty, img) VALUES (?,?,?,?,1,?)',
                    [$orderId, $p['id'], $p['name'], (int) $p['price'], $p['img']]);
            }
            order_add_event($orderId, 'received');
            $order = order_full($orderId);
            if (bool_setting('auto_assign', true)) {
                $c = pick_courier($order['location']);
                if ($c) assign_order($order, $c['id'], true);
            }
            api_ok(['order' => admin_order(order_full($orderId))]);
            break;

        default:
            api_fail(404, 'Action inconnue.');
    }
} catch (Throwable $e) {
    error_log('[PANNACOTTACLUB] ' . $e->getMessage());
    api_fail(500, 'Erreur serveur. Merci de réessayer.');
}

/* --------------------------------------------------------------- fonctions */

/** Retrouve une commande par son jeton de suivi (aucune donnée d'un autre client). */
function order_by_token(string $token): ?array
{
    if (strlen($token) < 16) return null;
    $o = q_one('SELECT id FROM orders WHERE track_token = ?', [$token]);
    return $o ? order_full($o['id']) : null;
}

/** Verrouillage anti-force-brute par compte (60 s après 8 échecs). */
function login_blocked(string $email): int
{
    $f = login_fails($email);
    if (!$f) return 0;
    if ($f['until'] > time()) return $f['until'] - time();
    if ($f['until'] > 0) login_clear_fail($email);
    return 0;
}

function login_note_fail(string $email): void
{
    $f = login_fails($email) ?: ['count' => 0, 'until' => 0];
    $f['count']++;
    if ($f['count'] >= 8) { $f['until'] = time() + 60; $f['count'] = 0; }
    login_fail_write($email, $f);
}

function login_clear_fail(string $email): void
{
    @unlink(login_fail_file($email));
}

function login_fail_file(string $email): string
{
    $dir = PC_ROOT . '/data';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir . '/login_' . md5(strtolower($email)) . '.json';
}

function login_fails(string $email): ?array
{
    $file = login_fail_file($email);
    if (!is_file($file)) return null;
    $d = json_decode((string) @file_get_contents($file), true);
    return is_array($d) ? $d : null;
}

function login_fail_write(string $email, array $data): void
{
    @file_put_contents(login_fail_file($email), json_encode($data), LOCK_EX);
}
