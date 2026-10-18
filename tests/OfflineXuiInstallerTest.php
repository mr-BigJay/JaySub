<?php

declare(strict_types=1);

use App\Services\OfflineXuiInstaller;
use PHPUnit\Framework\TestCase;

final class OfflineXuiInstallerTest extends TestCase
{
    public function testRecognizesOnlySupportedArchitectures(): void
    {
        self::assertSame('amd64', OfflineXuiInstaller::normalizeArchitecture("x86_64\n"));
        self::assertSame('arm64', OfflineXuiInstaller::normalizeArchitecture('aarch64'));
        self::assertNull(OfflineXuiInstaller::normalizeArchitecture('mips64'));
    }

    public function testOfflineScriptPinsOfficialChecksumAndNeverDownloads(): void
    {
        $remote = '/tmp/jaysub-xui-' . str_repeat('a', 32) . '-amd64.tar.gz';
        $script = OfflineXuiInstaller::remoteInstallScript('amd64', $remote);

        self::assertStringContainsString('1086716ea4b09f87893da3d83d5957af28ab7094c3a3a374cb3b369f6c0009a6', $script);
        self::assertStringContainsString('sha256sum -c -', $script);
        self::assertStringContainsString('refusing to overwrite', $script);
        self::assertStringContainsString('systemctl restart x-ui', $script);
        self::assertStringNotContainsString('curl ', $script);
        self::assertStringNotContainsString('wget ', $script);
        self::assertStringNotContainsString('apt-get ', $script);
    }

    public function testRefusesMalformedRemoteArchivePaths(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OfflineXuiInstaller::remoteInstallScript('amd64', '/root/malicious.tar.gz');
    }

    public function testProvidesFixedStoragePathsAndDoesNotFetchMissingPackage(): void
    {
        $config = ['paths' => ['storage' => '/tmp/jaysub-archive-test']];
        self::assertSame(
            '/tmp/jaysub-archive-test/xui-offline/v3.4.2/x-ui-linux-arm64.tar.gz',
            OfflineXuiInstaller::archivePath($config, 'arm64')
        );
        self::assertFalse(OfflineXuiInstaller::archiveExists($config, 'amd64'));
        $this->expectException(RuntimeException::class);
        OfflineXuiInstaller::validatedArchive($config, 'amd64');
    }

    public function testUploadRejectsAnUnsupportedArchitectureBeforeProcessing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OfflineXuiInstaller::stageUpload(['paths' => ['storage' => '/tmp']], 'armv7', []);
    }
}
