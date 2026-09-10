<?php

declare(strict_types=1);

namespace App\Xui;

final class XuiToken
{
    public static function normalize(string $token): string
    {
        $token = trim($token);
        if ($token === '') {
            return '';
        }
        if (preg_match('/^bearer\s+(.+)$/i', $token, $m) === 1) {
            return trim($m[1]);
        }
        return $token;
    }
}
