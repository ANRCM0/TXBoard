<?php

namespace App\Domains\UsageV2;

final class UsageMath
{
    public static function floor(string $raw, string $numerator, string $denominator): string
    {
        UsagePayload::decimal($raw, true); UsagePayload::decimal($numerator, true); UsagePayload::decimal($denominator, false);
        $billed = bcdiv(bcmul($raw, $numerator, 0), $denominator, 0);
        self::bounded($billed); return $billed;
    }
    public static function add(string ...$values): string
    {
        $sum = '0'; foreach ($values as $value) { UsagePayload::decimal($value, true); $sum = bcadd($sum, $value, 0); } self::bounded($sum); return $sum;
    }
    private static function bounded(string $value): void
    {
        if (bccomp($value, '0', 0) < 0 || bccomp($value, (string) PHP_INT_MAX, 0) > 0) { throw new UsageError('USAGE_ACCOUNTING_OVERFLOW'); }
    }
}
