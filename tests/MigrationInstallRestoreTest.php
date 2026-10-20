<?php

declare(strict_types=1);

use App\Services\OfflineXuiInstaller;
use App\Services\PanelMigrationService;
use App\Services\XuiMigrationRestore;
use PHPUnit\Framework\TestCase;

final class MigrationInstallRestoreTest extends TestCase
{
    public function testOfflineInstallSuccessMessageIsNotTreatedAsInstallFailure(): void
    {
        $success = "Offline 3x-ui v3.4.2 installation verified (MainPID/exe + database).\n";
        self::assertFalse(PanelMigrationService::remoteOutputIndicatesXuiInstallFailure($success));
    }

    public function testOfflineInstallFailureLinesStillDetectedWhenExitCodeWouldBeZero(): void
    {
        self::assertTrue(
            PanelMigrationService::remoteOutputIndicatesXuiInstallFailure(
                "Unexpected remote x-ui package path\n"
            )
        );
        self::assertTrue(
            PanelMigrationService::remoteOutputIndicatesXuiInstallFailure(
                "x-ui service or database failed after offline install\n"
            )
        );
    }

    public function testRestoreScriptRequiresSqlite3CliOnDestination(): void
    {
        $script = XuiMigrationRestore::remoteRestoreScript(
            XuiMigrationRestore::remoteBackupPath('panel.db')
        );
        self::assertStringContainsString('sqlite3', $script);
        self::assertStringContainsString('PRAGMA quick_check', $script);
        self::assertStringNotContainsString("grep -q 'SQLite format 3'", $script);
    }

    public function testRestoreScriptDocumentsRollbackWhenNoPriorDatabase(): void
    {
        $script = XuiMigrationRestore::remoteRestoreScript(
            XuiMigrationRestore::remoteBackupPath('panel.db')
        );
        self::assertStringContainsString('had_db=0', $script);
        self::assertStringContainsString('jaysub_remove_sqlite_sidecars "$DB"', $script);
    }

    public function testOfflineInstallScriptUsesStrictTarValidation(): void
    {
        $remote = OfflineXuiInstaller::remotePackagePath(str_repeat('a', 32), 'amd64');
        $script = OfflineXuiInstaller::remoteInstallScript('amd64', $remote);
        self::assertStringContainsString('validate_tar_members', $script);
        self::assertStringContainsString('verify_xui_binary', $script);
    }
}
