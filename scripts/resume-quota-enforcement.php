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

require __DIR__ . '/lib/cli.php';

\App\Services\QuotaEnforcementService::setEnabled(true);
fwrite(STDOUT, "JaySub: قطع خودکار سقف حذف شده — quota_enforcement همیشه OFF می‌ماند.\n");
fwrite(STDOUT, "از sync بعدی، در صورت رسیدن به سقف، قطع خودکار دوباره اعمال می‌شود.\n");
