<?php
/* ==========================================================================
   PANNACOTTACLUB — commandes : statuts, assignation, sérialisation
   Les formats JSON renvoyés sont identiques à ceux de la version Node :
   le site (HTML/CSS/JS) est le même dans les deux versions.
   ========================================================================== */

const PC_STATUSES = [
    'received'   => 'Commande reçue',
    'preparing'  => 'En préparation',
    'ready'      => 'Prête',
    'assigned'   => 'Livreur assigné',
    'en_route'   => 'Livreur en route',
    'nearby'     => 'Livreur proche',
    'arrived'    => 'Livreur arrivé',
    'delivered'  => 'Livrée',
    'cancelled'  => 'Annulée',
];

const PC_TIMELINE = ['received', 'preparing', 'ready', 'assigned', 'en_route', 'nearby', 'arrived', 'delivered'];
const PC_ACTIVE   = ['received', 'preparing', 'ready', 'assigned', 'en_route', 'nearby', 'arrived'];

function status_label(string $status): string
{
    return PC_STATUSES[$status] ?? $status;
}

/** Commande + ses lignes + son historique. */
function order_full(string $id): ?array
{
    $o = q_one('SELECT * FROM orders WHERE id = ?', [$id]);
    if (!$o) return null;
    $o['items']   = q_all('SELECT product_id, name, price, qty, img FROM order_items WHERE order_id = ? ORDER BY id', [$id]);
    $o['history'] = q_all('SELECT status, at FROM order_events WHERE order_id = ? ORDER BY at, id', [$id]);
    $o['customer'] = [
        'firstName' => $o['first_name'],
        'phone'     => $o['phone'],
        'note'      => $o['note'],
    ];
    $o['location'] = $o['lat'] !== null ? [
        'lat'      => (float) $o['lat'],
        'lng'      => (float) $o['lng'],
        'accuracy' => (int) $o['accuracy'],
        'at'       => (int) $o['location_at'],
    ] : null;
    return $o;
}

/** Numéro de commande séquentiel, sans doublon même en cas de commandes simultanées. */
function next_order_number(): string
{
    db()->beginTransaction();
    try {
        // verrou sur la ligne compteur
        q("INSERT INTO settings (k, v) VALUES ('seq', 1041) ON DUPLICATE KEY UPDATE v = v");
        $row = q_one("SELECT v FROM settings WHERE k = 'seq' FOR UPDATE");
        $n = (int) ($row['v'] ?? 1041) + 1;
        q("UPDATE settings SET v = ? WHERE k = 'seq'", [(string) $n]);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }
    return 'PC-' . $n;
}

function order_add_event(string $orderId, string $status): void
{
    q('INSERT INTO order_events (order_id, status, at) VALUES (?, ?, ?)', [$orderId, $status, now_ms()]);
}

function order_set_status(array &$order, string $status): void
{
    $now = now_ms();
    $fields = ['status' => $status, 'updated_at' => $now];
    if ($status === 'delivered') $fields['delivered_at'] = $now;
    $set = [];
    $vals = [];
    foreach ($fields as $k => $v) { $set[] = "$k = ?"; $vals[] = $v; }
    $vals[] = $order['id'];
    q('UPDATE orders SET ' . implode(', ', $set) . ' WHERE id = ?', $vals);
    order_add_event($order['id'], $status);
    $order = order_full($order['id']);
}

/** Combien de livraisons actives pour un livreur. */
function courier_load(string $courierId): int
{
    $in = implode(',', array_fill(0, count(PC_ACTIVE), '?'));
    return (int) q_val("SELECT COUNT(*) FROM orders WHERE courier_id = ? AND status IN ($in)", array_merge([$courierId], PC_ACTIVE), 0);
}

/**
 * Choisit le livreur à qui confier la commande :
 * en service d'abord, le moins chargé, puis le plus proche.
 */
