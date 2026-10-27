<?php

declare(strict_types=1);

namespace App\Services;

final class OutboundSaveGuard
{
    public const PERSIST_ENABLED = false;

    public static function assertPersistAllowed(): void
    {
        if (!self::PERSIST_ENABLED) {
            throw new \RuntimeException(
                'ذخیره اوتباند موقتاً غیرفعال است — پس از تأیید عملیاتی OutboundSaveGuard::PERSIST_ENABLED را فعال کنید.',
            );
        }
    }
}
