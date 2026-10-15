<?php

namespace App\Domains\Subscription;

use RuntimeException;

/** Exact catalog values shared by current purchase terms and catalog rehearsal. */
final class CatalogValue
{
    /** Exact arithmetic precedes the parity check against the current authoritative quote. */
    public function legacyPriceMinor(mixed $value): string
    {
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new RuntimeException('CATALOG_PRICE_INVALID');
            }
            $value = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } elseif (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value) || strlen($value) > 128
            || !preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]+))?(?:[eE]([+-]?[0-9]{1,4}))?$/D', $value, $parts)) {
            throw new RuntimeException('CATALOG_PRICE_INVALID');
        }
        $fraction = $parts[2] ?? '';
        $exponent = isset($parts[3]) ? (int) $parts[3] : 0;
        if (abs($exponent) > 128) {
            throw new RuntimeException('CATALOG_PRICE_OUT_OF_RANGE');
        }
        $digits = $parts[1].$fraction;
        $minorPoint = strlen($parts[1]) + $exponent + 2;
        if ($minorPoint < strlen($digits)) {
            $discarded = $minorPoint < 0 ? $digits : substr($digits, $minorPoint);
            if (trim($discarded, '0') !== '') {
                throw new RuntimeException('CATALOG_PRICE_PRECISION_UNSUPPORTED');
            }
            $digits = $minorPoint <= 0 ? '0' : substr($digits, 0, $minorPoint);
        } else {
            $digits .= str_repeat('0', $minorPoint - strlen($digits));
        }
        $minor = ltrim($digits, '0') ?: '0';
        if (strlen($minor) > strlen((string) PHP_INT_MAX)
            || (strlen($minor) === strlen((string) PHP_INT_MAX) && strcmp($minor, (string) PHP_INT_MAX) > 0)) {
            throw new RuntimeException('CATALOG_PRICE_OUT_OF_RANGE');
        }
        if ((string) (int) round((float) $value * 100) !== $minor) {
            throw new RuntimeException('CATALOG_LEGACY_QUOTE_PRECISION_MISMATCH');
        }
        return $minor;
    }

    public function hash(array $value): string
    {
        return hash('sha256', $this->json($value));
    }

    private function json(array $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (!is_array($item)) {
                return $item;
            }
            if (!array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
            return array_map($normalize, $item);
        };
        return json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
