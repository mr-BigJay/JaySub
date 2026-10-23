<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Encryption;
use App\Xui\XrayRoutingRules;
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
     * @return array{ok: bool, error?: string, backup_file?: string, msg?: string}
     */
    public static function saveEditableRules(
        int $panelId,
        array $editableRules,
        Encryption $encryption,
        array $config,
        ?int $adminId = null,
    ): array {
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

        $split = XrayRoutingRules::splitProtectedRules($parsed['rules']);
        try {
            $merged = XrayRoutingRules::mergeRulesForSave($split['protected'], $editableRules);
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $newConfig = XrayRoutingRules::configWithRules($parsed['config'], $merged);
        $validation = XrayRoutingRules::validateRulesForConfig($newConfig, $merged);
        if ($validation !== []) {
            return ['ok' => false, 'error' => implode('؛ ', $validation)];
        }

        $backupFile = RoutingBackupService::backupPanelSettings($panelId, $parsed['xraySettingRaw'], $config);
        $json = XrayRoutingRules::encodeConfig($newConfig);
        $update = $client->updateXrayTemplate($json, $parsed['outboundTestUrl']);
        if (!($update['ok'] ?? false)) {
            return [
                'ok' => false,
                'error' => (string) ($update['error'] ?? 'ذخیره در 3x-ui ناموفق'),
                'backup_file' => $backupFile,
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
     * @param array<string, mixed> $apiData
     * @return array{
     *   config: array<string, mixed>,
     *   rules: list<array<string, mixed>>,
     *   xraySettingRaw: string,
     *   inboundTags: list<string>,
     *   outboundTestUrl: ?string,
     *   error: ?string
     * }
     */
    public static function parseXrayResponse(array $apiData): array
    {
        $obj = $apiData['obj'] ?? null;
        if (!is_array($obj)) {
            return [
                'config' => [],
                'rules' => [],
                'xraySettingRaw' => '',
                'inboundTags' => [],
                'outboundTestUrl' => null,
                'error' => 'پاسخ Xray از پنل ناقص است',
            ];
        }

        $raw = $obj['xraySetting'] ?? '';
        if (!is_string($raw)) {
            return [
                'config' => [],
                'rules' => [],
                'xraySettingRaw' => '',
                'inboundTags' => [],
                'outboundTestUrl' => null,
                'error' => 'xraySetting در پاسخ پنل یافت نشد',
            ];
        }

        $parsed = XrayRoutingRules::parseConfig($raw);
        if (isset($parsed['error'])) {
            return [
                'config' => [],
                'rules' => [],
                'xraySettingRaw' => $raw,
                'inboundTags' => [],
                'outboundTestUrl' => null,
                'error' => $parsed['error'],
            ];
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
            'config' => $parsed['config'],
            'rules' => XrayRoutingRules::rulesFromConfig($parsed['config']),
            'xraySettingRaw' => $raw,
            'inboundTags' => $inboundTags,
            'outboundTestUrl' => $testUrl,
            'error' => null,
        ];
    }
}
