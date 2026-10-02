<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$configPath = $root . '/config/config.php';
if (!is_file($configPath)) {
    copy($root . '/config/config.example.php', $configPath);
}

/** @var array<string, mixed> $config */
$config = require $configPath;

\App\Core\Database::init($config['database']);

$password = $argv[1] ?? null;
if ($password === null || $password === '') {
    $initFile = $root . '/storage/.admin-init';
    if (is_file($initFile)) {
        $password = trim((string) file_get_contents($initFile));
    }
}
if ($password === null || $password === '') {
    $password = 'Admin@12345';
}

\App\Database\Migrator::run();
\App\Database\Migrator::setAdminPassword($password);

$initFile = $root . '/storage/.admin-init';
if (is_file($initFile)) {
    @unlink($initFile);
}

echo "Install complete.\n";
echo "Admin: admin / {$password}\n";
