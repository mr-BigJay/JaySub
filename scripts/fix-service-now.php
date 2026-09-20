<?php

declare(strict_types=1);

/**
 * خاموش کردن قطع سقف در JaySub + بازنشانی وضعیت DB (بدون enable/disable در 3x-ui)
 *   php scripts/fix-service-now.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require __DIR__ . '/lib/cli.php';

\App\Services\QuotaEnforcementService::setEnabled(false);
fwrite(STDOUT, 'quota_enforcement_enabled = OFF (فقط وضعیت JaySub؛ 3x-ui دست‌نخورده)' . "\n");

$sync = jaysub_cli_traffic_sync()['sync'];
$sync->reenableEveryCustomerWhileEnforcementPaused();
fwrite(STDOUT, "JaySub DB flags reset for all customers.\n");
fwrite(STDOUT, "Running panel traffic sync…\n");
$sync->syncAllPanels();
fwrite(STDOUT, "Done.\n");
