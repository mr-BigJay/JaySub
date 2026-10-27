<?php

declare(strict_types=1);

use App\Services\PanelOutboundService;
use App\Xui\OutboundJsonTree;
use App\Xui\XraySettingOutboundJson;
use PHPUnit\Framework\TestCase;

final class XraySettingOutboundJsonTest extends TestCase
{
    private function baseRaw(): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/xray-outbound-append-base.json');
    }

    public function testAppendPreservesHeadersObject(): void
    {
        $raw = $this->baseRaw();
        $new = OutboundJsonTree::decodeOutboundString(
            '{"tag":"copy-de","protocol":"vless","settings":{},"streamSettings":{"wsSettings":{"headers":{}}}}',
        );
        $updated = XraySettingOutboundJson::appendOutbound($raw, $new);
        self::assertStringContainsString('"headers":{}', $updated);
        self::assertStringContainsString('"settings":{}', $updated);
        self::assertStringNotContainsString('"headers":[]', $updated);
    }

    public function testUiJsonInputPreservesEmptyObjects(): void
    {
        $raw = $this->baseRaw();
        $uiJson = '{"tag":"ui-new","protocol":"vless","settings":{},"streamSettings":{"wsSettings":{"headers":{}}}}';
        $node = OutboundJsonTree::decodeOutboundString($uiJson);
        $updated = XraySettingOutboundJson::appendOutbound($raw, $node);
        self::assertStringContainsString('"headers":{}', $updated);
        self::assertStringContainsString('"settings":{}', $updated);
        self::assertNull(XraySettingOutboundJson::assertRoutingRulesUnchanged($raw, $updated));
    }

    public function testCopyFromSourcePreservesTypes(): void
    {
        $raw = $this->baseRaw();
        $source = PanelOutboundService::loadTemplateOutboundNode($raw, 'proxy');
        self::assertInstanceOf(\stdClass::class, $source);
        $source->tag = 'proxy-clone';
        $updated = XraySettingOutboundJson::appendOutbound($raw, $source);
        self::assertStringContainsString('"headers":{}', $updated);
    }

    public function testRejectsChangeOutsideOutbounds(): void
    {
        $raw = $this->baseRaw();
        $root = \App\Xui\XraySettingJson::decodeRoot($raw);
        $root->routing = (object) ['rules' => [(object) ['type' => 'field']]];
        $bad = json_encode($root, JSON_UNESCAPED_UNICODE);
        self::assertIsString($bad);
        self::assertNotNull(XraySettingOutboundJson::assertPreservedOutsideOutbounds($raw, $bad));
    }

    public function testDetectsConflictFingerprint(): void
    {
        $raw = $this->baseRaw();
        $a = OutboundJsonTree::decodeOutboundString('{"tag":"x","protocol":"freedom","settings":{}}');
        $b = OutboundJsonTree::decodeOutboundString('{"tag":"x","protocol":"blackhole","settings":{}}');
        self::assertSame('ready', PanelOutboundService::classifyTagOnConfig($raw, 'x', $a));
        $appended = XraySettingOutboundJson::appendOutbound($raw, $a);
        self::assertSame('conflict', PanelOutboundService::classifyTagOnConfig($appended, 'x', $b));
    }
}
