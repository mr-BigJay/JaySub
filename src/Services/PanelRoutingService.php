<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Encryption;
use App\Xui\XrayRoutingRules;
use App\Xui\XraySettingJson;
use App\Xui\XrayTemplateParser;
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

        $parsed = self::parseXrayFetch($xray);
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

        $parsed = self::parseXrayFetch($xray);
        if ($parsed['error'] !== null) {
            return ['ok' => false, 'error' => $parsed['error']];
        }
        if (!($parsed['xraySettingPersistable'] ?? false)) {
            return ['ok' => false, 'error' => 'xraySetting از پنل قابل ذخیرهٔ type-safe نیست'];
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
            $newRaw = self::rulesListUnchanged($parsed['rules'], $merged)
                ? $originalRaw
                : XraySettingJson::replaceRoutingRules($originalRaw, $merged);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        if ($newRaw === $originalRaw) {
            return ['ok' => true, 'msg' => 'تغییری در قوانین نبود — ذخیره انجام نشد'];
        }

        try {
            $backupFile = RoutingBackupService::backupPanelSettings($panelId, $originalRaw, $config);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'بک‌آپ قبل از ذخیره ناموفق: ' . $e->getMessage()];
        }

        return RoutingSaveWorkflow::persistAfterBackup(
            $client,
            $originalRaw,
            $newRaw,
            $parsed['outboundTestUrl'],
            $backupFile,
            $panelId,
            $adminId,
        );
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
        return XrayTemplateParser::parseFromDecodedArray($apiData);
    }

    /**
     * @param array<string, mixed> $fetchResult
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
    private static function parseXrayFetch(array $fetchResult): array
    {
        return XrayTemplateParser::parseFromFetchPayload(
            is_array($fetchResult['data'] ?? null) ? $fetchResult['data'] : [],
            isset($fetchResult['data_object']) && $fetchResult['data_object'] instanceof \stdClass
                ? $fetchResult['data_object']
                : null,
        );
    }
}
