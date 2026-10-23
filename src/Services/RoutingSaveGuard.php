<?php

declare(strict_types=1);

namespace App\Services;

/** Gate routing persistence until regression-safe JSON path is active. */
final class RoutingSaveGuard
{
    /** Set true only when XraySettingJson regression tests pass in CI/local PHPUnit. */
    public const PERSIST_ENABLED = true;

    public static function assertPersistAllowed(): void
    {
        if (!self::PERSIST_ENABLED) {
            throw new \RuntimeException(
                'ذخیره مسیریابی موقتاً غیرفعال است — تست‌های regression JSON در حال تکمیل هستند.',
            );
        }
    }
}
