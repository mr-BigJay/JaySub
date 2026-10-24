<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Encryption;
use App\Xui\XrayRoutingRules;

final class BulkRoutingService
{
    /**
     * @param list<int> $panelIds
     * @param array<string, mixed> $config
     * @return array{panels: list<array<string, mixed>>}
     */
    public static function preview(
        string $operation,
        array $panelIds,
        Encryption $encryption,
        array $config,
        ?array $rule = null,
        ?array $match = null,
        ?string $direction = null,
    ): array {
        $results = [];
        foreach (self::normalizePanelIds($panelIds) as $panelId) {
            $results[] = self::previewOne($operation, $panelId, $encryption, $config, $rule, $match, $direction);
        }

        return ['panels' => $results];
    }

    /**
     * @param list<int> $panelIds
     * @param array<string, mixed> $config
     * @return array{results: list<array<string, mixed>>}
     */
    public static function apply(
        string $operation,
        array $panelIds,
        Encryption $encryption,
        array $config,
        ?int $adminId = null,
        ?array $rule = null,
        ?array $match = null,
        ?string $direction = null,
    ): array {
        $results = [];
        foreach (self::normalizePanelIds($panelIds) as $panelId) {
            try {
                $results[] = self::applyOne(
                    $operation,
                    $panelId,
                    $encryption,
                    $config,
                    $adminId,
                    $rule,
                    $match,
                    $direction,
                );
            } catch (\Throwable $e) {
                $results[] = [
                    'panel_id' => $panelId,
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ];
            }
        }

        AuditLogService::log('admin', $adminId, 'routing_bulk_' . $operation, 'routing', null, [
            'panel_ids' => self::normalizePanelIds($panelIds),
            'results' => array_map(static fn ($r) => [
                'panel_id' => $r['panel_id'] ?? 0,
                'status' => $r['status'] ?? 'unknown',
            ], $results),
        ]);

        return ['results' => $results];
    }

    /**
     * @param list<int> $panelIds
     * @return list<int>
     */
    private static function normalizePanelIds(array $panelIds): array
    {
        $ids = [];
        foreach ($panelIds as $id) {
            $i = (int) $id;
            if ($i > 0) {
                $ids[$i] = $i;
            }
        }

        return array_values($ids);
    }

    /** @param array<string, mixed> $config @return array<string, mixed> */
    private static function previewOne(
        string $operation,
        int $panelId,
        Encryption $encryption,
        array $config,
        ?array $rule,
        ?array $match,
        ?string $direction,
    ): array {
        $panel = PanelService::findById($panelId);
        if ($panel === null) {
            return self::row($panelId, '', 'skipped', 'پنل یافت نشد');
        }

        $name = (string) ($panel['name'] ?? ('#' . $panelId));
        $state = PanelRoutingService::fetchPanelRouting($panelId, $encryption, $config);
        if (!($state['ok'] ?? false)) {
            return self::row($panelId, $name, 'skipped', (string) ($state['error'] ?? 'خواندن routing ناموفق'));
        }

        $editable = self::editableFromSummaries($state['rules'] ?? []);
        $change = self::mutateEditable($operation, $editable, $rule, $match, $direction);
        if ($change['error'] !== null) {
            return self::row($panelId, $name, 'skipped', $change['error']);
        }

        return [
            'panel_id' => $panelId,
            'panel_name' => $name,
            'status' => 'preview',
            'message' => $change['message'],
            'before_count' => count($editable),
            'after_count' => count($change['rules']),
        ];
    }

    /** @param array<string, mixed> $config @return array<string, mixed> */
    private static function applyOne(
        string $operation,
        int $panelId,
        Encryption $encryption,
        array $config,
        ?int $adminId,
        ?array $rule,
        ?array $match,
        ?string $direction,
    ): array {
        $panel = PanelService::findById($panelId);
        if ($panel === null) {
            return self::row($panelId, '', 'failed', 'پنل یافت نشد');
        }
        $name = (string) ($panel['name'] ?? ('#' . $panelId));

        $state = PanelRoutingService::fetchPanelRouting($panelId, $encryption, $config);
        if (!($state['ok'] ?? false)) {
            return self::row($panelId, $name, 'failed', (string) ($state['error'] ?? 'خواندن routing ناموفق'));
        }

        $editable = self::editableFromSummaries($state['rules'] ?? []);
        $change = self::mutateEditable($operation, $editable, $rule, $match, $direction);
        if ($change['error'] !== null) {
            return self::row($panelId, $name, 'skipped', $change['error']);
        }

        $save = PanelRoutingService::saveEditableRules($panelId, $change['rules'], $encryption, $config, $adminId);
        if (!($save['ok'] ?? false)) {
            return self::row($panelId, $name, 'failed', (string) ($save['error'] ?? 'ذخیره ناموفق'));
        }

        return [
            'panel_id' => $panelId,
            'panel_name' => $name,
            'status' => 'success',
            'message' => (string) ($save['msg'] ?? 'OK'),
            'backup_file' => $save['backup_file'] ?? null,
        ];
    }

