<?php
/**
 * ShelfSense Sorting – fast receiver for category list views and clicks.
 *
 * Copied to <magento root>/pub/shelfsense-track.php (the zip does it, or run
 * "bin/magento shelfsense:install-endpoint"). It does NOT start Magento: it reads the database
 * settings from app/etc/env.php and writes one small row batch. About 5 ms instead of ~100 ms.
 *
 * Stores only counts per day, store, category and product. No customer, session, cookie or IP data.
 * If this file is missing or blocked, ShelfSense uses its normal Magento endpoint instead.
 */
declare(strict_types=1);

header('Cache-Control: no-store');

if (isset($_GET['ping'])) {
    header('X-ShelfSense: ok');
    http_response_code(204);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}
http_response_code(204);

$agent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
if ($agent === '' || preg_match('/bot|crawl|spider|slurp|facebookexternalhit|preview|headless|lighthouse|pingdom|monitor/i', $agent)) {
    exit;
}
$raw = (string)file_get_contents('php://input', false, null, 0, 20001);
if ($raw === '' || strlen($raw) > 20000) {
    exit;
}
$data = json_decode($raw, true);
if (!is_array($data)) {
    exit;
}
$ids = static function ($list): array {
    if (!is_array($list)) {
        return [];
    }
    $out = [];
    foreach (array_slice($list, 0, 200) as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $out[$id] = true;
        }
    }
    return array_keys($out);
};
$categoryId = (int)($data['c'] ?? 0);
$storeId = (int)($data['s'] ?? 0);
$views = $ids($data['v'] ?? []);
$clicks = $ids($data['k'] ?? []);
if ($categoryId <= 0 || $storeId < 0 || $storeId > 65535 || (!$views && !$clicks)) {
    exit;
}

// The visitor gets the answer now; the database work happens after.
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

try {
    $env = include dirname(__DIR__) . '/app/etc/env.php';
    $db = $env['db']['connection']['default'] ?? null;
    if (!is_array($db)) {
        exit;
    }
    $prefix = (string)($env['db']['table_prefix'] ?? '');
    $host = (string)($db['host'] ?? 'localhost');
    if ($host !== '' && $host[0] === '/') {
        $dsn = 'mysql:unix_socket=' . $host;
    } elseif (str_contains($host, ':')) {
        [$h, $p] = explode(':', $host, 2);
        $dsn = ctype_digit($p) ? "mysql:host=$h;port=$p" : "mysql:unix_socket=$p";
    } else {
        $dsn = 'mysql:host=' . $host;
    }
    $dsn .= ';dbname=' . $db['dbname'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, (string)($db['username'] ?? ''), (string)($db['password'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 2,
    ]);

    // Is ShelfSense (and tracking) switched on? Cached for 60 s when APCu is available.
    $key = 'shelfsense_tracking_on';
    $on = function_exists('apcu_fetch') ? apcu_fetch($key, $found) : null;
    if (empty($found)) {
        $rows = $pdo->query(
            "SELECT path, value FROM {$prefix}core_config_data WHERE scope = 'default' AND path IN "
            . "('semattor_shelfsense/general/enabled', 'semattor_shelfsense/auto/tracking')"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        $on = ($rows['semattor_shelfsense/general/enabled'] ?? '1') === '1'
            && ($rows['semattor_shelfsense/auto/tracking'] ?? '1') === '1';
        if (function_exists('apcu_store')) {
            apcu_store($key, $on, 60);
        }
    }
    if (!$on) {
        exit;
    }

    $rows = [];
    foreach ($views as $id) {
        $rows[$id] = [1, 0];
    }
    foreach ($clicks as $id) {
        $rows[$id] = [1, 1];
    }
    $values = [];
    $params = [];
    $date = gmdate('Y-m-d');
    foreach ($rows as $productId => [$v, $k]) {
        $values[] = '(?, ?, ?, ?, ?, ?)';
        array_push($params, $date, $storeId, $categoryId, $productId, $v, $k);
    }
    $pdo->prepare(
        "INSERT INTO {$prefix}semattor_shelfsense_stats (stat_date, store_id, category_id, product_id, views, clicks) VALUES "
        . implode(',', $values)
        . ' ON DUPLICATE KEY UPDATE views = views + VALUES(views), clicks = clicks + VALUES(clicks)'
    )->execute($params);
} catch (\Throwable $e) {
    // Never show errors to visitors.
}
