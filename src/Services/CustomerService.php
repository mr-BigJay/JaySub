<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Encryption;

final class CustomerService
{
    public static function gbToBytes(float $gb): int
    {
        return (int) round($gb * 1024 * 1024 * 1024);
    }

    /** @return array<string, mixed>|null */
    public static function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM customers WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public static function listAll(): array
    {
        return Database::pdo()->query(
            'SELECT c.*,
                s.quota_bytes, s.used_upload_bytes, s.used_download_bytes, s.status AS sub_status, s.ends_at,
                (SELECT COUNT(*) FROM vpn_panels vp WHERE vp.customer_id = c.id) AS panel_count,
                (SELECT COUNT(*) FROM vpn_clients vc WHERE vc.customer_id = c.id) AS client_count
             FROM customers c
             LEFT JOIN subscriptions s ON s.id = (
                SELECT id FROM subscriptions WHERE customer_id = c.id
                ORDER BY id DESC LIMIT 1
             )
             ORDER BY c.id DESC'
        )->fetchAll();
    }

    public static function create(array $data, float $quotaGb, ?int $adminId = null): int
    {
        $customerId = self::createUser($data, $adminId);
        if ($quotaGb > 0) {
            self::createOrUpdateService($customerId, [
                'quota_gb' => $quotaGb,
                'warning1_percent' => (int) ($data['warning1_percent'] ?? 80),
                'warning2_percent' => (int) ($data['warning2_percent'] ?? 90),
            ], $adminId);
        }
        return $customerId;
    }

