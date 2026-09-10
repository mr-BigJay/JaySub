<?php

declare(strict_types=1);

return [
    'app' => [
        'name' => 'پنل مدیریت VPN',
        'url' => 'http://localhost',
        'timezone' => 'Asia/Tehran',
        'debug' => false,
    ],
    'database' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'vpn_panel',
        'user' => 'vpn_panel',
        'password' => 'change_me',
        'charset' => 'utf8mb4',
    ],
    'security' => [
        'session_name' => 'VPN_PANEL_SESS',
        'csrf_token_key' => 'csrf_token',
        'encryption_key' => 'CHANGE_THIS_32_CHAR_SECRET_KEY!!',
        'login_max_attempts' => 5,
        'login_lockout_minutes' => 15,
    ],
    'worker' => [
        'lock_file' => __DIR__ . '/../storage/worker.lock',
        'interval_seconds' => 60,
    ],
    'paths' => [
        'storage' => __DIR__ . '/../storage',
        'logs' => __DIR__ . '/../logs',
    ],
];
