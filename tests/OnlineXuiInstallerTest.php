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

    public function testMigrationKeepsSelectedOnlineAndOfflinePathsSeparate(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/src/Services/PanelMigrationService.php');
        self::assertIsString($source);
        self::assertStringNotContainsString('remoteConnectivityPreflightScript()', $source);
        self::assertStringNotContainsString("\$mode = 'offline';", $source);
        self::assertSame(
            1,
            substr_count($source, 'self::installOfflineXui($job, $target, $encryption, $config);'),
            'Offline installation may only be dispatched from the explicit offline branch'
        );
        self::assertStringContainsString("} elseif (\$mode === 'offline') {", $source);
        self::assertStringContainsString('OnlineXuiInstaller::remoteInstallScript()', $source);
        self::assertStringContainsString('روش انتخابی بدون اجازه به آفلاین تغییر نمی‌کند', $source);
    }

    public function testOnlineInstallerRemainsPinnedAndHasNoOfflinePrerequisiteProbe(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/src/Services/OnlineXuiInstaller.php');
        self::assertIsString($source);
        self::assertStringNotContainsString('remoteConnectivityPreflightScript', $source);
        self::assertStringNotContainsString('remoteOfflineInstallPrerequisiteScript', $source);
        self::assertStringContainsString('fetch_install_script', OnlineXuiInstaller::remoteInstallScript());
    }

    public function testPanelMigrationDelegatesToOnlineInstaller(): void
    {
        self::assertSame(
            OnlineXuiInstaller::remoteInstallScript(),
            PanelMigrationService::remoteXuiInstallScript('v3.4.2')
        );
    }
}
