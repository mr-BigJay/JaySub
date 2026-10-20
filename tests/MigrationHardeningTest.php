<?php

declare(strict_types=1);

use App\Services\MigrationPostInstallVerify;
use App\Services\OfflineXuiInstaller;
use App\Services\PanelMigrationService;
use App\Services\SslMigrationTransfer;
use PHPUnit\Framework\TestCase;

final class MigrationHardeningTest extends TestCase
{
    public function testNormalizeInstallMode(): void
    {
        self::assertSame('offline', PanelMigrationService::normalizeInstallMode('offline'));
        self::assertSame('online', PanelMigrationService::normalizeInstallMode('online'));
        self::assertSame('online', PanelMigrationService::normalizeInstallMode(''));
    }

    public function testPostInstallVerifyRequiresRealBinaryNotOnlyCliScript(): void
    {
        $script = MigrationPostInstallVerify::remoteVerifyScript();
        self::assertStringContainsString('/usr/local/x-ui/x-ui', $script);
        self::assertStringContainsString('Only management script /usr/bin/x-ui present', $script);
        self::assertStringContainsString('wait_xui_service_healthy', $script);
    }

    public function testOnlineInstallScriptDoesNotTreatUsrBinWrapperAsBinary(): void
    {
        $script = PanelMigrationService::remoteXuiInstallScript('v3.4.2');
        self::assertStringContainsString('/usr/local/x-ui/x-ui', $script);
        self::assertStringContainsString('invalid compressed data', $script);
        self::assertStringContainsString('fetch_install_script', $script);
        self::assertStringNotContainsString('/v3.4.2/install.sh', $script);
    }

    public function testCorruptTarLogSnippetIsInstallFailure(): void
    {
        $log = "gzip: stdin: invalid compressed data--format violated\ntar: Error is not recoverable\n";
        self::assertTrue(PanelMigrationService::remoteOutputIndicatesXuiInstallFailure($log));
    }

    public function testPostInstallSuccessLineIsNotInstallFailure(): void
    {
        $out = "JaySub post-install verify: binary, systemd, service, and database OK\n";
        self::assertFalse(PanelMigrationService::remoteOutputIndicatesXuiInstallFailure($out));
    }

    public function testSslScriptUsesGzipTestAndOpenssl(): void
    {
        $script = SslMigrationTransfer::remoteExtractAndVerifyScript('/root/cert', '/tmp/jaysub-certs.tar.gz', '.tar.gz');
        self::assertStringContainsString('gzip -t', $script);
        self::assertStringContainsString('openssl x509', $script);
        self::assertStringContainsString('chmod 600', $script);
        self::assertStringContainsString('pubkey SHA-256 OK', $script);
        self::assertStringContainsString('verify_cert_key_match', $script);
    }

    public function testDbSslVerifyChecksPanelPaths(): void
    {
        $script = SslMigrationTransfer::remoteVerifyDbCertPathsScript('/root/cert');
        self::assertStringContainsString('webCertFile', $script);
        self::assertStringContainsString('public key mismatch', $script);
    }

    public function testOnlineInstallRequiresChecksumVerifiedInLog(): void
    {
        $script = \App\Services\OnlineXuiInstaller::remoteInstallScript();
        self::assertStringContainsString('Checksum verified', $script);
        self::assertStringContainsString(OfflineXuiInstaller::releaseSha256('amd64'), $script);
    }

    public function testSslZipScriptUsesUnzipTest(): void
    {
        $script = SslMigrationTransfer::remoteExtractAndVerifyScript('/root/cert', '/tmp/jaysub-certs.zip', '.zip');
        self::assertStringContainsString('unzip -t', $script);
    }
}
