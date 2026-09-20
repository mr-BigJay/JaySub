<?php

declare(strict_types=1);

/**
 * بعد از اصلاح منطق سهمیه: یک بار sync و بازگردانی مشتریانی که زیر سقف هستند.
 *   php scripts/restore-quota-clients.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

$config = require dirname(__DIR__) . '/src/bootstrap-cli.php';
$enc = new \App\Core\Encryption($config['encryption']['key']);
$sync = new \App\Services\TrafficSyncService($enc, new \App\Services\TelegramService($config));

echo "Running full traffic sync (quota = mapped clients)…\n";
$sync->syncAllPanels();
echo "Done. Check admin customers / 3x-ui client enable state.\n";
