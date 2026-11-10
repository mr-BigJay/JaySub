<?php

declare(strict_types=1);

namespace App\Services;

use App\Xui\XrayPanelClientInterface;
use App\Xui\XraySettingJson;
use App\Xui\XrayTemplateParser;

/** Type-safe routing save with drift checks, health verification, and rollback. */
final class RoutingSaveWorkflow
{
    /**
     * @param list<array<string, mixed>> $mergedRules
     * @return array{
     *   ok: bool,
     *   error?: string,
     *   msg?: string,
     *   backup_file?: string,
     *   rolled_back?: bool,
     *   critical?: bool,
     *   noop?: bool
     * }
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
            return ['ok' => true, 'msg' => 'تغییری در قوانین نبود — ذخیره انجام نشد', 'noop' => true];
        }

        $outsideViolation = XraySettingJson::assertPreservedOutsideRoutingRules($originalRaw, $newRaw);
        if ($outsideViolation !== null) {
            return ['ok' => false, 'error' => $outsideViolation, 'backup_file' => $backupFile];
        }

        if (XraySettingJson::containsForbiddenEmptyArrayConversion($originalRaw, $newRaw)) {
            return [
                'ok' => false,
                'error' => 'تبدیل ممنوع object خالی ({}) به array ([]) در xraySetting تشخیص داده شد',
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

    /**
     * @return array{ok: bool, error?: string, backup_file?: string, rolled_back?: bool, critical?: bool}
     */
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
            return [
                'ok' => false,
                'error' => $updateError,
                'backup_file' => $backupFile,
            ];
        }

        return self::rollbackWithAudit(
            $client,
            $originalRaw,
            $outboundTestUrl,
            $backupFile,
            $updateError . ($changedFromOriginal ? '؛ تنظیمات پنل از نسخه اولیه drift کرده' : ''),
            $panelId,
            $adminId,
        );
    }

    /**
     * @return array{ok: bool, error?: string, msg?: string, backup_file?: string, rolled_back?: bool, critical?: bool}
     */
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
        $unexpectedOutsideRules = $currentRaw !== null
            && XraySettingJson::assertPreservedOutsideRoutingRules($expectedRaw, $currentRaw) !== null;

        if ($healthOk && $matchesExpected && !$unexpectedOutsideRules) {
            if ($panelId !== null) {
                AuditLogService::log('admin', $adminId, 'routing_rules_saved', 'vpn_panel', $panelId, [
                    'backup_file' => $backupFile,
                ]);
            }

            return ['ok' => true, 'msg' => 'قوانین مسیریابی ذخیره شد', 'backup_file' => $backupFile];
        }

        $reason = !$healthOk
            ? ($health['error'] ?? 'health check failed')
            : 'تنظیمات ذخیره‌شده با مقدار موردانتظار مطابقت ندارد';
        if ($unexpectedOutsideRules) {
            $reason .= '؛ تغییر خارج از routing.rules تشخیص داده شد';
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

    /**
     * @return array{ok: bool, error?: string, backup_file?: string, rolled_back?: bool, critical?: bool}
     */
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
            AuditLogService::log('admin', $adminId, 'routing_save_rollback', 'vpn_panel', $panelId, [
                'reason' => $reason,
                'restore' => $restore,
                'backup_file' => $backupFile,
            ]);
        }

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

    public static function fetchPersistableRaw(XrayPanelClientInterface $client): ?string
    {
        $xray = $client->fetchXrayTemplate();
        if (!($xray['ok'] ?? false)) {
            return null;
        }
        $parsed = XrayTemplateParser::parseFromFetchPayload(
            is_array($xray['data'] ?? null) ? $xray['data'] : [],
            isset($xray['data_object']) && $xray['data_object'] instanceof \stdClass
                ? $xray['data_object']
                : null,
        );
        if ($parsed['error'] !== null || !($parsed['xraySettingPersistable'] ?? false)) {
            return null;
        }
        $raw = (string) ($parsed['xraySettingRaw'] ?? '');

        return $raw !== '' ? $raw : null;
    }
}
