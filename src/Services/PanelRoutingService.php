<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Encryption;
use App\Xui\XrayRoutingRules;
use App\Xui\XraySettingJson;
use App\Xui\XuiClient;

final class PanelRoutingService
{
    /**
     * @param array<string, mixed> $config
     * @return list<array<string, mixed>>
     */
    public static function listPanelsSummary(array $config): array
    {
        $panels = DashboardService::allPanels();
        $out = [];
        foreach ($panels as $panel) {
            $id = (int) ($panel['id'] ?? 0);
            $out[] = [
                'id' => $id,
                'name' => (string) ($panel['name'] ?? ''),
                'customer_name' => (string) ($panel['customer_name'] ?? ''),
                'base_url' => (string) ($panel['base_url'] ?? ''),
                'is_active' => (int) ($panel['is_active'] ?? 1) === 1,
                'connection_status' => (string) ($panel['connection_status'] ?? 'unknown'),
                'last_error' => (string) ($panel['last_error'] ?? ''),
                'last_sync_at' => (string) ($panel['last_sync_at'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function fetchPanelRouting(int $panelId, Encryption $encryption, array $config): array
    {
        $panel = PanelService::findById($panelId);
        if ($panel === null) {
            throw new \RuntimeException('پنل یافت نشد');
        }

        $client = self::clientForPanel($panel, $encryption);
        $status = $client->getServerStatus();
        $connectionOk = (bool) ($status['ok'] ?? false);
        $connectionError = $connectionOk ? '' : (string) ($status['error'] ?? 'اتصال ناموفق');

        $xray = $client->fetchXrayTemplate();
        if (!($xray['ok'] ?? false)) {
            return [
                'ok' => false,
                'panel_id' => $panelId,
                'connection_ok' => $connectionOk,
                'connection_error' => $connectionError ?: (string) ($xray['error'] ?? ''),
                'error' => (string) ($xray['error'] ?? 'خواندن Xray ناموفق'),
            ];
        }

        $parsed = self::parseXrayResponse($xray['data'] ?? []);
        if ($parsed['error'] !== null) {
            return [
                'ok' => false,
                'panel_id' => $panelId,
                'connection_ok' => $connectionOk,
                'connection_error' => $connectionError,
                'error' => $parsed['error'],
            ];
        }

        $split = XrayRoutingRules::splitProtectedRules($parsed['rules']);
        $summaries = [];
        $prio = 1;
        foreach (array_merge($split['protected'], $split['editable']) as $rule) {
            $summaries[] = XrayRoutingRules::summarizeRule($rule, $prio++);
        }

        return [
            'ok' => true,
            'panel_id' => $panelId,
            'connection_ok' => $connectionOk,
            'connection_error' => $connectionError,
            'outbound_test_url' => $parsed['outboundTestUrl'],
            'inbound_tags' => $parsed['inboundTags'],
            'outbound_tags' => XrayRoutingRules::outboundTagsFromConfig($parsed['config']),
            'balancer_tags' => XrayRoutingRules::balancerTagsFromConfig($parsed['config']),
            'rules' => $summaries,
            'protected_count' => count($split['protected']),
        ];
    }

    /**
     * @param list<array<string, mixed>> $editableRules
     * @param array<string, mixed> $config
     * @return array{ok: bool, error?: string, backup_file?: string, msg?: string, rolled_back?: bool, critical?: bool}
     */
    public static function saveEditableRules(
        int $panelId,
        array $editableRules,
        Encryption $encryption,
        array $config,
        ?int $adminId = null,
    ): array {
        RoutingSaveGuard::assertPersistAllowed();

        $panel = PanelService::findById($panelId);
        if ($panel === null) {
            return ['ok' => false, 'error' => 'پنل یافت نشد'];
        }

        $client = self::clientForPanel($panel, $encryption);
        $xray = $client->fetchXrayTemplate();
        if (!($xray['ok'] ?? false)) {
            return ['ok' => false, 'error' => (string) ($xray['error'] ?? 'خواندن Xray ناموفق')];
        }

        $parsed = self::parseXrayResponse($xray['data'] ?? []);
        if ($parsed['error'] !== null) {
            return ['ok' => false, 'error' => $parsed['error']];
        }
        if (!($parsed['xraySettingPersistable'] ?? false)) {
            return [
                'ok' => false,
                'error' => 'ذخیره فقط وقتی مجاز است که xraySetting از API به‌صورت رشته JSON خام برگردد (بدون re-encode).',
            ];
        }

        $originalRaw = (string) ($parsed['xraySettingRaw'] ?? '');
        if ($originalRaw === '') {
            return ['ok' => false, 'error' => 'xraySetting خام از پنل دریافت نشد'];
        }

        $split = XrayRoutingRules::splitProtectedRules($parsed['rules']);
        try {
            $merged = XrayRoutingRules::mergeRulesForSave($split['protected'], $editableRules);
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $validationConfig = XraySettingJson::configArrayForValidation(XraySettingJson::decodeRoot($originalRaw));
        $validation = XrayRoutingRules::validateRulesForConfig(
            $validationConfig,
            $merged,
            $parsed['inboundTags'],
            $split['editable'],
        );
        if ($validation !== []) {
            return ['ok' => false, 'error' => implode('؛ ', $validation)];
        }

        try {
            $backupFile = RoutingBackupService::backupPanelSettings($panelId, $originalRaw, $config);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'بک‌آپ قبل از ذخیره ناموفق: ' . $e->getMessage()];
        }

        try {
            $newRaw = self::rulesListUnchanged($parsed['rules'], $merged)
                ? $originalRaw
                : XraySettingJson::replaceRoutingRules($originalRaw, $merged);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'backup_file' => $backupFile];
        }

        if (XraySettingJson::containsForbiddenEmptyArrayConversion($originalRaw, $newRaw)) {
            return [
                'ok' => false,
                'error' => 'تبدیل ممنوع object خالی ({}) به array ([]) در xraySetting تشخیص داده شد',
                'backup_file' => $backupFile,
            ];
        }

        $update = $client->updateXrayTemplate($newRaw, $parsed['outboundTestUrl']);
        $updateOk = (bool) ($update['ok'] ?? false);
        $health = XrayHealthVerifier::verify($client);
        $currentRaw = RoutingRollbackCoordinator::fetchPersistableRaw($client);
        $drifted = RoutingRollbackCoordinator::configDrifted($originalRaw, $currentRaw);
        $healthOk = (bool) ($health['ok'] ?? false);

        $needsRollback = $drifted || !$healthOk;
        if (!$updateOk && !$needsRollback) {
            return [
                'ok' => false,
                'error' => (string) ($update['error'] ?? 'ذخیره در 3x-ui ناموفق'),
                'backup_file' => $backupFile,
            ];
        }

        if ($needsRollback || !$updateOk) {
            $reason = !$updateOk
                ? (string) ($update['error'] ?? 'ذخیره در 3x-ui ناموفق')
                : ($health['error'] ?? 'health check failed');
            if ($drifted) {
                $reason .= '؛ تغییر ناخواسته در xraySetting تشخیص داده شد';
            }

            $restore = RoutingRollbackCoordinator::restoreOriginalAndVerify(
                $client,
                $originalRaw,
                $parsed['outboundTestUrl'],
            );
            AuditLogService::log('admin', $adminId, 'routing_save_rollback', 'vpn_panel', $panelId, [
                'reason' => $reason,
                'restore' => $restore,
                'backup_file' => $backupFile,
            ]);

            if (!($restore['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'critical' => true,
                    'error' => 'بحرانی: بازگردانی تأیید نشد — '
                        . ($restore['error'] ?? 'rollback failed')
                        . ' — از بک‌آپ raw استفاده کنید',
                    'backup_file' => $backupFile,
                    'rolled_back' => false,
                ];
            }

            return [
                'ok' => false,
                'error' => 'ذخیره ناموفق — تنظیمات قبلی بازگردانده و Xray running تأیید شد (' . $reason . ')',
                'backup_file' => $backupFile,
                'rolled_back' => true,
            ];
        }

        AuditLogService::log('admin', $adminId, 'routing_rules_saved', 'vpn_panel', $panelId, [
            'backup_file' => $backupFile,
            'rule_count' => count($merged),
        ]);

        return ['ok' => true, 'msg' => 'قوانین مسیریابی ذخیره شد', 'backup_file' => $backupFile];
    }

    /**
     * @param array<string, mixed> $panel
     */
    public static function clientForPanel(array $panel, Encryption $encryption): XuiClient
    {
        return new XuiClient(
            (string) $panel['base_url'],
            $encryption->decrypt((string) $panel['api_token_encrypted']),
            45,
        );
    }

    /**
     * @param list<array<string, mixed>> $before
     * @param list<array<string, mixed>> $after
     */
    private static function rulesListUnchanged(array $before, array $after): bool
    {
        if (count($before) !== count($after)) {
            return false;
        }
        foreach ($before as $i => $rule) {
            if (!is_array($rule) || !isset($after[$i]) || !is_array($after[$i])) {
                return false;
            }
            if (XrayRoutingRules::fingerprint($rule) !== XrayRoutingRules::fingerprint($after[$i])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $apiData
     * @return array{
     *   config: array<string, mixed>,
     *   rules: list<array<string, mixed>>,
     *   xraySettingRaw: string,
     *   xraySettingPersistable: bool,
     *   inboundTags: list<string>,
     *   outboundTestUrl: ?string,
     *   error: ?string
     * }
     */
    public static function parseXrayResponse(array $apiData): array
    {
        $empty = [
            'config' => [],
            'rules' => [],
            'xraySettingRaw' => '',
            'xraySettingPersistable' => false,
            'inboundTags' => [],
            'outboundTestUrl' => null,
            'error' => null,
        ];

        $obj = $apiData['obj'] ?? null;
        if (is_string($obj)) {
            $decodedObj = json_decode($obj, true);
            if (!is_array($decodedObj)) {
                return array_merge($empty, ['error' => 'obj پاسخ Xray JSON نامعتبر است']);
            }
            $obj = $decodedObj;
        }
        if (!is_array($obj)) {
            return array_merge($empty, ['error' => 'پاسخ Xray از پنل ناقص است']);
        }

        if (!array_key_exists('xraySetting', $obj)) {
            return array_merge($empty, ['error' => 'xraySetting در پاسخ پنل یافت نشد']);
        }

        $xrayField = $obj['xraySetting'];
        $config = [];
        $raw = '';
        $persistable = false;

        if (is_string($xrayField)) {
            $raw = $xrayField;
            try {
                $root = XraySettingJson::decodeRoot($raw);
            } catch (\Throwable) {
                return array_merge($empty, [
                    'xraySettingRaw' => $raw,
                    'error' => 'JSON قالب Xray نامعتبر است',
                ]);
            }
            $config = XraySettingJson::configArrayForValidation($root);
            $rules = XraySettingJson::rulesFromRoot($root);
            $persistable = true;
        } elseif (is_array($xrayField)) {
            $config = $xrayField;
            $rules = XrayRoutingRules::rulesFromConfig($config);
            $persistable = false;
        } else {
            return array_merge($empty, ['error' => 'xraySetting در پاسخ پنل یافت نشد']);
        }

        $inboundRaw = $obj['inboundTags'] ?? '[]';
        $inboundTags = [];
        if (is_string($inboundRaw)) {
            $decoded = json_decode($inboundRaw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $t) {
                    if (is_string($t)) {
                        $inboundTags[] = $t;
                    }
                }
            }
        }

        $testUrl = isset($obj['outboundTestUrl']) && is_string($obj['outboundTestUrl'])
            ? $obj['outboundTestUrl']
            : null;

        return [
            'config' => $config,
            'rules' => $rules ?? XrayRoutingRules::rulesFromConfig($config),
            'xraySettingRaw' => $raw,
            'xraySettingPersistable' => $persistable,
            'inboundTags' => $inboundTags,
            'outboundTestUrl' => $testUrl,
            'error' => null,
        ];
    }
}
