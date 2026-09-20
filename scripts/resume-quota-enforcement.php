<?php

declare(strict_types=1);

/**
 * فعال‌کردن دوباره قطع خودکار هنگام پر شدن سقف.
 *   php scripts/resume-quota-enforcement.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require dirname(__DIR__) . '/src/bootstrap-cli.php';

use App\Services\QuotaEnforcementService;

QuotaEnforcementService::setEnabled(true);
fwrite(STDOUT, "quota_enforcement_enabled = ON\n");
fwrite(STDOUT, "از sync بعدی، در صورت رسیدن به سقف، قطع خودکار دوباره اعمال می‌شود.\n");
