<?php

declare(strict_types=1);

namespace App\Core;

final class Format
{
    public static function bytesToGb(float $bytes, int $decimals = 2): string
    {
        $gb = $bytes / (1024 ** 3);
        return number_format($gb, $decimals, '.', '') . ' GB';
    }

    public static function bytesToGbNumber(float $bytes): float
    {
        return round($bytes / (1024 ** 3), 2);
    }

    public static function percent(float $used, float $quota): float
    {
        if ($quota <= 0) {
            return 0.0;
        }
        return min(100.0, round(($used / $quota) * 100, 1));
    }

    public static function bytesAuto(float $bytes, int $decimals = 2): string
    {
        $units = [
            1024 ** 4 => 'TB',
            1024 ** 3 => 'GB',
            1024 ** 2 => 'MB',
        ];
        foreach ($units as $size => $label) {
            if ($bytes >= $size) {
                return number_format($bytes / $size, $decimals, '.', '') . ' ' . $label;
            }
        }
        return number_format($bytes / 1024, 1, '.', '') . ' KB';
    }

    public static function jalaliOrGregorian(string $datetime): string
    {
        $ts = strtotime($datetime);
        if ($ts === false) {
            return $datetime;
        }
        return date('Y/m/d H:i', $ts);
    }

    public static function daysUntil(?string $datetime): ?int
    {
        if ($datetime === null || $datetime === '') {
            return null;
        }
        $ts = strtotime($datetime);
        if ($ts === false) {
            return null;
        }
        return max(0, (int) floor(($ts - time()) / 86400));
    }
}
