<?php

declare(strict_types=1);

use App\Xui\OutboundRedactor;
use App\Xui\XrayOutbounds;
use App\Xui\XraySettingJson;
use PHPUnit\Framework\TestCase;

final class XrayOutboundsTest extends TestCase
{
    public function testRedactsSecrets(): void
    {
        $ob = ['tag' => 't', 'protocol' => 'vless', 'settings' => ['uuid' => 'secret-uuid', 'password' => 'p']];
        $red = OutboundRedactor::redact($ob);
        self::assertSame('••••••••', $red['settings']['uuid']);
        self::assertSame('••••••••', $red['settings']['password']);
    }

    public function testSubscriptionKind(): void
    {
        $ob = ['tag' => 'sub-main', 'protocol' => 'freedom'];
        self::assertTrue(XrayOutbounds::isSubscriptionGenerated($ob));
        self::assertSame('subscription', XrayOutbounds::summarize($ob, 1)['kind']);
    }

    public function testProtectedTagValidation(): void
    {
        self::assertNotNull(XrayOutbounds::validateNewOutbound(['tag' => 'direct', 'protocol' => 'freedom']));
    }

    public function testRulesInOutboundAppendFixtureUnchanged(): void
    {
        $raw = (string) file_get_contents(__DIR__ . '/fixtures/xray-outbound-append-base.json');
        $updated = \App\Xui\XraySettingOutboundJson::appendOutbound($raw, [
            'tag' => 'new1',
            'protocol' => 'freedom',
            'settings' => [],
        ]);
        self::assertNull(\App\Xui\XraySettingJson::assertPreservedOutsideRoutingRules($raw, $updated));
    }
}
