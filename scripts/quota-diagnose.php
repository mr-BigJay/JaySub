<?php

declare(strict_types=1);

/**
 * تشخیص قطع سرویس / سقف حجم
 *   php scripts/quota-diagnose.php
 *   php scripts/quota-diagnose.php --customer USERNAME
 *   php scripts/quota-diagnose.php --cut-only
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require dirname(__DIR__) . '/src/bootstrap-cli.php';

use App\Services\CustomerService;
use App\Services\QuotaEnforcementService;

$filter = null;
$cutOnly = false;
$argv = $_SERVER['argv'] ?? [];
for ($i = 1; $i < count($argv); $i++) {
    if ($argv[$i] === '--customer' && isset($argv[$i + 1])) {
        $filter = strtolower(trim($argv[++$i]));
    }
    if ($argv[$i] === '--cut-only') {
        $cutOnly = true;
    }
}

function fmtGb(int $bytes): string
{
    if ($bytes <= 0) {
        return '0 GB';
    }
    return number_format($bytes / (1024 ** 3), 2, '.', '') . ' GB';
}

$pdo = \App\Core\Database::pdo();
$customers = $pdo->query(
    'SELECT c.id, c.username, c.name, c.vpn_enabled, c.service_status
     FROM customers c ORDER BY c.id'
)->fetchAll();

use App\Services\QuotaEnforcementService;

$enforce = QuotaEnforcementService::isEnabled();
fwrite(STDOUT, 'JaySub quota-diagnose — قطع خودکار: ' . ($enforce ? 'ON' : 'OFF (فقط sync)') . "\n\n");

foreach ($customers as $c) {
    if ($filter !== null
        && !str_contains(strtolower((string) $c['username']), $filter)
        && !str_contains(strtolower((string) $c['name']), $filter)) {
        continue;
    }

    $cid = (int) $c['id'];
    $sub = $pdo->prepare(
        "SELECT id, status, quota_bytes, used_upload_bytes, used_download_bytes FROM subscriptions
         WHERE customer_id = :id AND status IN ('active','exhausted') ORDER BY id DESC LIMIT 1"
    );
    $sub->execute(['id' => $cid]);
    $s = $sub->fetch();

    $isCutState = (int) $c['vpn_enabled'] === 0
        || (string) $c['service_status'] === 'exhausted'
        || ($s && $s['status'] === 'exhausted');
    if ($cutOnly && !$isCutState) {
        continue;
    }

    $d = CustomerService::quotaDiagnosis($cid);
    $usage = CustomerService::quotaUsageForCustomer($cid);

    echo "========== {$c['username']} ({$c['name']}) #{$cid} ==========\n";
    echo '  JaySub: vpn_enabled=' . $c['vpn_enabled'] . ', service_status=' . $c['service_status'] . "\n";
    if ($s) {
        echo '  subscription: status=' . $s['status'] . ', quota=' . fmtGb((int) $s['quota_bytes']);
        echo ', used_in_db=' . fmtGb((int) $s['used_upload_bytes'] + (int) $s['used_download_bytes']) . "\n";
    } else {
        echo "  subscription: NONE\n";
    }
    echo '  mapped clients: ' . $d['client_count'] . ', usage(source=' . $usage['source'] . ')=' . fmtGb($usage['total']) . "\n";
    echo '  panel inbound total (3x-ui Inbounds): ' . fmtGb($d['panel_total']) . "\n";
    echo '  percent vs quota: ' . round($d['percent'], 2) . "%\n";
    echo '  auto-cut now? ' . ($d['would_cut'] ? 'YES' : 'NO') . "\n";
    echo '  => ' . ($d['cut_reason'] ?? '') . "\n";

    $dup = $pdo->prepare(
        'SELECT xui_email, COUNT(*) AS n FROM vpn_clients WHERE customer_id = :id GROUP BY xui_email HAVING n > 1'
    );
    $dup->execute(['id' => $cid]);
    $dups = $dup->fetchAll();
    if ($dups !== []) {
        echo "  WARNING duplicate emails in vpn_clients (مصرف دوبار شمرده می‌شود):\n";
        foreach ($dups as $row) {
            echo '    - ' . $row['xui_email'] . ' x' . $row['n'] . "\n";
        }
    }

    $disabled = $pdo->prepare(
        'SELECT COUNT(*) FROM vpn_clients WHERE customer_id = :id AND disabled_by_quota = 1'
    );
    $disabled->execute(['id' => $cid]);
    $dq = (int) $disabled->fetchColumn();
    $offXui = $pdo->prepare(
        'SELECT COUNT(*) FROM vpn_clients WHERE customer_id = :id AND enabled_in_xui = 0'
    );
    $offXui->execute(['id' => $cid]);
    $ox = (int) $offXui->fetchColumn();
    echo "  clients disabled_by_quota={$dq}, enabled_in_xui=0 (last sync)={$ox}\n";

    $alerts = $pdo->prepare(
        'SELECT alert_type, sent_at FROM traffic_alerts WHERE customer_id = :id ORDER BY sent_at DESC LIMIT 5'
    );
    $alerts->execute(['id' => $cid]);
    foreach ($alerts->fetchAll() as $a) {
        echo '  alert: ' . $a['alert_type'] . ' @ ' . $a['sent_at'] . "\n";
    }

    $audit = $pdo->prepare(
        "SELECT action, created_at, details FROM audit_logs
         WHERE entity_type = 'customer' AND entity_id = :id
           AND action IN ('clients_disabled_quota','quota_restore_under_limit')
         ORDER BY id DESC LIMIT 3"
    );
    $audit->execute(['id' => $cid]);
    foreach ($audit->fetchAll() as $log) {
        echo '  audit: ' . $log['action'] . ' @ ' . $log['created_at'];
        if (!empty($log['details'])) {
            echo ' ' . $log['details'];
        }
        echo "\n";
    }

    $panelCount = $pdo->prepare('SELECT COUNT(*) FROM vpn_panels WHERE customer_id = :id');
    $panelCount->execute(['id' => $cid]);
    if ($d['client_count'] === 0 && (int) $panelCount->fetchColumn() > 0) {
        echo "  FIX: php worker/traffic_worker.php  (import clients from 3x-ui)\n";
    }
    if ($isCutState && !$d['would_cut']) {
        echo "  FIX: php scripts/restore-quota-clients.php  (بازگردانی بعد از اصلاح سقف)\n";
    }
    echo "\n";
}

echo "تاریخچه قطع خودکار: php scripts/quota-audit-log.php\n";
echo "Done.\n";
