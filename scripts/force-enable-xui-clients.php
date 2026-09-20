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

require __DIR__ . '/lib/cli.php';

if (\App\Services\QuotaEnforcementService::isEnabled()) {
    fwrite(STDERR, "ابتدا: php scripts/pause-quota-enforcement.php\n");
    exit(1);
}

$sync = jaysub_cli_traffic_sync()['sync'];
$sync->reenableEveryCustomerWhileEnforcementPaused();
fwrite(STDOUT, "All mapped clients bulkEnable requested. Run: php scripts/quota-diagnose.php --cut-only\n");
