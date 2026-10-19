<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\Format;
use App\Services\BackupService;

function assertBackupTime(bool $valid, string $description): void
{
    if (!$valid) {
        fwrite(STDERR, "FAIL: {$description}\n");
        exit(1);
    }
}

$previousTimezone = date_default_timezone_get();
date_default_timezone_set('UTC');
$file = tempnam(sys_get_temp_dir(), 'jaysub-backup-time-');
if ($file === false) {
    fwrite(STDERR, "FAIL: cannot create fixture file\n");
    exit(1);
}

try {
    // A remote XUI host has stamped its backup filename using a DIFFERENT clock.
    $remoteName = 'bell2.example_2026-10-19_230754.db';
    // The actual backup was saved on JaySub at 01:00 Tehran on 19 October.
    $savedAt = strtotime('2026-10-19T01:00:00+03:30');
    assertBackupTime($savedAt !== false, 'test time fixture');
    assertBackupTime(touch($file, $savedAt), 'touch file at known absolute timestamp');
    clearstatcache(true, $file);
    $storedMtime = filemtime($file);
    assertBackupTime($storedMtime !== false, 'read saved mtime');
    $actual = BackupService::backupTimestamp($remoteName, (int) $storedMtime);

    assertBackupTime($actual === $savedAt, 'remote filename must not shift the backup time');
    assertBackupTime(Format::fromTimestamp($actual) === '2026/10/19 01:00', 'Tehran display reflects when JaySub saved the file');

    // The same Unix timestamp must work regardless of the host's PHP timezone.
    date_default_timezone_set('America/Los_Angeles');
    assertBackupTime(BackupService::backupTimestamp($remoteName, (int) filemtime($file)) === $savedAt, 'host timezone independence');

    // Existing real files with earlier modification times sort as earlier backups.
    $older = strtotime('2026-10-18T20:00:00+03:30');
    assertBackupTime($older !== false, 'older fixture time');
    assertBackupTime(
        BackupService::backupTimestamp('another_2026-10-20_120000.db', $older)
        < BackupService::backupTimestamp($remoteName, (int) $storedMtime),
        'sorting follows local save time rather than future-dated remote filenames',
    );

    // Safely support the exceptional case of missing metadata for legacy files.
    assertBackupTime(
        BackupService::backupTimestamp('legacy.db', 0) === 0,
        'missing metadata without a recognized filename does not invent the current time',
    );

    echo "PASS: 3x-ui backup times come from JaySub file mtime, displayed in Asia/Tehran.\n";
} finally {
    date_default_timezone_set($previousTimezone);
    @unlink($file);
}
