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
        $pdo = Database::pdo();
        $total = (int) $pdo->query('SELECT COALESCE(SUM(total_bytes), 0) FROM traffic_snapshots')->fetchColumn();
        $today = (int) $pdo->query(
            'SELECT COALESCE(SUM(total_bytes), 0) FROM traffic_snapshots WHERE recorded_at >= CURDATE()'
        )->fetchColumn();
        $week = (int) $pdo->query(
            'SELECT COALESCE(SUM(total_bytes), 0) FROM traffic_snapshots WHERE recorded_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)'
        )->fetchColumn();
        $month = (int) $pdo->query(
            'SELECT COALESCE(SUM(total_bytes), 0) FROM traffic_snapshots WHERE recorded_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)'
        )->fetchColumn();

        return ['today' => $today, 'week' => $week, 'month' => $month, 'total' => $total > 0 ? $total : self::adminSummary()['total_traffic']];
    }

    /** @return list<array{label:string,bytes:int}> */
    public static function trafficChartLastDays(int $days = 7): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT DATE(recorded_at) AS d, COALESCE(SUM(total_bytes), 0) AS bytes
             FROM traffic_snapshots
             WHERE recorded_at >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
             GROUP BY DATE(recorded_at)
             ORDER BY d ASC'
        );
        $stmt->execute(['days' => $days]);
        $rows = $stmt->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['label' => (string) $r['d'], 'bytes' => (int) $r['bytes']];
        }
        return $out;
    }

    /** @return list<array<string, mixed>> */
    public static function allPanels(): array
    {
        return Database::pdo()->query(
            'SELECT vp.*, c.name AS customer_name, c.username AS customer_username
             FROM vpn_panels vp
             INNER JOIN customers c ON c.id = vp.customer_id
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
                COALESCE(SUM(vc.base_upload_bytes + vc.last_xui_upload + vc.base_download_bytes + vc.last_xui_download), 0) AS bytes
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
