<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
$config = require dirname(__DIR__) . '/config/config.php';
\App\Core\Database::init($config['database']);
\App\Database\Migrator::ensure();

$pdo = \App\Core\Database::pdo();
if (!\App\Database\Migrator::isReady($pdo)) {
    fwrite(STDERR, "Migrator: tables still missing\n");
    exit(1);
}
$admin = $pdo->query("SELECT id FROM users WHERE username='admin' AND is_active=1")->fetch();
if ($admin === false) {
    fwrite(STDERR, "Admin user missing — run: php scripts/install.php 'YourPass'\n");
    exit(1);
}
echo "Database OK\n";
