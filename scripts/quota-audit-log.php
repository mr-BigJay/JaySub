<?php

declare(strict_types=1);

/**
 * تاریخچه قطع خودکار سهمیه (بدون نیاز به دستور mysql دستی)
 *   php scripts/quota-audit-log.php
 *   php scripts/quota-audit-log.php --limit 50
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$config = require dirname(__DIR__) . '/src/bootstrap-cli.php';

$limit = 20;
$argv = $_SERVER['argv'] ?? [];
for ($i = 1; $i < count($argv); $i++) {
    if ($argv[$i] === '--limit' && isset($argv[$i + 1])) {
        $limit = max(1, min(200, (int) $argv[++$i]));
    }
}

$db = $config['database'] ?? [];
$dbName = (string) ($db['name'] ?? '?');
$dbUser = (string) ($db['user'] ?? '?');
$dbHost = (string) ($db['host'] ?? '127.0.0.1');

$pdo = \App\Core\Database::pdo();

echo "DB: {$dbUser}@{$dbHost}/{$dbName} (از config/config.php)\n\n";

$stmt = $pdo->prepare(
    "SELECT al.created_at, al.entity_id AS customer_id, c.username, al.details
     FROM audit_logs al
     LEFT JOIN customers c ON c.id = al.entity_id
     WHERE al.action IN ('clients_disabled_quota', 'quota_limit_jaysub')
     ORDER BY al.id DESC
     LIMIT :lim"
);
$stmt->bindValue('lim', $limit, \PDO::PARAM_INT);
$stmt->execute();

$rows = $stmt->fetchAll();
if ($rows === []) {
    echo "رکورد clients_disabled_quota در audit_logs نیست.\n";
    echo "(یا هنوز JaySub قطع خودکار نزده، یا لاگ پاک شده.)\n";
} else {
    foreach ($rows as $r) {
        $u = $r['username'] ?? ('#' . $r['customer_id']);
        echo $r['created_at'] . "  customer={$u} (id={$r['customer_id']})";
        if (!empty($r['details'])) {
            echo '  ' . $r['details'];
        }
        echo "\n";
    }
}

echo "\n--- آخرین بازگردانی خودکار (quota_restore_under_limit) ---\n";
$stmt2 = $pdo->prepare(
    "SELECT al.created_at, al.entity_id, c.username, al.details
     FROM audit_logs al
     LEFT JOIN customers c ON c.id = al.entity_id
     WHERE al.action = 'quota_restore_under_limit'
     ORDER BY al.id DESC LIMIT :lim"
);
$stmt2->bindValue('lim', min(10, $limit), \PDO::PARAM_INT);
$stmt2->execute();
foreach ($stmt2->fetchAll() as $r) {
    echo $r['created_at'] . '  ' . ($r['username'] ?? $r['entity_id']) . '  ' . ($r['details'] ?? '') . "\n";
}

echo "\nبرای تشخیص هر کاربر: php scripts/quota-diagnose.php --customer USERNAME\n";
