<?php

declare(strict_types=1);

use App\Services\PanelRoutingService;
use PHPUnit\Framework\TestCase;

final class PanelRoutingParseXrayTest extends TestCase
{
    public function testParsesXraySettingAsJsonString(): void
    {
        $xrayInner = json_encode([
            'routing' => ['rules' => [['type' => 'field', 'domain' => 'a.com', 'outboundTag' => 'direct']]],
            'outbounds' => [['tag' => 'direct']],
        ], JSON_UNESCAPED_UNICODE);
        $api = [
            'success' => true,
            'obj' => [
                'xraySetting' => $xrayInner,
                'inboundTags' => '["in-443"]',
                'outboundTestUrl' => 'https://www.google.com/generate_204',
            ],
        ];
        $parsed = PanelRoutingService::parseXrayResponse($api);
        self::assertNull($parsed['error']);
        self::assertSame($xrayInner, $parsed['xraySettingRaw']);
        self::assertTrue($parsed['xraySettingPersistable']);
        self::assertCount(1, $parsed['rules']);
        self::assertSame(['in-443'], $parsed['inboundTags']);
        self::assertSame('https://www.google.com/generate_204', $parsed['outboundTestUrl']);
    }

    public function testParsesXraySettingAsJsonObject(): void
    {
        $xrayConfig = [
            'routing' => ['rules' => [['type' => 'field', 'outboundTag' => 'direct']]],
            'outbounds' => [['tag' => 'direct']],
        ];
        $api = [
            'success' => true,
            'obj' => [
                'xraySetting' => $xrayConfig,
                'inboundTags' => '["inbound-1"]',
                'outboundTestUrl' => 'https://example.com/generate_204',
            ],
        ];
        $parsed = PanelRoutingService::parseXrayResponse($api);
        self::assertNull($parsed['error']);
        self::assertCount(1, $parsed['rules']);
        self::assertSame('direct', $parsed['rules'][0]['outboundTag'] ?? null);
        self::assertSame(['inbound-1'], $parsed['inboundTags']);
        self::assertFalse($parsed['xraySettingPersistable']);
        self::assertSame('', $parsed['xraySettingRaw']);
    }

    public function testParsesObjAsJsonStringV342(): void
    {
        $xrayInner = json_encode([
            'routing' => ['rules' => []],
            'outbounds' => [],
        ]);
        $objPayload = json_encode([
            'xraySetting' => $xrayInner,
            'inboundTags' => '[]',
            'outboundTestUrl' => '',
        ]);
        $api = ['success' => true, 'obj' => $objPayload];
        $parsed = PanelRoutingService::parseXrayResponse($api);
        self::assertNull($parsed['error']);
        self::assertSame($xrayInner, $parsed['xraySettingRaw']);
        self::assertSame([], $parsed['rules']);
    }

    public function testRejectsInvalidObjJsonString(): void
    {
        $parsed = PanelRoutingService::parseXrayResponse(['obj' => '{not-json']);
        self::assertSame('obj پاسخ Xray JSON نامعتبر است', $parsed['error']);
    }

    public function testRejectsMissingXraySetting(): void
    {
        $parsed = PanelRoutingService::parseXrayResponse(['obj' => ['inboundTags' => '[]']]);
        self::assertSame('xraySetting در پاسخ پنل یافت نشد', $parsed['error']);
    }

    public function testRejectsInvalidXraySettingJson(): void
    {
        $parsed = PanelRoutingService::parseXrayResponse([
            'obj' => ['xraySetting' => '{broken'],
        ]);
        self::assertSame('JSON قالب Xray نامعتبر است', $parsed['error']);
    }
}
