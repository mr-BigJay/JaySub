<?php

declare(strict_types=1);

namespace App\View;

final class Assets
{
    private static ?string $publicDir = null;

    public static function publicDir(): string
    {
        if (self::$publicDir === null) {
            self::$publicDir = dirname(__DIR__, 2) . '/public';
        }
        return self::$publicDir;
    }

    public static function url(string $path): string
    {
        $path = ltrim($path, '/');
        if (!str_starts_with($path, 'assets/')) {
            $path = 'assets/' . $path;
        }
        $file = self::publicDir() . '/' . $path;
        $ver = is_file($file) ? (string) filemtime($file) : '1';

        return '/' . $path . '?v=' . $ver;
    }

    /**
     * Serve /assets/* when Nginx sends all traffic to index.php or document root is wrong.
     */
    public static function serveIfRequested(string $uriPath): void
    {
        if (!preg_match('#^/assets/(.+)$#', $uriPath, $m)) {
            return;
        }

        $relative = str_replace('\\', '/', $m[1]);
        if ($relative === '' || str_contains($relative, '..')) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Forbidden';
            exit;
        }

        $assetsRoot = realpath(self::publicDir() . '/assets');
        $file = self::publicDir() . '/assets/' . $relative;
        $real = realpath($file);

        if ($assetsRoot === false || $real === false || !str_starts_with($real, $assetsRoot) || !is_file($real)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Asset not found';
            exit;
        }

        $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'css' => 'text/css; charset=utf-8',
            'js' => 'application/javascript; charset=utf-8',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'woff2' => 'font/woff2',
            'woff' => 'font/woff',
            default => 'application/octet-stream',
        };

        header('Content-Type: ' . $mime);
        header('Cache-Control: public, max-age=604800');
        header('Content-Length: ' . (string) filesize($real));
        readfile($real);
        exit;
    }
}