function pick_courier(?array $location): ?array
{
    $couriers = q_all("SELECT * FROM users WHERE role = 'courier' AND active = 1");
    if (!$couriers) return null;

    $forced = (string) setting_get('auto_assign_courier_id', '');
    if ($forced !== '') {
        foreach ($couriers as $c) if ($c['id'] === $forced) return $c;
    }
    $online = array_values(array_filter($couriers, fn($c) => (int) $c['online'] === 1));
    $pool = $online ?: $couriers;

    usort($pool, function ($a, $b) use ($location) {
        $la = courier_load($a['id']);
        $lb = courier_load($b['id']);
        if ($la !== $lb) return $la <=> $lb;
        $da = $a['last_lat'] !== null ? distance_m(['lat' => $a['last_lat'], 'lng' => $a['last_lng']], $location) : INF;
        $db = $b['last_lat'] !== null ? distance_m(['lat' => $b['last_lat'], 'lng' => $b['last_lng']], $location) : INF;
        return $da <=> $db;
    });
    return $pool[0] ?? null;
}

/** Confie la commande à un livreur : elle passe directement à « livreur assigné ». */
function assign_order(array &$order, string $courierId, bool $auto = false): void
{
    q('UPDATE orders SET courier_id = ?, assigned_at = ?, auto_assigned = ?, updated_at = ? WHERE id = ?',
        [$courierId, now_ms(), $auto ? 1 : 0, now_ms(), $order['id']]);
    if (!in_array($order['status'], ['en_route', 'nearby', 'arrived', 'delivered'], true)) {
        if ($order['status'] !== 'assigned') order_add_event($order['id'], 'assigned');
        q("UPDATE orders SET status = 'assigned' WHERE id = ?", [$order['id']]);
    }
    $order = order_full($order['id']);
}

/**
 * Livreurs actuellement en course, pour la carte publique de la page d'accueil.
 * Protection de la vie privée : on ne renvoie un livreur que s'il est en service,
 * qu'il a une livraison en cours (en route / proche / arrivé), que sa position a
 * moins de 2 minutes ; les coordonnées sont données au mètre près. Aucune donnée
 * personnelle du client (nom, téléphone, adresse, position) n'est publiée.
 * Aucune donnée client ne sort d'ici : ni nom, ni téléphone, ni adresse.
 */
function live_couriers(): array
{
    if (!bool_setting('live_map_public', (bool) cfg('live_map_public', true))) return [];

    $rows = q_all(
        "SELECT u.id, u.name, u.last_lat, u.last_lng, u.last_accuracy, u.last_seen_at,
                (SELECT o.id FROM orders o
                  WHERE o.courier_id = u.id AND o.status IN ('en_route','nearby','arrived')
                  ORDER BY o.created_at DESC LIMIT 1) AS order_id,
                (SELECT o.status FROM orders o
                  WHERE o.courier_id = u.id AND o.status IN ('en_route','nearby','arrived')
                  ORDER BY o.created_at DESC LIMIT 1) AS order_status
           FROM users u
          WHERE u.role = 'courier' AND u.active = 1 AND u.online = 1 AND u.last_lat IS NOT NULL"
    );

    $out = [];
    $now = now_ms();
    foreach ($rows as $r) {
        if (!$r['order_id'] || $r['last_lat'] === null) continue;
        if ($now - (int) $r['last_seen_at'] > 120000) continue;   // position trop vieille
        $out[] = [
            'name'      => $r['name'],
            'lat'       => round((float) $r['last_lat'], 5),       // ~1 m : position réelle
            'lng'       => round((float) $r['last_lng'], 5),
            'accuracy'  => (int) $r['last_accuracy'],
            'at'        => (int) $r['last_seen_at'],
            'status'    => $r['order_status'],
            'statusLabel' => status_label($r['order_status']),
        ];
    }
    return $out;
}

/** Estimation d'arrivée à partir de la position du livreur. */
function eta_for(array $order): ?array
{
    if (!$order['courier_id'] || !$order['location']) return null;
    $c = q_one('SELECT last_lat, last_lng, last_seen_at FROM users WHERE id = ?', [$order['courier_id']]);
    if (!$c || $c['last_lat'] === null) return null;
    $d = distance_m(
        ['lat' => (float) $c['last_lat'], 'lng' => (float) $c['last_lng']],
        $order['location']
    );
    $speed = max(0.8, (float) setting_get('avg_speed', 1.3));
    $min = (int) round($d / $speed / 60) + 1;
    return ['minutes' => max(1, min(45, $min)), 'distance' => (int) round($d)];
}

