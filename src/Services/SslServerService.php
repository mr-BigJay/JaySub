<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Encryption;

final class SslServerService
{
    public static function create(
        int $vpnPanelId,
        string $host,
        int $sshPort,
        string $sshUsername,
        string $authType,
        string $secretPlain,
        string $certPath,
        Encryption $encryption,
    ): int {
        $name = self::resolvePanelName($vpnPanelId);
        self::assertPanelNotLinked($vpnPanelId, null);
        $authType = $authType === 'key' ? 'key' : 'password';
        $stmt = Database::pdo()->prepare(
            'INSERT INTO ssl_servers (vpn_panel_id, name, host, ssh_port, ssh_username, auth_type, ssh_secret_encrypted, cert_path)
             VALUES (:pid, :n, :h, :p, :u, :a, :s, :c)'
        );
        $stmt->execute([
            'pid' => $vpnPanelId,
            'n' => $name,
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
        return Database::pdo()->query(
            'SELECT s.*, vp.name AS panel_name, vp.base_url AS panel_base_url
             FROM ssl_servers s
             LEFT JOIN vpn_panels vp ON vp.id = s.vpn_panel_id
             ORDER BY s.id DESC'
        )->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public static function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT s.*, vp.name AS panel_name, vp.base_url AS panel_base_url
             FROM ssl_servers s
             LEFT JOIN vpn_panels vp ON vp.id = s.vpn_panel_id
             WHERE s.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public static function findByPanelId(int $panelId): ?array
    {
        if ($panelId <= 0) {
            return null;
        }
        $stmt = Database::pdo()->prepare('SELECT * FROM ssl_servers WHERE vpn_panel_id = :p LIMIT 1');
        $stmt->execute(['p' => $panelId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public static function update(
        int $id,
        int $vpnPanelId,
        string $host,
        int $sshPort,
        string $sshUsername,
        string $authType,
        ?string $secretPlain,
        string $certPath,
        Encryption $encryption,
    ): void {
        $name = self::resolvePanelName($vpnPanelId);
        self::assertPanelNotLinked($vpnPanelId, $id);
        $authType = $authType === 'key' ? 'key' : 'password';
        if ($secretPlain !== null && $secretPlain !== '') {
            Database::pdo()->prepare(
                'UPDATE ssl_servers SET vpn_panel_id = :pid, name = :n, host = :h, ssh_port = :p, ssh_username = :u,
                    auth_type = :a, ssh_secret_encrypted = :s, cert_path = :c, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            )->execute([
                'pid' => $vpnPanelId,
                'n' => $name,
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
            'UPDATE ssl_servers SET vpn_panel_id = :pid, name = :n, host = :h, ssh_port = :p, ssh_username = :u,
                auth_type = :a, cert_path = :c, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        )->execute([
            'pid' => $vpnPanelId,
            'n' => $name,
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

    private static function resolvePanelName(int $vpnPanelId): string
    {
        if ($vpnPanelId <= 0) {
            throw new \InvalidArgumentException('پنل X-UI را انتخاب کنید.');
        }
        $panel = PanelService::findById($vpnPanelId);
        if ($panel === null) {
            throw new \InvalidArgumentException('پنل انتخاب‌شده یافت نشد.');
        }
        $name = trim((string) ($panel['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('نام پنل خالی است.');
        }
        return $name;
    }

    private static function assertPanelNotLinked(int $vpnPanelId, ?int $exceptServerId): void
    {
        $existing = self::findByPanelId($vpnPanelId);
        if ($existing === null) {
            return;
        }
        $existingId = (int) ($existing['id'] ?? 0);
        if ($exceptServerId !== null && $existingId === $exceptServerId) {
            return;
        }
        throw new \InvalidArgumentException('برای این پنل قبلاً سرور SSL ثبت شده است.');
    }
}
