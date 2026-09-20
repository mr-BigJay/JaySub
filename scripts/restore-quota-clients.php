<?php

declare(strict_types=1);

/**
 * بعد از اصلاح منطق سهمیه: یک بار sync و بازگردانی مشتریانی که زیر سقف هستند.
 *   php scripts/restore-quota-clients.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require __DIR__ . '/lib/cli.php';

try {
    $sync = jaysub_cli_traffic_sync()['sync'];
    fwrite(STDOUT, "Running full traffic sync (quota = mapped clients)…\n");
    $sync->syncAllPanels();
    fwrite(STDOUT, "Done.\n");
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getFile() . ':' . $e->getLine() . "\n");
    exit(1);
}
