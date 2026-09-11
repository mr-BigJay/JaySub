<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class DashboardService
{
    /** @return array{customers:int,active:int,services:int,panels_connected:int,total_traffic:int} */
    public static function adminSummary(): array
    {
        $pdo = Database::pdo();
        $customers = (int) $pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn();
        $active = (int) $pdo->query(
            "SELECT COUNT(*) FROM customers WHERE is_active = 1 AND service_status = 'active'"
        )->fetchColumn();
        $services = (int) $pdo->query(
            "SELECT COUNT(*) FROM subscriptions WHERE status IN ('active', 'exhausted')"
        )->fetchColumn();
        $panelsConnected = (int) $pdo->query(
            "SELECT COUNT(*) FROM vpn_panels WHERE connection_status = 'connected'"
        )->fetchColumn();
        $totalTraffic = (int) $pdo->query(
            'SELECT COALESCE(SUM(used_upload_bytes + used_download_bytes), 0) FROM subscriptions'
        )->fetchColumn();

        return [
            'customers' => $customers,
            'active' => $active,
            'services' => $services,
            'panels_connected' => $panelsConnected,
            'total_traffic' => $totalTraffic,
        ];
    }

    /** @return array{today:int,week:int,month:int,total:int} */
    public static function trafficPeriods(): array
    {
        $total = self::adminSummary()['total_traffic'];

        return [
            'today' => self::trafficSinceDatetime(date('Y-m-d 00:00:00')),
            'week' => self::trafficSinceDatetime(date('Y-m-d 00:00:00', strtotime('-7 days'))),
            'month' => self::trafficSinceDatetime(date('Y-m-d 00:00:00', strtotime('-30 days'))),
            'total' => $total,
        ];
    }

    /**
     * Bytes consumed since $cutoff: current subscription totals minus last snapshot before cutoff.
     * Snapshots store cumulative totals; never SUM(snapshot.total_bytes) over a time range.
     */
    public static function trafficSinceDatetime(string $cutoff): int
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(GREATEST(0,
                CAST(s.used_upload_bytes AS SIGNED) + CAST(s.used_download_bytes AS SIGNED)
                - COALESCE((
                    SELECT ts.total_bytes FROM traffic_snapshots ts
                    WHERE ts.subscription_id = s.id AND ts.recorded_at < :cutoff
                    ORDER BY ts.recorded_at DESC LIMIT 1
                ), 0)
            )), 0) AS bytes
             FROM subscriptions s
             WHERE s.status IN (\'active\', \'exhausted\')'
        );
        $stmt->execute(['cutoff' => $cutoff]);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<array{label:string,bytes:int}> */
    public static function trafficChartLastDays(int $days = 7): array
    {
        $days = max(1, min(31, $days));
        $out = [];
        for ($i = $days - 1; $i >= 0; --$i) {
            $ymd = date('Y-m-d', strtotime('-' . $i . ' days'));
            $out[] = [
                'label' => substr($ymd, 5),
                'bytes' => self::trafficOnCalendarDay($ymd),
            ];
        }

        return $out;
    }

    public static function trafficOnCalendarDay(string $ymd): int
    {
        if ($ymd === date('Y-m-d')) {
            return self::trafficSinceDatetime($ymd . ' 00:00:00');
        }

        $pdo = Database::pdo();
        $dayStart = $ymd . ' 00:00:00';
        $dayEnd = date('Y-m-d', strtotime($ymd . ' +1 day')) . ' 00:00:00';
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(GREATEST(0, COALESCE(e.end_t, 0) - COALESCE(b.start_t, 0))), 0) AS bytes
             FROM subscriptions s
             LEFT JOIN (
                SELECT subscription_id, MAX(total_bytes) AS end_t
                FROM traffic_snapshots
                WHERE recorded_at >= :dayStart AND recorded_at < :dayEnd
                GROUP BY subscription_id
             ) e ON e.subscription_id = s.id
             LEFT JOIN (
                SELECT ts.subscription_id, ts.total_bytes AS start_t
                FROM traffic_snapshots ts
                INNER JOIN (
                    SELECT subscription_id, MAX(recorded_at) AS mr
                    FROM traffic_snapshots
                    WHERE recorded_at < :dayStart2
                    GROUP BY subscription_id
                ) m ON m.subscription_id = ts.subscription_id AND ts.recorded_at = m.mr
             ) b ON b.subscription_id = s.id
             WHERE s.status IN (\'active\', \'exhausted\')'
        );
        $stmt->execute(['dayStart' => $dayStart, 'dayEnd' => $dayEnd, 'dayStart2' => $dayStart]);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    public static function allPanels(): array
    {
        return Database::pdo()->query(
            'SELECT vp.*, c.name AS customer_name, c.username AS customer_username,
                COALESCE(SUM(
                    GREATEST(0, CAST(vc.base_upload_bytes AS SIGNED) + CAST(vc.last_xui_upload AS SIGNED) - CAST(vc.xui_baseline_upload AS SIGNED))
                  + GREATEST(0, CAST(vc.base_download_bytes AS SIGNED) + CAST(vc.last_xui_download AS SIGNED) - CAST(vc.xui_baseline_download AS SIGNED))
                ), 0) AS traffic_bytes
             FROM vpn_panels vp
             INNER JOIN customers c ON c.id = vp.customer_id
             LEFT JOIN vpn_clients vc ON vc.panel_id = vp.id
             GROUP BY vp.id
             ORDER BY vp.id DESC'
        )->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public static function notifications(int $limit = 50): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT ta.*, c.username, c.name AS customer_name
             FROM traffic_alerts ta
             INNER JOIN customers c ON c.id = ta.customer_id
             ORDER BY ta.sent_at DESC
             LIMIT :lim'
        );
        $stmt->bindValue('lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** @return list<array{name:string,bytes:int}> */
    public static function trafficByPanel(): array
    {
        $rows = Database::pdo()->query(
            'SELECT vp.name,
                COALESCE(SUM(
                    GREATEST(0, CAST(vc.base_upload_bytes AS SIGNED) + CAST(vc.last_xui_upload AS SIGNED) - CAST(vc.xui_baseline_upload AS SIGNED))
                  + GREATEST(0, CAST(vc.base_download_bytes AS SIGNED) + CAST(vc.last_xui_download AS SIGNED) - CAST(vc.xui_baseline_download AS SIGNED))
                ), 0) AS bytes
             FROM vpn_panels vp
             LEFT JOIN vpn_clients vc ON vc.panel_id = vp.id
             GROUP BY vp.id, vp.name
             ORDER BY bytes DESC'
        )->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['name' => (string) $r['name'], 'bytes' => (int) $r['bytes']];
        }
        return $out;
    }

    public static function subscriptionLinkForCustomer(int $customerId): ?string
    {
        $customer = CustomerService::findById($customerId);
        if ($customer === null) {
            return null;
        }
        $link = trim((string) ($customer['subscription_link'] ?? ''));
        return $link !== '' ? $link : null;
    }
}
