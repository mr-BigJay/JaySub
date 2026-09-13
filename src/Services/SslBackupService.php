<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Encryption;

final class SslBackupService
{
    private const KEEP_PER_SERVER = 12;

    /** @param array<string, mixed> $config */
    public static function storageRoot(array $config): string
    {
        $base = (string) ($config['paths']['storage'] ?? dirname(__DIR__, 2) . '/storage');
        $dir = rtrim($base, '/') . '/backups/ssl';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        return $dir;
    }

    /** @param array<string, mixed> $config */
    public static function serverDir(array $config, int $serverId): string
    {
        $dir = self::storageRoot($config) . '/' . $serverId;
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        return $dir;
    }

    /**
     * @param array<string, mixed> $config
     * @return array{filename:string, bytes:int, server_id:int, server_name:string}
     */
    public static function backupServer(array $config, int $serverId, Encryption $encryption): array
    {
        $server = SslServerService::findById($serverId);
        if ($server === null) {
            throw new \RuntimeException('سرور یافت نشد.');
        }
        if ((int) ($server['is_active'] ?? 0) !== 1) {
            throw new \RuntimeException('سرور غیرفعال است.');
        }

        $result = SshRemoteZip::zipCertDirectory($server, $encryption);
        if (!$result['ok'] || !isset($result['body'])) {
            $msg = $result['error'] ?? 'SSH/zip ناموفق';
            SslServerService::markBackupResult($serverId, false, $msg);
            throw new \RuntimeException($msg);
        }

        $hostSlug = preg_replace('/[^a-zA-Z0-9._-]+/', '-', (string) $server['host']) ?? 'server';
        $hostSlug = trim($hostSlug, '-');
        if ($hostSlug === '') {
            $hostSlug = 'server';
        }
        $now = BackupCalendar::tehranNow();
        $filename = $hostSlug . '_' . $now->format('Y-m-d_His') . '.zip';
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $filename)) {
            throw new \RuntimeException('نام فایل نامعتبر.');
        }

        $path = self::serverDir($config, $serverId) . '/' . $filename;
        if (file_put_contents($path, $result['body'], LOCK_EX) === false) {
            SslServerService::markBackupResult($serverId, false, 'ذخیره zip روی JaySub ناموفق');
            throw new \RuntimeException('ذخیره فایل روی سرور JaySub ناموفق بود.');
        }

        self::pruneServerDir(self::serverDir($config, $serverId), self::KEEP_PER_SERVER);
        SslServerService::markBackupResult($serverId, true);

        return [
            'filename' => $filename,
            'bytes' => (int) filesize($path),
            'server_id' => $serverId,
            'server_name' => (string) $server['name'],
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return list<array{server_id:int, server_name:string, filename:string, bytes:int, ok:bool, error?:string}>
     */
    public static function backupAllActive(array $config, Encryption $encryption): array
    {
        $rows = Database::pdo()->query('SELECT id, name FROM ssl_servers WHERE is_active = 1 ORDER BY id')->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $sid = (int) $row['id'];
            try {
                $r = self::backupServer($config, $sid, $encryption);
                $out[] = [
                    'server_id' => $sid,
                    'server_name' => (string) $row['name'],
                    'filename' => $r['filename'],
                    'bytes' => $r['bytes'],
                    'ok' => true,
                ];
            } catch (\Throwable $e) {
                $out[] = [
                    'server_id' => $sid,
                    'server_name' => (string) $row['name'],
                    'filename' => '',
                    'bytes' => 0,
                    'ok' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }
        return $out;
    }

    /** @return list<array<string, mixed>> */
    public static function listFiles(array $config): array
    {
        $servers = SslServerService::listAll();
        $names = [];
        foreach ($servers as $s) {
            $names[(int) $s['id']] = (string) $s['name'];
        }
        $root = self::storageRoot($config);
        $out = [];
        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $sid = (int) basename($dir);
            foreach (glob($dir . '/*.zip') ?: [] as $path) {
                if (!is_file($path)) {
                    continue;
                }
                $name = basename($path);
                $out[] = [
                    'server_id' => $sid,
                    'server_name' => $names[$sid] ?? ('سرور #' . $sid),
                    'filename' => $name,
                    'bytes' => (int) filesize($path),
                    'mtime' => (int) filemtime($path),
                ];
            }
        }
        usort($out, static fn ($a, $b) => (int) $b['mtime'] <=> (int) $a['mtime']);
        return $out;
    }

    /** @param array<string, mixed> $config */
    public static function resolveDownloadPath(array $config, int $serverId, string $filename): ?string
    {
        if ($serverId <= 0 || !preg_match('/^[A-Za-z0-9._-]+\\.zip$/', $filename)) {
            return null;
        }
        $path = self::serverDir($config, $serverId) . '/' . $filename;
        return is_file($path) ? $path : null;
    }

    private static function pruneServerDir(string $dir, int $keep): void
    {
        $files = glob($dir . '/*.zip') ?: [];
        usort($files, static fn ($a, $b) => filemtime($b) <=> filemtime($a));
        foreach (array_slice($files, $keep) as $old) {
            @unlink($old);
        }
    }
}
