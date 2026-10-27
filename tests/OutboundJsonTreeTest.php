<?php

declare(strict_types=1);

use App\Services\PanelOutboundService;
use App\Xui\OutboundJsonTree;
use PHPUnit\Framework\TestCase;

final class OutboundJsonTreeTest extends TestCase
{
    public function testParseOutboundJsonInputRejectsPhpArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PanelOutboundService::parseOutboundJsonInput(['tag' => 'x', 'settings' => []]);
    }

    public function testDecodePreservesEmptyObject(): void
    {
        $node = OutboundJsonTree::decodeOutboundString('{"tag":"a","protocol":"vless","settings":{}}');
        $json = json_encode($node, JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('"settings":{}', $json);
    }
}
