<?php

declare(strict_types=1);

use App\Services\RoutingSaveGuard;
use PHPUnit\Framework\TestCase;

final class RoutingSaveGuardTest extends TestCase
{
    public function testPersistDisabledByDefault(): void
    {
        self::assertFalse(RoutingSaveGuard::PERSIST_ENABLED);
        $this->expectException(\RuntimeException::class);
        RoutingSaveGuard::assertPersistAllowed();
    }
}