/** Position du livreur, si elle est récente (5 min). */
function courier_position(?array $courier): ?array
{
    if (!$courier || $courier['last_lat'] === null) return null;
    if (now_ms() - (int) $courier['last_seen_at'] > 300000) return null;
    return [
        'lat'      => (float) $courier['last_lat'],
        'lng'      => (float) $courier['last_lng'],
        'accuracy' => (int) $courier['last_accuracy'],
        'at'       => (int) $courier['last_seen_at'],
    ];
}

/* ------------------------------------------------------------ sérialiseurs */

/** Version visible par le CLIENT qui suit sa commande. */
function public_order(array $o): array
{
    $courier = $o['courier_id'] ? q_one('SELECT * FROM users WHERE id = ?', [$o['courier_id']]) : null;
    $active = in_array($o['status'], PC_ACTIVE, true);
    return [
        'id'              => $o['id'],
        'status'          => $o['status'],
        'statusLabel'     => status_label($o['status']),
        'createdAt'       => (int) $o['created_at'],
        'items'           => array_map(fn($i) => [
            'productId' => $i['product_id'], 'name' => $i['name'],
            'price' => (int) $i['price'], 'qty' => (int) $i['qty'], 'img' => $i['img'],
        ], $o['items']),
        'subtotal'        => (int) $o['subtotal'],
        'deliveryFee'     => (int) $o['delivery_fee'],
        'total'           => (int) $o['total'],
        'paymentStatus'   => $o['payment_status'],
        'note'            => $o['note'],
        'firstName'       => $o['first_name'],
        'location'        => $o['location'],
        'awaitingCourier' => !$o['courier_id'] && $active,
        // Le suivi GPS s'arrête avec la course : après « livrée » (ou annulée),
        // plus aucune position n'est transmise au client.
        'courier'         => $courier ? [
            'id'       => $courier['id'],
            'name'     => $courier['name'],
            'phone'    => $courier['phone'],
            'position' => $active ? courier_position($courier) : null,
        ] : null,
        'eta'             => $active ? eta_for($o) : null,
        'history'         => array_map(fn($h) => ['status' => $h['status'], 'at' => (int) $h['at']], $o['history']),
        'timeline'        => PC_TIMELINE,
        'outOfZone'       => (int) $o['out_of_zone'] === 1,
    ];
}

/** Version visible par le LIVREUR assigné. */
function courier_order(array $o): array
{
    $c = $o['courier_id'] ? q_one('SELECT last_lat, last_lng FROM users WHERE id = ?', [$o['courier_id']]) : null;
    $d = null;
    if ($c && $c['last_lat'] !== null && $o['location']) {
        $d = (int) round(distance_m(['lat' => (float) $c['last_lat'], 'lng' => (float) $c['last_lng']], $o['location']));
    }
    return [
        'id'            => $o['id'],
        'status'        => $o['status'],
        'statusLabel'   => status_label($o['status']),
        'createdAt'     => (int) $o['created_at'],
        'assignedAt'    => $o['assigned_at'] !== null ? (int) $o['assigned_at'] : null,
        'items'         => array_map(fn($i) => [
            'productId' => $i['product_id'], 'name' => $i['name'],
            'price' => (int) $i['price'], 'qty' => (int) $i['qty'], 'img' => $i['img'],
        ], $o['items']),
        'total'         => (int) $o['total'],
        'paymentStatus' => $o['payment_status'],
        'paidAt'        => $o['paid_at'] !== null ? (int) $o['paid_at'] : null,
        'customer'      => $o['customer'],
        'location'      => $o['location'],
        'eta'           => eta_for($o),
        'distance'      => $d,
        'history'       => array_map(fn($h) => ['status' => $h['status'], 'at' => (int) $h['at']], $o['history']),
    ];
}

