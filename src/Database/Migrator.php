<?php

declare(strict_types=1);

namespace App\Database;

use App\Core\Database;
use PDO;
use PDOException;

final class Migrator
{
    public const SCHEMA_VERSION = 2;

    /** Auto-run on every app boot — idempotent, fast if already OK. */
    public static function ensure(): void
    {
        $pdo = Database::pdo();
        if (self::isReady($pdo)) {
            self::applyAdminInitFile($pdo);
            return;
        }
        self::run($pdo);
        self::applyAdminInitFile($pdo);
    }

    public static function run(?PDO $pdo = null): void
    {
        $pdo ??= Database::pdo();
        $pdo->exec('SET NAMES utf8mb4');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::statements() as $sql) {
            $pdo->exec($sql);
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        self::setSchemaVersion($pdo);
    }

    public static function isReady(PDO $pdo): bool
    {
        $required = [
            'users', 'customers', 'subscriptions', 'vpn_panels', 'vpn_clients',
            'traffic_snapshots', 'traffic_alerts', 'system_settings', 'audit_logs', 'login_attempts',
        ];
        try {
            $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($required as $t) {
                if (!in_array($t, $tables, true)) {
                    return false;
                }
            }
            return true;
        } catch (PDOException) {
            return false;
        }
    }

    public static function setAdminPassword(string $password): void
    {
        $pdo = Database::pdo();
        self::run($pdo);
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $pdo->prepare(
            'INSERT INTO users (username, password_hash, is_active) VALUES (\'admin\', :h, 1)
             ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), is_active = 1'
        )->execute(['h' => $hash]);
    }

    private static function applyAdminInitFile(PDO $pdo): void
    {
        $path = dirname(__DIR__, 2) . '/storage/.admin-init';
        if (!is_file($path)) {
            return;
        }
        $pass = trim((string) file_get_contents($path));
        if ($pass === '') {
            @unlink($path);
            return;
        }
        self::setAdminPassword($pass);
        @unlink($path);
    }

    private static function setSchemaVersion(PDO $pdo): void
    {
        $pdo->prepare(
            'INSERT INTO system_settings (setting_key, setting_value) VALUES (\'schema_version\', :v)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        )->execute(['v' => (string) self::SCHEMA_VERSION]);
    }

    /** @return list<string> */
    private static function statements(): array
    {
        return [
            <<<'SQL'
CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_active (is_active)
) ENGINE=InnoDB
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS customers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    username VARCHAR(64) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    mobile VARCHAR(20) NULL,
    telegram_chat_id VARCHAR(64) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    vpn_enabled TINYINT(1) NOT NULL DEFAULT 1,
    warning1_percent TINYINT UNSIGNED NOT NULL DEFAULT 80,
    warning2_percent TINYINT UNSIGNED NOT NULL DEFAULT 90,
    service_status ENUM('active', 'warning', 'exhausted', 'disabled', 'expired') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_customers_active (is_active),
    INDEX idx_customers_status (service_status)
) ENGINE=InnoDB
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id BIGINT UNSIGNED NOT NULL,
    quota_bytes BIGINT UNSIGNED NOT NULL,
    used_upload_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    used_download_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('active', 'exhausted', 'completed', 'cancelled') NOT NULL DEFAULT 'active',
    started_at DATETIME NOT NULL,
    ends_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_subscriptions_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    INDEX idx_subscriptions_customer (customer_id),
    INDEX idx_subscriptions_status (status)
) ENGINE=InnoDB
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS vpn_panels (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    base_url VARCHAR(512) NOT NULL,
    api_token_encrypted TEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    connection_status ENUM('connected', 'sync_error', 'offline', 'unknown') NOT NULL DEFAULT 'unknown',
    last_sync_at DATETIME NULL,
    last_error TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_panels_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    INDEX idx_panels_customer (customer_id),
    INDEX idx_panels_active (is_active)
) ENGINE=InnoDB
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS vpn_clients (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id BIGINT UNSIGNED NOT NULL,
    panel_id BIGINT UNSIGNED NOT NULL,
    subscription_id BIGINT UNSIGNED NOT NULL,
    inbound_id INT UNSIGNED NOT NULL,
    xui_email VARCHAR(255) NOT NULL,
    uuid VARCHAR(64) NULL,
    protocol VARCHAR(32) NULL,
    enabled_in_xui TINYINT(1) NOT NULL DEFAULT 1,
    base_upload_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    base_download_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_xui_upload BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_xui_download BIGINT UNSIGNED NOT NULL DEFAULT 0,
    disabled_by_quota TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_vpn_clients_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT fk_vpn_clients_panel FOREIGN KEY (panel_id) REFERENCES vpn_panels(id) ON DELETE CASCADE,
    CONSTRAINT fk_vpn_clients_subscription FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE CASCADE,
    UNIQUE KEY uq_panel_email (panel_id, xui_email),
    INDEX idx_vpn_clients_customer (customer_id),
    INDEX idx_vpn_clients_panel (panel_id)
) ENGINE=InnoDB
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS traffic_snapshots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id BIGINT UNSIGNED NOT NULL,
    subscription_id BIGINT UNSIGNED NOT NULL,
    recorded_at DATETIME NOT NULL,
    upload_bytes BIGINT UNSIGNED NOT NULL,
    download_bytes BIGINT UNSIGNED NOT NULL,
    total_bytes BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_snapshots_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT fk_snapshots_subscription FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE CASCADE,
    INDEX idx_snapshots_customer_time (customer_id, recorded_at),
    INDEX idx_snapshots_sub_time (subscription_id, recorded_at)
) ENGINE=InnoDB
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS traffic_alerts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id BIGINT UNSIGNED NOT NULL,
    subscription_id BIGINT UNSIGNED NOT NULL,
    alert_type ENUM('warning_1', 'warning_2', 'limit_reached', 'quota_recharged', 'service_restored') NOT NULL,
    sent_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_alerts_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT fk_alerts_subscription FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE CASCADE,
    UNIQUE KEY uq_alert_once (subscription_id, alert_type),
    INDEX idx_alerts_customer (customer_id)
) ENGINE=InnoDB
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS system_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(128) NOT NULL UNIQUE,
    setting_value TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_type ENUM('admin', 'customer', 'system') NOT NULL,
    actor_id BIGINT UNSIGNED NULL,
    action VARCHAR(128) NOT NULL,
    entity_type VARCHAR(64) NULL,
    entity_id BIGINT UNSIGNED NULL,
    details JSON NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_created (created_at),
    INDEX idx_audit_actor (actor_type, actor_id)
) ENGINE=InnoDB
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    login_type ENUM('admin', 'customer') NOT NULL,
    username VARCHAR(64) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    INDEX idx_login_attempts_lookup (login_type, username, ip_address, attempted_at)
) ENGINE=InnoDB
SQL,
        ];
    }
}
