<?php

declare(strict_types=1);

/**
 * Show why traffic may be zero (panels, mapped clients, last sync).
 *   php scripts/traffic-status.php
 *   php scripts/traffic-status.php --customer bellmobile
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

fwrite(STDOUT, "JaySub traffic-status…\n");
fflush(STDOUT);

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    $config = require dirname(__DIR__) . '/src/bootstrap-cli.php';
} catch (\Throwable $e) {
    fwrite(STDERR, 'Bootstrap failed: ' . $e->getMessage() . "\n");
    fwrite(STDERR, "Check MySQL in config/config.php (host, user, password). Test: mysql -h HOST -u USER -p DBNAME\n");
    exit(1);
}

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
        echo "  => Run: php worker/traffic_worker.php (auto-imports all XUI clients)\n";
    }
    echo "\n";
}

$log = dirname(__DIR__) . '/logs/worker.log';
echo "Worker log: $log\n";
if (is_readable($log)) {
    $lines = file($log, FILE_IGNORE_NEW_LINES);
    if (is_array($lines) && $lines !== []) {
        $tail = array_slice($lines, -2);
        echo "  last lines:\n    " . implode("\n    ", $tail) . "\n";
    }
} else {
    echo "  (no log yet — ensure cron: * * * * * php " . dirname(__DIR__) . "/worker/traffic_worker.php)\n";
}

echo "Done.\n";
