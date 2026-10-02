<?php

declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    public static function token(string $key = 'csrf_token'): string
    {
        $existing = Session::get($key);
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }
        $token = bin2hex(random_bytes(32));
        Session::set($key, $token);
        return $token;
    }

    public static function validate(?string $submitted, string $key = 'csrf_token'): bool
    {
        $stored = Session::get($key);
        if (!is_string($stored) || !is_string($submitted)) {
            return false;
        }
        return hash_equals($stored, $submitted);
    }

    public static function field(string $key = 'csrf_token'): string
    {
        $t = htmlspecialchars(self::token($key), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="_csrf" value="' . $t . '">';
    }
}
