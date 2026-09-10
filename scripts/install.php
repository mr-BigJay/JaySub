<?php

declare(strict_types=1);

/**
 * CLI: php scripts/install.php
 * Creates config, runs schema, sets admin password.
 */

$root = dirname(__DIR__);
$configExample = $root . '/config/config.example.php';
$configFile = $root . '/config/config.php';

if (!is_file($configFile)) {
    copy($configExample, $configFile);
    echo "Created config/config.php from example — edit database credentials.\n";
}

$config = require $configFile;

$dsn = sprintf(
    'mysql:host=%s;port=%d;charset=%s',
    $config['database']['host'],
    (int) $config['database']['port'],
    $config['database']['charset'] ?? 'utf8mb4'
);
$pdo = new PDO($dsn, $config['database']['user'], $config['database']['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$sql = file_get_contents($root . '/database/schema.sql');
if ($sql === false) {
    throw new RuntimeException('schema.sql not found');
}
foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
    if ($statement === '' || str_starts_with($statement, '--')) {
        continue;
    }
    try {
        $pdo->exec($statement);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'already exists')) {
            continue;
        }
        throw $e;
    }
}

$password = $argv[1] ?? 'Admin@12345';
$hash = password_hash($password, PASSWORD_DEFAULT);
$pdo->prepare('UPDATE users SET password_hash = :h WHERE username = \'admin\'')->execute(['h' => $hash]);

echo "Install complete. Admin password: {$password}\n";
