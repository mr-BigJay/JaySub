<?php

declare(strict_types=1);

$config = require dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\Encryption;
use App\Services\SettingsService;
use App\Services\TelegramService;
use App\Services\TrafficSyncService;

$lockFile = $config['worker']['lock_file'] ?? dirname(__DIR__) . '/storage/worker.lock';
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
    fwrite(STDOUT, "Worker already running, skip.\n");
    exit(0);
}

try {
    $encryption = new Encryption($config['security']['encryption_key']);
    $telegram = new TelegramService(SettingsService::get('telegram_bot_token'));
    $sync = new TrafficSyncService($encryption, $telegram);
    $sync->syncAllPanels();
    fwrite(STDOUT, "Sync completed at " . date('c') . "\n");
} catch (\Throwable $e) {
    fwrite(STDERR, 'Worker error: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    flock($fp, LOCK_UN);
    fclose($fp);
}
