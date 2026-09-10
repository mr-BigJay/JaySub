<?php

declare(strict_types=1);

namespace App\Database;

use PDO;

final class SchemaUpgrade
{
    public const VERSION = 5;

    public static function apply(PDO $pdo): void
    {
        try {
            $current = self::readVersion($pdo);
            if ($current < 3) {
                self::addColumnIfMissing($pdo, 'customers', 'notes', 'TEXT NULL');
                self::addColumnIfMissing($pdo, 'customers', 'subscription_link', 'VARCHAR(512) NULL');
                self::writeVersion($pdo, 3);
                $current = 3;
            }
            if ($current < 4) {
                self::addColumnIfMissing($pdo, 'vpn_clients', 'xui_baseline_upload', 'BIGINT UNSIGNED NOT NULL DEFAULT 0');
                self::addColumnIfMissing($pdo, 'vpn_clients', 'xui_baseline_download', 'BIGINT UNSIGNED NOT NULL DEFAULT 0');
                // Start measuring from upgrade moment — do not import lifetime XUI totals.
                $pdo->exec(
                    'UPDATE vpn_clients SET
                        xui_baseline_upload = last_xui_upload,
                        xui_baseline_download = last_xui_download
                     WHERE xui_baseline_upload = 0 AND xui_baseline_download = 0
                       AND (last_xui_upload > 0 OR last_xui_download > 0)'
                );
                $pdo->exec(
                    'UPDATE subscriptions SET used_upload_bytes = 0, used_download_bytes = 0, status = \'active\'
                     WHERE status IN (\'active\', \'exhausted\')'
                );
                $pdo->exec(
                    "UPDATE customers SET service_status = 'active', vpn_enabled = 1 WHERE service_status = 'exhausted'"
                );
                self::writeVersion($pdo, 4);
                $current = 4;
            }
            if ($current < 5) {
                self::addColumnIfMissing($pdo, 'customers', 'usage_view_token', 'VARCHAR(64) NULL');
                try {
                    $pdo->exec('CREATE UNIQUE INDEX uq_customers_usage_view_token ON customers (usage_view_token)');
                } catch (\Throwable) {
                    // index may already exist
                }
                $ids = $pdo->query(
                    "SELECT id FROM customers WHERE usage_view_token IS NULL OR usage_view_token = ''"
                )->fetchAll(\PDO::FETCH_COLUMN);
                $upd = $pdo->prepare('UPDATE customers SET usage_view_token = :t WHERE id = :id');
                foreach ($ids as $cid) {
                    $upd->execute(['t' => bin2hex(random_bytes(32)), 'id' => (int) $cid]);
                }
                self::writeVersion($pdo, 5);
            }
        } catch (\Throwable $e) {
            error_log('JaySub SchemaUpgrade: ' . $e->getMessage());
        }
    }

    private static function readVersion(PDO $pdo): int
    {
        try {
            $v = $pdo->query(
                "SELECT setting_value FROM system_settings WHERE setting_key = 'schema_version' LIMIT 1"
            )->fetchColumn();
            return is_numeric($v) ? (int) $v : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    private static function writeVersion(PDO $pdo, int $version): void
    {
        $pdo->prepare(
            'INSERT INTO system_settings (setting_key, setting_value) VALUES (\'schema_version\', :v)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        )->execute(['v' => (string) $version]);
    }

    private static function addColumnIfMissing(PDO $pdo, string $table, string $column, string $definition): void
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
        );
        $stmt->execute(['t' => $table, 'c' => $column]);
        if ((int) $stmt->fetchColumn() > 0) {
            return;
        }
        $pdo->exec(sprintf('ALTER TABLE `%s` ADD COLUMN `%s` %s', $table, $column, $definition));
    }
}
