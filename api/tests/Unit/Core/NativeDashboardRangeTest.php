<?php

namespace Tests\Unit\Core;

use App\Domains\Analytics\NativeDashboardRead;
use DomainException;
use PHPUnit\Framework\TestCase;

final class NativeDashboardRangeTest extends TestCase
{
    public function test_range_is_utc_inclusive_calendar_days_with_an_exclusive_observation_bound(): void
    {
        $now = strtotime('2026-10-21T12:00:00Z');
        self::assertSame(['2026-09-22', '2026-10-21', strtotime('2026-09-22T00:00:00Z'), $now + 1], NativeDashboardRead::range([], $now));
        $range = NativeDashboardRead::range(['start_date' => '2026-01-01', 'end_date' => '2026-01-07'], $now);
        self::assertSame(strtotime('2026-01-08T00:00:00Z'), $range[3]);
    }

    public function test_range_refuses_invalid_dates_future_dates_reversed_dates_and_overlong_ranges(): void
    {
        foreach ([['2026-02-30', '2026-03-01'], ['2026-10-21', '2026-10-22'],
            ['2026-10-21', '2026-10-20'], ['2025-10-20', '2026-10-21']] as [$start, $end]) {
            try { NativeDashboardRead::range(['start_date' => $start, 'end_date' => $end], strtotime('2026-10-21T12:00:00Z')); self::fail('Invalid range accepted'); }
            catch (DomainException $error) { self::assertSame('DASHBOARD_RANGE_INVALID', $error->getMessage()); }
        }
        $this->expectException(DomainException::class);
        NativeDashboardRead::range(['start_date' => '2026-10-01'], time());
    }

    public function test_aggregates_preserve_exact_values_above_number_precision_and_currencies(): void
    {
        $method = new \ReflectionMethod(NativeDashboardRead::class, 'money');
        $totals = [];
        $method->invokeArgs(null, [&$totals, 'CNY', '9007199254740993']);
        $method->invokeArgs(null, [&$totals, 'CNY', '7']);
        $method->invokeArgs(null, [&$totals, 'USD', '123']);
        self::assertSame(['currency' => 'CNY', 'amount_minor' => '9007199254741000', 'count' => '2'], $totals['CNY']);
        self::assertSame('123', $totals['USD']['amount_minor']);
        $this->expectException(DomainException::class);
        $method->invokeArgs(null, [&$totals, 'CNY', '1e3']);
    }
}
