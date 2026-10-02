<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** بازنشانی وضعیت سرویس در JaySub (بدون هیچ API کلاینت در 3x-ui). */
final class ServiceStateResetService
{
    public static function resetQuotaFlags(): void
    {
        QuotaEnforcementService::setEnabled(false);
        $pdo = Database::pdo();
        $pdo->exec("UPDATE subscriptions SET status = 'active' WHERE status = 'exhausted'");
        $pdo->exec(
            "UPDATE customers SET vpn_enabled = 1, service_status = 'active'
             WHERE service_status IN ('exhausted', 'warning')"
        );
        $pdo->exec(
            "DELETE FROM traffic_alerts WHERE alert_type IN ('limit_reached', 'warning_1', 'warning_2')"
        );
        $pdo->exec('UPDATE vpn_clients SET disabled_by_quota = 0');
    }
}
