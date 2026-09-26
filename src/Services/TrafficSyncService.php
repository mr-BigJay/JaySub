<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Xui\InboundTraffic;
use App\Xui\XuiClient;
use PDO;

/** Panel columns store inbound totals; quota / cut-off uses mapped XUI clients only. */

final class TrafficSyncService
{
    public function __construct(
        private readonly \App\Core\Encryption $encryption,
        private readonly TelegramService $telegram,
    ) {
    }

    public function syncAllPanels(): void
    {
        $pdo = Database::pdo();
        $panels = $pdo->query(
            'SELECT vp.*, c.id AS customer_id FROM vpn_panels vp
             INNER JOIN customers c ON c.id = vp.customer_id
             WHERE vp.is_active = 1 AND c.is_active = 1'
        )->fetchAll();

        foreach ($panels as $panel) {
            try {
                $this->syncPanel((int) $panel['id']);
            } catch (\Throwable $e) {
                $this->markPanelError((int) $panel['id'], $e->getMessage());
                AuditLogService::log('system', null, 'panel_sync_error', 'vpn_panel', (int) $panel['id'], [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $customers = $pdo->query('SELECT id FROM customers WHERE is_active = 1')->fetchAll();
        foreach ($customers as $row) {
            $this->aggregateCustomer((int) $row['id']);
        }
    }

    public function syncPanelAndAggregate(int $panelId): void
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT customer_id FROM vpn_panels WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $panelId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new \RuntimeException('Panel not found');
        }
        $this->syncPanel($panelId);
        $this->aggregateCustomer((int) $row['customer_id']);
    }

    public function syncPanel(int $panelId): void
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM vpn_panels WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $panelId]);
        $panel = $stmt->fetch();
        if ($panel === false) {
            throw new \RuntimeException('Panel not found');
        }

        $token = $this->encryption->decrypt($panel['api_token_encrypted']);
        $client = new XuiClient($panel['base_url'], $token);

        $status = $client->getServerStatus();
        $statusOk = $status['ok'];

        $list = $client->listInbounds();
        if (!$list['ok']) {
            $this->markPanelError($panelId, $list['error'] ?? 'inbounds failed');
            return;
        }

        $data = $list['data'] ?? null;
        if (!is_array($data)) {
            $this->markPanelError($panelId, 'Invalid inbounds response');
            return;
        }
        $obj = InboundTraffic::inboundsFromListResult($list);

