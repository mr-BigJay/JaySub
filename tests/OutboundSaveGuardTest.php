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

    public function testRoutingPersistStillDisabled(): void
    {
        self::assertFalse(RoutingSaveGuard::PERSIST_ENABLED);
    }
}
