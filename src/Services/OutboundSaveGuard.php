<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Fail-closed write gate for Xray Outbound settings.
 *
 * Full fleet persistence remains locked. A non-production canary may be
 * explicitly authorized in the server-side config, for exactly one panel.
 * Never enable all-panel writes by flipping PERSIST_ENABLED.
 */
final class OutboundSaveGuard
{
    /** Hard lock on fleet / unrestricted persistence. */
    public const PERSIST_ENABLED = false;

    /** @param array<string, mixed> $config */
    public static function canaryPanelId(array $config): ?int
    {
        $settings = $config['outbound_persistence'] ?? null;
        if (!is_array($settings)
            || ($settings['mode'] ?? '') !== 'canary'
            || ($settings['nonproduction_confirmed'] ?? false) !== true) {
            return null;
        }

        $rawId = $settings['canary_panel_id'] ?? null;
        if (!(is_int($rawId) || (is_string($rawId) && ctype_digit($rawId)))) {
            return null;
        }
        $id = (int) $rawId;

        return $id > 0 ? $id : null;
    }

    /**
     * @param list<int|string> $targetIds
     * @param array<string, mixed> $config
     */
    public static function assertTargetsAllowed(array $targetIds, array $config): void
    {
        $allowed = self::canaryPanelId($config);
        $ids = array_values(array_unique(array_map(static fn ($id): int => (int) $id, $targetIds)));

        if ($allowed === null || count($ids) !== 1 || $ids[0] !== $allowed) {
            throw new \RuntimeException(
                'ذخیره اوتباند برای این مجموعه پنل‌ها قفل است؛ فقط یک پنل غیرعملیاتیِ مجاز در حالت Canary می‌تواند تغییر کند.',
            );
        }
    }

    /** @param array<string, mixed> $config */
    public static function assertPersistAllowed(?int $panelId = null, array $config = []): void
    {
        if ($panelId === null) {
            throw new \RuntimeException('ذخیره سراسری Outbound غیرفعال است.');
        }

        self::assertTargetsAllowed([$panelId], $config);
    }
}
