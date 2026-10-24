<?php

declare(strict_types=1);

use App\Xui\XrayRoutingRules;
use App\Xui\XraySettingJson;
use PHPUnit\Framework\TestCase;

final class XraySettingJsonTest extends TestCase
{
    private function fixtureRaw(): string
    {
        $path = dirname(__DIR__) . '/tests/fixtures/xray-v342-ws-headers.json';
        $raw = file_get_contents($path);
        self::assertIsString($raw);

        return $raw;
    }

    public function testPreservesHeadersEmptyObjectAfterRulesReplace(): void
    {
        $raw = $this->fixtureRaw();
        $root = XraySettingJson::decodeRoot($raw);
        $rules = XraySettingJson::rulesFromRoot($root);
        $rules[] = ['type' => 'field', 'domain' => 'new.test', 'outboundTag' => 'direct'];

        $updated = XraySettingJson::replaceRoutingRules($raw, $rules);
        self::assertStringContainsString('"headers":{}', $updated);
        self::assertStringNotContainsString('"headers":[]', $updated);
        self::assertStringContainsString('"attrs":{}', $updated);
        self::assertStringContainsString('"sources":[]', $updated);
        self::assertStringNotContainsString('"attrs":[]', $updated);
    }

    public function testNoOpRulesReplaceKeepsRawIdentical(): void
    {
        $raw = $this->fixtureRaw();
        $rules = XraySettingJson::rulesFromRoot(XraySettingJson::decodeRoot($raw));
        $updated = XraySettingJson::replaceRoutingRules($raw, $rules);
        self::assertSame($raw, $updated);
    }

    public function testAssocDecodeReencodeCorruptsHeadersObject(): void
    {
        $raw = $this->fixtureRaw();
        $broken = XrayRoutingRules::encodeConfig(
            json_decode($raw, true) ?: [],
        );
        self::assertStringContainsString('"headers":[]', $broken);
        self::assertNotNull(XraySettingJson::assertPreservedOutsideRoutingRules($raw, $broken));
    }

    public function testSafeReplaceDoesNotAlterOutboundsOutsideRules(): void
    {
        $raw = $this->fixtureRaw();
        $rules = XraySettingJson::rulesFromRoot(XraySettingJson::decodeRoot($raw));
        $updated = XraySettingJson::replaceRoutingRules($raw, $rules);
        self::assertNull(XraySettingJson::assertPreservedOutsideRoutingRules($raw, $updated));
    }

    public function testRealArrayStaysArray(): void
    {
        $raw = '{"routing":{"rules":[]},"outbounds":[{"tag":"x","settings":{"vnext":[]}}]}';
        $updated = XraySettingJson::replaceRoutingRules($raw, [
            ['type' => 'field', 'outboundTag' => 'x'],
        ]);
        self::assertStringContainsString('"vnext":[]', $updated);
    }

    public function testBackupRawEqualsOriginalBytes(): void
    {
        $raw = $this->fixtureRaw();
        $dir = sys_get_temp_dir() . '/jaysub-routing-bak-' . bin2hex(random_bytes(3));
        mkdir($dir, 0700, true);
        $cfg = ['paths' => ['storage' => $dir]];
        try {
            $name = \App\Services\RoutingBackupService::backupPanelSettings(1, $raw, $cfg);
            $stored = file_get_contents($dir . '/routing-backups/1/' . $name);
            self::assertSame($raw, $stored);
        } finally {
            @unlink($dir . '/routing-backups/1/' . ($name ?? ''));
            @glob($dir . '/routing-backups/1/*');
            @rmdir($dir . '/routing-backups/1');
            @rmdir($dir . '/routing-backups');
            @rmdir($dir);
        }
    }
}
