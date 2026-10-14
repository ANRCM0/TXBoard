<?php

namespace App\Domains\Network;

use App\Services\TrafficUsage;

/** Canonical, bounded attribution segments; signatures are checked under User locks. */
final class NativeTrafficSegments
{
    public static function canonical(array $segments, array $traffic): array
    {
        if ($segments === [] || !array_is_list($segments) || count($segments) > 10000) self::invalid();
        $merged = [];
        foreach ($segments as $segment) {
            if (!is_array($segment) || count($segment) !== 4
                || array_diff(array_keys($segment), ['user_id', 'period', 'upload', 'download'])
                || !isset($segment['user_id'], $segment['period'], $segment['upload'], $segment['download'])
                || (!is_int($segment['user_id']) && !is_string($segment['user_id']))
                || !is_string($segment['period']) || strlen($segment['period']) > 130 || $segment['period'] === '') self::invalid();
            $bytes = TrafficUsage::normalize([$segment['user_id'] => [$segment['upload'], $segment['download']]], 1);
            if (count($bytes) !== 1) self::invalid();
            $uid = (int) array_key_first($bytes);
            [$up, $down] = $bytes[$uid];
            $key = $uid . ':' . $segment['period'];
            if (!isset($merged[$key])) $merged[$key] = ['user_id' => $uid, 'period' => $segment['period'], 'upload' => 0, 'download' => 0];
            foreach (['upload' => $up, 'download' => $down] as $direction => $value) {
                if ($value > TrafficUsage::MAX_REPORT_BYTES - $merged[$key][$direction]) self::invalid();
                $merged[$key][$direction] += $value;
            }
        }
        $canonical = array_values($merged);
        usort($canonical, static fn (array $a, array $b): int => ($a['user_id'] <=> $b['user_id']) ?: strcmp($a['period'], $b['period']));
        $totals = [];
        foreach ($canonical as $segment) {
            $uid = $segment['user_id']; $totals[$uid] ??= [0, 0];
            foreach ([0 => 'upload', 1 => 'download'] as $index => $direction) {
                if ($segment[$direction] > TrafficUsage::MAX_REPORT_BYTES - $totals[$uid][$index]) self::invalid();
                $totals[$uid][$index] += $segment[$direction];
            }
        }
        ksort($totals, SORT_NUMERIC); ksort($traffic, SORT_NUMERIC);
        if ($totals !== $traffic) self::invalid();
        return $canonical;
    }

    public static function ledgerHash(mixed $rate, array $traffic, ?array $segments): string
    {
        // Legacy hashes are byte-for-byte unchanged when attribution is absent.
        return hash('sha256', json_encode($segments === null ? [$rate, $traffic] : [$rate, $traffic, $segments], JSON_THROW_ON_ERROR));
    }

    private static function invalid(): never
    {
        throw new NodeReportError('INVALID_TRAFFIC_SEGMENTS', 'Invalid traffic attribution segments');
    }
}
