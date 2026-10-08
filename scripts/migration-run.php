<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require __DIR__ . '/lib/cli.php';

$jobId = isset($argv[1]) ? trim((string) $argv[1]) : '';
if ($jobId === '') {
    fwrite(STDERR, "Usage: php scripts/migration-run.php <job_id>\n");
    exit(1);
}

$config = require dirname(__DIR__) . '/src/bootstrap-cli.php';
$encryption = new \App\Core\Encryption((string) $config['security']['encryption_key']);

\App\Services\PanelMigrationService::execute($jobId, $config, $encryption);
