<?php

declare(strict_types=1);

use App\Services\MigrationTargetSnapshot;
use PHPUnit\Framework\TestCase;

final class MigrationTargetSnapshotTest extends TestCase
{
    public function testSnapshotAndRollbackScriptsReferenceRollbackDir(): void
    {
        $create = MigrationTargetSnapshot::remoteCreateSnapshotScript('/root/cert');
        $rollback = MigrationTargetSnapshot::remoteRollbackSnapshotScript();
        self::assertStringContainsString(MigrationTargetSnapshot::ROLLBACK_DIR, $create);
        self::assertStringContainsString('latest.env', $create);
        self::assertStringContainsString('x-ui.db', $create);
        self::assertStringContainsString('latest.env', $rollback);
    }
}
