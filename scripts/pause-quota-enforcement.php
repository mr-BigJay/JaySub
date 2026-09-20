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

require __DIR__ . '/lib/cli.php';

\App\Services\QuotaEnforcementService::setEnabled(false);
$off = \App\Services\QuotaEnforcementService::isEnabled() ? 'ON' : 'OFF';
fwrite(STDOUT, "quota_enforcement_enabled = {$off} (sync پنل‌ها از cron/worker همچنان فعال است)\n");
if ($off !== 'OFF') {
    fwrite(STDERR, "ERROR: setting did not save — check DB system_settings.\n");
    exit(1);
}

try {
    $sync = jaysub_cli_traffic_sync()['sync'];
    fwrite(STDOUT, "Enabling all mapped clients in 3x-ui…\n");
    $sync->reenableEveryCustomerWhileEnforcementPaused();
    fwrite(STDOUT, "Running traffic sync…\n");
    $sync->syncAllPanels();
    fwrite(STDOUT, "Done.\n");
    fwrite(STDOUT, "برای فعال‌کردن دوباره قطع سقف: php scripts/resume-quota-enforcement.php\n");
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getFile() . ':' . $e->getLine() . "\n");
    exit(1);
}
