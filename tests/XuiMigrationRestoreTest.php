<?php

declare(strict_types=1);

use App\Services\XuiMigrationRestore;
use PHPUnit\Framework\TestCase;

final class XuiMigrationRestoreTest extends TestCase
{
    public function testRemoteBackupPathMatchesValidation(): void
    {
        $path = XuiMigrationRestore::remoteBackupPath('panel-2024-01-01.db');
        self::assertSame('/tmp/jaysub-restore-panel-2024-01-01.db', $path);
        self::assertTrue(XuiMigrationRestore::isValidRemoteBackupPath($path));
    }

    public function testRejectsUnsafeRemoteBackupBasenames(): void
    {
        $this->expectException(InvalidArgumentException::class);
        XuiMigrationRestore::remoteBackupPath('evil;rm.db');
    }

    public function testRestoreScriptRequiresSqliteAndExistingBinary(): void
    {
        $path = XuiMigrationRestore::remoteBackupPath('backup.db');
        $script = XuiMigrationRestore::remoteRestoreScript($path);

        self::assertStringContainsString('/usr/local/x-ui/x-ui', $script);
        self::assertStringContainsString('/etc/x-ui/x-ui.db', $script);
        self::assertStringContainsString('sqlite3 CLI is required', $script);
        self::assertStringContainsString('203/EXEC risk', $script);
        self::assertStringContainsString('wait_xui_service_healthy', $script);
        self::assertStringContainsString('MainPID', $script);
        self::assertStringContainsString('/proc/', $script);
        self::assertStringContainsString('PRAGMA quick_check', $script);
        self::assertStringNotContainsString('pgrep -f', $script);
        self::assertStringNotContainsString('rm -rf /usr/local/x-ui', $script);
        self::assertStringContainsString('jaysub_install_sqlite_db_from_backup', $script);
        self::assertStringContainsString('jaysub_remove_sqlite_sidecars', $script);
    }

    public function testInternalRollbackTrapStopsServiceAndVerifiesHealth(): void
    {
        $script = XuiMigrationRestore::remoteRestoreScript(
            XuiMigrationRestore::remoteBackupPath('backup.db'),
        );
        self::assertStringContainsString('JaySub restore internal rollback: DB swap or quick_check failed', $script);
        self::assertStringContainsString('JaySub restore internal rollback: x-ui health check failed', $script);
        self::assertMatchesRegularExpression(
            '/rollback\(\)[\s\S]*systemctl stop x-ui[\s\S]*jaysub_install_sqlite_db_from_backup/',
            $script,
        );
    }

    public function testRestoreScriptRejectsMalformedRemotePath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        XuiMigrationRestore::remoteRestoreScript('/var/tmp/evil.db');
    }

    public function testGeneratedRestoreScriptPassesSyntaxCheck(): void
    {
        if (!is_executable('/bin/bash')) {
            self::markTestSkipped('Bash is unavailable');
        }

        $path = tempnam(sys_get_temp_dir(), 'xui-restore-test-');
        self::assertIsString($path);
        try {
            file_put_contents(
                $path,
                XuiMigrationRestore::remoteRestoreScript(
                    XuiMigrationRestore::remoteBackupPath('test.db')
                )
            );
            exec('bash -n ' . escapeshellarg($path) . ' 2>&1', $out, $code);
            self::assertSame(0, $code, implode("\n", $out));
        } finally {
            @unlink($path);
        }
    }

    public function testAssertLocalSqliteBackupAcceptsValidHeader(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'sqlite-');
        self::assertIsString($file);
        try {
            if (!extension_loaded('sqlite3') && trim((string) shell_exec('command -v sqlite3')) === '') {
                self::markTestSkipped('sqlite3 required for quick_check test');
            }
            $db = new SQLite3($file);
            $db->exec('CREATE TABLE jaysub_test(id INTEGER)');
            $db->close();
            XuiMigrationRestore::assertLocalSqliteBackup($file);
            self::assertTrue(true);
        } finally {
            @unlink($file);
        }
    }

    public function testAssertLocalSqliteBackupRejectsNonDatabase(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'notdb-');
        self::assertIsString($file);
        try {
            file_put_contents($file, 'not a sqlite file');
            $this->expectException(RuntimeException::class);
            XuiMigrationRestore::assertLocalSqliteBackup($file);
        } finally {
            @unlink($file);
        }
    }
}
