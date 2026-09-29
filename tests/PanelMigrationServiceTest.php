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
        self::assertStringContainsString('x-ui.db', $script);
        self::assertStringNotContainsString('| tail -n', $script);
    }

    public function testDetectsKnownInstallFailureSnippets(): void
    {
        $log = "Failed to extract the x-ui release archive -- the previous installation has already been removed\n";
        self::assertTrue(PanelMigrationService::remoteOutputIndicatesXuiInstallFailure($log));
        self::assertFalse(PanelMigrationService::remoteOutputIndicatesXuiInstallFailure("Install complete\n"));
    }
}