    /** @param array<string, mixed> $data */
    public static function createUser(array $data, ?int $adminId = null): int
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO customers (name, username, password_hash, mobile, telegram_chat_id, notes, warning1_percent, warning2_percent, is_active)
             VALUES (:name, :username, :hash, :mobile, :tg, :notes, :w1, :w2, :active)'
        );
        $stmt->execute([
            'name' => $data['name'],
            'username' => $data['username'],
            'hash' => password_hash($data['password'], PASSWORD_DEFAULT),
            'mobile' => $data['mobile'] ?? null,
            'tg' => $data['telegram_chat_id'] ?? null,
            'notes' => $data['notes'] ?? null,
            'w1' => (int) ($data['warning1_percent'] ?? 80),
            'w2' => (int) ($data['warning2_percent'] ?? 90),
            'active' => isset($data['is_active']) ? (int) $data['is_active'] : 1,
        ]);
        $customerId = (int) $pdo->lastInsertId();
        AuditLogService::log('admin', $adminId, 'customer_created', 'customer', $customerId);
        return $customerId;
    }

    /** @param array<string, mixed> $service */
    public static function createOrUpdateService(int $customerId, array $service, ?int $adminId = null): void
    {
        $pdo = Database::pdo();
        $quotaBytes = self::gbToBytes((float) ($service['quota_gb'] ?? 0));
        $endsAt = $service['ends_at'] ?? null;
        $subLink = $service['subscription_link'] ?? null;

        if (isset($service['warning1_percent'], $service['warning2_percent'])) {
            $pdo->prepare('UPDATE customers SET warning1_percent = :w1, warning2_percent = :w2 WHERE id = :id')
                ->execute([
                    'w1' => (int) $service['warning1_percent'],
                    'w2' => (int) $service['warning2_percent'],
                    'id' => $customerId,
                ]);
        }
        if ($subLink !== null) {
            $pdo->prepare('UPDATE customers SET subscription_link = :l WHERE id = :id')
                ->execute(['l' => $subLink !== '' ? $subLink : null, 'id' => $customerId]);
        }

        $existing = self::activeSubscription($customerId);
        if ($existing) {
            $pdo->prepare(
                'UPDATE subscriptions SET quota_bytes = :q, ends_at = :e, status = \'active\' WHERE id = :id'
            )->execute([
                'q' => $quotaBytes > 0 ? $quotaBytes : (int) $existing['quota_bytes'],
                'e' => $endsAt,
                'id' => (int) $existing['id'],
            ]);
        } else {
            $pdo->prepare(
                'INSERT INTO subscriptions (customer_id, quota_bytes, status, started_at, ends_at)
                 VALUES (:cid, :quota, \'active\', NOW(), :e)'
            )->execute([
                'cid' => $customerId,
                'quota' => $quotaBytes,
                'e' => $endsAt,
            ]);
        }
        $pdo->prepare('UPDATE customers SET service_status = \'active\', vpn_enabled = 1 WHERE id = :id')
            ->execute(['id' => $customerId]);
        AuditLogService::log('admin', $adminId, 'service_updated', 'customer', $customerId, $service);
    }

    /** @param list<int> $activePanelIds */
    public static function setPanelActivation(int $customerId, array $activePanelIds): void
    {
        $pdo = Database::pdo();
        $pdo->prepare('UPDATE vpn_panels SET is_active = 0 WHERE customer_id = :c')->execute(['c' => $customerId]);
        if ($activePanelIds !== []) {
            $placeholders = implode(',', array_fill(0, count($activePanelIds), '?'));
            $stmt = $pdo->prepare("UPDATE vpn_panels SET is_active = 1 WHERE customer_id = ? AND id IN ($placeholders)");
            $stmt->execute(array_merge([$customerId], $activePanelIds));
        }
    }

    public static function addQuota(int $customerId, float $additionalGb, Encryption $encryption, TelegramService $telegram, ?int $adminId = null): void
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $sub = $pdo->prepare(
                "SELECT * FROM subscriptions WHERE customer_id = :cid AND status IN ('active', 'exhausted') ORDER BY id DESC LIMIT 1"
            );
            $sub->execute(['cid' => $customerId]);
            $subscription = $sub->fetch();
            if ($subscription === false) {
                throw new \RuntimeException('No subscription found');
            }
            $subId = (int) $subscription['id'];
            $addBytes = self::gbToBytes($additionalGb);
            $newQuota = (int) $subscription['quota_bytes'] + $addBytes;

            $pdo->prepare('UPDATE subscriptions SET quota_bytes = :q, status = \'active\' WHERE id = :id')
                ->execute(['q' => $newQuota, 'id' => $subId]);
            $pdo->prepare('UPDATE customers SET vpn_enabled = 1, service_status = \'active\' WHERE id = :id')
                ->execute(['id' => $customerId]);

            $pdo->prepare('DELETE FROM traffic_alerts WHERE subscription_id = :sid AND alert_type IN (\'warning_1\', \'warning_2\', \'limit_reached\')')
                ->execute(['sid' => $subId]);

            $clients = $pdo->prepare(
                'SELECT vc.xui_email, vc.panel_id, vp.base_url, vp.api_token_encrypted
                 FROM vpn_clients vc INNER JOIN vpn_panels vp ON vp.id = vc.panel_id
                 WHERE vc.customer_id = :cid AND vc.subscription_id = :sid AND vc.disabled_by_quota = 1'
            );
            $clients->execute(['cid' => $customerId, 'sid' => $subId]);
            $rows = $clients->fetchAll();

            /** @var array<int, array{base_url: string, token: string, emails: list<string>}> $byPanel */
            $byPanel = [];
            foreach ($rows as $row) {
                $pid = (int) $row['panel_id'];
                if (!isset($byPanel[$pid])) {
                    $byPanel[$pid] = [
                        'base_url' => $row['base_url'],
                        'token' => $row['api_token_encrypted'],
                        'emails' => [],
                    ];
                }
                $byPanel[$pid]['emails'][] = $row['xui_email'];
            }

            foreach ($byPanel as $panelId => $info) {
                $xui = new \App\Xui\XuiClient($info['base_url'], $encryption->decrypt($info['token']));
                $xui->bulkEnable($info['emails']);
            }

            $pdo->prepare('UPDATE vpn_clients SET disabled_by_quota = 0 WHERE customer_id = :cid AND subscription_id = :sid')
                ->execute(['cid' => $customerId, 'sid' => $subId]);

            $cust = self::findById($customerId);
            if ($cust && !empty($cust['telegram_chat_id'])) {
                $telegram->sendMessage((string) $cust['telegram_chat_id'], TelegramService::rechargeMessage((float) $newQuota));
            }

            $pdo->prepare(
                'INSERT INTO traffic_alerts (customer_id, subscription_id, alert_type, sent_at)
                 VALUES (:c, :s, \'quota_recharged\', NOW())
                 ON DUPLICATE KEY UPDATE sent_at = NOW()'
            )->execute(['c' => $customerId, 's' => $subId]);

            $pdo->commit();
            AuditLogService::log('admin', $adminId, 'quota_added', 'customer', $customerId, [
                'additional_gb' => $additionalGb,
                'new_quota_bytes' => $newQuota,
            ]);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** @return array<string, mixed>|null */
    public static function activeSubscription(int $customerId): ?array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM subscriptions WHERE customer_id = :cid AND status IN ('active', 'exhausted') ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute(['cid' => $customerId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public static function panelUsageBreakdown(int $customerId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT vp.id, vp.name,
                COALESCE(SUM(vc.base_upload_bytes + vc.last_xui_upload), 0) AS upload_bytes,
                COALESCE(SUM(vc.base_download_bytes + vc.last_xui_download), 0) AS download_bytes
             FROM vpn_panels vp
             LEFT JOIN vpn_clients vc ON vc.panel_id = vp.id AND vc.customer_id = :cid
             WHERE vp.customer_id = :cid2 AND vp.is_active = 1
             GROUP BY vp.id, vp.name'
        );
        $stmt->execute(['cid' => $customerId, 'cid2' => $customerId]);
        return $stmt->fetchAll();
    }
}
