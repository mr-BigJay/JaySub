<?php

declare(strict_types=1);

/**
 * فقط فعال‌کردن کلاینت‌ها در 3x-ui (بدون sync کامل). نیاز: quota_enforcement_enabled = OFF
 *   php scripts/force-enable-xui-clients.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$config = require dirname(__DIR__) . '/src/bootstrap-cli.php';

use App\Core\Encryption;
use App\Services\QuotaEnforcementService;
use App\Services\SettingsService;
use App\Services\TelegramSyncService;
use App\Services\TelegramService;
use App\Services\TrafficSyncService;

if (QuotaEnforcementService::isEnabled()) {
    fwrite(STDERR, "ابتدا: php scripts/pause-quota-enforcement.php\n");
    exit(1);
}

$encryption = new Encryption($config['security']['encryption_key']);
$sync = new TrafficSyncService($encryption, new TelegramService(SettingsService::get('telegram_bot_token')));
$sync->reenableEveryCustomerWhileEnforcementPaused();
fwrite(STDOUT, "All mapped clients bulkEnable requested. Run: php scripts/quota-diagnose.php --cut-only\n");
