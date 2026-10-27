<?php

declare(strict_types=1);

use App\Xui\XrayOutbounds;
use App\Xui\XraySettingJson;
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
        $new = [
            'tag' => 'copy-de',
            'protocol' => 'vless',
            'settings' => ['vnext' => []],
            'streamSettings' => ['wsSettings' => ['headers' => (object) []]],
        ];
        $updated = XraySettingOutboundJson::appendOutbound($raw, $new);
        self::assertStringContainsString('"headers":{}', $updated);
        self::assertStringNotContainsString('"headers":[]', $updated);
        self::assertNull(XraySettingOutboundJson::assertPreservedOutsideOutbounds($raw, $updated));
    }

    public function testRejectsChangeOutsideOutbounds(): void
    {
        $raw = $this->baseRaw();
        $root = XraySettingJson::decodeRoot($raw);
        $root->routing = (object) ['rules' => [(object) ['type' => 'field']]];
        $bad = json_encode($root, JSON_UNESCAPED_UNICODE);
        self::assertIsString($bad);
        self::assertNotNull(XraySettingOutboundJson::assertPreservedOutsideOutbounds($raw, $bad));
    }

    public function testDetectsConflictFingerprint(): void
    {
        $raw = $this->baseRaw();
        $a = ['tag' => 'x', 'protocol' => 'freedom', 'settings' => []];
        $b = ['tag' => 'x', 'protocol' => 'blackhole', 'settings' => []];
        self::assertSame('ready', \App\Services\PanelOutboundService::classifyTagOnConfig($raw, 'x', $a));
        self::assertSame('conflict', \App\Services\PanelOutboundService::classifyTagOnConfig(
            XraySettingOutboundJson::appendOutbound($raw, $a),
            'x',
            $b,
        ));
    }
}
