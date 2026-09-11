<?php

declare(strict_types=1);

/**
 * CLI bootstrap: config + DB + migrations — no HTTP session (avoids hangs in scripts/cron).
 *
 * @return array<string, mixed>
 */
$configPath = dirname(__DIR__) . '/config/config.php';
if (!is_file($configPath)) {
    $configPath = dirname(__DIR__) . '/config/config.example.php';
}
/** @var array<string, mixed> $config */
$config = require $configPath;

date_default_timezone_set($config['app']['timezone'] ?? 'UTC');

require dirname(__DIR__) . '/vendor/autoload.php';

$db = $config['database'];
if (!isset($db['connect_timeout'])) {
    $db['connect_timeout'] = 5;
}
\App\Core\Database::init($db);
\App\Database\Migrator::ensure();

return $config;
