<?php

declare(strict_types=1);

use App\Services\PanelOutboundService;
use App\Xui\OutboundJsonTree;
use PHPUnit\Framework\TestCase;

final class OutboundSubscriptionConflictTest extends TestCase
{
    public function testSubscriptionTagCausesConflict(): void
    {
        $raw = '{"outbounds":[{"tag":"direct","protocol":"freedom"}]}';
        $node = OutboundJsonTree::decodeOutboundString('{"tag":"sub-live","protocol":"freedom","settings":{}}');
        self::assertSame(
            'conflict',
            PanelOutboundService::classifyTagOnConfig($raw, 'sub-live', $node, ['sub-live']),
        );
    }

    public function testUiListsDoNotDuplicateSubscriptionTag(): void
    {
        $body = '{"success":true,"obj":{"xraySetting":{"outbounds":[{"tag":"my-proxy","protocol":"vless"},{"tag":"sub-only","protocol":"freedom"}]},"subscriptionOutboundTags":["sub-only"],"subscriptionOutbounds":[{"tag":"sub-only","protocol":"freedom"}]}}';
        $parsed = \App\Xui\XrayTemplateParser::parseFromApiJsonString($body);
        $root = \App\Xui\XraySettingJson::decodeRoot($parsed['xraySettingRaw']);
        $lists = \App\Xui\XrayOutbounds::buildUiOutboundLists(
            $root,
            $parsed['subscriptionOutbounds'],
            $parsed['subscriptionOutboundTags'],
        );
        $tags = array_merge(
            array_column($lists['template'], 'tag'),
            array_column($lists['subscription'], 'tag'),
        );
        self::assertSame(['my-proxy', 'sub-only'], $tags);
    }
}
