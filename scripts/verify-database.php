<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$configFile = $root . '/config/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "Missing config.php\n");
    exit(1);
}

/** @var array<string, mixed> $config */
$config = require $configFile;
$db = $config['database'];

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $db['host'],
    (int) $db['port'],
    $db['name'],
    $db['charset'] ?? 'utf8mb4'
);

try {
    $pdo = new PDO($dsn, $db['user'], $db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, 'DB connect failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$required = [
    'users',
    'customers',
    'subscriptions',
    'vpn_panels',
    'vpn_clients',
    'traffic_snapshots',
    'traffic_alerts',
    'system_settings',
    'audit_logs',
    'login_attempts',
];

$existing = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$missing = array_values(array_diff($required, $existing));
if ($missing !== []) {
    fwrite(STDERR, 'Missing tables: ' . implode(', ', $missing) . "\n");
    exit(1);
}

$admin = $pdo->query("SELECT id FROM users WHERE username = 'admin' AND is_active = 1 LIMIT 1")->fetch();
if ($admin === false) {
    fwrite(STDERR, "Missing admin user\n");
    exit(1);
}

echo "Database OK (" . count($required) . " tables, admin user)\n";
exit(0);
