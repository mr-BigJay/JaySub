<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\OfflineXuiInstaller;

function verifyOfflinePath(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

foreach (['amd64', 'arm64'] as $architecture) {
    $path = OfflineXuiInstaller::remoteArchivePath($architecture);
    $secondPath = OfflineXuiInstaller::remoteArchivePath($architecture);
    verifyOfflinePath(
        (bool) preg_match('~\\A/tmp/jaysub-xui-[a-f0-9]{32}-' . $architecture . '\\.tar\\.gz\\z~D', $path),
        "strict filename contract: {$architecture}",
    );
    verifyOfflinePath($path !== $secondPath, "unique upload filename: {$architecture}");

    $script = OfflineXuiInstaller::remoteInstallScript($architecture, $path);
    verifyOfflinePath(
        str_contains($script, escapeshellarg($path)),
        "same archive path in remote installation script: {$architecture}",
    );
    verifyOfflinePath(str_contains($script, 'sha256sum -c -'), 'checksum verification preserved');
    verifyOfflinePath(str_contains($script, 'refusing to overwrite'), 'existing installation protected');
    verifyOfflinePath(
        !str_contains($script, 'curl ') && !str_contains($script, 'wget ') && !str_contains($script, 'apt-get '),
        'offline installer does not depend on downloading packages',
    );

    if (is_executable('/bin/bash')) {
        $temp = tempnam(sys_get_temp_dir(), 'xui-path-test-');
        verifyOfflinePath(is_string($temp), 'temp file creation');
        try {
            file_put_contents($temp, $script);
            $output = [];
            $exitCode = 0;
            exec('bash -n ' . escapeshellarg($temp) . ' 2>&1', $output, $exitCode);
            verifyOfflinePath($exitCode === 0, 'generated shell passes bash syntax: ' . implode("\n", $output));
        } finally {
            @unlink($temp);
        }
    }
}

$wrongArch = OfflineXuiInstaller::remoteArchivePath('arm64');
try {
    OfflineXuiInstaller::remoteInstallScript('amd64', $wrongArch);
    verifyOfflinePath(false, 'cross-architecture archive path must fail');
} catch (InvalidArgumentException) {
    // Expected.
}

try {
    OfflineXuiInstaller::remoteInstallScript('amd64', '/tmp/jaysub-xui-legacy-amd64.tar.gz');
    verifyOfflinePath(false, 'malformed path must fail');
} catch (InvalidArgumentException) {
    // Expected.
}

echo "PASS: generated remote paths are valid, unique and architecture-safe before SCP.\n";
