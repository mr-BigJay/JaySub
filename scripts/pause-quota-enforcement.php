<?php

declare(strict_types=1);

/**
 * sync پنل‌ها ادامه دارد؛ قطع خودکار سقف خاموش + یک بار فعال‌سازی مجدد کلاینت‌ها.
 *   php scripts/pause-quota-enforcement.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$config = require dirname(__DIR__) . '/src/bootstrap-cli.php';

use App\Core\Encryption;
use App\Services\QuotaEnforcementService;
use App\Services\SettingsService;
use App\Services\TelegramService;
use App\Services\TrafficSyncService;

QuotaEnforcementService::setEnabled(false);
fwrite(STDOUT, "quota_enforcement_enabled = OFF (sync پنل‌ها از cron/worker همچنان فعال است)\n");

try {
    $encryption = new Encryption($config['security']['encryption_key']);
    $telegram = new TelegramService(SettingsService::get('telegram_bot_token'));
    $sync = new TrafficSyncService($encryption, $telegram);
    fwrite(STDOUT, "Running traffic sync + restore clients…\n");
    $sync->syncAllPanels();
    fwrite(STDOUT, "Done.\n");
    fwrite(STDOUT, "برای فعال‌کردن دوباره قطع سقف: php scripts/resume-quota-enforcement.php\n");
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
