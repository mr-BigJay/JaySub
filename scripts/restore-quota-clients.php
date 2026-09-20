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

$config = require dirname(__DIR__) . '/src/bootstrap-cli.php';

use App\Core\Encryption;
use App\Services\SettingsService;
use App\Services\TelegramService;
use App\Services\TrafficSyncService;

try {
    $encryption = new Encryption($config['security']['encryption_key']);
    $telegram = new TelegramService(SettingsService::get('telegram_bot_token'));
    $sync = new TrafficSyncService($encryption, $telegram);
    fwrite(STDOUT, "Running full traffic sync (quota = mapped clients)…\n");
    $sync->syncAllPanels();
    fwrite(STDOUT, "Done. Check admin customers / 3x-ui client enable state.\n");
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
