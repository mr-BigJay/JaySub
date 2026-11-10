<?php

declare(strict_types=1);

namespace App\Xui;

/** Parse 3x-ui v3.4.2 POST /panel/api/xray/ with type-preserving xraySetting. */
final class XrayTemplateParser
{
    /**
     * @return array{
     *   config: array<string, mixed>,
     *   rules: list<array<string, mixed>>,
     *   xraySettingRaw: string,
     *   xraySettingPersistable: bool,
     *   inboundTags: list<string>,
     *   outboundTestUrl: ?string,
     *   error: ?string
     * }
     */
    public static function parseFromApiJsonString(string $responseBody): array
    {
        $empty = self::emptyResult();
        try {
            $root = json_decode($responseBody, false, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return array_merge($empty, ['error' => 'JSON پاسخ Xray نامعتبر است']);
        }
        if (!is_object($root)) {
            return array_merge($empty, ['error' => 'پاسخ Xray از پنل ناقص است']);
        }

        return self::parseFromWireRoot($root);
    }

    /**
     * @param array<string, mixed> $apiData Legacy tests / decoded array payloads.
     * @return array{
     *   config: array<string, mixed>,
     *   rules: list<array<string, mixed>>,
     *   xraySettingRaw: string,
     *   xraySettingPersistable: bool,
     *   inboundTags: list<string>,
     *   outboundTestUrl: ?string,
     *   error: ?string
     * }
     */
    public static function parseFromDecodedArray(array $apiData): array
    {
        $encoded = json_encode($apiData, JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            return array_merge(self::emptyResult(), ['error' => 'پاسخ Xray از پنل ناقص است']);
        }

        return self::parseFromApiJsonString($encoded);
    }

    /**
     * @return array{
     *   config: array<string, mixed>,
     *   rules: list<array<string, mixed>>,
     *   xraySettingRaw: string,
     *   xraySettingPersistable: bool,
     *   inboundTags: list<string>,
     *   outboundTestUrl: ?string,
     *   error: ?string
     * }
     */
    public static function parseFromFetchPayload(array $fetchOkData, ?object $fetchObjectRoot): array
    {
        if ($fetchObjectRoot instanceof \stdClass) {
            return self::parseFromWireRoot($fetchObjectRoot);
        }

        return self::parseFromDecodedArray($fetchOkData);
    }

    /**
     * @return array{
     *   config: array<string, mixed>,
     *   rules: list<array<string, mixed>>,
     *   xraySettingRaw: string,
     *   xraySettingPersistable: bool,
     *   inboundTags: list<string>,
     *   outboundTestUrl: ?string,
     *   error: ?string
     * }
     */
    private static function parseFromWireRoot(object $wireRoot): array
    {
        $empty = self::emptyResult();
        $obj = $wireRoot->obj ?? null;
        if (is_string($obj)) {
            try {
                $obj = json_decode($obj, false, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                return array_merge($empty, ['error' => 'obj پاسخ Xray JSON نامعتبر است']);
            }
        }
        if (!is_object($obj)) {
            return array_merge($empty, ['error' => 'پاسخ Xray از پنل ناقص است']);
        }
        if (!property_exists($obj, 'xraySetting')) {
            return array_merge($empty, ['error' => 'xraySetting در پاسخ پنل یافت نشد']);
        }

        $xrayField = $obj->xraySetting;
        $raw = '';
        if (is_string($xrayField)) {
            $raw = $xrayField;
        } elseif (is_object($xrayField)) {
            $raw = self::encodeValueTree($xrayField);
            if ($raw === '') {
                return array_merge($empty, ['error' => 'سریال‌سازی xraySetting object ناموفق بود']);
            }
        } else {
            return array_merge($empty, ['error' => 'xraySetting در پاسخ پنل یافت نشد']);
        }

        try {
            $root = XraySettingJson::decodeRoot($raw);
        } catch (\Throwable) {
            return array_merge($empty, [
                'xraySettingRaw' => $raw,
                'error' => 'JSON قالب Xray نامعتبر است',
            ]);
        }

        $inboundTags = self::parseInboundTags($obj->inboundTags ?? null);
        $testUrl = isset($obj->outboundTestUrl) && is_string($obj->outboundTestUrl)
            ? $obj->outboundTestUrl
            : null;
        $subscriptionTags = self::parseStringListField($obj->subscriptionOutboundTags ?? null);
        $subscriptionOutbounds = self::parseObjectListField($obj->subscriptionOutbounds ?? null);

        return [
            'config' => XraySettingJson::configArrayForValidation($root),
            'rules' => XraySettingJson::rulesFromRoot($root),
            'xraySettingRaw' => $raw,
            'xraySettingPersistable' => true,
            'inboundTags' => $inboundTags,
            'outboundTestUrl' => $testUrl,
            'subscriptionOutboundTags' => $subscriptionTags,
            'subscriptionOutbounds' => $subscriptionOutbounds,
            'error' => null,
        ];
    }

    /** @return list<string> */
    private static function parseStringListField(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }

        return $out;
    }

    /** @return list<object> */
    private static function parseObjectListField(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, false);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (is_object($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private static function parseInboundTags(mixed $inboundRaw): array
    {
        $inboundTags = [];
        if (is_string($inboundRaw)) {
            $decoded = json_decode($inboundRaw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $t) {
                    if (is_string($t)) {
                        $inboundTags[] = $t;
                    }
                }
            }
        } elseif (is_array($inboundRaw)) {
            foreach ($inboundRaw as $t) {
                if (is_string($t)) {
                    $inboundTags[] = $t;
                }
            }
        }

        return $inboundTags;
    }

    private static function encodeValueTree(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : '';
    }

    /** @return array<string, mixed> */
    private static function emptyResult(): array
    {
        return [
            'config' => [],
            'rules' => [],
            'xraySettingRaw' => '',
            'xraySettingPersistable' => false,
            'inboundTags' => [],
            'outboundTestUrl' => null,
            'subscriptionOutboundTags' => [],
            'subscriptionOutbounds' => [],
            'error' => null,
        ];
    }
}
