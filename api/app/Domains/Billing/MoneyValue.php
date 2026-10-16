<?php

namespace App\Domains\Billing;

/** Exact ledger values; external adapters must normalize their own currency precision. */
final class MoneyValue
{
    public const MAX = '9223372036854775807';

    public static function minor(mixed $value, bool $positive = false): string
    {
        if ((!is_string($value) && !is_int($value))
            || preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/D', (string) $value) !== 1
            || self::compare((string) $value, self::MAX) > 0
            || ($positive && (string) $value === '0')) {
            throw new BillingError('BILLING_AMOUNT_INVALID');
        }
        return (string) $value;
    }

    public static function signed(mixed $value): string
    {
        if (!is_string($value) && !is_int($value)) {
            throw new BillingError('BILLING_AMOUNT_INVALID');
        }
        $value = (string) $value;
        if (str_starts_with($value, '-')) {
            self::minor(substr($value, 1), true);
        } else {
            self::minor($value);
        }
        return $value;
    }

    public static function currency(mixed $currency): string
    {
        if (!is_string($currency) || preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1) {
            throw new BillingError('BILLING_CURRENCY_INVALID');
        }
        return $currency;
    }

    public static function key(mixed $key, int $maximum = 128): string
    {
        if (!is_string($key) || strlen($key) > $maximum
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]*\z/D', $key) !== 1) {
            throw new BillingError('BILLING_BUSINESS_KEY_INVALID');
        }
        return $key;
    }

    public static function add(string $left, string $right): string
    {
        return self::signed(bcadd(self::signed($left), self::signed($right), 0));
    }

    public static function hash(array $value): string
    {
        $normalize = static function (mixed $item) use (&$normalize): mixed {
            if (!is_array($item)) {
                return $item;
            }
            if (!array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
            foreach ($item as &$part) {
                $part = $normalize($part);
            }
            return $item;
        };
        return hash('sha256', json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private static function compare(string $left, string $right): int
    {
        return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
    }
}
