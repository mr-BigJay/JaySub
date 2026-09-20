<?php

declare(strict_types=1);

namespace App\Services;

/**
 * JaySub فقط مصرف را محاسبه می‌کند — قطع سرویس/سقف غیرفعال است (همیشه).
 */
final class QuotaEnforcementService
{
    public const SETTING_KEY = 'quota_enforcement_enabled';

    public static function isEnabled(): bool
    {
        return false;
    }

    public static function setEnabled(bool $enabled): void
    {
        SettingsService::set(self::SETTING_KEY, '0');
    }
}
