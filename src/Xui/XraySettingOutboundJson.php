<?php

declare(strict_types=1);

namespace App\Xui;

/** Type-safe append to xraySetting.outbounds only. */
final class XraySettingOutboundJson
{
    public static function appendOutbound(string $originalRaw, object $newOutbound): string
    {
        $root = XraySettingJson::decodeRoot($originalRaw);
        $nodes = self::outboundNodes($root);
        $nodes[] = OutboundJsonTree::cloneNode($newOutbound);
        $root->outbounds = $nodes;

        $encoded = json_encode($root, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException('سریال‌سازی xraySetting ناموفق بود');
        }

        $violation = self::assertOnlyAppendedOutbound($originalRaw, $encoded, OutboundJsonTree::tag($newOutbound));
        if ($violation !== null) {
            throw new \RuntimeException($violation);
        }

        $outside = self::assertPreservedOutsideOutbounds($originalRaw, $encoded);
        if ($outside !== null) {
            throw new \RuntimeException($outside);
        }

        if (XraySettingJson::containsForbiddenEmptyArrayConversion($originalRaw, $encoded)) {
            throw new \RuntimeException('تبدیل ممنوع {} به [] در xraySetting');
        }

        return $encoded;
    }

    public static function assertOnlyAppendedOutbound(string $originalRaw, string $newRaw, string $appendedTag): ?string
    {
        try {
            $before = XraySettingJson::decodeRoot($originalRaw);
            $after = XraySettingJson::decodeRoot($newRaw);
        } catch (\Throwable $e) {
            return 'JSON نامعتبر: ' . $e->getMessage();
        }

        $beforeNodes = self::outboundNodes($before);
        $afterNodes = self::outboundNodes($after);
        if (count($afterNodes) !== count($beforeNodes) + 1) {
            return 'تعداد outbounds باید دقیقاً یک واحد افزایش یابد';
        }

        for ($i = 0; $i < count($beforeNodes); ++$i) {
            $err = XraySettingJson::compareStructurePublic($beforeNodes[$i], $afterNodes[$i], 'outbounds[' . $i . ']');
            if ($err !== null) {
                return 'outbound موجود تغییر کرده: ' . $err;
            }
        }

        $last = $afterNodes[count($afterNodes) - 1] ?? null;
        if (OutboundJsonTree::tagFromNode($last) !== $appendedTag) {
            return 'outbound افزوده‌شده tag موردانتظار را ندارد';
        }

        return null;
    }

    public static function assertPreservedOutsideOutbounds(string $originalRaw, string $newRaw): ?string
    {
        try {
            $before = XraySettingJson::decodeRoot($originalRaw);
            $after = XraySettingJson::decodeRoot($newRaw);
        } catch (\Throwable $e) {
            return 'JSON نامعتبر: ' . $e->getMessage();
        }

        unset($before->outbounds);
        unset($after->outbounds);

        return XraySettingJson::compareStructurePublic($before, $after, '');
    }

    public static function assertRoutingRulesUnchanged(string $originalRaw, string $newRaw): ?string
    {
        try {
            $before = XraySettingJson::decodeRoot($originalRaw);
            $after = XraySettingJson::decodeRoot($newRaw);
        } catch (\Throwable $e) {
            return 'JSON نامعتبر: ' . $e->getMessage();
        }

        $br = $before->routing->rules ?? null;
        $ar = $after->routing->rules ?? null;

        return XraySettingJson::compareStructurePublic($br, $ar, 'routing.rules');
    }

    /** @return list<mixed> */
    private static function outboundNodes(object $root): array
    {
        if (!isset($root->outbounds) || !is_array($root->outbounds)) {
            return [];
        }

        return $root->outbounds;
    }
}
