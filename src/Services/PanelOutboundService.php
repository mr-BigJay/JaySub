<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Encryption;
use App\Xui\OutboundRedactor;
use App\Xui\XrayOutbounds;
use App\Xui\XraySettingJson;
use App\Xui\XraySettingOutboundJson;
use App\Xui\XrayTemplateParser;
use App\Xui\XuiClient;

final class PanelOutboundService
{
    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function fetchPanelOutbounds(int $panelId, Encryption $encryption, array $config): array
    {
        $panel = PanelService::findById($panelId);
        if ($panel === null) {
            return ['ok' => false, 'error' => 'پنل یافت نشد'];
        }

        $client = PanelRoutingService::clientForPanel($panel, $encryption);
        $status = $client->getServerStatus();
        $connectionOk = (bool) ($status['ok'] ?? false);

        $xray = $client->fetchXrayTemplate();
        if (!($xray['ok'] ?? false)) {
            return [
                'ok' => false,
                'panel_id' => $panelId,
                'connection_ok' => $connectionOk,
                'error' => (string) ($xray['error'] ?? 'خواندن Xray ناموفق'),
            ];
        }

        $parsed = self::parseFetch($xray);
        if ($parsed['error'] !== null) {
            return ['ok' => false, 'panel_id' => $panelId, 'connection_ok' => $connectionOk, 'error' => $parsed['error']];
        }

        $root = XraySettingJson::decodeRoot($parsed['xraySettingRaw']);
        $rawList = XrayOutbounds::outboundsFromRoot($root);
        $summaries = [];
        $prio = 1;
        foreach ($rawList as $ob) {
            $summaries[] = XrayOutbounds::summarize($ob, $prio++);
        }

        $template = array_values(array_filter($summaries, static fn (array $s): bool => ($s['kind'] ?? '') === 'template'));
        $subscription = array_values(array_filter($summaries, static fn (array $s): bool => ($s['kind'] ?? '') === 'subscription'));

        return [
            'ok' => true,
            'panel_id' => $panelId,
            'connection_ok' => $connectionOk,
            'outbounds' => $summaries,
            'template_outbounds' => $template,
            'subscription_outbounds' => $subscription,
            'persist_enabled' => OutboundSaveGuard::PERSIST_ENABLED,
        ];
    }

    /**
     * @param array<string, mixed> $outbound
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function addOutbound(
        int $panelId,
        array $outbound,
        Encryption $encryption,
        array $config,
        ?int $adminId = null,
    ): array {
        OutboundSaveGuard::assertPersistAllowed();

        $err = XrayOutbounds::validateNewOutbound($outbound);
        if ($err !== null) {
            return ['ok' => false, 'error' => $err];
        }

        $panel = PanelService::findById($panelId);
        if ($panel === null) {
            return ['ok' => false, 'error' => 'پنل یافت نشد'];
        }

        $client = PanelRoutingService::clientForPanel($panel, $encryption);
        $snapshot = self::fetchPersistableSnapshot($client);
        if ($snapshot === null) {
            return ['ok' => false, 'error' => 'خواندن xraySetting ناموفق'];
        }

        $tag = XrayOutbounds::tagOf($outbound);
        $conflict = self::classifyTagOnConfig($snapshot['raw'], $tag, $outbound);
        if ($conflict === 'exists') {
            return ['ok' => true, 'skipped' => true, 'status' => 'already_exists', 'msg' => 'Tag از قبل وجود دارد'];
        }
        if ($conflict === 'conflict') {
            return ['ok' => false, 'error' => 'Tag موجود است ولی تنظیمات متفاوت است (Conflict)'];
        }

        $fresh = self::fetchPersistableSnapshot($client);
        if ($fresh === null || !XraySettingJson::configsStructurallyEqual($snapshot['raw'], $fresh['raw'])) {
            return ['ok' => false, 'error' => 'نسخه xraySetting پنل تغییر کرده — ذخیره لغو شد'];
        }

        try {
            $backupFile = OutboundBackupService::backupPanelSettings($panelId, $snapshot['raw'], $config);
            $newRaw = XraySettingOutboundJson::appendOutbound($snapshot['raw'], $outbound);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        return OutboundSaveWorkflow::persistAfterBackup(
            $client,
            $snapshot['raw'],
            $newRaw,
            $snapshot['outboundTestUrl'],
            $backupFile,
            $panelId,
            $adminId,
        );
    }

    /**
     * @param array<string, mixed> $outbound
     * @return 'ready'|'exists'|'conflict'|'invalid'
     */
    public static function classifyTagOnConfig(string $raw, string $tag, array $outbound): string
    {
        if ($tag === '' || XrayOutbounds::isProtectedTag($tag)) {
            return 'invalid';
        }
        try {
            $root = XraySettingJson::decodeRoot($raw);
        } catch (\Throwable) {
            return 'invalid';
        }
        foreach (XrayOutbounds::outboundsFromRoot($root) as $existing) {
            if (XrayOutbounds::tagOf($existing) !== $tag) {
                continue;
            }
            if (XrayOutbounds::fingerprint($existing) === XrayOutbounds::fingerprint($outbound)) {
                return 'exists';
            }

            return 'conflict';
        }

        return 'ready';
    }

    /** @return array{raw: string, outboundTestUrl: ?string}|null */
    public static function fetchPersistableSnapshot(XuiClient $client): ?array
    {
        $xray = $client->fetchXrayTemplate();
        if (!($xray['ok'] ?? false)) {
            return null;
        }
        $parsed = self::parseFetch($xray);
        if ($parsed['error'] !== null || !($parsed['xraySettingPersistable'] ?? false)) {
            return null;
        }
        $raw = (string) ($parsed['xraySettingRaw'] ?? '');
        if ($raw === '') {
            return null;
        }

        return ['raw' => $raw, 'outboundTestUrl' => $parsed['outboundTestUrl']];
    }

    /**
     * @param array<string, mixed> $fetchResult
     * @return array<string, mixed>
     */
    private static function parseFetch(array $fetchResult): array
    {
        return XrayTemplateParser::parseFromFetchPayload(
            is_array($fetchResult['data'] ?? null) ? $fetchResult['data'] : [],
            isset($fetchResult['data_object']) && $fetchResult['data_object'] instanceof \stdClass
                ? $fetchResult['data_object']
                : null,
        );
    }

    /** @param array<string, mixed> $outbound @return array<string, mixed> */
    public static function parseOutboundJsonInput(mixed $input): array
    {
        if (is_string($input)) {
            $decoded = json_decode(trim($input), true);
            if (!is_array($decoded)) {
                throw new \InvalidArgumentException('JSON outbound نامعتبر است');
            }

            return $decoded;
        }
        if (is_array($input)) {
            return $input;
        }

        throw new \InvalidArgumentException('outbound باید JSON object باشد');
    }
}
