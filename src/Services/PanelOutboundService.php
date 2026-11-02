<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Encryption;
use App\Xui\OutboundJsonTree;
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
        $subTags = is_array($parsed['subscriptionOutboundTags'] ?? null) ? $parsed['subscriptionOutboundTags'] : [];
        $subObs = is_array($parsed['subscriptionOutbounds'] ?? null) ? $parsed['subscriptionOutbounds'] : [];
        $lists = XrayOutbounds::buildUiOutboundLists($root, $subObs, $subTags);
        $template = $lists['template'];
        $subscription = $lists['subscription'];
        $summaries = array_merge($template, $subscription);

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
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function addOutbound(
        int $panelId,
        object $outbound,
        Encryption $encryption,
        array $config,
        ?int $adminId = null,
    ): array {
        OutboundSaveGuard::assertPersistAllowed();

        $err = OutboundJsonTree::validateStructure($outbound);
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

        $tag = OutboundJsonTree::tag($outbound);
        $conflict = self::classifyTagOnConfig(
            $snapshot['raw'],
            $tag,
            $outbound,
            $snapshot['subscriptionOutboundTags'],
        );
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
     * @param list<string> $subscriptionOutboundTags
     * @return 'ready'|'exists'|'conflict'|'invalid'
     */
    public static function classifyTagOnConfig(
        string $raw,
        string $tag,
        object $outbound,
        array $subscriptionOutboundTags = [],
    ): string {
        if ($tag === '' || XrayOutbounds::isProtectedTag($tag)) {
            return 'invalid';
        }
        if (in_array($tag, $subscriptionOutboundTags, true)) {
            return 'conflict';
        }
        try {
            $root = XraySettingJson::decodeRoot($raw);
        } catch (\Throwable) {
            return 'invalid';
        }
        foreach (OutboundJsonTree::templateNodesFromRoot($root) as $existing) {
            if (OutboundJsonTree::tag($existing) !== $tag) {
                continue;
            }
            if (OutboundJsonTree::fingerprint($existing) === OutboundJsonTree::fingerprint($outbound)) {
                return 'exists';
            }

            return 'conflict';
        }

        return 'ready';
    }

    /**
     * @return array{
     *   raw: string,
     *   outboundTestUrl: ?string,
     *   subscriptionOutboundTags: list<string>
     * }|null
     */
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
        $subTags = is_array($parsed['subscriptionOutboundTags'] ?? null) ? $parsed['subscriptionOutboundTags'] : [];

        return [
            'raw' => $raw,
            'outboundTestUrl' => $parsed['outboundTestUrl'],
            'subscriptionOutboundTags' => $subTags,
        ];
    }

    public static function loadTemplateOutboundNode(string $raw, string $tag): ?object
    {
        try {
            $root = XraySettingJson::decodeRoot($raw);
        } catch (\Throwable) {
            return null;
        }
        foreach (OutboundJsonTree::templateNodesFromRoot($root) as $node) {
            if (OutboundJsonTree::tag($node) === $tag) {
                return OutboundJsonTree::cloneNode($node);
            }
        }

        return null;
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

    public static function parseOutboundJsonInput(mixed $outboundJson): object
    {
        if (is_array($outboundJson)) {
            throw new \InvalidArgumentException(
                'outbound_json باید رشته JSON خام باشد (object/array PHP نوع را حفظ نمی‌کند)',
            );
        }
        if (!is_string($outboundJson) || trim($outboundJson) === '') {
            throw new \InvalidArgumentException('outbound_json الزامی است');
        }

        $node = OutboundJsonTree::decodeOutboundString($outboundJson);
        $structure = OutboundJsonTree::validateStructure($node);
        if ($structure !== null) {
            throw new \InvalidArgumentException($structure);
        }

        return $node;
    }
}
