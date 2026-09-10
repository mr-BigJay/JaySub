<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Encryption;
use App\Xui\XuiClient;
use PDO;

final class TrafficSyncService
{
    public function __construct(
        private readonly Encryption $encryption,
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
        if (!$status['ok']) {
            $this->markPanelError($panelId, $status['error'] ?? 'status failed');
            return;
        }

        $list = $client->listInbounds();
        if (!$list['ok']) {
            $this->markPanelError($panelId, $list['error'] ?? 'inbounds failed');
            return;
        }

        $obj = $list['data']['obj'] ?? [];
        if (!is_array($obj)) {
            $this->markPanelError($panelId, 'Invalid inbounds response');
            return;
        }

        /** @var array<string, array{up:int,down:int,enable:bool,uuid?:string}> $statsByEmail */
        $statsByEmail = [];
        foreach ($obj as $inbound) {
            if (!is_array($inbound)) {
                continue;
            }
            $inboundId = (int) ($inbound['id'] ?? 0);
            $protocol = is_string($inbound['protocol'] ?? null) ? $inbound['protocol'] : null;
            $clientStats = $inbound['clientStats'] ?? [];
            if (!is_array($clientStats)) {
                continue;
            }
            foreach ($clientStats as $stat) {
                if (!is_array($stat) || !isset($stat['email'])) {
                    continue;
                }
                $email = (string) $stat['email'];
                $statsByEmail[$email] = [
                    'inbound_id' => $inboundId,
                    'protocol' => $protocol,
                    'up' => (int) ($stat['up'] ?? 0),
                    'down' => (int) ($stat['down'] ?? 0),
                    'enable' => (bool) ($stat['enable'] ?? true),
                    'uuid' => isset($stat['uuid']) ? (string) $stat['uuid'] : null,
                ];
            }
        }

        $mapped = $pdo->prepare(
            'SELECT * FROM vpn_clients WHERE panel_id = :panel_id'
        );
        $mapped->execute(['panel_id' => $panelId]);
        $vpnClients = $mapped->fetchAll();

        foreach ($vpnClients as $vc) {
            $email = $vc['xui_email'];
            if (!isset($statsByEmail[$email])) {
                continue;
            }
            $s = $statsByEmail[$email];
            $result = TrafficCounter::applyReading(
                (int) $vc['base_upload_bytes'],
                (int) $vc['base_download_bytes'],
                (int) $vc['last_xui_upload'],
                (int) $vc['last_xui_download'],
                $s['up'],
                $s['down'],
            );

            $upd = $pdo->prepare(
                'UPDATE vpn_clients SET
                    base_upload_bytes = :base_up,
                    base_download_bytes = :base_down,
                    last_xui_upload = :last_up,
                    last_xui_download = :last_down,
                    enabled_in_xui = :enabled,
                    uuid = COALESCE(:uuid, uuid),
                    protocol = COALESCE(:protocol, protocol),
                    inbound_id = :inbound_id,
                    updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $upd->execute([
                'base_up' => $result['base_up'],
                'base_down' => $result['base_down'],
                'last_up' => $result['last_up'],
                'last_down' => $result['last_down'],
                'enabled' => $s['enable'] ? 1 : 0,
                'uuid' => $s['uuid'],
                'protocol' => $s['protocol'],
                'inbound_id' => $s['inbound_id'],
                'id' => $vc['id'],
            ]);
        }

        $ok = $pdo->prepare(
            "UPDATE vpn_panels SET connection_status = 'connected', last_sync_at = NOW(), last_error = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id"
        );
        $ok->execute(['id' => $panelId]);
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
            "SELECT * FROM subscriptions WHERE customer_id = :cid AND status = 'active' ORDER BY id DESC LIMIT 1"
        );
        $sub->execute(['cid' => $customerId]);
        $subscription = $sub->fetch();
        if ($subscription === false) {
            return;
        }

        $subId = (int) $subscription['id'];
        $sum = $pdo->prepare(
            'SELECT
                COALESCE(SUM(base_upload_bytes + last_xui_upload), 0) AS up,
                COALESCE(SUM(base_download_bytes + last_xui_download), 0) AS down
             FROM vpn_clients WHERE customer_id = :cid AND subscription_id = :sid'
        );
        $sum->execute(['cid' => $customerId, 'sid' => $subId]);
        $totals = $sum->fetch();
        $upload = (int) ($totals['up'] ?? 0);
        $download = (int) ($totals['down'] ?? 0);
        $total = $upload + $download;
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

        if ($percent >= 100 && $subscription['status'] === 'active') {
            $this->enforceQuotaLimit($customerId, $subId, (float) $quota);
        } elseif ($percent < 100 && (int) $customer['vpn_enabled'] === 0 && $subscription['status'] === 'active') {
            // still exhausted until admin recharges — no auto enable
        }

        $serviceStatus = 'active';
        if ($percent >= 100) {
            $serviceStatus = 'exhausted';
        } elseif ($percent >= $w2) {
            $serviceStatus = 'warning';
        }
        $pdo->prepare('UPDATE customers SET service_status = :st WHERE id = :id')->execute([
            'st' => $serviceStatus,
            'id' => $customerId,
        ]);
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

    private function enforceQuotaLimit(int $customerId, int $subId, float $quota): void
    {
        $pdo = Database::pdo();
        $check = $pdo->prepare(
            "SELECT id FROM traffic_alerts WHERE subscription_id = :sid AND alert_type = 'limit_reached' LIMIT 1"
        );
        $check->execute(['sid' => $subId]);
        if ($check->fetch() !== false) {
            return;
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE subscriptions SET status = 'exhausted' WHERE id = :id")->execute(['id' => $subId]);
            $pdo->prepare('UPDATE customers SET vpn_enabled = 0, service_status = \'exhausted\' WHERE id = :id')
                ->execute(['id' => $customerId]);

            $clients = $pdo->prepare('SELECT vc.*, vp.base_url, vp.api_token_encrypted FROM vpn_clients vc
                INNER JOIN vpn_panels vp ON vp.id = vc.panel_id
                WHERE vc.customer_id = :cid AND vc.subscription_id = :sid');
            $clients->execute(['cid' => $customerId, 'sid' => $subId]);
            $rows = $clients->fetchAll();

            /** @var array<int, array{base_url: string, token: string, emails: list<string>}> $emailsByPanel */
            $emailsByPanel = [];
            foreach ($rows as $row) {
                $pid = (int) $row['panel_id'];
                if (!isset($emailsByPanel[$pid])) {
                    $emailsByPanel[$pid] = [
                        'base_url' => $row['base_url'],
                        'token' => $row['api_token_encrypted'],
                        'emails' => [],
                    ];
                }
                $emailsByPanel[$pid]['emails'][] = $row['xui_email'];
            }

            foreach ($emailsByPanel as $panelId => $info) {
                if ($info['emails'] === []) {
                    continue;
                }
                $token = $this->encryption->decrypt($info['token']);
                $xui = new XuiClient($info['base_url'], $token);
                $xui->bulkDisable($info['emails']);
                $placeholders = implode(',', array_fill(0, count($info['emails']), '?'));
                $params = array_merge([$panelId], $info['emails']);
                $pdo->prepare(
                    "UPDATE vpn_clients SET disabled_by_quota = 1 WHERE panel_id = ? AND xui_email IN ({$placeholders})"
                )->execute($params);
            }

            $cust = $pdo->prepare('SELECT telegram_chat_id FROM customers WHERE id = :id');
            $cust->execute(['id' => $customerId]);
            $c = $cust->fetch();
            if ($c && !empty($c['telegram_chat_id'])) {
                $this->telegram->sendMessage((string) $c['telegram_chat_id'], TelegramService::limitMessage($quota));
            }

            $pdo->prepare(
                'INSERT INTO traffic_alerts (customer_id, subscription_id, alert_type, sent_at) VALUES (:c, :s, \'limit_reached\', NOW())'
            )->execute(['c' => $customerId, 's' => $subId]);

            $pdo->commit();
            AuditLogService::log('system', null, 'clients_disabled_quota', 'customer', $customerId);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
