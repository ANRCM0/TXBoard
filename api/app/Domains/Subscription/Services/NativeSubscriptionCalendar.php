<?php

namespace App\Domains\Subscription\Services;

use Carbon\Carbon;
use DateTimeZone;
use DomainException;

/** Calendar calculations use purchased policies, never mutable application defaults. */
final class NativeSubscriptionCalendar
{
    public function expiry(array $grant, int $from): ?int
    {
        if ($grant['purchase_kind'] === 'perpetual') {
            return null;
        }
        $months = $grant['duration_months'] ?? null;
        $policy = $grant['calendar_policy'] ?? [];
        if (!in_array($months, [1, 3, 6, 12, 24, 36], true)
            || ($policy['timezone'] ?? null) !== 'UTC' || !is_bool($policy['month_overflow'] ?? null)) {
            throw new DomainException('NATIVE_CALENDAR_INVALID');
        }
        $expiry = Carbon::createFromTimestamp($from, 'UTC')
            ->settings(['monthOverflow' => $policy['month_overflow']])->addMonths($months)->timestamp;
        if ($expiry <= $from) {
            throw new DomainException('NATIVE_CALENDAR_INVALID');
        }
        return SubscriptionTermsTimeline::timestamp($expiry);
    }

    public function nextReset(array $policy, ?int $expiry, int $from): ?int
    {
        $method = $policy['method'] ?? null;
        $timezone = $policy['timezone'] ?? null;
        if (!is_int($method) || !in_array($method, [0, 1, 2, 3, 4], true)
            || !is_string($timezone) || !in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new DomainException('NATIVE_RESET_POLICY_INVALID');
        }
        if ($method === 2 || $expiry === null) {
            return null;
        }
        $date = Carbon::createFromTimestamp($from, $timezone);
        if ($method === 0) {
            return SubscriptionTermsTimeline::timestamp($date->copy()->addMonthNoOverflow()->startOfMonth()->timestamp);
        }
        if ($method === 3) {
            return SubscriptionTermsTimeline::timestamp($date->copy()->addYearNoOverflow()->startOfYear()->timestamp);
        }
        $anchor = Carbon::createFromTimestamp($expiry, $timezone);
        $time = [$anchor->hour, $anchor->minute, $anchor->second];
        if ($method === 1) {
            $candidate = $date->copy()->startOfMonth();
            $candidate->day(min($anchor->day, $candidate->daysInMonth))->setTime(...$time);
            if ($candidate->timestamp <= $from) {
                $candidate = $date->copy()->startOfMonth()->addMonthNoOverflow();
                $candidate->day(min($anchor->day, $candidate->daysInMonth))->setTime(...$time);
            }
        } else {
            $candidate = $date->copy()->startOfYear()->month($anchor->month);
            $candidate->day(min($anchor->day, $candidate->daysInMonth))->setTime(...$time);
            if ($candidate->timestamp <= $from) {
                $candidate = $date->copy()->startOfYear()->addYearNoOverflow()->month($anchor->month);
                $candidate->day(min($anchor->day, $candidate->daysInMonth))->setTime(...$time);
            }
        }
        return SubscriptionTermsTimeline::timestamp($candidate->timestamp);
    }

    public function periodEnd(?int $nextReset, ?int $expiry): ?int
    {
        return $nextReset === null ? $expiry : ($expiry === null ? $nextReset : min($nextReset, $expiry));
    }
}
