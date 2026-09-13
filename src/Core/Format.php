<?php

declare(strict_types=1);

namespace App\Core;

final class Format
{
    public const TZ = 'Asia/Tehran';

    public static function bytesToGb(float $bytes, int $decimals = 2): string
    {
        $gb = $bytes / (1024 ** 3);
        return number_format($gb, $decimals, '.', '') . ' GB';
    }

    /** e.g. "GB 1.32" for public usage mockup */
    public static function gbPrefix(float $bytes, int $decimals = 2): string
    {
        $gb = $bytes / (1024 ** 3);
        return 'GB ' . number_format($gb, $decimals, '.', '');
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

    public static function tehranNow(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone(self::TZ));
    }

    /** نمایش تاریخ/ساعت در UI — همیشه Asia/Tehran */
    public static function jalaliOrGregorian(string $datetime): string
    {
        $tz = new \DateTimeZone(self::TZ);
        $trim = trim($datetime);
        if ($trim === '') {
            return $datetime;
        }
        if (ctype_digit($trim)) {
            return self::fromTimestamp((int) $trim);
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $trim, $tz);
        if ($dt === false) {
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $trim, $tz);
        }
        if ($dt === false) {
            $ts = strtotime($trim);
            if ($ts === false) {
                return $datetime;
            }
            $dt = (new \DateTimeImmutable('@' . $ts))->setTimezone($tz);
        }
        return $dt->format('Y/m/d H:i');
    }

    public static function fromTimestamp(int $timestamp): string
    {
        return (new \DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new \DateTimeZone(self::TZ))
            ->format('Y/m/d H:i');
    }

    public static function dateTehran(string $format, ?int $timestamp = null): string
    {
        $dt = $timestamp !== null
            ? (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone(self::TZ))
            : self::tehranNow();
        return $dt->format($format);
    }

    /** مقدار فیلد date در فرم‌ها (میلادی Y-m-d، ساعت تهران) */
    public static function gregorianDateForInput(?string $datetime): string
    {
        if ($datetime === null || trim($datetime) === '') {
            return '';
        }
        $tz = new \DateTimeZone(self::TZ);
        $trim = trim($datetime);
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $trim, $tz);
        if ($dt === false) {
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $trim, $tz);
        }
        return $dt !== false ? $dt->format('Y-m-d') : '';
    }

    public static function daysUntil(?string $datetime): ?int
    {
        if ($datetime === null || $datetime === '') {
            return null;
        }
        $tz = new \DateTimeZone(self::TZ);
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', trim($datetime), $tz);
        if ($dt === false) {
            $ts = strtotime($datetime);
            if ($ts === false) {
                return null;
            }
            $dt = (new \DateTimeImmutable('@' . $ts))->setTimezone($tz);
        }
        $now = self::tehranNow();
        $diff = $dt->getTimestamp() - $now->getTimestamp();

        return max(0, (int) floor($diff / 86400));
    }
}
