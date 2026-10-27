<?php

declare(strict_types=1);

namespace App\Xui;

/** Type-preserving outbound JSON (stdClass); never json_decode(..., true) on write paths. */
final class OutboundJsonTree
{
    public static function decodeOutboundString(string $json): object
    {
        $json = trim($json);
        if ($json === '') {
            throw new \InvalidArgumentException('JSON outbound خالی است');
        }
        $node = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        if (!is_object($node)) {
            throw new \InvalidArgumentException('outbound باید JSON object باشد');
        }

        return $node;
    }

    public static function cloneNode(object $node): object
    {
        $json = json_encode($node, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('clone outbound ناموفق بود');
        }
        $cloned = json_decode($json, false);
        if (!is_object($cloned)) {
            throw new \RuntimeException('clone outbound نامعتبر بود');
        }

        return $cloned;
    }

    public static function tag(object $node): string
    {
        $tag = $node->tag ?? null;

        return is_string($tag) ? trim($tag) : '';
    }

    public static function protocol(object $node): string
    {
        $p = $node->protocol ?? null;

        return is_string($p) ? trim($p) : '';
    }

    public static function fingerprint(object $node): string
    {
        $json = json_encode($node, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash('sha256', $json !== false ? $json : '');
    }

    public static function validateNew(object $node): ?string
    {
        $tag = self::tag($node);
        if ($tag === '') {
            return 'فیلد tag الزامی است';
        }
        if (XrayOutbounds::isProtectedTag($tag)) {
            return 'افزودن یا بازنویسی outbound سیستمی «' . $tag . '» مجاز نیست';
        }
        if (self::protocol($node) === '') {
            return 'فیلد protocol الزامی است';
        }

        return null;
    }

    /** @return list<object> */
    public static function templateNodesFromRoot(object $root): array
    {
        if (!isset($root->outbounds) || !is_array($root->outbounds)) {
            return [];
        }
        $out = [];
        foreach ($root->outbounds as $item) {
            if (is_object($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    public static function tagFromNode(mixed $node): string
    {
        return is_object($node) ? self::tag($node) : '';
    }

    public static function fingerprintNode(mixed $node): string
    {
        if (!is_object($node)) {
            return '';
        }

        return self::fingerprint($node);
    }
}
