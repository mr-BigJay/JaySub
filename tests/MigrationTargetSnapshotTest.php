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
