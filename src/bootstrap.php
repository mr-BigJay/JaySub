<?php

declare(strict_types=1);

$configPath = dirname(__DIR__) . '/config/config.php';
if (!is_file($configPath)) {
    $configPath = dirname(__DIR__) . '/config/config.example.php';
}
/** @var array<string, mixed> $config */
$config = require $configPath;

date_default_timezone_set($config['app']['timezone'] ?? 'UTC');

require dirname(__DIR__) . '/vendor/autoload.php';

\App\Core\Database::init($config['database']);
\App\Core\Session::start($config['security']['session_name'] ?? 'VPN_PANEL_SESS');

return $config;
