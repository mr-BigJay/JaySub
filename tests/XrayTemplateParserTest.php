<?php

declare(strict_types=1);

use App\Xui\XrayTemplateParser;
use PHPUnit\Framework\TestCase;

final class XrayTemplateParserTest extends TestCase
{
    public function testParsesObjStringWithXraySettingObject(): void
    {
        $body = '{"success":true,"obj":"{\"xraySetting\":{\"routing\":{\"rules\":[]},\"outbounds\":[{\"tag\":\"p\",\"streamSettings\":{\"wsSettings\":{\"headers\":{}}}}]},\"inboundTags\":\"[]\"}"}';
        $parsed = XrayTemplateParser::parseFromApiJsonString($body);
        self::assertNull($parsed['error']);
        self::assertTrue($parsed['xraySettingPersistable']);
        self::assertStringContainsString('"headers":{}', $parsed['xraySettingRaw']);
    }

    public function testParsesXraySettingAsEmbeddedObject(): void
    {
        $body = '{"success":true,"obj":{"xraySetting":{"routing":{"rules":[{"type":"field","attrs":{},"sources":[]}]},"outbounds":[{"tag":"direct"}]},"inboundTags":"[]","outboundTestUrl":""}}';
        $parsed = XrayTemplateParser::parseFromApiJsonString($body);
        self::assertNull($parsed['error']);
        self::assertTrue($parsed['xraySettingPersistable']);
        self::assertStringContainsString('"attrs":{}', $parsed['xraySettingRaw']);
        self::assertStringContainsString('"sources":[]', $parsed['xraySettingRaw']);
    }
}
