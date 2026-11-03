<?php

declare(strict_types=1);

namespace App\Services;

final class OutboundBackupService
{
    /** @param array<string, mixed> $config */
    public static function directory(array $config): string
    {
        $base = (string) ($config['paths']['storage'] ?? dirname(__DIR__, 2) . '/storage');
        $dir = rtrim($base, '/') . '/outbound-backups';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        return $dir;
    }

    /** @param array<string, mixed> $config */
    public static function backupPanelSettings(int $panelId, string $xraySettingJson, array $config): string
    {
        if (trim($xraySettingJson) === '') {
            throw new \RuntimeException('بک‌آپ outbound: xraySetting خالی است');
        }

        $panelDir = self::directory($config) . '/' . $panelId;
        if (!is_dir($panelDir)) {
            mkdir($panelDir, 0750, true);
        }
        $stamp = date('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $rawName = $stamp . '.xray.raw.json';
        $rawPath = $panelDir . '/' . $rawName;
        if (file_put_contents($rawPath, $xraySettingJson, LOCK_EX) === false) {
            throw new \RuntimeException('ذخیره بک‌آپ خام xraySetting ناموفق بود');
        }
        chmod($rawPath, 0600);

        return $rawName;
    }
}
