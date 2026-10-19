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

    public function testGeneratedTransferPathsAlwaysPassStrictInstallerValidation(): void
    {
        foreach (['amd64', 'arm64'] as $arch) {
            $one = OfflineXuiInstaller::remoteArchivePath($arch);
            $two = OfflineXuiInstaller::remoteArchivePath($arch);

            self::assertMatchesRegularExpression(
                '~\\A/tmp/jaysub-xui-[a-f0-9]{32}-' . $arch . '\\.tar\\.gz\\z~D',
                $one,
            );
            self::assertNotSame($one, $two, 'Each transfer must get its own unique destination');
            $script = OfflineXuiInstaller::remoteInstallScript($arch, $one);
            self::assertStringContainsString(escapeshellarg($one), $script);
            self::assertStringContainsString('sha256sum -c -', $script);
        }
    }

    public function testRejectsRemotePathWithDifferentArchitecture(): void
    {
        $wrongPath = OfflineXuiInstaller::remoteArchivePath('arm64');
        $this->expectException(InvalidArgumentException::class);
        OfflineXuiInstaller::remoteInstallScript('amd64', $wrongPath);
    }

    public function testRefusesUnsupportedArchitectureBeforeGeneratingRemotePath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OfflineXuiInstaller::remoteArchivePath('mips64');
    }

    public function testGeneratedBashScriptPassesSyntaxCheck(): void
    {
        if (!is_executable('/bin/bash')) {
            self::markTestSkipped('Bash is unavailable');
        }

        $path = tempnam(sys_get_temp_dir(), 'xui-test-');
        self::assertIsString($path);
        try {
            file_put_contents($path, OfflineXuiInstaller::remoteInstallScript(
                'arm64', '/tmp/jaysub-xui-' . str_repeat('a', 32) . '-arm64.tar.gz'
            ));
            exec('bash -n ' . escapeshellarg($path) . ' 2>&1', $out, $code);
            self::assertSame(0, $code, implode("\n", $out));
        } finally {
            @unlink($path);
        }
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

    public function testRejectsCorruptExistingManagerCacheInsteadOfSilentlyDownloading(): void
    {
        $root = sys_get_temp_dir() . '/xui-cache-test-' . bin2hex(random_bytes(4));
        $config = ['paths' => ['storage' => $root]];
        $path = OfflineXuiInstaller::archivePath($config, 'amd64');
        mkdir(dirname($path), 0700, true);
        file_put_contents($path, 'invalid archive - must not be used');
        try {
            $this->expectException(RuntimeException::class);
            OfflineXuiInstaller::ensureArchive($config, 'amd64');
        } finally {
            @unlink($path);
            @unlink(dirname($path) . '/.download-amd64.lock');
            @rmdir(dirname($path));
            @rmdir($root . '/xui-offline');
            @rmdir($root);
        }
    }

    public function testUploadRejectsAnUnsupportedArchitectureBeforeProcessing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OfflineXuiInstaller::stageUpload(['paths' => ['storage' => '/tmp']], 'armv7', []);
    }
}
