<?php

declare(strict_types=1);

use App\Services\PanelMigrationService;
use PHPUnit\Framework\TestCase;

final class PanelMigrationServiceTest extends TestCase
{
    public function testInstallScriptWaitsForAptAndDoesNotMaskExitCodeWithTailPipe(): void
    {
        $script = PanelMigrationService::remoteXuiInstallScript('v3.4.2');
        self::assertStringContainsString('wait_apt', $script);
        self::assertStringContainsString('jaysub-xui-install.log', $script);
        self::assertStringContainsString('XUI_DB_TYPE=sqlite', $script);
        self::assertStringContainsString('/v3.4.2/install.sh', $script);
        self::assertStringContainsString('xui_binary_ready', $script);
        self::assertStringContainsString('jaysub-xui-installer.sh', $script);
        self::assertStringContainsString('xui_unit_ready', $script);
        self::assertStringNotContainsString('| tail -n', $script);
        self::assertStringNotContainsString('/master/install.sh', $script);
    }

    public function testDetectsKnownInstallFailureSnippets(): void
    {
        $log = "Failed to extract the x-ui release archive -- the previous installation has already been removed\n";
        self::assertTrue(PanelMigrationService::remoteOutputIndicatesXuiInstallFailure($log));
        self::assertFalse(PanelMigrationService::remoteOutputIndicatesXuiInstallFailure("Install complete\n"));
    }
}
