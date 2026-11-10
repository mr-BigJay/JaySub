<?php

declare(strict_types=1);

namespace App\Services;

/** Gate routing persistence until regression-safe JSON path is active. */
final class RoutingSaveGuard
{
    /** Enable only after separate operational sign-off (default fail-closed). */
    public const PERSIST_ENABLED = false;

    public static function assertPersistAllowed(): void
    {
        if (!self::PERSIST_ENABLED) {
            throw new \RuntimeException(
                'ذخیره مسیریابی موقتاً غیرفعال است — تست‌های regression JSON در حال تکمیل هستند.',
            );
        }
    }
}
