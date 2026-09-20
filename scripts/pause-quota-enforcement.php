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

\App\Services\QuotaEnforcementService::setEnabled(false);
$off = \App\Services\QuotaEnforcementService::isEnabled() ? 'ON' : 'OFF';
fwrite(STDOUT, "quota_enforcement_enabled = {$off} (sync پنل‌ها از cron/worker همچنان فعال است)\n");
if ($off !== 'OFF') {
    fwrite(STDERR, "ERROR: setting did not save — check DB system_settings.\n");
    exit(1);
}

try {
    $encryption = new Encryption($config['security']['encryption_key']);
    $telegram = new TelegramService(SettingsService::get('telegram_bot_token'));
    $sync = new TrafficSyncService($encryption, $telegram);
    fwrite(STDOUT, "Enabling all mapped clients in 3x-ui…\n");
    $sync->reenableEveryCustomerWhileEnforcementPaused();
    fwrite(STDOUT, "Running traffic sync…\n");
    $sync->syncAllPanels();
    fwrite(STDOUT, "Done.\n");
    fwrite(STDOUT, "برای فعال‌کردن دوباره قطع سقف: php scripts/resume-quota-enforcement.php\n");
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
