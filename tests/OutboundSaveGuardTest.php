<?php

declare(strict_types=1);

use App\Services\OutboundSaveGuard;
use App\Services\RoutingSaveGuard;
use PHPUnit\Framework\TestCase;

final class OutboundSaveGuardTest extends TestCase
{
    public function testOutboundPersistDisabledByDefault(): void
    {
        self::assertFalse(OutboundSaveGuard::PERSIST_ENABLED);
        $this->expectException(\RuntimeException::class);
        OutboundSaveGuard::assertPersistAllowed();
    }

    public function testCanaryDefaultsToLockedWithoutExplicitApproval(): void
    {
        self::assertNull(OutboundSaveGuard::canaryPanelId([]));
        self::assertNull(OutboundSaveGuard::canaryPanelId([
            'outbound_persistence' => ['mode' => 'canary', 'canary_panel_id' => 7],
        ]));
        self::assertNull(OutboundSaveGuard::canaryPanelId([
            'outbound_persistence' => [
                'mode' => 'fleet', 'canary_panel_id' => 7, 'nonproduction_confirmed' => true,
            ],
        ]));
    }

    public function testOnlyExplicitNonProductionCanaryIsWritable(): void
    {
        $config = [
            'outbound_persistence' => [
                'mode' => 'canary',
                'nonproduction_confirmed' => true,
                'canary_panel_id' => 42,
            ],
        ];
        self::assertSame(42, OutboundSaveGuard::canaryPanelId($config));
        OutboundSaveGuard::assertPersistAllowed(42, $config);
        OutboundSaveGuard::assertTargetsAllowed([42], $config);
        self::assertTrue(true);
    }

    public function testCanaryCannotWriteToOtherPanel(): void
    {
        $config = [
            'outbound_persistence' => [
                'mode' => 'canary',
                'nonproduction_confirmed' => true,
                'canary_panel_id' => 42,
            ],
        ];
        $this->expectException(\RuntimeException::class);
        OutboundSaveGuard::assertPersistAllowed(43, $config);
    }

    public function testCanaryCannotWriteToFleet(): void
    {
        $config = [
            'outbound_persistence' => [
                'mode' => 'canary',
                'nonproduction_confirmed' => true,
                'canary_panel_id' => 42,
            ],
        ];
        $this->expectException(\RuntimeException::class);
        OutboundSaveGuard::assertTargetsAllowed([42, 43], $config);
    }

    public function testCanaryCannotBeActivatedByUnrestrictedFleetFlag(): void
    {
        $config = [
            'outbound_persistence' => [
                'mode' => 'fleet',
                'nonproduction_confirmed' => true,
                'canary_panel_id' => 42,
            ],
        ];
        $this->expectException(\RuntimeException::class);
        OutboundSaveGuard::assertTargetsAllowed([42], $config);
    }

    public function testRoutingPersistStillDisabled(): void
    {
        self::assertFalse(RoutingSaveGuard::PERSIST_ENABLED);
    }
}
