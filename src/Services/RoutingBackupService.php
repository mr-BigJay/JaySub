<?php

declare(strict_types=1);

namespace App\Services;

/** File backup of raw xraySetting JSON before routing edits. */
final class RoutingBackupService
{
    /** @param array<string, mixed> $config */
    public static function directory(array $config): string
    {
        $base = (string) ($config['paths']['storage'] ?? dirname(__DIR__, 2) . '/storage');
        $dir = rtrim($base, '/') . '/routing-backups';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        return $dir;
    }

    /** @param array<string, mixed> $config */
    public static function backupPanelSettings(int $panelId, string $xraySettingJson, array $config): string
    {
        $panelDir = self::directory($config) . '/' . $panelId;
        if (!is_dir($panelDir)) {
            mkdir($panelDir, 0750, true);
        }
        $name = date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.json';
        $path = $panelDir . '/' . $name;
        $payload = json_encode(
            [
                'panel_id' => $panelId,
                'saved_at' => date('c'),
                'xraySetting' => $xraySettingJson,
            ],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
        );
        if ($payload === false) {
            throw new \RuntimeException('ذخیره بک‌آپ routing ناموفق بود');
        }
        file_put_contents($path, $payload, LOCK_EX);
        chmod($path, 0600);

        return $name;
    }
}
