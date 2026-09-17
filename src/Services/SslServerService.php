<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Encryption;

final class SslServerService
{
    public static function create(
        string $name,
        string $host,
        int $sshPort,
        string $sshUsername,
        string $authType,
        string $secretPlain,
        string $certPath,
        Encryption $encryption,
    ): int {
        $authType = $authType === 'key' ? 'key' : 'password';
        $stmt = Database::pdo()->prepare(
            'INSERT INTO ssl_servers (name, host, ssh_port, ssh_username, auth_type, ssh_secret_encrypted, cert_path)
             VALUES (:n, :h, :p, :u, :a, :s, :c)'
        );
        $stmt->execute([
            'n' => trim($name),
            'h' => trim($host),
            'p' => max(1, min(65535, $sshPort)),
            'u' => trim($sshUsername) !== '' ? trim($sshUsername) : 'root',
            'a' => $authType,
            's' => $encryption->encrypt($secretPlain),
            'c' => trim($certPath) !== '' ? trim($certPath) : '/root/cert',
        ]);
        return (int) Database::pdo()->lastInsertId();
    }

    /** @return list<array<string, mixed>> */
    public static function listAll(): array
    {
        return Database::pdo()->query('SELECT * FROM ssl_servers ORDER BY id DESC')->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public static function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM ssl_servers WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public static function update(
        int $id,
        string $name,
        string $host,
        int $sshPort,
        string $sshUsername,
        string $authType,
        ?string $secretPlain,
        string $certPath,
        Encryption $encryption,
    ): void {
        $authType = $authType === 'key' ? 'key' : 'password';
        if ($secretPlain !== null && $secretPlain !== '') {
            Database::pdo()->prepare(
                'UPDATE ssl_servers SET name = :n, host = :h, ssh_port = :p, ssh_username = :u,
                    auth_type = :a, ssh_secret_encrypted = :s, cert_path = :c, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            )->execute([
                'n' => trim($name),
                'h' => trim($host),
                'p' => max(1, min(65535, $sshPort)),
                'u' => trim($sshUsername) !== '' ? trim($sshUsername) : 'root',
                'a' => $authType,
                's' => $encryption->encrypt($secretPlain),
                'c' => trim($certPath) !== '' ? trim($certPath) : '/root/cert',
                'id' => $id,
            ]);
            return;
        }
        Database::pdo()->prepare(
            'UPDATE ssl_servers SET name = :n, host = :h, ssh_port = :p, ssh_username = :u,
                auth_type = :a, cert_path = :c, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        )->execute([
            'n' => trim($name),
            'h' => trim($host),
            'p' => max(1, min(65535, $sshPort)),
            'u' => trim($sshUsername) !== '' ? trim($sshUsername) : 'root',
            'a' => $authType,
            'c' => trim($certPath) !== '' ? trim($certPath) : '/root/cert',
            'id' => $id,
        ]);
    }

    public static function setActive(int $id, bool $active): void
    {
        Database::pdo()->prepare('UPDATE ssl_servers SET is_active = :a WHERE id = :id')
            ->execute(['a' => $active ? 1 : 0, 'id' => $id]);
    }

    public static function markBackupResult(int $id, bool $ok, ?string $error = null): void
    {
        if ($ok) {
            Database::pdo()->prepare(
                "UPDATE ssl_servers SET last_backup_at = NOW(), last_error = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id"
            )->execute(['id' => $id]);
            return;
        }
        Database::pdo()->prepare(
            'UPDATE ssl_servers SET last_error = :e, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        )->execute(['e' => mb_substr((string) $error, 0, 2000), 'id' => $id]);
    }
}
