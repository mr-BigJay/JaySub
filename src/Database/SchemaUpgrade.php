<?php

declare(strict_types=1);

namespace App\Database;

use PDO;

final class SchemaUpgrade
{
    public const VERSION = 9;

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
                self::backfillUsageViewTokens($pdo);
                self::writeVersion($pdo, 5);
                $current = 5;
            }
            if ($current < 6) {
                $pdo->exec(
                    <<<'SQL'
CREATE TABLE IF NOT EXISTS ssl_servers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    host VARCHAR(255) NOT NULL,
    ssh_port INT UNSIGNED NOT NULL DEFAULT 22,
    ssh_username VARCHAR(64) NOT NULL DEFAULT 'root',
    auth_type ENUM('password', 'key') NOT NULL DEFAULT 'password',
    ssh_secret_encrypted TEXT NOT NULL,
    cert_path VARCHAR(512) NOT NULL DEFAULT '/root/cert',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_backup_at DATETIME NULL,
    last_error TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ssl_servers_active (is_active)
) ENGINE=InnoDB
SQL
                );
                self::writeVersion($pdo, 6);
                $current = 6;
            }
            if ($current < 7) {
                self::addColumnIfMissing($pdo, 'vpn_panels', 'xui_inbound_up', 'BIGINT UNSIGNED NOT NULL DEFAULT 0');
                self::addColumnIfMissing($pdo, 'vpn_panels', 'xui_inbound_down', 'BIGINT UNSIGNED NOT NULL DEFAULT 0');
                self::writeVersion($pdo, 7);
                $current = 7;
            }
            if ($current < 8) {
                self::resetTrafficForPanelInboundTotals($pdo);
                self::writeVersion($pdo, 8);
                $current = 8;
            }
            if ($current < 9) {
                self::enableMonitorOnlyTrafficMode($pdo);
                self::writeVersion($pdo, 9);
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

    private static function backfillUsageViewTokens(PDO $pdo): void
    {
        try {
            $ids = $pdo->query(
                "SELECT id FROM customers WHERE usage_view_token IS NULL OR usage_view_token = ''"
            )->fetchAll(\PDO::FETCH_COLUMN);
            $upd = $pdo->prepare('UPDATE customers SET usage_view_token = :t WHERE id = :id');
            foreach ($ids as $cid) {
                $upd->execute(['t' => bin2hex(random_bytes(32)), 'id' => (int) $cid]);
            }
        } catch (\Throwable $e) {
            error_log('JaySub usage_view_token backfill: ' . $e->getMessage());
        }
    }

    /** بدون قطع سرویس: فقط محاسبه مصرف — پاک‌کردن وضعیت exhausted قدیمی. */
    private static function enableMonitorOnlyTrafficMode(PDO $pdo): void
    {
        $pdo->exec("UPDATE subscriptions SET status = 'active' WHERE status = 'exhausted'");
        $pdo->exec(
            "UPDATE customers SET vpn_enabled = 1, service_status = 'active'
             WHERE service_status IN ('exhausted', 'warning')"
        );
        $pdo->exec("DELETE FROM traffic_alerts WHERE alert_type IN ('limit_reached', 'warning_1', 'warning_2')");
        $pdo->exec('UPDATE vpn_clients SET disabled_by_quota = 0');
        $pdo->prepare(
            'INSERT INTO system_settings (setting_key, setting_value) VALUES (\'quota_enforcement_enabled\', \'0\')
             ON DUPLICATE KEY UPDATE setting_value = \'0\''
        )->execute();
        $pdo->prepare(
            'INSERT INTO system_settings (setting_key, setting_value) VALUES (\'traffic_mode\', \'monitor_only\')
             ON DUPLICATE KEY UPDATE setting_value = \'monitor_only\''
        )->execute();
    }

    /** Wipe JaySub per-client traffic accounting; usage comes from 3x-ui inbound up/down sums after sync. */
    private static function resetTrafficForPanelInboundTotals(PDO $pdo): void
    {
        $pdo->exec('DELETE FROM traffic_snapshots');
        $pdo->exec('DELETE FROM traffic_alerts');
        $pdo->exec(
            'UPDATE subscriptions SET used_upload_bytes = 0, used_download_bytes = 0, status = \'active\'
             WHERE status IN (\'active\', \'exhausted\')'
        );
        $pdo->exec(
            "UPDATE customers SET service_status = 'active', vpn_enabled = 1
             WHERE service_status IN ('exhausted', 'warning')"
        );
        $pdo->exec(
            'UPDATE vpn_clients SET
                base_upload_bytes = 0,
                base_download_bytes = 0,
                last_xui_upload = 0,
                last_xui_download = 0,
                xui_baseline_upload = 0,
                xui_baseline_download = 0,
                disabled_by_quota = 0'
        );
        $pdo->exec('UPDATE vpn_panels SET xui_inbound_up = 0, xui_inbound_down = 0');
        $pdo->prepare(
            'INSERT INTO system_settings (setting_key, setting_value) VALUES (\'traffic_source\', :v)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        )->execute(['v' => 'xui_inbound_totals']);
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
