<?php

namespace App\Core\Host\Configuration;

use App\Domains\InternalApi\StrictJson;
use RuntimeException;

/** Operator-provisioned files only; errors never include paths or contents. */
final class TrustedConfiguration
{
    public static function text(mixed $path, int $maximum): string
    {
        if (!is_string($path) || $path === '' || is_link($path) || !is_file($path)) {
            throw new RuntimeException('TRUSTED_CONFIGURATION_UNAVAILABLE');
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) throw new RuntimeException('TRUSTED_CONFIGURATION_UNAVAILABLE');
        try {
            $value = stream_get_contents($handle, $maximum + 1);
            if (!is_string($value) || $value === '' || strlen($value) > $maximum) {
                throw new RuntimeException('TRUSTED_CONFIGURATION_INVALID');
            }
            return $value;
        } finally { fclose($handle); }
    }

    public static function object(mixed $path, int $maximum = 65536): array
    {
        try { return StrictJson::object(self::text($path, $maximum)); }
        catch (\Throwable) { throw new RuntimeException('TRUSTED_CONFIGURATION_INVALID'); }
    }
}
