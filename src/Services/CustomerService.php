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
        $password = (string) ($data['password'] ?? '');
        if ($password === '') {
            $password = bin2hex(random_bytes(16));
        }
        $params = [
            'name' => $data['name'],
            'username' => $data['username'],
            'hash' => password_hash($password, PASSWORD_DEFAULT),
            'mobile' => $data['mobile'] ?? null,
            'tg' => $data['telegram_chat_id'] ?? null,
            'notes' => $data['notes'] ?? null,
            'w1' => (int) ($data['warning1_percent'] ?? 80),
            'w2' => (int) ($data['warning2_percent'] ?? 90),
            'active' => isset($data['is_active']) ? (int) $data['is_active'] : 1,
        ];
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO customers (name, username, password_hash, mobile, telegram_chat_id, notes, warning1_percent, warning2_percent, is_active)
                 VALUES (:name, :username, :hash, :mobile, :tg, :notes, :w1, :w2, :active)'
            );
            $stmt->execute($params);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'notes') || str_contains($e->getMessage(), 'Unknown column')) {
                $stmt = $pdo->prepare(
                    'INSERT INTO customers (name, username, password_hash, mobile, telegram_chat_id, warning1_percent, warning2_percent, is_active)
                     VALUES (:name, :username, :hash, :mobile, :tg, :w1, :w2, :active)'
                );
                $stmt->execute($params);
            } else {
                throw $e;
            }
        }
        $customerId = (int) $pdo->lastInsertId();
        self::ensureUsageViewToken($customerId);
        AuditLogService::log('admin', $adminId, 'customer_created', 'customer', $customerId);
        return $customerId;
    }

    public static function ensureUsageViewToken(int $customerId): string
    {
        if (!self::hasUsageViewTokenColumn()) {
            \App\Database\SchemaUpgrade::apply(Database::pdo());
        }
        $customer = self::findById($customerId);
        if ($customer === null) {
            throw new \RuntimeException('Customer not found');
        }
        $existing = trim((string) ($customer['usage_view_token'] ?? ''));
        if ($existing !== '') {
            return $existing;
        }
        $token = bin2hex(random_bytes(32));
        Database::pdo()->prepare('UPDATE customers SET usage_view_token = :t WHERE id = :id')
            ->execute(['t' => $token, 'id' => $customerId]);
        return $token;
    }

    private static function hasUsageViewTokenColumn(): bool
    {
        try {
            $stmt = Database::pdo()->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'customers\' AND COLUMN_NAME = \'usage_view_token\''
            );
            $stmt->execute();
            return (int) $stmt->fetchColumn() > 0;
        } catch (\PDOException) {
            return false;
        }
    }

    /** @return array<string, mixed>|null */
    public static function findByUsageViewToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || strlen($token) > 64) {
            return null;
        }
        try {
            $stmt = Database::pdo()->prepare(
                'SELECT * FROM customers WHERE usage_view_token = :t AND is_active = 1 LIMIT 1'
            );
            $stmt->execute(['t' => $token]);
            $row = $stmt->fetch();
            return $row === false ? null : $row;
        } catch (\PDOException) {
            return null;
        }
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
            try {
                $pdo->prepare('UPDATE customers SET subscription_link = :l WHERE id = :id')
                    ->execute(['l' => $subLink !== '' ? $subLink : null, 'id' => $customerId]);
            } catch (\PDOException $e) {
                if (!str_contains($e->getMessage(), 'subscription_link')) {
                    throw $e;
                }
            }
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
        if ($activePanelIds === []) {
            // فرم بدون تیک پنل نباید همه را غیرفعال کند — مصرف صفر می‌شود.
            $pdo->prepare('UPDATE vpn_panels SET is_active = 1 WHERE customer_id = :c')->execute(['c' => $customerId]);
            return;
        }
        $pdo->prepare('UPDATE vpn_panels SET is_active = 0 WHERE customer_id = :c')->execute(['c' => $customerId]);
        $placeholders = implode(',', array_fill(0, count($activePanelIds), '?'));
        $stmt = $pdo->prepare("UPDATE vpn_panels SET is_active = 1 WHERE customer_id = ? AND id IN ($placeholders)");
        $stmt->execute(array_merge([$customerId], $activePanelIds));
    }

    /** بعد از اتصال/تعویض API پنل، باید در محاسبه مصرف لحاظ شود. */
    public static function ensurePanelActiveForCustomer(int $customerId, int $panelId): void
    {
        Database::pdo()->prepare(
            'UPDATE vpn_panels SET is_active = 1 WHERE id = :pid AND customer_id = :cid'
        )->execute(['pid' => $panelId, 'cid' => $customerId]);
    }

    /** کلاینت‌های import شده را به اشتراک فعال فعلی وصل می‌کند (مثلاً بعد از حذف/ساخت دوباره API). */
    public static function rebindClientsToActiveSubscription(int $customerId, ?int $panelId = null): void
    {
        $sub = self::activeSubscription($customerId);
        if ($sub === null) {
            return;
        }
        $sid = (int) $sub['id'];
        if ($panelId !== null) {
            Database::pdo()->prepare(
                'UPDATE vpn_clients SET subscription_id = :sid WHERE customer_id = :cid AND panel_id = :pid'
            )->execute(['sid' => $sid, 'cid' => $customerId, 'pid' => $panelId]);
            return;
        }
        Database::pdo()->prepare(
            'UPDATE vpn_clients SET subscription_id = :sid WHERE customer_id = :cid'
        )->execute(['sid' => $sid, 'cid' => $customerId]);
    }

    public static function addQuota(int $customerId, float $additionalGb, Encryption $encryption, TelegramService $telegram, ?int $adminId = null): void
    {
        self::adjustQuota($customerId, $additionalGb, $encryption, $telegram, $adminId);
    }

    /** Positive = increase ceiling, negative = decrease. Does not change measured usage bytes. */
    public static function adjustQuota(int $customerId, float $deltaGb, Encryption $encryption, TelegramService $telegram, ?int $adminId = null): void
    {
        if ($deltaGb == 0.0) {
            throw new \RuntimeException('مقدار تغییر حجم نمی‌تواند صفر باشد');
        }
        $sub = self::activeSubscription($customerId);
        if ($sub === null) {
            throw new \RuntimeException('اشتراک فعالی برای این کاربر نیست — ابتدا راه‌اندازی سرویس');
        }
        $currentGb = self::bytesToGb((int) $sub['quota_bytes']);
        self::setQuotaCeiling($customerId, $currentGb + $deltaGb, $encryption, $telegram, $adminId);
    }

    /** Sets absolute quota ceiling (GB). Does not reset measured usage from XUI. */
    public static function setQuotaCeiling(int $customerId, float $quotaGb, Encryption $encryption, TelegramService $telegram, ?int $adminId = null): void
    {
        if ($quotaGb < 0) {
            throw new \RuntimeException('سقف حجم نمی‌تواند منفی باشد');
        }
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $sub = $pdo->prepare(
                "SELECT * FROM subscriptions WHERE customer_id = :cid AND status IN ('active', 'exhausted') ORDER BY id DESC LIMIT 1"
            );
            $sub->execute(['cid' => $customerId]);
            $subscription = $sub->fetch();
            if ($subscription === false) {
                throw new \RuntimeException('اشتراک فعالی برای این کاربر نیست — ابتدا راه‌اندازی سرویس');
            }
            $subId = (int) $subscription['id'];
            $oldQuota = (int) $subscription['quota_bytes'];
            $newQuota = self::gbToBytes($quotaGb);
            $increased = $newQuota > $oldQuota;

            $pdo->prepare(
                'UPDATE subscriptions SET quota_bytes = :q, status = \'active\' WHERE id = :id'
            )->execute([
                'q' => $newQuota,
                'id' => $subId,
            ]);

            if ($increased) {
                $pdo->prepare('UPDATE customers SET vpn_enabled = 1, service_status = \'active\' WHERE id = :id')
                    ->execute(['id' => $customerId]);
                $pdo->prepare('DELETE FROM traffic_alerts WHERE subscription_id = :sid AND alert_type IN (\'warning_1\', \'warning_2\', \'limit_reached\')')
                    ->execute(['sid' => $subId]);

                $pdo->prepare('UPDATE vpn_clients SET disabled_by_quota = 0 WHERE customer_id = :cid AND subscription_id = :sid')
                    ->execute(['cid' => $customerId, 'sid' => $subId]);

                $cust = self::findById($customerId);
                if ($cust && !empty($cust['telegram_chat_id'])) {
                    $telegram->sendMessage((string) $cust['telegram_chat_id'], TelegramService::rechargeMessage(self::bytesToGb($newQuota)));
                }

                $pdo->prepare(
                    'INSERT INTO traffic_alerts (customer_id, subscription_id, alert_type, sent_at)
                     VALUES (:c, :s, \'quota_recharged\', NOW())
                     ON DUPLICATE KEY UPDATE sent_at = NOW()'
                )->execute(['c' => $customerId, 's' => $subId]);
            }

            $pdo->commit();
            AuditLogService::log('admin', $adminId, 'quota_ceiling_set', 'customer', $customerId, [
                'quota_gb' => $quotaGb,
                'old_quota_bytes' => $oldQuota,
                'new_quota_bytes' => $newQuota,
            ]);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function bytesToGb(int $bytes): float
    {
        return $bytes / (1024 * 1024 * 1024);
    }

    /**
     * مصرف سهمیه: برای هر پنل فعال جمع کلاینت‌های sync‌شده؛ اگر ناقص/صفر بود از inbound همان پنل.
     *
     * @return array{upload:int, download:int, total:int, source:string}
     */
    public static function trafficUsageForCustomer(int $customerId): array
    {
        return self::quotaUsageForCustomer($customerId);
    }

    /**
     * @return array{upload:int, download:int, total:int, source:string, panel_count:int}
     */
    public static function perPanelQuotaUsage(int $customerId): array
    {
        $pdo = Database::pdo();
        $panels = $pdo->prepare(
            'SELECT id, xui_inbound_up, xui_inbound_down, last_sync_at
             FROM vpn_panels WHERE customer_id = :cid AND is_active = 1 ORDER BY id'
        );
        $panels->execute(['cid' => $customerId]);
        $rows = $panels->fetchAll();
        if ($rows === []) {
            return [
                'upload' => 0,
                'download' => 0,
                'total' => 0,
                'source' => 'none',
                'panel_count' => 0,
            ];
        }

        $clientStmt = $pdo->prepare(
            'SELECT COALESCE(SUM(last_xui_upload), 0) AS up,
                    COALESCE(SUM(last_xui_download), 0) AS down
             FROM vpn_clients WHERE panel_id = :pid AND customer_id = :cid'
        );

        $up = 0;
        $down = 0;
        $usedClients = false;
        $usedInbound = false;
        foreach ($rows as $panel) {
            $pid = (int) $panel['id'];
            $clientStmt->execute(['pid' => $pid, 'cid' => $customerId]);
            $c = $clientStmt->fetch();
            $cUp = (int) ($c['up'] ?? 0);
            $cDown = (int) ($c['down'] ?? 0);
            $cTotal = $cUp + $cDown;
            $pUp = (int) $panel['xui_inbound_up'];
            $pDown = (int) $panel['xui_inbound_down'];
            $pTotal = $pUp + $pDown;
            $synced = !empty($panel['last_sync_at']);

            if ($cTotal > 0 && $cTotal >= $pTotal) {
                $up += $cUp;
                $down += $cDown;
                $usedClients = true;
            } elseif ($synced && $pTotal > 0) {
                $up += $pUp;
                $down += $pDown;
                $usedInbound = true;
            } elseif ($cTotal > 0) {
                $up += $cUp;
                $down += $cDown;
                $usedClients = true;
            }
        }

        $source = match (true) {
            $usedClients && $usedInbound => 'xui_multi_panel_mixed',
            $usedClients => 'xui_mapped_clients',
            $usedInbound => 'xui_inbound_totals',
            default => 'no_traffic',
        };

        return [
            'upload' => $up,
            'download' => $down,
            'total' => $up + $down,
            'source' => $source,
            'panel_count' => count($rows),
        ];
    }

    /**
     * @return array{upload:int, download:int, total:int, client_count:int}
     */
    public static function mappedClientTrafficTotals(int $customerId): array
    {
        $sql = 'SELECT COALESCE(SUM(vc.last_xui_upload), 0) AS up,
                       COALESCE(SUM(vc.last_xui_download), 0) AS down,
                       COUNT(vc.id) AS client_count
                FROM vpn_clients vc
                INNER JOIN vpn_panels vp ON vp.id = vc.panel_id AND vp.is_active = 1
                WHERE vc.customer_id = :cid';
        $params = ['cid' => $customerId];
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        $up = (int) ($row['up'] ?? 0);
        $down = (int) ($row['down'] ?? 0);

        return [
            'upload' => $up,
            'download' => $down,
            'total' => $up + $down,
            'client_count' => (int) ($row['client_count'] ?? 0),
        ];
    }

    /**
     * @return array{upload:int, download:int, total:int, source:string}
     */
    public static function panelInboundTotalsForCustomer(int $customerId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COALESCE(SUM(xui_inbound_up), 0) AS up,
                    COALESCE(SUM(xui_inbound_down), 0) AS down,
                    SUM(CASE WHEN last_sync_at IS NOT NULL THEN 1 ELSE 0 END) AS synced_panels
             FROM vpn_panels
             WHERE customer_id = :cid AND is_active = 1'
        );
        $stmt->execute(['cid' => $customerId]);
        $row = $stmt->fetch();
        $up = (int) ($row['up'] ?? 0);
        $down = (int) ($row['down'] ?? 0);
        $synced = (int) ($row['synced_panels'] ?? 0);
        if ($synced > 0) {
            return [
                'upload' => $up,
                'download' => $down,
                'total' => $up + $down,
                'source' => 'xui_inbound_totals',
            ];
        }

        return ['upload' => 0, 'download' => 0, 'total' => 0, 'source' => 'none'];
    }

    /**
     * @return array{upload:int, download:int, total:int, source:string}
     */
    public static function quotaUsageForCustomer(int $customerId): array
    {
        $usage = self::perPanelQuotaUsage($customerId);
        if ($usage['total'] > 0 || $usage['source'] !== 'no_traffic') {
            return [
                'upload' => $usage['upload'],
                'download' => $usage['download'],
                'total' => $usage['total'],
                'source' => $usage['source'],
            ];
        }

        if (self::activeSubscription($customerId) === null) {
            return ['upload' => 0, 'download' => 0, 'total' => 0, 'source' => 'none'];
        }

        $mapped = self::mappedClientTrafficTotals($customerId);
        if ($mapped['client_count'] > 0) {
            return [
                'upload' => 0,
                'download' => 0,
                'total' => 0,
                'source' => 'mapped_clients_zero_traffic',
            ];
        }

        return [
            'upload' => 0,
            'download' => 0,
            'total' => 0,
            'source' => 'no_mapped_clients',
        ];
    }

    /** @return array{client_count:int, mapped_total:int, panel_total:int, quota_bytes:int, percent:float, would_cut:bool, cut_reason:?string} */
    public static function quotaDiagnosis(int $customerId): array
    {
        $sub = self::activeSubscription($customerId);
        $quota = $sub !== null ? (int) $sub['quota_bytes'] : 0;
        $subId = $sub !== null ? (int) $sub['id'] : null;
        $mapped = self::mappedClientTrafficTotals($customerId, $subId);
        $panel = self::panelInboundTotalsForCustomer($customerId);
        $usage = self::quotaUsageForCustomer($customerId);
        $total = $usage['total'];
        $percent = $quota > 0 ? ($total / $quota) * 100 : 0.0;
        $wouldCut = false;
        $reason = 'JaySub فقط مصرف را محاسبه می‌کند؛ قطع خودکار ندارد (' . round($percent, 1) . '٪ از سقف).';
        if ($mapped['client_count'] === 0) {
            $reason .= ' هنوز کلاینت sync نشده — worker را چک کنید.';
        }

        return [
            'client_count' => $mapped['client_count'],
            'mapped_total' => $mapped['total'],
            'panel_total' => $panel['total'],
            'quota_bytes' => $quota,
            'percent' => $percent,
            'would_cut' => $wouldCut,
            'cut_reason' => $reason,
        ];
    }

    /** هم‌خوان‌کردن subscriptions.used_* با مصرف سهمیه (بعد از sync). */
    public static function persistTrafficUsageFromPanels(int $customerId): void
    {
        $usage = self::quotaUsageForCustomer($customerId);
        if (!in_array($usage['source'], ['xui_mapped_clients', 'xui_inbound_totals', 'xui_multi_panel_mixed'], true)) {
            return;
        }
        $sub = self::activeSubscription($customerId);
        if ($sub === null) {
            return;
        }
        Database::pdo()->prepare(
            'UPDATE subscriptions SET used_upload_bytes = :u, used_download_bytes = :d, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        )->execute([
            'u' => $usage['upload'],
            'd' => $usage['download'],
            'id' => (int) $sub['id'],
        ]);
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

    /**
     * Ensures a subscription row exists so panel traffic sync can auto-import all XUI clients.
     *
     * @return array<string, mixed>
     */
    public static function ensureTrafficSubscription(int $customerId): array
    {
        $sub = self::activeSubscription($customerId);
        if ($sub !== null) {
            return $sub;
        }
        $pdo = Database::pdo();
        $pdo->prepare(
            'INSERT INTO subscriptions (customer_id, quota_bytes, status, started_at)
             VALUES (:cid, 0, \'active\', NOW())'
        )->execute(['cid' => $customerId]);
        $pdo->prepare(
            "UPDATE customers SET service_status = 'active', vpn_enabled = 1 WHERE id = :id"
        )->execute(['id' => $customerId]);
        $sub = self::activeSubscription($customerId);
        if ($sub === null) {
            throw new \RuntimeException('Could not create traffic subscription');
        }
        return $sub;
    }

    /** @return list<array<string, mixed>> */
    public static function panelUsageBreakdown(int $customerId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT vp.id, vp.name,
                CAST(vp.xui_inbound_up AS UNSIGNED) AS upload_bytes,
                CAST(vp.xui_inbound_down AS UNSIGNED) AS download_bytes
             FROM vpn_panels vp
             WHERE vp.customer_id = :cid AND vp.is_active = 1
             ORDER BY vp.id'
        );
        $stmt->execute(['cid' => $customerId]);
        return $stmt->fetchAll();
    }
}
