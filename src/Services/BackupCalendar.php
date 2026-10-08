<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Saturday–Friday weeks and Jalali month helpers (Asia/Tehran).
 */
final class BackupCalendar
{
    public const TZ = 'Asia/Tehran';

    public static function tehranNow(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone(self::TZ));
    }

    /** Saturday 00:00:00 Tehran for the week containing $dt. */
    public static function weekStartSaturday(\DateTimeImmutable $dt): \DateTimeImmutable
    {
        $local = $dt->setTimezone(new \DateTimeZone(self::TZ));
        $w = (int) $local->format('w'); // 0=Sun … 6=Sat
        $daysSinceSaturday = ($w + 1) % 7;
        return $local->modify('-' . $daysSinceSaturday . ' days')->setTime(0, 0, 0);
    }

    public static function weekEndFriday(\DateTimeImmutable $weekStart): \DateTimeImmutable
    {
        return $weekStart->modify('+6 days')->setTime(23, 59, 59);
    }

    public static function weekKey(\DateTimeImmutable $dt): string
    {
        return self::weekStartSaturday($dt)->format('Y-m-d');
    }

    /** @return array{0:int,1:int,2:int} [jy, jm, jd] */
    public static function gregorianToJalali(int $gy, int $gm, int $gd): array
    {
        $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2 = $gy - 1600;
        $gm2 = $gm - 1;
        $gd2 = $gd - 1;
        $gDayNo = 365 * $gy2 + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400);
        $gDayNo += $g_d_m[$gm2] + $gd2;
        if ($gm2 > 1 && (($gy % 4 === 0 && $gy % 100 !== 0) || $gy % 400 === 0)) {
            ++$gDayNo;
        }
        $jDayNo = $gDayNo - 79;
        $jNp = intdiv($jDayNo, 12053);
        $jDayNo %= 12053;
        $jy = 979 + 33 * $jNp + 4 * intdiv($jDayNo, 1461);
        $jDayNo %= 1461;
        if ($jDayNo >= 366) {
            $jy += intdiv($jDayNo - 1, 365);
            $jDayNo = ($jDayNo - 1) % 365;
        }
        $jm = $jDayNo < 186 ? 1 + intdiv($jDayNo, 31) : 7 + intdiv($jDayNo - 186, 30);
        $jd = 1 + ($jDayNo < 186 ? $jDayNo % 31 : ($jDayNo - 186) % 30);
        return [$jy, $jm, $jd];
    }

    /** @return array{jy:int,jm:int,jd:int} */
    public static function jalaliFromTimestamp(int $ts): array
    {
        $dt = (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone(self::TZ));
        [$jy, $jm, $jd] = self::gregorianToJalali(
            (int) $dt->format('Y'),
            (int) $dt->format('n'),
            (int) $dt->format('j'),
        );
        return ['jy' => $jy, 'jm' => $jm, 'jd' => $jd];
    }

    public static function timestampFromBackupFilename(string $filename): ?int
    {
        if (preg_match('/_(\d{4}-\d{2}-\d{2})_(\d{6})\.(db|dump)$/i', $filename, $m)) {
            $iso = $m[1] . ' ' . substr($m[2], 0, 2) . ':' . substr($m[2], 2, 2) . ':' . substr($m[2], 4, 2);
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $iso, new \DateTimeZone(self::TZ));
            if ($dt instanceof \DateTimeImmutable) {
                return $dt->getTimestamp();
            }
        }
        return null;
    }
}
