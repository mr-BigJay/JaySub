<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Format;

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
            'SELECT COALESCE(SUM(CAST(xui_inbound_up AS UNSIGNED) + CAST(xui_inbound_down AS UNSIGNED)), 0)
             FROM vpn_panels WHERE is_active = 1'
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

        $dayStart = Format::tehranNow()->setTime(0, 0, 0);

        return [
            'today' => self::trafficSinceDatetime($dayStart->format('Y-m-d H:i:s')),
            'week' => self::trafficSinceDatetime($dayStart->modify('-7 days')->format('Y-m-d H:i:s')),
            'month' => self::trafficSinceDatetime($dayStart->modify('-30 days')->format('Y-m-d H:i:s')),
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

    /**
     * @return list<array{date:string,label:string,bytes:?int,status:string,detail:string,samples:int}>
     */
    public static function trafficChartLastDays(int $days = 7): array
    {
        $days = max(1, min(31, $days));
        $today = Format::tehranNow()->setTime(0, 0);
        $first = $today->modify('-' . ($days - 1) . ' days');

        return self::trafficSeriesBetween($first, $today);
    }

    /** Null indicates missing or insufficient measurements. */
    public static function trafficOnCalendarDay(string $ymd): ?int
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, new \DateTimeZone(Format::TZ));
        if ($day === false || $day->format('Y-m-d') !== $ymd) {
            return null;
        }
        $rows = self::trafficSeriesBetween($day, $day);

        return $rows[0]['bytes'] ?? null;
    }

    /**
     * traffic_snapshots.recorded_at uses MySQL NOW() in session time.
     * FROM_UNIXTIME / UNIX_TIMESTAMP use that same session timezone, then
     * we assign instants to Tehran dates in TrafficDailySeries.
     * Include historical subscriptions and their pre-window baseline.
     *
     * @return list<array{date:string,label:string,bytes:?int,status:string,detail:string,samples:int}>
     */
    private static function trafficSeriesBetween(\DateTimeImmutable $first, \DateTimeImmutable $last): array
    {
        $sql = <<<'SQL'
SELECT subscription_id, total_bytes, UNIX_TIMESTAMP(recorded_at) AS recorded_ts, id
FROM traffic_snapshots
WHERE recorded_at >= FROM_UNIXTIME(:since)
UNION ALL
SELECT b.subscription_id, b.total_bytes, UNIX_TIMESTAMP(b.recorded_at) AS recorded_ts, b.id
FROM traffic_snapshots b
INNER JOIN (
    SELECT MAX(t.id) AS baseline_id
    FROM traffic_snapshots t
    INNER JOIN (
        SELECT subscription_id, MAX(recorded_at) AS latest_at
        FROM traffic_snapshots
        WHERE recorded_at < FROM_UNIXTIME(:before)
        GROUP BY subscription_id
    ) latest ON latest.subscription_id = t.subscription_id AND latest.latest_at = t.recorded_at
    GROUP BY t.subscription_id
) baselines ON baselines.baseline_id = b.id
ORDER BY subscription_id, recorded_ts, id
SQL;
        $stmt = Database::pdo()->prepare($sql);
        $stmt->bindValue(':since', $first->getTimestamp(), \PDO::PARAM_INT);
        $stmt->bindValue(':before', $first->getTimestamp(), \PDO::PARAM_INT);
        $stmt->execute();

        return TrafficDailySeries::aggregate($stmt->fetchAll(), $first, $last);
    }

    /** @return list<array<string, mixed>> */
    public static function allPanels(): array
    {
        return Database::pdo()->query(
            'SELECT vp.*, c.name AS customer_name, c.username AS customer_username,
                (CAST(vp.xui_inbound_up AS UNSIGNED) + CAST(vp.xui_inbound_down AS UNSIGNED)) AS traffic_bytes
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
                (CAST(vp.xui_inbound_up AS UNSIGNED) + CAST(vp.xui_inbound_down AS UNSIGNED)) AS bytes
             FROM vpn_panels vp
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
