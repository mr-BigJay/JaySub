<?php

declare(strict_types=1);

/**
 * یک‌بار بعد از مشکل بیرونی (مثل انقضای دامنه): وضعیت JaySub را عادی می‌کند + sync پنل‌ها.
 *   php scripts/reset-jaysub-service-state.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require __DIR__ . '/lib/cli.php';

\App\Services\ServiceStateResetService::resetQuotaFlags();
fwrite(STDOUT, "JaySub: quota enforcement OFF, exhausted/warning flags cleared.\n");
fwrite(STDOUT, "3x-ui clients are not modified by JaySub.\n");

$sync = jaysub_cli_traffic_sync()['sync'];
$sync->syncAllPanels();
fwrite(STDOUT, "Panel traffic sync done.\n");
