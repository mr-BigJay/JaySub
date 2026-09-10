<?php

declare(strict_types=1);

namespace App\Database;

use PDO;

final class SchemaUpgrade
{
    public const VERSION = 3;

    public static function apply(PDO $pdo): void
    {
        $current = (int) $pdo->query(
            "SELECT setting_value FROM system_settings WHERE setting_key = 'schema_version' LIMIT 1"
        )->fetchColumn();
        if ($current >= self::VERSION) {
            return;
        }
        self::addColumnIfMissing($pdo, 'customers', 'notes', 'TEXT NULL');
        self::addColumnIfMissing($pdo, 'customers', 'subscription_link', 'VARCHAR(512) NULL');
        $pdo->prepare(
            'INSERT INTO system_settings (setting_key, setting_value) VALUES (\'schema_version\', :v)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        )->execute(['v' => (string) self::VERSION]);
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
