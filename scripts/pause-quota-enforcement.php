<?php

declare(strict_types=1);

/**
 * خاموش کردن علامت‌گذاری سقف در JaySub (sync پنل ادامه دارد).
 *   php scripts/pause-quota-enforcement.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require __DIR__ . '/lib/cli.php';

\App\Services\QuotaEnforcementService::setEnabled(false);
fwrite(STDOUT, 'quota_enforcement_enabled = OFF' . "\n");
fwrite(STDOUT, "For full DB reset use: php scripts/reset-jaysub-service-state.php\n");
