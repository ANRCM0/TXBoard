<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Validate untrusted node traffic increments before billing or statistics.
 * All byte counters are integral, unsigned and capped before multiplication.
 */
final class TrafficUsage
{
    // Per-user, per-report safety bound: 1 PiB.
    public const MAX_REPORT_BYTES = 1125899906842624;
    public const MAX_RATE = 1000;

    public static function normalize(array $traffic, mixed $rate): array
    {
        if (!self::validRate($rate)) {
            Log::warning('Rejected node traffic report with invalid billing rate');
            return [];
        }

        $valid = [];
        $rejected = 0;
        foreach ($traffic as $uid => $usage) {
            $id = self::parsePositiveId($uid);
            if ($id === null || !is_array($usage) || array_keys($usage) !== [0, 1]) {
                ++$rejected;
                continue;
            }

            $u = self::parseBytes($usage[0]);
            $d = self::parseBytes($usage[1]);
            if ($u === null || $d === null
                || ($u > 0 && $u > intdiv(PHP_INT_MAX, max(1, (int) ceil((float) $rate))))
                || ($d > 0 && $d > intdiv(PHP_INT_MAX, max(1, (int) ceil((float) $rate))))) {
                ++$rejected;
                continue;
            }

            $valid[$id] = [$u, $d];
        }

        if ($rejected > 0) {
            Log::warning('Rejected malformed node traffic entries', [
                'rejected' => $rejected, 'accepted' => count($valid),
            ]);
        }

        return $valid;
    }

    private static function validRate(mixed $rate): bool
    {
        if (!is_int($rate) && !is_float($rate) && !is_string($rate)) {
            return false;
        }
        if (!is_numeric($rate)) {
            return false;
        }
        $value = (float) $rate;
        return is_finite($value) && $value > 0 && $value <= self::MAX_RATE;
    }

    private static function parsePositiveId(int|string $value): ?int
    {
        if (!preg_match('/^[1-9][0-9]*$/D', (string) $value)
            || strlen((string) $value) > strlen((string) PHP_INT_MAX)
            || (float) $value > PHP_INT_MAX) {
            return null;
        }

        return (int) $value;
    }

    private static function parseBytes(mixed $value): ?int
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }
        if (!preg_match('/^(0|[1-9][0-9]*)$/D', (string) $value)
            || strlen((string) $value) > strlen((string) self::MAX_REPORT_BYTES)
            || (float) $value > self::MAX_REPORT_BYTES) {
            return null;
        }

        return (int) $value;
    }
}
