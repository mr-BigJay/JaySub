<?php

declare(strict_types=1);

use App\Services\TrafficDailySeries;
use App\View\Layout;
use PHPUnit\Framework\TestCase;

final class TrafficDailySeriesTest extends TestCase
{
    private function tehran(string $datetime): \DateTimeImmutable
    {
        return new \DateTimeImmutable($datetime, new \DateTimeZone('Asia/Tehran'));
    }

    /** @return array{subscription_id:int,total_bytes:int,recorded_ts:int,id:int} */
    private function sample(int $subscription, int $bytes, string $tehranTime, int $id): array
    {
        return [
            'subscription_id' => $subscription,
            'total_bytes' => $bytes,
            'recorded_ts' => $this->tehran($tehranTime)->getTimestamp(),
            'id' => $id,
        ];
    }

    public function testRealIncrementsFromMultipleSubscriptions(): void
    {
        $rows = [
            $this->sample(1, 100, '2026-10-30 08:00:00', 1),
            $this->sample(1, 150, '2026-10-30 09:00:00', 2),
            $this->sample(1, 190, '2026-10-30 10:00:00', 3),
            $this->sample(2, 200, '2026-10-30 08:00:00', 4),
            $this->sample(2, 230, '2026-10-30 09:00:00', 5),
        ];

        $result = TrafficDailySeries::aggregate(
            $rows,
            $this->tehran('2026-10-30'),
            $this->tehran('2026-10-31'),
        );
        self::assertCount(2, $result);
        self::assertSame('2026-10-30', $result[0]['date']);
        self::assertSame('10/30', $result[0]['label']);
        self::assertSame(120, $result[0]['bytes']); // 50 + 40 + 30
        self::assertSame('partial', $result[0]['status']); // No initial baseline.
        self::assertNull($result[1]['bytes']);
        self::assertSame('no_data', $result[1]['status']);
    }

    public function testCounterResetIsNotChargedAsUsage(): void
    {
        $rows = [
            $this->sample(1, 100, '2026-10-30 10:00:00', 1),
            $this->sample(1, 5, '2026-10-30 11:00:00', 2),
            $this->sample(1, 9, '2026-10-30 12:00:00', 3),
        ];
        $result = TrafficDailySeries::aggregate(
            $rows,
            $this->tehran('2026-10-30'),
            $this->tehran('2026-10-30'),
        );

        self::assertSame(4, $result[0]['bytes']);
        self::assertSame('partial', $result[0]['status']);
    }

    public function testCrossMidnightIntervalIsNotInventedForEitherDay(): void
    {
        // Tehran is UTC+03:30; a measured interval spanning midnight has
        // no exact per-day allocation and must not be split or assigned.
        $rows = [
            $this->sample(1, 100, '2026-10-30 23:59:00', 1),
            $this->sample(1, 500, '2026-10-31 00:01:00', 2),
        ];
        $result = TrafficDailySeries::aggregate(
            $rows,
            $this->tehran('2026-10-30'),
            $this->tehran('2026-10-31'),
        );
        self::assertSame('2026-10-30', $result[0]['date']);
        self::assertSame('2026-10-31', $result[1]['date']);
        self::assertNull($result[0]['bytes']);
        self::assertNull($result[1]['bytes']);
        self::assertSame('partial', $result[1]['status']);
    }

    public function testValidZeroTrafficIsDifferentFromNoData(): void
    {
        $rows = [
            $this->sample(1, 500, '2026-10-30 08:00:00', 1),
            $this->sample(1, 500, '2026-10-30 09:00:00', 2),
        ];
        $result = TrafficDailySeries::aggregate(
            $rows,
            $this->tehran('2026-10-30'),
            $this->tehran('2026-10-31'),
        );
        self::assertSame(0, $result[0]['bytes']);
        self::assertNull($result[1]['bytes']);
    }

    public function testMidnightInTehranUsesTehranDateNotUtcDate(): void
    {
        $rows = [
            ['subscription_id' => 9, 'total_bytes' => 100, 'recorded_ts' => strtotime('2026-10-30 20:30:00 UTC'), 'id' => 1],
            ['subscription_id' => 9, 'total_bytes' => 120, 'recorded_ts' => strtotime('2026-10-30 21:00:00 UTC'), 'id' => 2],
        ];
        $result = TrafficDailySeries::aggregate(
            $rows,
            $this->tehran('2026-10-30'),
            $this->tehran('2026-10-31'),
        );
        self::assertNull($result[0]['bytes']);
        self::assertSame(20, $result[1]['bytes']);
    }

    public function testRenderedChartShowsDateAndActualValueAndMissingData(): void
    {
        $html = Layout::barChart([
            ['date' => '2026-10-30', 'label' => '10/30', 'bytes' => 1024 * 1024 * 1024, 'status' => 'measured', 'detail' => 'ثبت شده'],
            ['date' => '2026-10-31', 'label' => '10/31', 'bytes' => null, 'status' => 'no_data', 'detail' => 'داده کافی نیست'],
        ]);
        self::assertStringContainsString('2026-10-30', $html);
        self::assertStringContainsString('1.00 GB', $html);
        self::assertStringContainsString('داده کافی نیست', $html);
        self::assertStringContainsString('aria-label=', $html);
        self::assertStringContainsString('10/31', $html);
    }
}