        $statsByEmail = InboundTraffic::statsByEmail($obj);
        $panelTotals = InboundTraffic::panelTrafficTotals($obj);
        $pdo->prepare(
            'UPDATE vpn_panels SET xui_inbound_up = :u, xui_inbound_down = :d, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        )->execute([
            'u' => $panelTotals['up'],
            'd' => $panelTotals['down'],
            'id' => $panelId,
        ]);

        $customerId = (int) $panel['customer_id'];
        $subscription = CustomerService::ensureTrafficSubscription($customerId);
        $subId = (int) $subscription['id'];

        $selectVc = $pdo->prepare(
            'SELECT * FROM vpn_clients WHERE panel_id = :panel_id AND xui_email = :email LIMIT 1'
        );
        $insertVc = $pdo->prepare(
            'INSERT INTO vpn_clients (
                customer_id, panel_id, subscription_id, inbound_id, xui_email, uuid, protocol,
                last_xui_upload, last_xui_download, enabled_in_xui
             ) VALUES (
                :cid, :pid, :sid, :inbound, :email, :uuid, :protocol,
                :last_up, :last_down, :enabled
             )'
        );
        $upd = $pdo->prepare(
            'UPDATE vpn_clients SET
                last_xui_upload = :last_up,
                last_xui_download = :last_down,
                enabled_in_xui = :enabled,
                uuid = COALESCE(:uuid, uuid),
                protocol = COALESCE(:protocol, protocol),
                inbound_id = :inbound_id,
                subscription_id = :sid,
                updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );

        foreach ($statsByEmail as $email => $s) {
            $selectVc->execute(['panel_id' => $panelId, 'email' => $email]);
            $vc = $selectVc->fetch();
            if ($vc === false) {
                $insertVc->execute([
                    'cid' => $customerId,
                    'pid' => $panelId,
                    'sid' => $subId,
                    'inbound' => $s['inbound_id'],
                    'email' => $email,
                    'uuid' => $s['uuid'],
                    'protocol' => $s['protocol'],
                    'last_up' => $s['up'],
                    'last_down' => $s['down'],
                    'enabled' => $s['enable'] ? 1 : 0,
                ]);
                continue;
            }
            $upd->execute([
                'last_up' => $s['up'],
                'last_down' => $s['down'],
                'enabled' => $s['enable'] ? 1 : 0,
                'uuid' => $s['uuid'],
                'protocol' => $s['protocol'],
                'inbound_id' => $s['inbound_id'],
                'sid' => $subId,
                'id' => $vc['id'],
            ]);
        }

        CustomerService::rebindClientsToActiveSubscription($customerId, $panelId);

        $this->aggregateCustomer($customerId);

        $connStatus = $statusOk ? 'connected' : 'sync_error';
        $lastErr = $statusOk ? null : mb_substr((string) ($status['error'] ?? 'status failed'), 0, 2000);
        $ok = $pdo->prepare(
            "UPDATE vpn_panels SET connection_status = :st, last_sync_at = NOW(), last_error = :err, updated_at = CURRENT_TIMESTAMP WHERE id = :id"
        );
        $ok->execute(['st' => $connStatus, 'err' => $lastErr, 'id' => $panelId]);
    }

    private function markPanelError(int $panelId, string $error): void
    {
        $stmt = Database::pdo()->prepare(
            "UPDATE vpn_panels SET connection_status = 'sync_error', last_error = :err, updated_at = CURRENT_TIMESTAMP WHERE id = :id"
        );
        $stmt->execute(['err' => mb_substr($error, 0, 2000), 'id' => $panelId]);
    }

    public function aggregateCustomer(int $customerId): void
    {
        $pdo = Database::pdo();
        $sub = $pdo->prepare(
            "SELECT * FROM subscriptions WHERE customer_id = :cid AND status IN ('active', 'exhausted') ORDER BY id DESC LIMIT 1"
        );
        $sub->execute(['cid' => $customerId]);
        $subscription = $sub->fetch();
        if ($subscription === false) {
            return;
        }

        $subId = (int) $subscription['id'];
        $usage = CustomerService::quotaUsageForCustomer($customerId);
        $upload = $usage['upload'];
        $download = $usage['download'];
        $total = $usage['total'];
        $quota = (int) $subscription['quota_bytes'];

        $pdo->prepare(
            'UPDATE subscriptions SET used_upload_bytes = :u, used_download_bytes = :d, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        )->execute(['u' => $upload, 'd' => $download, 'id' => $subId]);

        $pdo->prepare(
            'INSERT INTO traffic_snapshots (customer_id, subscription_id, recorded_at, upload_bytes, download_bytes, total_bytes)
             VALUES (:cid, :sid, NOW(), :u, :d, :t)'
        )->execute(['cid' => $customerId, 'sid' => $subId, 'u' => $upload, 'd' => $download, 't' => $total]);

        $cust = $pdo->prepare('SELECT * FROM customers WHERE id = :id');
        $cust->execute(['id' => $customerId]);
        $customer = $cust->fetch();
        if ($customer === false) {
            return;
        }

        $percent = $quota > 0 ? ($total / $quota) * 100 : 0;
        $w1 = (int) $customer['warning1_percent'];
        $w2 = (int) $customer['warning2_percent'];

        $this->maybeSendAlert($customerId, $subId, 'warning_1', $percent >= $w1 && $percent < $w2, function () use ($customer, $quota, $total, $w1) {
            $chat = $customer['telegram_chat_id'] ?? '';
            if ($chat) {
                $this->telegram->sendMessage((string) $chat, TelegramService::warningMessage('هشدار مصرف', (float) $quota, (float) $total, $w1));
            }
            AuditLogService::log('system', null, 'warning_sent', 'customer', (int) $customer['id'], ['level' => 'warning_1']);
        });

        $this->maybeSendAlert($customerId, $subId, 'warning_2', $percent >= $w2 && $percent < 100, function () use ($customer, $quota, $total, $w2) {
            $chat = $customer['telegram_chat_id'] ?? '';
            if ($chat) {
                $this->telegram->sendMessage((string) $chat, TelegramService::warningMessage('هشدار مصرف', (float) $quota, (float) $total, $w2));
            }
            AuditLogService::log('system', null, 'warning_sent', 'customer', (int) $customer['id'], ['level' => 'warning_2']);
        });

        // فقط محاسبه و نمایش — JaySub سرویس را قطع نمی‌کند.
        if ($subscription['status'] === 'exhausted') {
            $pdo->prepare("UPDATE subscriptions SET status = 'active' WHERE id = :id")->execute(['id' => $subId]);
        }
        $st = (string) $customer['service_status'];
        if ($st === 'exhausted') {
            $pdo->prepare(
                "UPDATE customers SET vpn_enabled = 1, service_status = 'active' WHERE id = :id AND service_status NOT IN ('disabled', 'expired')"
            )->execute(['id' => $customerId]);
            $st = 'active';
        }

        $serviceStatus = $percent >= $w2 ? 'warning' : 'active';
        if (!in_array($st, ['disabled', 'expired'], true)) {
            $pdo->prepare('UPDATE customers SET service_status = :st WHERE id = :id')->execute([
                'st' => $serviceStatus,
                'id' => $customerId,
            ]);
        }
    }

    private function maybeSendAlert(int $customerId, int $subId, string $type, bool $condition, callable $send): void
    {
        if (!$condition) {
            return;
        }
        $pdo = Database::pdo();
        $check = $pdo->prepare(
            'SELECT id FROM traffic_alerts WHERE subscription_id = :sid AND alert_type = :t LIMIT 1'
        );
        $check->execute(['sid' => $subId, 't' => $type]);
        if ($check->fetch() !== false) {
            return;
        }
        $send();
        $pdo->prepare(
            'INSERT INTO traffic_alerts (customer_id, subscription_id, alert_type, sent_at) VALUES (:c, :s, :t, NOW())'
        )->execute(['c' => $customerId, 's' => $subId, 't' => $type]);
    }

}
