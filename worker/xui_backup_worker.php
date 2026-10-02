<?php

declare(strict_types=1);

$config = require dirname(__DIR__) . '/src/bootstrap-cli.php';

use App\Core\Encryption;
use App\Services\BackupService;

$lockFile = $config['worker']['backup_lock_file'] ?? dirname(__DIR__) . '/storage/xui_backup.lock';
$dir = dirname($lockFile);
if (!is_dir($dir)) {
    mkdir($dir, 0750, true);
}

$fp = fopen($lockFile, 'c+');
if ($fp === false) {
    fwrite(STDERR, "Cannot open lock file\n");
    exit(1);
}

if (!flock($fp, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, "XUI backup worker already running, skip.\n");
    exit(0);
}

try {
    $encryption = new Encryption($config['security']['encryption_key']);
    $results = BackupService::backupAllActivePanels($config, $encryption);
    $ok = 0;
    $fail = 0;
    foreach ($results as $r) {
        if ($r['ok']) {
            ++$ok;
            fwrite(STDOUT, sprintf(
                "OK panel %d (%s): %s (%d bytes)\n",
                $r['panel_id'],
                $r['panel_name'],
                $r['filename'],
                $r['bytes']
            ));
        } else {
            ++$fail;
            fwrite(STDERR, sprintf(
                "FAIL panel %d (%s): %s\n",
                $r['panel_id'],
                $r['panel_name'],
                $r['error'] ?? 'unknown'
            ));
        }
    }
    fwrite(STDOUT, "XUI backup finished at " . date('c') . " — ok={$ok} fail={$fail}\n");
    exit($fail > 0 && $ok === 0 ? 1 : 0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'XUI backup worker error: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    flock($fp, LOCK_UN);
    fclose($fp);
}
