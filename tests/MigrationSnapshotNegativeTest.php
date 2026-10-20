<?php

declare(strict_types=1);

use App\Services\MigrationTargetSnapshot;
use App\Services\PanelMigrationService;
use App\Services\SslMigrationTransfer;
use App\Services\XuiMigrationRestore;
use PHPUnit\Framework\TestCase;

/** Static checks for migration recovery scripts (no live VPS). */
final class MigrationSnapshotNegativeTest extends TestCase
{
    private const JOB = 'deadbeefdeadbeefdeadbeefdeadbeef';

    public function testSslScriptReferencesJobManifestNotSharedLatestEnv(): void
    {
        $script = SslMigrationTransfer::remoteExtractAndVerifyScript(
            '/root/cert',
            '/tmp/jaysub-certs.tar.gz',
            '.tar.gz',
            self::JOB,
        );
        $manifest = MigrationTargetSnapshot::manifestPathForJob(self::JOB);
        self::assertStringContainsString($manifest, $script);
        self::assertStringNotContainsString('latest.env', $script);
        self::assertStringContainsString('rollback_cert_from_snapshot', $script);
        self::assertStringContainsString('No private key file found', $script);
    }

    public function testRollbackScriptLogsExplicitFailureWhenManifestMissing(): void
    {
        $script = MigrationTargetSnapshot::remoteRollbackSnapshotScript(self::JOB);
        self::assertStringContainsString('rollback: FAILED', $script);
    }

    public function testCreateSnapshotRequiresSqliteBackupNotPlainCopy(): void
    {
        $script = MigrationTargetSnapshot::remoteCreateSnapshotScript('/root/cert', self::JOB);
        self::assertStringContainsString('.backup', $script);
        self::assertStringNotContainsString('cp -a "$DB"', $script);
    }

    public function testRestoreScriptMentionsServiceRestartForRecoveryContext(): void
    {
        $script = XuiMigrationRestore::remoteRestoreScript(
            XuiMigrationRestore::remoteBackupPath('panel.db'),
        );
        self::assertStringContainsString('systemctl restart x-ui', $script);
    }

    public function testSnapshotPermissionsEnforcedAtStartOfScripts(): void
    {
        $create = MigrationTargetSnapshot::remoteCreateSnapshotScript('/root/cert', self::JOB);
        $rollback = MigrationTargetSnapshot::remoteRollbackSnapshotScript(self::JOB);
        self::assertMatchesRegularExpression('/umask 077[\s\S]*chmod 700/', $create);
        self::assertStringStartsWith('set -euo pipefail', $rollback);
        self::assertStringContainsString('umask 077', $rollback);
    }

    public function testSslRollbackWhenCertDirDidNotExistBeforeMigration(): void
    {
        $rollback = MigrationTargetSnapshot::bashApplyCertStateFromManifest();
        self::assertStringContainsString('CERT_DIR_EXISTED', $rollback);
        self::assertStringContainsString('rm -rf "$CERT_DIR"', $rollback);
    }

    public function testPanelMigrationServiceWiresPostSnapshotRecovery(): void
    {
        $ref = new \ReflectionClass(PanelMigrationService::class);
        $source = file_get_contents($ref->getFileName());
        self::assertIsString($source);
        self::assertStringContainsString('target_snapshot_status', $source);
        self::assertStringContainsString('runSslAndRestoreSteps', $source);
        self::assertStringContainsString('rollbackTargetSnapshot', $source);
        self::assertStringContainsString('JaySub migration recovery: rollback SUCCESS', $source);
        self::assertStringContainsString('JaySub migration recovery: rollback FAILED', $source);
    }
}
