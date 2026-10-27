<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Encryption;
use App\Xui\OutboundJsonTree;
use App\Xui\XrayOutbounds;

final class BulkOutboundService
{
    /**
     * @param list<int|string> $targetPanelIds
     * @param array<string, mixed> $config
     * @return array{panels: list<array<string, mixed>>}
     */
    public static function previewCopy(
        int $sourcePanelId,
        array $targetPanelIds,
        string $sourceTag,
        Encryption $encryption,
        array $config,
    ): array {
        $outbound = self::loadSourceOutbound($sourcePanelId, $sourceTag, $encryption);
        if ($outbound === null) {
            return ['panels' => [[
                'panel_id' => $sourcePanelId,
                'status' => 'invalid',
                'message' => 'Outbound مبدأ یافت نشد',
            ]]];
        }

        return ['panels' => self::previewRows($targetPanelIds, $outbound, $encryption)];
    }

    /**
     * @param list<int|string> $targetPanelIds
     * @return array{panels: list<array<string, mixed>>}
     */
    public static function previewApplyJson(
        array $targetPanelIds,
        object $outbound,
        Encryption $encryption,
    ): array {
        $err = OutboundJsonTree::validateNew($outbound);
        if ($err !== null) {
            return ['panels' => [[
                'panel_id' => 0,
                'status' => 'invalid',
                'message' => $err,
            ]]];
        }

        return ['panels' => self::previewRows($targetPanelIds, $outbound, $encryption)];
    }

    /**
     * @param list<int|string> $targetPanelIds
     * @param array<string, mixed> $config
     * @return array{results: list<array<string, mixed>>, stopped?: bool}
     */
    public static function applyCopy(
        int $sourcePanelId,
        array $targetPanelIds,
        string $sourceTag,
        Encryption $encryption,
        array $config,
        ?int $adminId = null,
    ): array {
        OutboundSaveGuard::assertPersistAllowed();

        $outbound = self::loadSourceOutbound($sourcePanelId, $sourceTag, $encryption);
        if ($outbound === null) {
            return ['results' => [[
                'panel_id' => $sourcePanelId,
                'status' => 'invalid',
                'message' => 'Outbound مبدأ یافت نشد',
            ]]];
        }

        return self::applyToPanels($targetPanelIds, $outbound, $encryption, $config, $adminId);
    }

    /**
     * @param list<int|string> $targetPanelIds
     * @param array<string, mixed> $config
     * @return array{results: list<array<string, mixed>>, stopped?: bool}
     */
    public static function applyJson(
        array $targetPanelIds,
        object $outbound,
        Encryption $encryption,
        array $config,
        ?int $adminId = null,
    ): array {
        OutboundSaveGuard::assertPersistAllowed();

        $err = OutboundJsonTree::validateNew($outbound);
        if ($err !== null) {
            return ['results' => [[
                'panel_id' => 0,
                'status' => 'invalid',
                'message' => $err,
            ]]];
        }

        return self::applyToPanels($targetPanelIds, $outbound, $encryption, $config, $adminId);
    }

    /**
     * @param list<int|string> $targetPanelIds
     * @param array<string, mixed> $config
     * @return array{results: list<array<string, mixed>>, stopped?: bool}
     */
    private static function applyToPanels(
        array $targetPanelIds,
        object $outbound,
        Encryption $encryption,
        array $config,
        ?int $adminId,
    ): array {
        $results = [];
        foreach (self::normalizePanelIds($targetPanelIds) as $pid) {
            $preview = self::previewRow($pid, $outbound, $encryption);
            if (($preview['status'] ?? '') !== 'ready') {
                $results[] = $preview + ['ok' => ($preview['status'] ?? '') === 'already_exists'];

                continue;
            }

            $save = PanelOutboundService::addOutbound($pid, OutboundJsonTree::cloneNode($outbound), $encryption, $config, $adminId);
            if (!($save['ok'] ?? false) && ($save['critical'] ?? false)) {
                $results[] = [
                    'panel_id' => $pid,
                    'panel_name' => $preview['panel_name'] ?? '',
                    'status' => 'critical',
                    'message' => $save['error'] ?? 'rollback critical',
                    'ok' => false,
                ];

                return ['results' => $results, 'stopped' => true];
            }

            $status = ($save['skipped'] ?? false) ? 'already_exists' : (($save['ok'] ?? false) ? 'success' : 'failed');
            $results[] = [
                'panel_id' => $pid,
                'panel_name' => $preview['panel_name'] ?? '',
                'status' => $status,
                'message' => $save['msg'] ?? $save['error'] ?? '',
                'ok' => (bool) ($save['ok'] ?? false),
                'rolled_back' => $save['rolled_back'] ?? false,
            ];
        }

        return ['results' => $results];
    }

    /**
     * @param list<int|string> $targetPanelIds
     * @return list<array<string, mixed>>
     */
    private static function previewRows(array $targetPanelIds, object $outbound, Encryption $encryption): array
    {
        $rows = [];
        foreach (self::normalizePanelIds($targetPanelIds) as $pid) {
            $rows[] = self::previewRow($pid, $outbound, $encryption);
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private static function previewRow(int $panelId, object $outbound, Encryption $encryption): array
    {
        $panel = PanelService::findById($panelId);
        $name = (string) ($panel['name'] ?? '');
        if ($panel === null) {
            return ['panel_id' => $panelId, 'panel_name' => $name, 'status' => 'invalid', 'message' => 'پنل یافت نشد'];
        }

        $client = PanelRoutingService::clientForPanel($panel, $encryption);
        $status = $client->getServerStatus();
        if (!($status['ok'] ?? false)) {
            return [
                'panel_id' => $panelId,
                'panel_name' => $name,
                'status' => 'connection_failed',
                'message' => (string) ($status['error'] ?? 'اتصال ناموفق'),
            ];
        }

        $snapshot = PanelOutboundService::fetchPersistableSnapshot($client);
        if ($snapshot === null) {
            return [
                'panel_id' => $panelId,
                'panel_name' => $name,
                'status' => 'connection_failed',
                'message' => 'خواندن xraySetting ناموفق',
            ];
        }

        $tag = OutboundJsonTree::tag($outbound);
        $class = PanelOutboundService::classifyTagOnConfig($snapshot['raw'], $tag, $outbound);
        $statusMap = [
            'ready' => ['status' => 'ready', 'message' => 'آماده افزودن'],
            'exists' => ['status' => 'already_exists', 'message' => 'Already Exists'],
            'conflict' => ['status' => 'tag_conflict', 'message' => 'Conflict — Tag با تنظیمات متفاوت'],
            'invalid' => ['status' => 'invalid', 'message' => 'Tag نامعتبر یا محافظت‌شده'],
        ];
        $row = $statusMap[$class] ?? $statusMap['invalid'];

        return ['panel_id' => $panelId, 'panel_name' => $name] + $row;
    }

    private static function loadSourceOutbound(int $sourcePanelId, string $sourceTag, Encryption $encryption): ?object
    {
        $panel = PanelService::findById($sourcePanelId);
        if ($panel === null) {
            return null;
        }
        $client = PanelRoutingService::clientForPanel($panel, $encryption);
        $snapshot = PanelOutboundService::fetchPersistableSnapshot($client);
        if ($snapshot === null) {
            return null;
        }

        return PanelOutboundService::loadTemplateOutboundNode($snapshot['raw'], trim($sourceTag));
    }

    /** @param list<int|string> $ids @return list<int> */
    private static function normalizePanelIds(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $pid = (int) $id;
            if ($pid > 0) {
                $out[] = $pid;
            }
        }

        return array_values(array_unique($out));
    }
}
