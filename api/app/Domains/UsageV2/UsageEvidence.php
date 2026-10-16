<?php

namespace App\Domains\UsageV2;

/** Stable hashing and interval checks for persisted native usage evidence. */
final class UsageEvidence
{
    public static function assertIdentity(object $source): void
    {
        if ($source->core_instance_id !== config('core_usage_v2.core_instance_id')
            || (string) $source->receipt_generation !== (string) config('core_usage_v2.receipt_generation')) {
            throw new UsageError('CORE_INSTANCE_RECOVERY_REQUIRED', 409);
        }
    }

    public static function covers(int $start, int $end, mixed $from, mixed $until): bool
    {
        return $from !== null && $start >= (int) $from && ($until === null || $end <= (int) $until);
    }

    public static function hash(array $data): string
    {
        return hash('sha256', json_encode(self::normalize($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private static function normalize(mixed $data): mixed
    {
        if (!is_array($data)) { return $data; }
        if (!array_is_list($data)) { ksort($data, SORT_STRING); }
        foreach ($data as &$value) { $value = self::normalize($value); } unset($value);
        return $data;
    }
}