/** Version visible par l'ADMINISTRATEUR. */
function admin_order(array $o): array
{
    $courier = $o['courier_id'] ? q_one('SELECT name FROM users WHERE id = ?', [$o['courier_id']]) : null;
    return [
        'id'            => $o['id'],
        'status'        => $o['status'],
        'statusLabel'   => status_label($o['status']),
        'createdAt'     => (int) $o['created_at'],
        'items'         => array_map(fn($i) => [
            'productId' => $i['product_id'], 'name' => $i['name'],
            'price' => (int) $i['price'], 'qty' => (int) $i['qty'], 'img' => $i['img'],
        ], $o['items']),
        'subtotal'      => (int) $o['subtotal'],
        'deliveryFee'   => (int) $o['delivery_fee'],
        'total'         => (int) $o['total'],
        'paymentStatus' => $o['payment_status'],
        'paidAt'        => $o['paid_at'] !== null ? (int) $o['paid_at'] : null,
        'customer'      => $o['customer'],
        'location'      => $o['location'],
        'courierId'     => $o['courier_id'],
        'courierName'   => $courier['name'] ?? null,
        'autoAssigned'  => (int) $o['auto_assigned'] === 1,
        'outOfZone'     => (int) $o['out_of_zone'] === 1,
        'eta'           => eta_for($o),
        'history'       => array_map(fn($h) => ['status' => $h['status'], 'at' => (int) $h['at']], $o['history']),
    ];
}

/** Produit visible par le client (jamais de produit retiré). */
function menu_products(bool $includeUnavailable = false): array
{
    $sql = 'SELECT * FROM products' . ($includeUnavailable ? '' : ' WHERE available = 1') . ' ORDER BY sort, name';
    return array_map(fn($p) => [
        'id'          => $p['id'],
        'catId'       => $p['cat_id'],
        'name'        => $p['name'],
        'description' => $p['description'],
        'price'       => (int) $p['price'],
        'img'         => $p['img'],
        'available'   => (int) $p['available'] === 1,
        'sort'        => (int) $p['sort'],
        'createdAt'   => (int) $p['created_at'],
    ], q_all($sql));
}

function categories_all(): array
{
    return array_map(fn($c) => ['id' => $c['id'], 'name' => $c['name'], 'icon' => $c['icon']],
        q_all('SELECT * FROM categories ORDER BY sort'));
}

/** Réglages exposés au public (aucune donnée sensible). */
function public_settings(): array
{
    $z = zone();
    return [
        'acceptingOrders' => bool_setting('accepting_orders', true),
        'zone'            => $z,
        'requireInZone'   => bool_setting('require_in_zone', true),
        'autoAssign'      => bool_setting('auto_assign', true),
        'liveMapPublic'   => bool_setting('live_map_public', (bool) cfg('live_map_public', true)),
    ];
}

/** Réglages du plan/carte transmis au navigateur (clé Google incluse). */
function public_map(): array
{
    $m = cfg('map');
    $m = is_array($m) ? $m : [];
    $cle = trim((string) setting_get('google_maps_key', ''));
    // La clé enregistrée dans l'admin prime sur celle de config.php.
    $m['google_key'] = $cle !== '' ? $cle : trim((string) ($m['google_key'] ?? ''));
    return $m;
}

function admin_settings(): array
{
    $z = zone();
    return array_merge(public_settings(), [
        'googleKey'           => (string) setting_get('google_maps_key', ''),
        'autoAssignCourierId' => (string) setting_get('auto_assign_courier_id', ''),
        'avgSpeed'            => (float) setting_get('avg_speed', 1.3),
        'nearbyRadius'        => (int) setting_get('nearby_radius', 150),
        'arrivedRadius'       => (int) setting_get('arrived_radius', 45),
        'zone'                => $z,
    ]);
}

/** URL publique du site (sert à fabriquer les QR codes). */
function public_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    return ($https ? 'https://' : 'http://') . $host . ($dir === '' ? '' : $dir);
}