    /**
     * @param list<array<string, mixed>> $summaries
     * @return list<array<string, mixed>>
     */
    private static function editableFromSummaries(array $summaries): array
    {
        $editable = [];
        foreach ($summaries as $row) {
            if (!empty($row['protected'])) {
                continue;
            }
            $raw = $row['raw'] ?? null;
            if (is_array($raw)) {
                $editable[] = $raw;
            }
        }

        return $editable;
    }

    /**
     * @param list<array<string, mixed>> $editable
     * @param array<string, mixed>|null $rule
     * @param array<string, mixed>|null $match
     * @return array{rules: list<array<string, mixed>>, message: string, error: ?string}
     */
    private static function mutateEditable(
        string $operation,
        array $editable,
        ?array $rule,
        ?array $match,
        ?string $direction,
    ): array {
        $operation = strtolower(trim($operation));
        if ($operation === 'add') {
            if ($rule === null || $rule === []) {
                return ['rules' => $editable, 'message' => '', 'error' => 'قانون جدید مشخص نشده'];
            }
            if (XrayRoutingRules::isProtectedRoutingRule($rule)) {
                return ['rules' => $editable, 'message' => '', 'error' => 'افزودن قانون محافظت‌شده API مجاز نیست'];
            }
            $next = XrayRoutingRules::dedupeRules(array_merge($editable, [$rule]));

            return ['rules' => $next, 'message' => 'افزودن یک قانون', 'error' => null];
        }

        if ($operation === 'remove') {
            if ($match === null || $match === []) {
                return ['rules' => $editable, 'message' => '', 'error' => 'معیار حذف مشخص نشده'];
            }
            $idx = XrayRoutingRules::findEditableIndex($editable, $match);
            if ($idx === null) {
                return ['rules' => $editable, 'message' => '', 'error' => 'قانون مطابق در این پنل یافت نشد'];
            }
            array_splice($editable, $idx, 1);

            return ['rules' => $editable, 'message' => 'حذف یک قانون', 'error' => null];
        }

        if ($operation === 'move') {
            if ($match === null || $match === []) {
                return ['rules' => $editable, 'message' => '', 'error' => 'معیار جابجایی مشخص نشده'];
            }
            $idx = XrayRoutingRules::findEditableIndex($editable, $match);
            if ($idx === null) {
                return ['rules' => $editable, 'message' => '', 'error' => 'قانون مطابق در این پنل یافت نشد'];
            }
            $dir = strtolower((string) $direction);
            if ($dir === 'up') {
                if ($idx <= 0) {
                    return ['rules' => $editable, 'message' => '', 'error' => 'قانون در بالاترین اولویت است'];
                }
                $tmp = $editable[$idx - 1];
                $editable[$idx - 1] = $editable[$idx];
                $editable[$idx] = $tmp;

                return ['rules' => $editable, 'message' => 'انتقال به بالا', 'error' => null];
            }
            if ($dir === 'down') {
                if ($idx >= count($editable) - 1) {
                    return ['rules' => $editable, 'message' => '', 'error' => 'قانون در پایین‌ترین اولویت است'];
                }
                $tmp = $editable[$idx + 1];
                $editable[$idx + 1] = $editable[$idx];
                $editable[$idx] = $tmp;

                return ['rules' => $editable, 'message' => 'انتقال به پایین', 'error' => null];
            }

            return ['rules' => $editable, 'message' => '', 'error' => 'جهت جابجایی نامعتبر'];
        }

        return ['rules' => $editable, 'message' => '', 'error' => 'عملیات گروهی نامعتبر'];
    }

    /** @return array<string, mixed> */
    private static function row(int $panelId, string $name, string $status, string $message): array
    {
        return [
            'panel_id' => $panelId,
            'panel_name' => $name,
            'status' => $status,
            'message' => $message,
        ];
    }
}
