<?php

declare(strict_types=1);

use App\Xui\OutboundJsonTree;
use App\Xui\OutboundRedactor;
use App\Xui\XrayOutbounds;
use App\Xui\XraySettingJson;
use App\Xui\XraySettingOutboundJson;
use App\Xui\XrayTemplateParser;
use PHPUnit\Framework\TestCase;

final class XrayOutboundsTest extends TestCase
{
    public function testRedactsSecretsOnDisplayNode(): void
    {
        $node = OutboundJsonTree::decodeOutboundString(
            '{"tag":"t","protocol":"vless","settings":{"uuid":"secret-uuid","password":"p"}}',
        );
        $red = OutboundRedactor::redactNode($node);
        self::assertSame('••••••••', $red['settings']['uuid']);
        self::assertSame('••••••••', $red['settings']['password']);
    }

    public function testSubscriptionTagExcludedFromTemplateList(): void
    {
        $body = '{"success":true,"obj":{"xraySetting":{"outbounds":[{"tag":"my-proxy","protocol":"vless"}]},"subscriptionOutboundTags":["my-proxy"],"subscriptionOutbounds":[]}}';
        $parsed = XrayTemplateParser::parseFromApiJsonString($body);
        $root = XraySettingJson::decodeRoot($parsed['xraySettingRaw']);
        $lists = XrayOutbounds::buildUiOutboundLists($root, $parsed['subscriptionOutbounds'], $parsed['subscriptionOutboundTags']);
        self::assertSame([], $lists['template']);
        self::assertCount(1, $lists['subscription']);
    }

    public function testSubscriptionOutboundsFromApiField(): void
    {
        $body = '{"success":true,"obj":{"xraySetting":{"outbounds":[]},"subscriptionOutboundTags":["sub-a"],"subscriptionOutbounds":[{"tag":"sub-a","protocol":"freedom"}]}}';
        $parsed = XrayTemplateParser::parseFromApiJsonString($body);
        $subs = XrayOutbounds::summarizeSubscriptionApiOutbounds(
            $parsed['subscriptionOutbounds'],
            $parsed['subscriptionOutboundTags'],
        );
        self::assertCount(1, $subs);
        self::assertSame('subscription', $subs[0]['kind']);
    }

    public function testProtectedTagValidation(): void
    {
        $node = OutboundJsonTree::decodeOutboundString('{"tag":"direct","protocol":"freedom"}');
        self::assertNotNull(OutboundJsonTree::validateNew($node));
    }

    public function testRoutingRulesUnchangedAfterAppend(): void
    {
        $raw = (string) file_get_contents(__DIR__ . '/fixtures/xray-outbound-append-base.json');
        $node = OutboundJsonTree::decodeOutboundString('{"tag":"new1","protocol":"freedom","settings":{}}');
        $updated = XraySettingOutboundJson::appendOutbound($raw, $node);
        self::assertNull(XraySettingOutboundJson::assertRoutingRulesUnchanged($raw, $updated));
        self::assertNull(XraySettingOutboundJson::assertPreservedOutsideOutbounds($raw, $updated));
    }
}
