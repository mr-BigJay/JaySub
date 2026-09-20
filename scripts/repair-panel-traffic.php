<?php

declare(strict_types=1);

/**
 * بعد از حذف/ساخت دوباره API پنل: فعال‌کردن پنل، rebind کلاینت‌ها، sync یک‌بار.
 *
 *   php scripts/repair-panel-traffic.php --panel 12
 *   php scripts/repair-panel-traffic.php --customer USERNAME
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require __DIR__ . '/lib/cli.php';

$panelId = null;
$customerFilter = null;
$argv = $_SERVER['argv'] ?? [];
for ($i = 1; $i < count($argv); $i++) {
    if ($argv[$i] === '--panel' && isset($argv[$i + 1])) {
        $panelId = (int) $argv[++$i];
    }
    if ($argv[$i] === '--customer' && isset($argv[$i + 1])) {
        $customerFilter = strtolower(trim($argv[++$i]));
    }
}

$pdo = \App\Core\Database::pdo();
$config = require dirname(__DIR__) . '/src/bootstrap-cli.php';
$encryption = new \App\Core\Encryption((string) $config['security']['encryption_key']);
$sync = new \App\Services\TrafficSyncService($encryption, new \App\Services\TelegramService(''));

if ($panelId !== null && $panelId > 0) {
    $row = $pdo->prepare('SELECT id, customer_id, name FROM vpn_panels WHERE id = :id');
    $row->execute(['id' => $panelId]);
    $panel = $row->fetch();
    if ($panel === false) {
        fwrite(STDERR, "Panel #$panelId not found\n");
        exit(1);
    }
    $panels = [$panel];
} else {
    $sql = 'SELECT id, customer_id, name FROM vpn_panels vp INNER JOIN customers c ON c.id = vp.customer_id';
    if ($customerFilter !== null) {
        $sql .= ' WHERE LOWER(c.username) LIKE :u OR LOWER(c.name) LIKE :u';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['u' => '%' . $customerFilter . '%']);
        $panels = $stmt->fetchAll();
    } else {
        $panels = $pdo->query($sql)->fetchAll();
    }
}

if ($panels === []) {
    fwrite(STDERR, "No panels matched.\n");
    exit(1);
}

foreach ($panels as $panel) {
    $pid = (int) $panel['id'];
    $cid = (int) $panel['customer_id'];
    echo "Repair panel #$pid {$panel['name']} (customer #$cid)…\n";
    \App\Services\CustomerService::ensurePanelActiveForCustomer($cid, $pid);
    \App\Services\CustomerService::rebindClientsToActiveSubscription($cid, $pid);
    try {
        $sync->syncPanelAndAggregate($pid);
        echo "  OK — sync done.\n";
    } catch (\Throwable $e) {
        echo '  SYNC ERROR: ' . $e->getMessage() . "\n";
    }
}

echo "Done. Check: php scripts/traffic-status.php";
if ($customerFilter !== null) {
    echo ' --customer ' . escapeshellarg($customerFilter);
}
echo "\n";
