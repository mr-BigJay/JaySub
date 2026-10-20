<?php

declare(strict_types=1);

use App\Services\PanelMigrationService;
use PHPUnit\Framework\TestCase;

/** Ensures POST → job field normalization matches worker expectations. */
final class MigrationInstallModeJobTest extends TestCase
{
    public function testPostValuesMapToJobField(): void
    {
        $cases = [
            ['offline', 'offline'],
            ['online', 'online'],
            ['OFFLINE', 'offline'],
            ['', 'online'],
            ['offline ', 'offline'],
        ];
        foreach ($cases as [$post, $expected]) {
            self::assertSame(
                $expected,
                PanelMigrationService::normalizeInstallMode($post),
                'POST value ' . var_export($post, true)
            );
        }
    }

    public function testJobPayloadUsesNormalizedMode(): void
    {
        foreach (['offline', 'online'] as $mode) {
            $job = [
                'xui_install_mode' => PanelMigrationService::normalizeInstallMode($mode),
            ];
            self::assertSame($mode, $job['xui_install_mode']);
        }
    }
}
