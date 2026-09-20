<?php

declare(strict_types=1);

/**
 * خاموش کردن قطع سقف + فعال‌سازی کلاینت‌های disable در 3x-ui + sync
 *   php scripts/fix-service-now.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require __DIR__ . '/lib/cli.php';

\App\Services\QuotaEnforcementService::setEnabled(false);
fwrite(STDOUT, 'quota_enforcement_enabled = ' . (\App\Services\QuotaEnforcementService::isEnabled() ? 'ON' : 'OFF') . "\n");

$pdo = \App\Core\Database::pdo();
$panels = $pdo->query(
    'SELECT vp.id, vp.name, vp.base_url, vp.customer_id, vp.api_token_encrypted, c.username
     FROM vpn_panels vp INNER JOIN customers c ON c.id = vp.customer_id
     WHERE vp.is_active = 1 ORDER BY vp.id'
)->fetchAll();

$encryption = new \App\Core\Encryption(
    (string) (jaysub_cli_traffic_sync()['config']['security']['encryption_key'] ?? '')
);

foreach ($panels as $p) {
    $xui = new \App\Xui\XuiClient($p['base_url'], $encryption->decrypt($p['api_token_encrypted']));
    $list = $xui->listInbounds();
    if (!($list['ok'] ?? false)) {
        fwrite(STDOUT, "Panel #{$p['id']} {$p['name']} ({$p['username']}): list FAIL — " . ($list['error'] ?? '?') . "\n");
        continue;
    }
    $obj = \App\Xui\InboundTraffic::inboundsFromListResult($list);
    $disabled = \App\Xui\InboundTraffic::disabledEmails($obj);
    fwrite(STDOUT, "Panel #{$p['id']} {$p['name']}: disabled in 3x-ui = " . count($disabled) . "\n");
    if ($disabled !== []) {
        foreach (array_chunk($disabled, 80) as $chunk) {
            $res = $xui->bulkEnable($chunk);
            $changed = is_array($res['data'] ?? null) ? ($res['data']['obj']['changed'] ?? '?') : '?';
            if (!($res['ok'] ?? false)) {
                fwrite(STDOUT, '  bulkEnable ERR: ' . ($res['error'] ?? 'failed') . "\n");
            } else {
                fwrite(STDOUT, "  bulkEnable OK, changed={$changed}\n");
            }
        }
    }
}

$sync = jaysub_cli_traffic_sync()['sync'];
$sync->reenableEveryCustomerWhileEnforcementPaused();
fwrite(STDOUT, "Running full sync…\n");
$sync->syncAllPanels();
fwrite(STDOUT, "Done. Run: php scripts/quota-diagnose.php --cut-only\n");
