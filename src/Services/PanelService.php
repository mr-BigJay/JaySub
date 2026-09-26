<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\AuthService;
use App\Core\Database;
use App\Core\Encryption;
use App\Xui\XuiClient;
use App\Xui\XuiToken;

final class PanelService
{
    public static function create(int $customerId, string $name, string $baseUrl, string $apiToken, Encryption $encryption, ?int $adminId = null): int
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        $apiToken = XuiToken::normalize($apiToken);
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

    /** فعال/غیرفعال دستی برای محاسبه مصرف و sync (پیش‌فرض DB: فعال). */
    public static function setManualActive(int $panelId, bool $active, ?int $adminId = null): void
    {
        $panel = self::findById($panelId);
        if ($panel === null) {
            throw new \RuntimeException('پنل یافت نشد');
        }
        Database::pdo()->prepare(
            'UPDATE vpn_panels SET is_active = :a, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        )->execute(['a' => $active ? 1 : 0, 'id' => $panelId]);
        AuditLogService::log('admin', $adminId, $active ? 'panel_enabled' : 'panel_disabled', 'vpn_panel', $panelId, [
            'customer_id' => (int) $panel['customer_id'],
        ]);
    }

    /**
     * قطع / وصل اینترنت کلاینت‌های همان پنل در 3x-ui (دستی — بدون قطع خودکار سقف).
     *
     * @return int تعداد کلاینت‌هایی که درخواست برای آن‌ها ارسال شد
     */
    public static function setPanelInternetConnected(
        int $panelId,
        bool $connected,
        Encryption $encryption,
        ?int $adminId = null,
    ): int {
        $panel = self::findById($panelId);
        if ($panel === null) {
            throw new \RuntimeException('پنل یافت نشد');
        }

        $discovered = self::discoverClients($panelId, $encryption);
        $emails = [];
        foreach ($discovered as $row) {
            $email = trim((string) ($row['email'] ?? ''));
            if ($email !== '') {
                $emails[$email] = true;
            }
        }
        $emails = array_keys($emails);
        if ($emails === []) {
            throw new \RuntimeException('کلاینتی روی این پنل پیدا نشد — ابتدا «تست» یا worker sync را بزنید.');
        }

        $xui = new XuiClient(
            (string) $panel['base_url'],
            $encryption->decrypt((string) $panel['api_token_encrypted']),
        );
        $result = $connected ? $xui->bulkEnable($emails) : $xui->bulkDisable($emails);
        if (!($result['ok'] ?? false)) {
            throw new \RuntimeException((string) ($result['error'] ?? 'خطای API پنل 3x-ui'));
        }

        $pdo = Database::pdo();
        $pdo->prepare(
            'UPDATE vpn_panels SET internet_cut = :c, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        )->execute(['c' => $connected ? 0 : 1, 'id' => $panelId]);
        $pdo->prepare(
            'UPDATE vpn_clients SET enabled_in_xui = :e, updated_at = CURRENT_TIMESTAMP WHERE panel_id = :pid'
        )->execute(['e' => $connected ? 1 : 0, 'pid' => $panelId]);

        AuditLogService::log(
            'admin',
            $adminId,
            $connected ? 'panel_internet_on' : 'panel_internet_off',
            'vpn_panel',
            $panelId,
            ['customer_id' => (int) $panel['customer_id'], 'client_count' => count($emails)],
        );

        return count($emails);
    }

    public static function togglePanelInternet(int $panelId, Encryption $encryption, ?int $adminId = null): bool
    {
        $panel = self::findById($panelId);
        if ($panel === null) {
            throw new \RuntimeException('پنل یافت نشد');
        }
        $currentlyCut = (int) ($panel['internet_cut'] ?? 0) === 1;
        $connect = $currentlyCut;
        self::setPanelInternetConnected($panelId, $connect, $encryption, $adminId);

        return $connect;
    }

    /**
     * نگه‌داشتن قطع دستی: worker هر sync ممکن است 3x-ui دوباره enable کند — دوباره bulkDisable.
     *
     * @param list<string> $emails
     */
    public static function reapplyInternetCut(int $panelId, Encryption $encryption, array $emails = []): void
    {
        $panel = self::findById($panelId);
        if ($panel === null || (int) ($panel['internet_cut'] ?? 0) !== 1) {
            return;
        }

        $normalized = [];
        foreach ($emails as $email) {
            $e = trim((string) $email);
            if ($e !== '') {
                $normalized[$e] = true;
            }
        }
        if ($normalized === []) {
            $stmt = Database::pdo()->prepare('SELECT xui_email FROM vpn_clients WHERE panel_id = :pid');
            $stmt->execute(['pid' => $panelId]);
            foreach ($stmt->fetchAll() as $row) {
                $e = trim((string) ($row['xui_email'] ?? ''));
                if ($e !== '') {
                    $normalized[$e] = true;
                }
            }
        }
        $list = array_keys($normalized);
        if ($list === []) {
            return;
        }

        $xui = new XuiClient(
            (string) $panel['base_url'],
            $encryption->decrypt((string) $panel['api_token_encrypted']),
        );
        $result = $xui->bulkDisable($list);
        if (!($result['ok'] ?? false)) {
            error_log(
                'JaySub reapplyInternetCut panel #' . $panelId . ': '
                . (string) ($result['error'] ?? 'bulkDisable failed')
            );
            return;
        }

        Database::pdo()->prepare(
            'UPDATE vpn_clients SET enabled_in_xui = 0, updated_at = CURRENT_TIMESTAMP WHERE panel_id = :pid'
        )->execute(['pid' => $panelId]);
    }

    /** @return array<string, mixed>|null */
    public static function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM vpn_panels WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public static function update(int $panelId, string $name, string $baseUrl, ?string $apiToken, Encryption $encryption, ?int $adminId = null): void
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($apiToken !== null && $apiToken !== '') {
            $apiToken = XuiToken::normalize($apiToken);
            Database::pdo()->prepare(
                'UPDATE vpn_panels SET name = :n, base_url = :u, api_token_encrypted = :t, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            )->execute([
                'n' => $name,
                'u' => $baseUrl,
                't' => $encryption->encrypt($apiToken),
                'id' => $panelId,
            ]);
        } else {
            Database::pdo()->prepare(
                'UPDATE vpn_panels SET name = :n, base_url = :u, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            )->execute(['n' => $name, 'u' => $baseUrl, 'id' => $panelId]);
        }
        AuditLogService::log('admin', $adminId, 'panel_updated', 'vpn_panel', $panelId);
    }

    /** @return list<array<string, mixed>> */
    public static function forCustomer(int $customerId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, customer_id, name, base_url, is_active, internet_cut, connection_status, last_sync_at, last_error, created_at
             FROM vpn_panels WHERE customer_id = :cid ORDER BY id'
        );
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

        $obj = \App\Xui\InboundTraffic::inboundsFromListResult($list);
        if ($obj === []) {
            return [];
        }
        $statsByEmail = \App\Xui\InboundTraffic::statsByEmail($obj);
        $result = [];
        foreach ($statsByEmail as $email => $s) {
            $result[] = [
                'inbound_id' => $s['inbound_id'],
                'protocol' => $s['protocol'],
                'email' => $email,
                'up' => $s['up'],
                'down' => $s['down'],
                'enable' => $s['enable'],
                'uuid' => $s['uuid'],
                'mapped' => isset($mappedSet[$email]),
            ];
        }
        return $result;
    }

