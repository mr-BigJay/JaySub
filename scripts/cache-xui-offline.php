<?php

declare(strict_types=1);

// Pre-stage official 3x-ui v3.4.2 packages on the JaySub management VPS.
// Run as the same account as PHP-FPM/migration jobs, so the worker can read it.
// Usage: php scripts/cache-xui-offline.php [amd64] [arm64]

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$configFile = $root . '/config/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "JaySub config/config.php is missing.\n");
    exit(1);
}
$config = require $configFile;
if (!is_array($config)) {
    fwrite(STDERR, "Invalid JaySub configuration.\n");
    exit(1);
}

$architectures = array_slice($argv, 1);
if ($architectures === []) {
    $architectures = \App\Services\OfflineXuiInstaller::architectures();
}

$failed = false;
foreach ($architectures as $architecture) {
    try {
        $architecture = (string) $architecture;
        fwrite(STDOUT, "Caching 3x-ui v3.4.2 ({$architecture}) on JaySub management server...\n");
        $archive = \App\Services\OfflineXuiInstaller::ensureArchive($config, $architecture);
        fwrite(STDOUT, "Verified: {$archive['path']} (SHA-256: {$archive['sha256']})\n");
    } catch (\Throwable $e) {
        $failed = true;
        fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    }
}

exit($failed ? 1 : 0);
