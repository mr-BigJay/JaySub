<?php

declare(strict_types=1);

namespace App\Xui;

/** Remove secrets from outbound payloads for API/UI responses. */
final class OutboundRedactor
{
    /** @var list<string> */
    private const SENSITIVE_KEYS = [
        'password',
        'uuid',
        'privateKey',
        'publicKey',
        'shortId',
        'secretKey',
        'encryption',
        'token',
        'psk',
    ];

    /** @return array<string, mixed> Display-only (not used on save path). */
    public static function redactNode(object $node): array
    {
        $json = json_encode($node, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return [];
        }
        $arr = json_decode($json, true);

        return is_array($arr) ? self::walk($arr) : [];
    }

    /** @param array<string, mixed> $outbound @return array<string, mixed> */
    public static function redact(array $outbound): array
    {
        return self::walk($outbound);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private static function walk(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $out[$key] = '••••••••';
                continue;
            }
            if (is_array($value)) {
                $out[$key] = self::walk($value);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $lower = strtolower($key);
        foreach (self::SENSITIVE_KEYS as $s) {
            if ($lower === strtolower($s)) {
                return true;
            }
        }

        return false;
    }
}
