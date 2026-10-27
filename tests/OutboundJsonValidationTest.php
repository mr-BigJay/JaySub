<?php

declare(strict_types=1);

use App\Services\PanelOutboundService;
use App\Xui\OutboundJsonTree;
use PHPUnit\Framework\TestCase;

final class OutboundJsonValidationTest extends TestCase
{
    public function testHeadersObjectIsValid(): void
    {
        $node = OutboundJsonTree::decodeOutboundString(
            '{"tag":"x","protocol":"vless","streamSettings":{"wsSettings":{"headers":{}}}}',
        );
        self::assertNull(OutboundJsonTree::validateStructure($node));
    }

    public function testHeadersArrayIsInvalid(): void
    {
        $node = OutboundJsonTree::decodeOutboundString(
            '{"tag":"x","protocol":"vless","streamSettings":{"wsSettings":{"headers":[]}}}',
        );
        self::assertNotNull(OutboundJsonTree::validateStructure($node));
    }

    public function testParseOutboundJsonInputRejectsHeadersArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PanelOutboundService::parseOutboundJsonInput(
            '{"tag":"x","protocol":"vless","streamSettings":{"wsSettings":{"headers":[]}}}',
        );
    }
}
