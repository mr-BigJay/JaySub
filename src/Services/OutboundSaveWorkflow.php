<?php

declare(strict_types=1);

namespace App\Services;

use App\Xui\XrayPanelClientInterface;
use App\Xui\XraySettingJson;
use App\Xui\XraySettingOutboundJson;
use App\Xui\XrayTemplateParser;

final class OutboundSaveWorkflow
{
    /**
     * @return array{ok: bool, error?: string, msg?: string, backup_file?: string, rolled_back?: bool, critical?: bool, noop?: bool}
     */
    public static function persistAfterBackup(
        XrayPanelClientInterface $client,
        string $originalRaw,
        string $newRaw,
        ?string $outboundTestUrl,
        string $backupFile,
        ?int $panelId = null,
        ?int $adminId = null,
    ): array {
        if ($newRaw === $originalRaw) {
            return ['ok' => true, 'msg' => 'تغییری نبود', 'noop' => true];
        }

        $appendCheck = XraySettingOutboundJson::assertPreservedOutsideOutbounds($originalRaw, $newRaw);
        if ($appendCheck !== null) {
            return ['ok' => false, 'error' => $appendCheck, 'backup_file' => $backupFile];
        }

        if (XraySettingJson::containsForbiddenEmptyArrayConversion($originalRaw, $newRaw)) {
            return [
                'ok' => false,
                'error' => 'تبدیل ممنوع object خالی ({}) به array ([])',
                'backup_file' => $backupFile,
            ];
        }

        $update = $client->updateXrayTemplate($newRaw, $outboundTestUrl);
        $updateOk = (bool) ($update['ok'] ?? false);

        if (!$updateOk) {
            return self::handleFailedUpdate(
                $client,
                $originalRaw,
                $outboundTestUrl,
                $backupFile,
                (string) ($update['error'] ?? 'ذخیره در 3x-ui ناموفق'),
                $panelId,
                $adminId,
            );
        }

        return self::handleSuccessfulUpdate(
            $client,
            $originalRaw,
            $newRaw,
            $outboundTestUrl,
            $backupFile,
            $panelId,
            $adminId,
        );
    }

    /** @return array{ok: bool, error?: string, backup_file?: string, rolled_back?: bool, critical?: bool} */
    private static function handleFailedUpdate(
        XrayPanelClientInterface $client,
        string $originalRaw,
        ?string $outboundTestUrl,
        string $backupFile,
        string $updateError,
        ?int $panelId,
        ?int $adminId,
    ): array {
        $health = XrayHealthVerifier::verify($client);
        $currentRaw = self::fetchPersistableRaw($client);
        $changedFromOriginal = RoutingRollbackCoordinator::configDrifted($originalRaw, $currentRaw);
        $healthOk = (bool) ($health['ok'] ?? false);

        if (!$changedFromOriginal && $healthOk) {
            return ['ok' => false, 'error' => $updateError, 'backup_file' => $backupFile];
        }

        return self::rollbackWithAudit(
            $client,
            $originalRaw,
            $outboundTestUrl,
            $backupFile,
            $updateError,
            $panelId,
            $adminId,
        );
    }

    /** @return array{ok: bool, error?: string, msg?: string, backup_file?: string, rolled_back?: bool, critical?: bool} */
    private static function handleSuccessfulUpdate(
        XrayPanelClientInterface $client,
        string $originalRaw,
        string $expectedRaw,
        ?string $outboundTestUrl,
        string $backupFile,
        ?int $panelId,
        ?int $adminId,
    ): array {
        $health = XrayHealthVerifier::verify($client);
        $healthOk = (bool) ($health['ok'] ?? false);
        $currentRaw = self::fetchPersistableRaw($client);
        $matchesExpected = $currentRaw !== null
            && XraySettingJson::configsStructurallyEqual($expectedRaw, $currentRaw);
        $outsideBad = $currentRaw !== null
            && XraySettingOutboundJson::assertPreservedOutsideOutbounds($expectedRaw, $currentRaw) !== null;

        if ($healthOk && $matchesExpected && !$outsideBad) {
            if ($panelId !== null) {
                AuditLogService::log('admin', $adminId, 'outbound_added', 'vpn_panel', $panelId, [
                    'backup_file' => $backupFile,
                ]);
            }

            return ['ok' => true, 'msg' => 'Outbound ذخیره شد', 'backup_file' => $backupFile];
        }

        $reason = !$healthOk
            ? ($health['error'] ?? 'health check failed')
            : 'تنظیمات ذخیره‌شده با مقدار موردانتظار مطابقت ندارد';
        if ($outsideBad) {
            $reason .= '؛ تغییر خارج از outbounds';
        }

        return self::rollbackWithAudit(
            $client,
            $originalRaw,
            $outboundTestUrl,
            $backupFile,
            $reason,
            $panelId,
            $adminId,
        );
    }

    /** @return array{ok: bool, error?: string, backup_file?: string, rolled_back?: bool, critical?: bool} */
    private static function rollbackWithAudit(
        XrayPanelClientInterface $client,
        string $originalRaw,
        ?string $outboundTestUrl,
        string $backupFile,
        string $reason,
        ?int $panelId,
        ?int $adminId,
    ): array {
        $restore = RoutingRollbackCoordinator::restoreOriginalAndVerify(
            $client,
            $originalRaw,
            $outboundTestUrl,
        );
        if ($panelId !== null) {
            AuditLogService::log('admin', $adminId, 'outbound_save_rollback', 'vpn_panel', $panelId, [
                'reason' => $reason,
                'restore' => $restore,
                'backup_file' => $backupFile,
            ]);
        }

        if (!($restore['ok'] ?? false)) {
            return [
                'ok' => false,
                'critical' => true,
                'error' => 'بحرانی: بازگردانی تأیید نشد — ' . ($restore['error'] ?? 'rollback failed'),
                'backup_file' => $backupFile,
                'rolled_back' => false,
            ];
        }

        return [
            'ok' => false,
            'error' => 'ذخیره ناموفق — بازگردانی انجام شد (' . $reason . ')',
            'backup_file' => $backupFile,
            'rolled_back' => true,
        ];
    }

    public static function fetchPersistableRaw(XrayPanelClientInterface $client): ?string
    {
        return RoutingSaveWorkflow::fetchPersistableRaw($client);
    }
}
