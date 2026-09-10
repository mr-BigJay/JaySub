<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\AuthService;
use App\Core\Database;
use App\Core\Encryption;
use App\Xui\XuiClient;

final class PanelService
{
    public static function create(int $customerId, string $name, string $baseUrl, string $apiToken, Encryption $encryption, ?int $adminId = null): int
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        $stmt = Database::pdo()->prepare(
            'INSERT INTO vpn_panels (customer_id, name, base_url, api_token_encrypted) VALUES (:cid, :name, :url, :tok)'
        );
        $stmt->execute([
            'cid' => $customerId,
            'name' => $name,
            'url' => $baseUrl,
            'tok' => $encryption->encrypt($apiToken),
        ]);
        $id = (int) Database::pdo()->lastInsertId();
        AuditLogService::log('admin', $adminId, 'panel_created', 'vpn_panel', $id);
        return $id;
    }

    /** @return list<array<string, mixed>> */
    public static function forCustomer(int $customerId): array
    {
        $stmt = Database::pdo()->prepare('SELECT id, customer_id, name, base_url, is_active, connection_status, last_sync_at, last_error, created_at FROM vpn_panels WHERE customer_id = :cid ORDER BY id');
        $stmt->execute(['cid' => $customerId]);
        return $stmt->fetchAll();
    }

    /**
     * @return list<array{inbound_id:int, protocol:?string, email:string, up:int, down:int, enable:bool, uuid:?string, mapped:bool}>
     */
    public static function discoverClients(int $panelId, Encryption $encryption): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM vpn_panels WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $panelId]);
        $panel = $stmt->fetch();
        if ($panel === false) {
            throw new \RuntimeException('Panel not found');
        }

        $xui = new XuiClient($panel['base_url'], $encryption->decrypt($panel['api_token_encrypted']));
        $list = $xui->listInbounds();
        if (!$list['ok']) {
            throw new \RuntimeException($list['error'] ?? 'Failed to list inbounds');
        }

        $mappedStmt = Database::pdo()->prepare('SELECT xui_email FROM vpn_clients WHERE panel_id = :pid');
        $mappedStmt->execute(['pid' => $panelId]);
        $mapped = array_column($mappedStmt->fetchAll(), 'xui_email');
        $mappedSet = array_flip($mapped);

        $result = [];
        $obj = $list['data']['obj'] ?? [];
        if (!is_array($obj)) {
            return [];
        }
        foreach ($obj as $inbound) {
            if (!is_array($inbound)) {
                continue;
            }
            $inboundId = (int) ($inbound['id'] ?? 0);
            $protocol = is_string($inbound['protocol'] ?? null) ? $inbound['protocol'] : null;
            foreach ($inbound['clientStats'] ?? [] as $stat) {
                if (!is_array($stat) || !isset($stat['email'])) {
                    continue;
                }
                $email = (string) $stat['email'];
                $result[] = [
                    'inbound_id' => $inboundId,
                    'protocol' => $protocol,
                    'email' => $email,
                    'up' => (int) ($stat['up'] ?? 0),
                    'down' => (int) ($stat['down'] ?? 0),
                    'enable' => (bool) ($stat['enable'] ?? true),
                    'uuid' => isset($stat['uuid']) ? (string) $stat['uuid'] : null,
                    'mapped' => isset($mappedSet[$email]),
                ];
            }
        }
        return $result;
    }

    public static function assignClient(int $panelId, int $customerId, string $email, int $inboundId, ?string $uuid, ?string $protocol): void
    {
        $sub = CustomerService::activeSubscription($customerId);
        if ($sub === null) {
            throw new \RuntimeException('Customer has no active subscription');
        }
        $stmt = Database::pdo()->prepare(
            'INSERT INTO vpn_clients (customer_id, panel_id, subscription_id, inbound_id, xui_email, uuid, protocol)
             VALUES (:cid, :pid, :sid, :inbound, :email, :uuid, :protocol)
             ON DUPLICATE KEY UPDATE customer_id = VALUES(customer_id), subscription_id = VALUES(subscription_id),
                inbound_id = VALUES(inbound_id), uuid = VALUES(uuid), protocol = VALUES(protocol)'
        );
        $stmt->execute([
            'cid' => $customerId,
            'pid' => $panelId,
            'sid' => (int) $sub['id'],
            'inbound' => $inboundId,
            'email' => $email,
            'uuid' => $uuid,
            'protocol' => $protocol,
        ]);
        AuditLogService::log('admin', AuthService::adminId(), 'client_assigned', 'vpn_client', (int) Database::pdo()->lastInsertId(), [
            'email' => $email,
            'panel_id' => $panelId,
        ]);
    }

    /** @return array{ok:bool,message:string} */
    public static function testConnection(int $panelId, Encryption $encryption): array
    {
        $stmt = Database::pdo()->prepare('SELECT base_url, api_token_encrypted FROM vpn_panels WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $panelId]);
        $panel = $stmt->fetch();
        if ($panel === false) {
            return ['ok' => false, 'message' => 'پنل یافت نشد'];
        }
        $xui = new XuiClient((string) $panel['base_url'], $encryption->decrypt((string) $panel['api_token_encrypted']));
        $result = $xui->getServerStatus();
        if ($result['ok'] ?? false) {
            Database::pdo()->prepare(
                "UPDATE vpn_panels SET connection_status = 'connected', last_error = NULL WHERE id = :id"
            )->execute(['id' => $panelId]);
            return ['ok' => true, 'message' => 'متصل'];
        }
        $err = (string) ($result['error'] ?? 'خطای اتصال');
        Database::pdo()->prepare(
            "UPDATE vpn_panels SET connection_status = 'sync_error', last_error = :e WHERE id = :id"
        )->execute(['e' => $err, 'id' => $panelId]);
        return ['ok' => false, 'message' => $err];
    }
}
