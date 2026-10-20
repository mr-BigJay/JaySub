<?php

declare(strict_types=1);

use App\Services\MigrationTargetSnapshot;
use PHPUnit\Framework\TestCase;

final class MigrationTargetSnapshotTest extends TestCase
{
    private const JOB_A = 'a1b2c3d4e5f6789012345678abcdef01';

    private const JOB_B = 'fedcba9876543210fedcba9876543210';

    public function testSnapshotAndRollbackScriptsUsePerJobManifest(): void
    {
        $create = MigrationTargetSnapshot::remoteCreateSnapshotScript('/root/cert', self::JOB_A);
        $rollback = MigrationTargetSnapshot::remoteRollbackSnapshotScript(self::JOB_A);
        $manifest = MigrationTargetSnapshot::manifestPathForJob(self::JOB_A);
        self::assertStringContainsString(MigrationTargetSnapshot::ROLLBACK_DIR, $create);
        self::assertStringContainsString($manifest, $create);
        self::assertStringNotContainsString('latest.env', $create);
        self::assertStringContainsString('umask 077', $create);
        self::assertStringContainsString('chmod 700', $create);
        self::assertStringContainsString('chmod 600', $create);
        self::assertStringContainsString('sqlite3', $create);
        self::assertStringContainsString('.backup', $create);
        self::assertStringContainsString('CERT_DIR_EXISTED', $create);
        self::assertStringContainsString($manifest, $rollback);
        self::assertStringContainsString('JaySub migration rollback: SUCCESS', $rollback);
        self::assertStringNotContainsString('latest.env', $rollback);
        self::assertStringContainsString('systemctl stop x-ui', $rollback);
        self::assertStringContainsString('jaysub_install_sqlite_db_from_backup', $rollback);
        self::assertStringContainsString('jaysub_remove_sqlite_sidecars', $rollback);
        self::assertStringContainsString('PRAGMA quick_check', $rollback);
        self::assertStringContainsString('wait_xui_service_healthy', $rollback);
        self::assertStringContainsString('health check failed after restore', $rollback);
    }

    public function testGeneratedRollbackScriptPassesSyntaxCheck(): void
    {
        if (!is_executable('/bin/bash')) {
            self::markTestSkipped('Bash is unavailable');
        }

        $path = tempnam(sys_get_temp_dir(), 'jaysub-snap-rb-');
        self::assertIsString($path);
        try {
            file_put_contents($path, MigrationTargetSnapshot::remoteRollbackSnapshotScript(self::JOB_A));
            exec('bash -n ' . escapeshellarg($path) . ' 2>&1', $out, $code);
            self::assertSame(0, $code, implode("\n", $out));
        } finally {
            @unlink($path);
        }
    }

    public function testConcurrentJobsGetIsolatedSnapshotPaths(): void
    {
        $dirA = MigrationTargetSnapshot::snapshotDirForJob(self::JOB_A);
        $dirB = MigrationTargetSnapshot::snapshotDirForJob(self::JOB_B);
        self::assertNotSame($dirA, $dirB);
        self::assertStringContainsString(self::JOB_A, $dirA);
        self::assertStringContainsString(self::JOB_B, $dirB);
    }

    public function testRollbackRemovesCertDirWhenItDidNotExistBeforeMigration(): void
    {
        $rollback = MigrationTargetSnapshot::remoteRollbackSnapshotScript(self::JOB_A);
        self::assertStringContainsString('CERT_DIR_EXISTED', $rollback);
        self::assertStringContainsString('removed certificate dir created during migration', $rollback);
    }

    public function testSanitizeJobIdRejectsUnsafeIds(): void
    {
        self::expectException(\InvalidArgumentException::class);
        MigrationTargetSnapshot::sanitizeJobId('ghijklmnopqrstuvwxyz');
    }
}
