<?php

declare(strict_types=1);

use App\Services\MigrationExistingInstallProbe;
use App\Services\OfflineXuiInstaller;
use PHPUnit\Framework\TestCase;

final class MigrationExistingInstallProbeTest extends TestCase
{
    public function testProbeScriptDoesNotRequireSqlite3(): void
    {
        $script = MigrationExistingInstallProbe::remoteProbeScript();
        self::assertStringNotContainsString('sqlite3', $script);
        self::assertStringContainsString('healthy-existing', $script);
        self::assertStringContainsString('probe_xui_service_healthy', $script);
        self::assertStringNotContainsString('wait_xui_service_healthy', $script);
    }

    public function testRemoteArchivePathIsIndependentOfJobId(): void
    {
        $a = OfflineXuiInstaller::remoteArchivePath('amd64');
        $b = OfflineXuiInstaller::remoteArchivePath('amd64');
        self::assertNotSame($a, $b);
        self::assertTrue(OfflineXuiInstaller::isValidRemotePackagePath($a));
        self::assertMatchesRegularExpression('~\A/tmp/jaysub-xui-[a-f0-9]{32}-amd64\.tar\.gz\z~', $a);
    }
}
