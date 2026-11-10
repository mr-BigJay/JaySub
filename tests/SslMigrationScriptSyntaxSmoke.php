<?php

declare(strict_types=1);

// Standalone, non-destructive syntax validation for generated remote SSL scripts.
// Run on JaySub manager: php tests/SslMigrationScriptSyntaxSmoke.php
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\SslMigrationTransfer;

if (!is_executable('/bin/bash')) {
    fwrite(STDERR, "SKIP: /bin/bash is required\n");
    exit(2);
}

$checked = 0;
foreach (['.zip', '.tar.gz'] as $ext) {
    $script = SslMigrationTransfer::remoteExtractAndVerifyScript(
        '/root/cert',
        '/tmp/jaysub-certs' . $ext,
        $ext,
        'deadbeefdeadbeefdeadbeefdeadbeef'
    );
    // A sanity check that the fragment boundary is present before verification.
    if (!str_contains($script, "rm -rf \"\$d\"\ncert_pubkey_sha256()")) {
        fwrite(STDERR, "FAIL: SSL $ext fragment separator missing\n");
        exit(1);
    }
    $temporary = tempnam(sys_get_temp_dir(), 'jaysub-ssl-bash-');
    if ($temporary === false) {
        throw new RuntimeException('Could not create temporary file');
    }
    try {
        if (file_put_contents($temporary, $script) === false) {
            throw new RuntimeException('Could not write temporary shell script');
        }
        $output = [];
        $status = 1;
        exec('/bin/bash -n ' . escapeshellarg($temporary) . ' 2>&1', $output, $status);
        if ($status !== 0) {
            fwrite(STDERR, "FAIL: SSL $ext Bash syntax: " . implode("\n", $output) . "\n");
            exit(1);
        }
        echo "PASS: $ext generated SSL script: bash -n\n";
        ++$checked;
    } finally {
        @unlink($temporary);
    }
}
$script = SslMigrationTransfer::remoteVerifyDbCertPathsScript('/root/cert');
$temporary = tempnam(sys_get_temp_dir(), 'jaysub-ssl-db-bash-');
if ($temporary === false) {
    throw new RuntimeException('Could not create temporary DB SSL script');
}
try {
    file_put_contents($temporary, $script);
    $output = [];
    $status = 1;
    exec('/bin/bash -n ' . escapeshellarg($temporary) . ' 2>&1', $output, $status);
    if ($status !== 0) {
        fwrite(STDERR, "FAIL: DB SSL verification script: " . implode("\n", $output) . "\n");
        exit(1);
    }
    echo "PASS: post-restore SSL DB verification script: bash -n\n";
    ++$checked;
} finally {
    @unlink($temporary);
}
echo "PASS: $checked SSL generated shell scripts checked without modifying any certificates\n";
