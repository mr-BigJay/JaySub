<?php

declare(strict_types=1);

namespace App\Services;

/** قطع خودکار کلاینت در 3x-ui هنگام پر شدن سقف — قابل خاموش کردن بدون توقف sync پنل. */
final class QuotaEnforcementService
{
    public const SETTING_KEY = 'quota_enforcement_enabled';

    public static function isEnabled(): bool
    {
        // پیش‌فرض خاموش تا بدون تنظیم صریح، JaySub کسی را در 3x-ui قطع نکند.
        return SettingsService::get(self::SETTING_KEY, '0') === '1';
    }

    public static function setEnabled(bool $enabled): void
    {
        SettingsService::set(self::SETTING_KEY, $enabled ? '1' : '0');
    }
}
