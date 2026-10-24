<?php

declare(strict_types=1);

namespace App\Services;

use App\Xui\XrayPanelClientInterface;
use App\Xui\XraySettingJson;

/** Restore raw xraySetting and verify config + xray.state after failed save. */
final class RoutingRollbackCoordinator
{
    /**
     * @return array{
     *   ok: bool,
     *   critical?: bool,
     *   error?: string,
     *   config_restored?: bool,
     *   xray_running?: bool,
     *   http_ok?: bool
     * }
     */
    public static function restoreOriginalAndVerify(
        XrayPanelClientInterface $client,
        string $originalRaw,
        ?string $outboundTestUrl,
    ): array {
        $update = $client->updateXrayTemplate($originalRaw, $outboundTestUrl);
        $httpOk = (bool) ($update['ok'] ?? false);

        $fetched = self::fetchPersistableRaw($client);
        $configRestored = $fetched !== null && XraySettingJson::configsStructurallyEqual($originalRaw, $fetched);

        $health = XrayHealthVerifier::verify($client);
        $xrayRunning = (bool) ($health['ok'] ?? false);

        if ($configRestored && $xrayRunning) {
            return [
                'ok' => true,
                'config_restored' => true,
                'xray_running' => true,
                'http_ok' => $httpOk,
            ];
        }

        $parts = [];
        if (!$configRestored) {
            $parts[] = 'تنظیمات خام به نسخه قبلی بازنگشت نشده';
        }
        if (!$xrayRunning) {
            $parts[] = $health['error'] ?? 'Xray در حالت running نیست';
        }
        if (!$httpOk) {
            $parts[] = (string) ($update['error'] ?? 'درخواست rollback HTTP ناموفق');
        }

        return [
            'ok' => false,
            'critical' => true,
            'error' => implode('؛ ', $parts),
            'config_restored' => $configRestored,
            'xray_running' => $xrayRunning,
            'http_ok' => $httpOk,
        ];
    }

    public static function fetchPersistableRaw(XrayPanelClientInterface $client): ?string
    {
        return RoutingSaveWorkflow::fetchPersistableRaw($client);
    }

    public static function configDrifted(string $originalRaw, ?string $currentRaw): bool
    {
        if ($currentRaw === null) {
            return true;
        }

        return !XraySettingJson::configsStructurallyEqual($originalRaw, $currentRaw);
    }
}
