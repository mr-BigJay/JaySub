<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $pdo = null;

    /** @param array<string, mixed> $dbConfig */
    public static function init(array $dbConfig): void
    {
        if (self::$pdo !== null) {
            return;
        }
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $dbConfig['host'],
            (int) $dbConfig['port'],
            $dbConfig['name'],
            $dbConfig['charset'] ?? 'utf8mb4'
        );
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        if (isset($dbConfig['connect_timeout'])) {
            $options[PDO::MYSQL_ATTR_CONNECT_TIMEOUT] = max(1, (int) $dbConfig['connect_timeout']);
        }
        self::$pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['password'], $options);
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            throw new PDOException('Database not initialized');
        }
        return self::$pdo;
    }
}
