<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Encryption;
use App\Xui\XuiClient;

final class BackupService
{
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
        self::applyWeeklyRetention($config);
        return $out;
    }

    /**
     * Past weeks (before current Sat–Fri week): keep latest backup per panel per week; delete others.
     *
     * @param array<string, mixed> $config
     */
    public static function applyWeeklyRetention(array $config): void
    {
        $now = BackupCalendar::tehranNow();
        $currentWeekStart = BackupCalendar::weekStartSaturday($now);
        $root = self::storageRoot($config);
        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $panelDir) {
            $paths = array_filter(glob($panelDir . '/*') ?: [], 'is_file');
            /** @var array<string, list<string>> $byWeek */
            $byWeek = [];
            foreach ($paths as $path) {
                $name = basename($path);
                if (!self::isAllowedFilename($name)) {
                    continue;
                }
                $ts = self::backupTimestamp($name, (int) filemtime($path));
                $weekStart = BackupCalendar::weekStartSaturday(
                    (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone(BackupCalendar::TZ))
                );
                if ($weekStart >= $currentWeekStart) {
                    continue;
                }
                $key = $weekStart->format('Y-m-d');
                $byWeek[$key][] = $path;
            }
            foreach ($byWeek as $weekFiles) {
                if (count($weekFiles) <= 1) {
                    continue;
                }
                usort($weekFiles, static function (string $a, string $b): int {
                    $ta = self::backupTimestamp(basename($a), (int) filemtime($a));
                    $tb = self::backupTimestamp(basename($b), (int) filemtime($b));
                    return $tb <=> $ta;
                });
                foreach (array_slice($weekFiles, 1) as $old) {
                    @unlink($old);
                }
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listLatestPerPanel(array $config): array
    {
        $all = self::listFiles($config);
        $best = [];
        foreach ($all as $f) {
            $pid = (int) $f['panel_id'];
            if (!isset($best[$pid]) || (int) $f['backup_ts'] > (int) $best[$pid]['backup_ts']) {
                $best[$pid] = $f;
            }
        }
        $out = array_values($best);
        usort($out, static fn ($a, $b) => strcmp((string) $a['panel_name'], (string) $b['panel_name']));
        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listCurrentWeek(array $config): array
    {
        $start = BackupCalendar::weekStartSaturday(BackupCalendar::tehranNow());
        $end = BackupCalendar::weekEndFriday($start);
        $from = $start->getTimestamp();
        $to = $end->getTimestamp();
        $out = [];
        foreach (self::listFiles($config) as $f) {
            $ts = (int) $f['backup_ts'];
            if ($ts >= $from && $ts <= $to) {
                $out[] = $f;
            }
        }
        usort($out, static fn ($a, $b) => (int) $b['backup_ts'] <=> (int) $a['backup_ts']);
        return $out;
    }

    /**
     * One latest backup per panel per week in the given Jalali month.
     *
     * @return list<array<string, mixed>>
     */
    public static function listMonthWeekly(array $config, int $jalaliYear, int $jalaliMonth): array
    {
        if ($jalaliMonth < 1 || $jalaliMonth > 12) {
            return [];
        }
        $all = self::listFiles($config);
        /** @var array<string, array<string, mixed>> $groups */
        $groups = [];
        foreach ($all as $f) {
            $j = BackupCalendar::jalaliFromTimestamp((int) $f['backup_ts']);
            if ($j['jy'] !== $jalaliYear || $j['jm'] !== $jalaliMonth) {
                continue;
            }
            $weekKey = BackupCalendar::weekKey(
                (new \DateTimeImmutable('@' . (int) $f['backup_ts']))->setTimezone(new \DateTimeZone(BackupCalendar::TZ))
            );
            $gkey = $f['panel_id'] . '|' . $weekKey;
            if (!isset($groups[$gkey]) || (int) $f['backup_ts'] > (int) $groups[$gkey]['backup_ts']) {
                $f['week_key'] = $weekKey;
                $groups[$gkey] = $f;
            }
        }
        $out = array_values($groups);
        usort($out, static fn ($a, $b) => (int) $b['backup_ts'] <=> (int) $a['backup_ts']);
        return $out;
    }

    /**
     * @return list<array<string, mixed>>
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
                $mtime = (int) filemtime($path);
                $backupTs = self::backupTimestamp($name, $mtime);
                $fileHost = self::hostFromBackupFilename($name);
                $mismatch = $panelHost !== null && $fileHost !== null
                    && strtolower($fileHost) !== strtolower($panelHost);
                $out[] = [
                    'panel_id' => $panelId,
                    'panel_name' => $meta['name'],
                    'panel_base_url' => $meta['base_url'],
                    'filename' => $name,
                    'bytes' => (int) filesize($path),
                    'mtime' => $mtime,
                    'backup_ts' => $backupTs,
                    'host_mismatch' => $mismatch,
                    'filename_host' => $fileHost,
                ];
            }
        }
        usort($out, static fn ($a, $b) => (int) $b['backup_ts'] <=> (int) $a['backup_ts']);
        return $out;
    }

    public static function backupTimestamp(string $filename, int $mtimeFallback): int
    {
        $fromName = BackupCalendar::timestampFromBackupFilename($filename);
        return $fromName ?? $mtimeFallback;
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

    public static function hostFromBackupFilename(string $filename): ?string
    {
        $base = preg_replace('/\.(db|dump)$/i', '', $filename) ?? $filename;
        if (preg_match('/^(.+)_\d{4}-\d{2}-\d{2}_\d{6}$/', $base, $m)) {
            return $m[1];
        }
        return null;
    }
}