    /** @return list<array{email:string,bytes:int,tracked_bytes:int,enabled:bool}> */
    public static function listTrackedClients(int $panelId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT xui_email, base_upload_bytes, base_download_bytes, last_xui_upload, last_xui_download,
                    xui_baseline_upload, xui_baseline_download, enabled_in_xui
             FROM vpn_clients WHERE panel_id = :pid ORDER BY xui_email'
        );
        $stmt->execute(['pid' => $panelId]);
        $rows = $stmt->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $up = (int) $r['last_xui_upload'];
            $down = (int) $r['last_xui_download'];
            $out[] = [
                'email' => (string) $r['xui_email'],
                'bytes' => $up + $down,
                'tracked_bytes' => $up + $down,
                'enabled' => (int) $r['enabled_in_xui'] === 1,
            ];
        }
        return $out;
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
    public static function testConnection(int $panelId, Encryption $encryption, ?string $plainTokenOverride = null): array
    {
        $stmt = Database::pdo()->prepare('SELECT base_url, api_token_encrypted FROM vpn_panels WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $panelId]);
        $panel = $stmt->fetch();
        if ($panel === false) {
            return ['ok' => false, 'message' => 'پنل یافت نشد'];
        }
        if ($plainTokenOverride !== null && $plainTokenOverride !== '') {
            $apiToken = XuiToken::normalize($plainTokenOverride);
        } else {
            try {
                $apiToken = $encryption->decrypt((string) $panel['api_token_encrypted']);
            } catch (\Throwable) {
                return [
                    'ok' => false,
                    'message' => 'توکن ذخیره‌شده قابل خواندن نیست (احتمالاً encryption_key در config.php عوض شده). توکن را در ویرایش پنل دوباره وارد و ذخیره کنید.',
                ];
            }
            $apiToken = XuiToken::normalize($apiToken);
        }
        if ($apiToken === '') {
            return ['ok' => false, 'message' => 'توکن API خالی است — در ویرایش پنل توکن جدید از 3x-ui را وارد و ذخیره کنید.'];
        }
        $xui = new XuiClient((string) $panel['base_url'], $apiToken);
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
