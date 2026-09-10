-- JaySub VPN Customer Panel — MySQL 8 schema
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS vpn_panel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE vpn_panel;

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_active (is_active)
) ENGINE=InnoDB;

CREATE TABLE customers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    username VARCHAR(64) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    mobile VARCHAR(20) NULL,
    telegram_chat_id VARCHAR(64) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    vpn_enabled TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 when quota exhausted, web panel stays active',
    warning1_percent TINYINT UNSIGNED NOT NULL DEFAULT 80,
    warning2_percent TINYINT UNSIGNED NOT NULL DEFAULT 90,
    service_status ENUM('active', 'warning', 'exhausted', 'disabled', 'expired') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_customers_active (is_active),
    INDEX idx_customers_status (service_status)
) ENGINE=InnoDB;

CREATE TABLE subscriptions (
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
) ENGINE=InnoDB;

CREATE TABLE vpn_panels (
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
) ENGINE=InnoDB;

CREATE TABLE vpn_clients (
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
) ENGINE=InnoDB;

CREATE TABLE traffic_snapshots (
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
) ENGINE=InnoDB;

CREATE TABLE traffic_alerts (
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
) ENGINE=InnoDB;

CREATE TABLE system_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(128) NOT NULL UNIQUE,
    setting_value TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
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
) ENGINE=InnoDB;

CREATE TABLE login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    login_type ENUM('admin', 'customer') NOT NULL,
    username VARCHAR(64) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    INDEX idx_login_attempts_lookup (login_type, username, ip_address, attempted_at)
) ENGINE=InnoDB;

-- Admin user is created by scripts/install.php (password you set during install)
INSERT IGNORE INTO users (username, password_hash, is_active) VALUES
('admin', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1);

SET FOREIGN_KEY_CHECKS = 1;
