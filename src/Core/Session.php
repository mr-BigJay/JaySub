<?php

declare(strict_types=1);

namespace App\Core;

final class Session
{
    public static function start(string $name, ?string $savePath = null): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        if ($savePath !== null && $savePath !== '') {
            if (!is_dir($savePath)) {
                mkdir($savePath, 0750, true);
            }
            if (is_dir($savePath) && is_writable($savePath)) {
                session_save_path($savePath);
            }
        }
        session_name($name);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }
}
