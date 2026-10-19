<?php

declare(strict_types=1);

use App\Services\SslBackupService;
use PHPUnit\Framework\TestCase;

final class SslBackupServiceTest extends TestCase
{
    public function testLatestBackupFilePathReturnsNewestArchive(): void
    {
        $root = sys_get_temp_dir() . '/ssl-backup-test-' . bin2hex(random_bytes(4));
        $config = ['paths' => ['storage' => $root]];
        $dir = SslBackupService::serverDir($config, 7);
        $older = $dir . '/host_2020-01-01_120000.zip';
        $newer = $dir . '/host_2024-06-01_120000.tar.gz';
        file_put_contents($older, 'old');
        file_put_contents($newer, 'new');
        touch($older, 1_600_000_000);
        touch($newer, 1_700_000_000);
        try {
            self::assertSame($newer, SslBackupService::latestBackupFilePath($config, 7));
        } finally {
            @unlink($older);
            @unlink($newer);
            @rmdir($dir);
            @rmdir($root . '/backups/ssl');
            @rmdir($root . '/backups');
            @rmdir($root);
        }
    }
}
