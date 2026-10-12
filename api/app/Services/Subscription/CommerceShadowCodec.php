<?php

namespace App\Services\Subscription;

use RuntimeException;

/** Exact minor-unit and canonical JSON boundary. No floating point conversion. */
final class CommerceShadowCodec
{
    public static function integer(mixed $value, bool $positive = false, bool $nullable = false): ?string
    {
        if ($value === null && $nullable) {
            return null;
        }
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]*)$/D', $value)
            || strlen($value) > 19 || (strlen($value) === 19 && strcmp($value, '9223372036854775807') > 0)
            || ($positive && $value === '0')) {
            throw new RuntimeException('COMMERCE_INTEGER_INVALID');
        }
        return $value;
    }

    public static function add(string ...$values): string
    {
        $total = 0;
        foreach ($values as $value) {
            $amount = (int) self::integer($value);
            if ($total > PHP_INT_MAX - $amount) {
                throw new RuntimeException('COMMERCE_AMOUNT_OVERFLOW');
            }
            $total += $amount;
        }
        return (string) $total;
    }

    public static function timestamp(mixed $value, bool $nullable = false): ?int
    {
        $number = self::integer($value, nullable: $nullable);
        if ($number === null) {
            return null;
        }
        if ((int) $number > 9007199254740991) {
            throw new RuntimeException('COMMERCE_TIME_INVALID');
        }
        return (int) $number;
    }

    public static function json(array $value): string
    {
        return json_encode(self::canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public static function hash(array $value): string
    {
        return hash('sha256', self::json($value));
    }

    public static function reference(string $domain, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || strlen($value) > 255) {
            throw new RuntimeException('COMMERCE_REFERENCE_INVALID');
        }
        return hash('sha256', "txboard/commerce-shadow/v1/{$domain}\0".$value);
    }

    /** Reports sanitized structured fields only; raw legacy references never enter this function. */
    public static function differences(array $expected, array $actual, string $prefix = '', bool $reverse = false): array
    {
        $differences = [];
        foreach (array_keys($expected) as $field) {
            $path = $prefix.(string) $field;
            $before = $expected[$field] ?? null;
            $after = $actual[$field] ?? null;
            if (is_array($before) && is_array($after)) {
                $differences = [...$differences, ...self::differences($before, $after, $path.'.', $reverse)];
            } elseif ($before !== $after || array_key_exists($field, $expected) !== array_key_exists($field, $actual)) {
                $differences[] = ['field' => $path, 'expected' => self::safeValue($reverse ? $after : $before),
                    'actual' => self::safeValue($reverse ? $before : $after)];
            }
        }
        $extra = count(array_diff_key($actual, $expected));
        if ($extra > 0) {
            $differences[] = ['field' => $prefix.'unexpected_field_count', 'expected' => $reverse ? (string) $extra : '0',
                'actual' => $reverse ? '0' : (string) $extra];
        }
        return $differences;
    }

    private static function safeValue(mixed $value): mixed
    {
        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }
        if (is_string($value) && (preg_match('/^(?:0|[1-9][0-9]*|[a-f0-9]{64})$/D', $value)
            || in_array($value, ['user', 'system', 'wallet', 'commission', 'debit', 'credit', 'posted',
                'migration_wallet_clearing', 'migration_commission_clearing', 'legacy_wallet_opening',
                'legacy_commission_opening', 'USD', 'CNY', 'EUR', 'GBP', 'HKD', 'SGD', 'CAD', 'AUD', 'NZD', 'TWD',
                'monthly', 'quarterly', 'half_yearly', 'yearly', 'two_yearly', 'three_yearly', 'onetime', 'reset_traffic',
                'legacy_snapshot_unknown', 'none', 'legacy_marker_unverified', 'pending_predebit', 'processing_predebit',
                'cancelled_legacy_release', 'completed_legacy_consumption', 'discounted_legacy_consumption'], true))) {
            return $value;
        }
        return '[invalid]';
    }

    private static function canonical(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = self::canonical($item);
            }
        }
        return $value;
    }
}
