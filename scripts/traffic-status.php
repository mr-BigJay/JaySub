<?php

declare(strict_types=1);

/**
 * Show why traffic may be zero (panels, mapped clients, last sync).
 *   php scripts/traffic-status.php
 *   php scripts/traffic-status.php --customer bellmobile
 */

require dirname(__DIR__) . '/vendor/autoload.php';
$config = require dirname(__DIR__) . '/src/bootstrap.php';
\App\Core\Database::init($config['database']);

$filter = null;
$argv = $_SERVER['argv'] ?? [];
for ($i = 1; $i < count($argv); $i++) {
    if ($argv[$i] === '--customer' && isset($argv[$i + 1])) {
        $filter = strtolower(trim($argv[++$i]));
    }
}

$pdo = \App\Core\Database::pdo();
$customers = $pdo->query(
    'SELECT c.id, c.username, c.name,
        (SELECT COUNT(*) FROM vpn_panels vp WHERE vp.customer_id = c.id) AS panels,
        (SELECT COUNT(*) FROM vpn_panels vp WHERE vp.customer_id = c.id AND vp.is_active = 1) AS panels_active,
        (SELECT COUNT(*) FROM vpn_clients vc WHERE vc.customer_id = c.id) AS clients
     FROM customers c ORDER BY c.id'
)->fetchAll();

foreach ($customers as $c) {
    if ($filter !== null && !str_contains(strtolower((string) $c['username']), $filter) && !str_contains(strtolower((string) $c['name']), $filter)) {
        continue;
    }
    $sub = $pdo->prepare(
        "SELECT used_upload_bytes, used_download_bytes, quota_bytes, status FROM subscriptions WHERE customer_id = :id ORDER BY id DESC LIMIT 1"
    );
    $sub->execute(['id' => $c['id']]);
    $s = $sub->fetch();
    $used = $s ? (int) $s['used_upload_bytes'] + (int) $s['used_download_bytes'] : 0;
    echo '--- ' . $c['username'] . ' (' . $c['name'] . ") ---\n";
    echo "  panels: {$c['panels']} (active: {$c['panels_active']}), mapped clients: {$c['clients']}\n";
    if ($s) {
        echo '  subscription: ' . $s['status'] . ', used bytes: ' . $used . ', quota: ' . $s['quota_bytes'] . "\n";
    } else {
        echo "  subscription: NONE — run service setup\n";
    }
    $panels = $pdo->prepare('SELECT id, name, is_active, connection_status, last_sync_at, last_error FROM vpn_panels WHERE customer_id = :id');
    $panels->execute(['id' => $c['id']]);
    foreach ($panels->fetchAll() as $p) {
        echo '  panel #' . $p['id'] . ' ' . $p['name'] . ': active=' . $p['is_active'] . ', ' . $p['connection_status'];
        echo ', last_sync=' . ($p['last_sync_at'] ?? 'never') . "\n";
        if (!empty($p['last_error'])) {
            echo '    error: ' . mb_substr((string) $p['last_error'], 0, 120) . "\n";
        }
    }
    if ((int) $c['clients'] === 0 && (int) $c['panels'] > 0) {
        echo "  => Assign clients: admin → پنل‌های XUI → کلاینت‌ها → اختصاص\n";
    }
    echo "\n";
}

$lock = $config['worker']['lock_file'] ?? dirname(__DIR__) . '/storage/worker.lock';
$log = dirname(__DIR__) . '/logs/worker.log';
echo "Worker log: $log\n";
if (is_readable($log)) {
    $tail = trim((string) shell_exec('tail -n 2 ' . escapeshellarg($log) . ' 2>/dev/null'));
    if ($tail !== '') {
        echo "  last lines:\n    " . str_replace("\n", "\n    ", $tail) . "\n";
    }
} else {
    echo "  (no log yet — ensure cron: * * * * * php worker/traffic_worker.php)\n";
}
