<?php

declare(strict_types=1);

use App\Services\OnlineXuiInstaller;
use App\Services\PanelMigrationService;
use PHPUnit\Framework\TestCase;

final class OnlineXuiInstallerTest extends TestCase
{
    public function testUsesPinnedInstallShNotTagBranch(): void
    {
        self::assertStringContainsString(
            OnlineXuiInstaller::INSTALL_SH_COMMIT,
            OnlineXuiInstaller::installShRawUrl()
        );
        self::assertStringNotContainsString('/v3.4.2/install.sh', OnlineXuiInstaller::installShRawUrl());
        self::assertStringNotContainsString('/master/install.sh', OnlineXuiInstaller::installShRawUrl());
    }

    public function testRemoteScriptPassesVersionAndVerifiesChecksum(): void
    {
        $script = OnlineXuiInstaller::remoteInstallScript();
        self::assertStringContainsString(OnlineXuiInstaller::INSTALL_SH_SHA256, $script);
        self::assertStringContainsString("'v3.4.2'", $script);
        self::assertStringContainsString('fetch_install_script', $script);
        self::assertStringContainsString('detect_partial_xui', $script);
        self::assertStringContainsString('/usr/local/x-ui/x-ui', $script);
        self::assertStringContainsString('Checksum verified', $script);
        self::assertStringContainsString('Checksum mismatch', $script);
    }

    public function testReadOnlyConnectivityPreflightCoversInstallerAndRelease(): void
    {
        $script = OnlineXuiInstaller::remoteConnectivityPreflightScript();
        self::assertStringContainsString(OnlineXuiInstaller::installShRawUrl(), $script);
        self::assertStringContainsString('releases/download/v3.4.2/', $script);
        self::assertStringContainsString('x-ui-linux-', $script);
        self::assertStringContainsString('--connect-timeout 5 --max-time 12', $script);
        self::assertStringNotContainsString('apt-get', $script);
    }

    public function testMigrationContainsSafeOfflineFallback(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/src/Services/PanelMigrationService.php');
        self::assertIsString($source);
        self::assertStringContainsString('remoteConnectivityPreflightScript()', $source);
        self::assertStringContainsString("str_contains(\$installOut, 'Could not download verified install.sh')", $source);
        self::assertStringContainsString('installOfflineXui($job, $target, $encryption, $config)', $source);
        self::assertStringContainsString("xui_install_mode_effective", $source);
    }

    public function testPanelMigrationDelegatesToOnlineInstaller(): void
    {
        self::assertSame(
            OnlineXuiInstaller::remoteInstallScript(),
            PanelMigrationService::remoteXuiInstallScript('v3.4.2')
        );
    }
}
