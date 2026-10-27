<?php

declare(strict_types=1);

namespace App\Xui;

/** Parse and classify xraySetting.outbounds (3x-ui v3.4.2 template). */
final class XrayOutbounds
{
    /** @var list<string> */
    public const PROTECTED_TAGS = ['direct', 'block', 'api'];

    /**
     * @return list<array<string, mixed>>
     */
    public static function outboundsFromRoot(object $root): array
    {
        if (!isset($root->outbounds) || !is_array($root->outbounds)) {
            return [];
        }
        $out = [];
        foreach ($root->outbounds as $item) {
            if (is_object($item)) {
                $out[] = self::nodeToArray($item);
            } elseif (is_array($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $outbound */
    public static function tagOf(array $outbound): string
    {
        $tag = $outbound['tag'] ?? '';

        return is_string($tag) ? trim($tag) : '';
    }

    public static function isProtectedTag(string $tag): bool
    {
        $t = strtolower(trim($tag));

        return in_array($t, self::PROTECTED_TAGS, true);
    }

    /** @param array<string, mixed> $outbound */
    public static function isSubscriptionGenerated(array $outbound): bool
    {
        $tag = strtolower(self::tagOf($outbound));
        if ($tag !== '' && (str_starts_with($tag, 'sub-') || str_starts_with($tag, 'sub_') || str_contains($tag, 'subscription'))) {
            return true;
        }
        $settings = $outbound['settings'] ?? null;
        if (is_array($settings)) {
            foreach (['subscription', 'subscriptionId', 'fromSubscription'] as $key) {
                if (array_key_exists($key, $settings)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $outbound
     * @return array<string, mixed>
     */
    public static function summarize(array $outbound, int $priority, bool $enabled = true): array
    {
        $tag = self::tagOf($outbound);
        $protocol = is_string($outbound['protocol'] ?? null) ? (string) $outbound['protocol'] : '—';
        $kind = self::isSubscriptionGenerated($outbound) ? 'subscription' : 'template';

        return [
            'priority' => $priority,
            'tag' => $tag,
            'protocol' => $protocol,
            'enabled' => $enabled,
            'protected' => self::isProtectedTag($tag),
            'kind' => $kind,
            'fingerprint' => self::fingerprint($outbound),
            'raw' => OutboundRedactor::redact($outbound),
        ];
    }

    /** @param array<string, mixed> $outbound */
    public static function fingerprint(array $outbound): string
    {
        $json = json_encode(self::normalizeForFingerprint($outbound), JSON_UNESCAPED_UNICODE);

        return hash('sha256', $json !== false ? $json : '');
    }

    /** @param array<string, mixed> $outbound */
    public static function validateNewOutbound(array $outbound): ?string
    {
        $tag = self::tagOf($outbound);
        if ($tag === '') {
            return 'فیلد tag الزامی است';
        }
        if (self::isProtectedTag($tag)) {
            return 'افزودن یا بازنویسی outbound سیستمی «' . $tag . '» مجاز نیست';
        }
        $protocol = $outbound['protocol'] ?? '';
        if (!is_string($protocol) || trim($protocol) === '') {
            return 'فیلد protocol الزامی است';
        }

        return null;
    }

    /** @param array<string, mixed> $data @return mixed */
    private static function normalizeForFingerprint(mixed $data): mixed
    {
        if (!is_array($data)) {
            return $data;
        }
        ksort($data);
        foreach ($data as $k => $v) {
            $data[$k] = self::normalizeForFingerprint($v);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private static function nodeToArray(object $obj): array
    {
        $json = json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return [];
        }
        $arr = json_decode($json, true);

        return is_array($arr) ? $arr : [];
    }
}
