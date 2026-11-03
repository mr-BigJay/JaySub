<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Format;

/**
 * Measured per-subscription traffic increments, grouped by Tehran calendar day.
 *
 * A cumulative counter alone cannot locate usage inside an interval that crosses
 * midnight. Such intervals (and resets) are excluded, never guessed or spread
 * across dates. Null bytes mean we have no comparable measurements for the day.
 */
final class TrafficDailySeries
{
    /**
     * @param list<array{subscription_id:int|string,total_bytes:int|string,recorded_ts:int|string,id?:int|string}> $rows
     * @return list<array{date:string,label:string,bytes:?int,status:string,detail:string,samples:int}>
     */
    public static function aggregate(array $rows, \DateTimeImmutable $firstDay, \DateTimeImmutable $lastDay): array
    {
        $tz = new \DateTimeZone(Format::TZ);
        $start = $firstDay->setTimezone($tz)->setTime(0, 0);
        $end = $lastDay->setTimezone($tz)->setTime(0, 0);
        $days = [];
        for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $days[$date] = [
                'date' => $date,
                'label' => $day->format('m/d'),
                'bytes' => 0,
                'samples' => 0,
                'pairs' => 0,
                'incomplete' => false,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            return [(string) $a['subscription_id'], (int) $a['recorded_ts'], (int) ($a['id'] ?? 0)]
                <=> [(string) $b['subscription_id'], (int) $b['recorded_ts'], (int) ($b['id'] ?? 0)];
        });

        $previous = [];
        foreach ($rows as $row) {
            $timestamp = (int) ($row['recorded_ts'] ?? 0);
            $subscription = (string) ($row['subscription_id'] ?? '');
            if ($timestamp <= 0 || $subscription === '') {
                continue;
            }

            $date = (new \DateTimeImmutable('@' . $timestamp))->setTimezone($tz)->format('Y-m-d');
            $total = max(0, (int) ($row['total_bytes'] ?? 0));
            if (isset($days[$date])) {
                ++$days[$date]['samples'];
            }

            $prev = $previous[$subscription] ?? null;
            if ($prev === null) {
                if (isset($days[$date])) {
                    $days[$date]['incomplete'] = true; // Unknown first baseline.
                }
            } elseif ($timestamp <= $prev['timestamp'] || $total < $prev['total']) {
                if (isset($days[$date])) {
                    $days[$date]['incomplete'] = true; // Duplicate time or counter reset.
                }
            } elseif ($date !== $prev['date']) {
                // No observed per-day split exists for a cross-midnight interval.
                if (isset($days[$date])) {
                    $days[$date]['incomplete'] = true;
                }
                if (isset($days[$prev['date']])) {
                    $days[$prev['date']]['incomplete'] = true;
                }
            } elseif (isset($days[$date])) {
                $days[$date]['bytes'] += $total - $prev['total'];
                ++$days[$date]['pairs'];
            }

            $previous[$subscription] = [
                'timestamp' => $timestamp,
                'total' => $total,
                'date' => $date,
            ];
        }

        $result = [];
        foreach ($days as $day) {
            if ($day['samples'] === 0) {
                $status = 'no_data';
                $bytes = null;
                $detail = 'در این روز نمونه مصرف ثبت نشده است';
            } elseif ($day['pairs'] === 0) {
                $status = 'partial';
                $bytes = null;
                $detail = 'نمونه‌ها برای محاسبه اختلاف معتبر کافی نیستند';
            } else {
                $bytes = $day['bytes'];
                $status = $day['incomplete'] ? 'partial' : 'measured';
                $detail = $day['incomplete']
                    ? 'فقط مصرف قابل محاسبه نمایش داده می‌شود؛ بخشی از روز داده کافی ندارد'
                    : 'مصرف اندازه‌گیری‌شده بین نمونه‌های همین روز؛ ممکن است همه ساعات روز را پوشش ندهد';
            }
            $result[] = [
                'date' => $day['date'],
                'label' => $day['label'],
                'bytes' => $bytes,
                'status' => $status,
                'detail' => $detail,
                'samples' => $day['samples'],
            ];
        }

        return $result;
    }
}
