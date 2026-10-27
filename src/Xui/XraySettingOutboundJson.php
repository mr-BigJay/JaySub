<?php

declare(strict_types=1);

namespace App\Xui;

/** Type-safe append to xraySetting.outbounds only. */
final class XraySettingOutboundJson
{
    /**
     * @param array<string, mixed> $newOutbound
     */
    public static function appendOutbound(string $originalRaw, array $newOutbound): string
    {
        $root = XraySettingJson::decodeRoot($originalRaw);
        $nodes = self::outboundNodes($root);
        $newNode = self::arrayToNode($newOutbound);
        $nodes[] = $newNode;
        $root->outbounds = $nodes;

        $encoded = json_encode($root, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException('سریال‌سازی xraySetting ناموفق بود');
        }

        $violation = self::assertOnlyAppendedOutbound($originalRaw, $encoded, XrayOutbounds::tagOf($newOutbound));
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
        $lastTag = self::nodeTag($last);
        if ($lastTag !== $appendedTag) {
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

    /** @return list<mixed> */
    private static function outboundNodes(object $root): array
    {
        if (!isset($root->outbounds) || !is_array($root->outbounds)) {
            return [];
        }

        return $root->outbounds;
    }

    private static function nodeTag(mixed $node): string
    {
        if (!is_object($node) && !is_array($node)) {
            return '';
        }
        $json = json_encode($node, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return '';
        }
        $arr = json_decode($json, true);

        return is_array($arr) ? XrayOutbounds::tagOf($arr) : '';
    }

    /** @param array<string, mixed> $outbound */
    private static function arrayToNode(array $outbound): mixed
    {
        $json = json_encode($outbound, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('سریال‌سازی outbound جدید ناموفق بود');
        }
        $node = json_decode($json, false);
        if (!is_object($node) && !is_array($node)) {
            throw new \RuntimeException('outbound JSON نامعتبر است');
        }

        return $node;
    }
}
