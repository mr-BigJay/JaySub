<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Encryption;
use App\Xui\XuiClient;

final class BackupService
{
    private const MAX_FILES_PER_PANEL = 40;

    /** @param array<string, mixed> $config */
    public static function storageRoot(array $config): string
    {
        $base = (string) ($config['paths']['storage'] ?? dirname(__DIR__, 2) . '/storage');
        $dir = rtrim($base, '/') . '/backups/xui';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        return $dir;
    }

    /** @param array<string, mixed> $config */
    public static function panelDir(array $config, int $panelId): string
    {
        $dir = self::storageRoot($config) . '/' . $panelId;
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        return $dir;
    }

    /**
     * @param array<string, mixed> $config
     * @return array{filename:string, bytes:int, panel_id:int, panel_name:string}
     */
    public static function backupPanel(array $config, int $panelId, Encryption $encryption): array
    {
        $stmt = Database::pdo()->prepare('SELECT id, name, base_url, api_token_encrypted, is_active FROM vpn_panels WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $panelId]);
        $panel = $stmt->fetch();
        if ($panel === false) {
            throw new \RuntimeException('پنل یافت نشد.');
        }
        if ((int) ($panel['is_active'] ?? 0) !== 1) {
            throw new \RuntimeException('پنل غیرفعال است — بک‌آپ خودکار فقط برای پنل‌های فعال است.');
        }

        $token = $encryption->decrypt((string) $panel['api_token_encrypted']);
        $xui = new XuiClient((string) $panel['base_url'], $token);
        $result = $xui->downloadDatabaseBackup();
        if (!$result['ok'] || !isset($result['body'], $result['filename'])) {
            throw new \RuntimeException($result['error'] ?? 'دانلود بک‌آپ از 3x-ui ناموفق بود.');
        }

        $filename = self::sanitizeFilename($result['filename']);
        $dir = self::panelDir($config, $panelId);
        $path = $dir . '/' . $filename;
        if (file_put_contents($path, $result['body'], LOCK_EX) === false) {
            throw new \RuntimeException('ذخیره فایل بک‌آپ روی سرور ناموفق بود.');
        }

        self::prunePanelDir($dir, self::MAX_FILES_PER_PANEL);

        return [
            'filename' => $filename,
            'bytes' => (int) filesize($path),
            'panel_id' => $panelId,
            'panel_name' => (string) $panel['name'],
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return list<array{panel_id:int, panel_name:string, filename:string, bytes:int, ok:bool, error?:string}>
     */
    public static function backupAllActivePanels(array $config, Encryption $encryption): array
    {
        $rows = Database::pdo()->query(
            'SELECT id, name FROM vpn_panels WHERE is_active = 1 ORDER BY id'
        )->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $pid = (int) $row['id'];
            $name = (string) $row['name'];
            try {
                $r = self::backupPanel($config, $pid, $encryption);
                $out[] = [
                    'panel_id' => $pid,
                    'panel_name' => $name,
                    'filename' => $r['filename'],
                    'bytes' => $r['bytes'],
                    'ok' => true,
                ];
            } catch (\Throwable $e) {
                $out[] = [
                    'panel_id' => $pid,
                    'panel_name' => $name,
                    'filename' => '',
                    'bytes' => 0,
                    'ok' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }
        return $out;
    }

    /**
     * @return list<array{panel_id:int, panel_name:string, filename:string, bytes:int, mtime:int}>
     */
    public static function listFiles(array $config): array
    {
        $root = self::storageRoot($config);
        $panels = Database::pdo()->query('SELECT id, name, base_url FROM vpn_panels ORDER BY id')->fetchAll();
        /** @var array<int, array{name:string, base_url:string}> $panelMeta */
        $panelMeta = [];
        foreach ($panels as $p) {
            $panelMeta[(int) $p['id']] = [
                'name' => (string) $p['name'],
                'base_url' => (string) $p['base_url'],
            ];
        }

        $out = [];
        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $panelDir) {
            $panelId = (int) basename($panelDir);
            if ($panelId <= 0) {
                continue;
            }
            $meta = $panelMeta[$panelId] ?? ['name' => 'پنل #' . $panelId, 'base_url' => ''];
            $panelHost = self::hostFromPanelUrl($meta['base_url']);
            foreach (glob($panelDir . '/*') ?: [] as $path) {
                if (!is_file($path)) {
                    continue;
                }
                $name = basename($path);
                if (!self::isAllowedFilename($name)) {
                    continue;
                }
                $fileHost = self::hostFromBackupFilename($name);
                $mismatch = $panelHost !== null && $fileHost !== null
                    && strtolower($fileHost) !== strtolower($panelHost);
                $out[] = [
                    'panel_id' => $panelId,
                    'panel_name' => $meta['name'],
                    'panel_base_url' => $meta['base_url'],
                    'filename' => $name,
                    'bytes' => (int) filesize($path),
                    'mtime' => (int) filemtime($path),
                    'host_mismatch' => $mismatch,
                    'filename_host' => $fileHost,
                ];
            }
        }
        usort($out, static fn ($a, $b) => $b['mtime'] <=> $a['mtime']);
        return $out;
    }

    /** @param array<string, mixed> $config */
    public static function resolveDownloadPath(array $config, int $panelId, string $filename): ?string
    {
        if ($panelId <= 0 || !self::isAllowedFilename($filename)) {
            return null;
        }
        $path = self::panelDir($config, $panelId) . '/' . $filename;
        return is_file($path) ? $path : null;
    }

    public static function sanitizeFilename(string $filename): string
    {
        $filename = basename(str_replace(['\\', '/'], '', $filename));
        $filename = trim($filename);
        if ($filename === '' || !self::isAllowedFilename($filename)) {
            throw new \RuntimeException('نام فایل بک‌آپ از پنل نامعتبر است: ' . $filename);
        }
        return $filename;
    }

    public static function isAllowedFilename(string $filename): bool
    {
        if ($filename === '' || strlen($filename) > 200) {
            return false;
        }
        return (bool) preg_match('/^[A-Za-z0-9._\-]+$/', $filename);
    }

    public static function hostFromPanelUrl(string $baseUrl): ?string
    {
        $host = parse_url(trim($baseUrl), PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }
        return strtolower($host);
    }

    /** Host prefix in 3x-ui backup names, e.g. bell2.jay-force.ir from bell2.jay-force.ir_2026-09-11_091435.db */
    public static function hostFromBackupFilename(string $filename): ?string
    {
        $base = preg_replace('/\.(db|dump)$/i', '', $filename) ?? $filename;
        if (preg_match('/^(.+)_\d{4}-\d{2}-\d{2}_\d{6}$/', $base, $m)) {
            return $m[1];
        }
        return null;
    }

    private static function prunePanelDir(string $dir, int $keep): void
    {
        $files = glob($dir . '/*') ?: [];
        $files = array_filter($files, 'is_file');
        usort($files, static fn ($a, $b) => filemtime($b) <=> filemtime($a));
        foreach (array_slice($files, $keep) as $old) {
            @unlink($old);
        }
    }
}
