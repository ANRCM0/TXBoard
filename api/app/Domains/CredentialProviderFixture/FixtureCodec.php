<?php

namespace App\Domains\CredentialProviderFixture;

use DomainException;

final class FixtureCodec
{
    public const VERSION = 'credential-provider/local-fixture/1';
    public const MAX_BYTES = 4096;

    public static function decimal(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[1-9][0-9]{0,18}$/D', $value)
            || (strlen($value) === 19 && strcmp($value, '9223372036854775807') > 0)) {
            throw new DomainException('PROVIDER_INPUT_INVALID');
        }
        return $value;
    }

    public static function provider(string $id): string
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $id)) { throw new DomainException('PROVIDER_INPUT_INVALID'); }
        return $id;
    }

    public static function time(mixed $at): int
    {
        if (!is_int($at) || $at < 0 || $at > 9007199254740991) { throw new DomainException('PROVIDER_INPUT_INVALID'); }
        return $at;
    }

    public static function json(array $value): string
    {
        $sort = function (array $item) use (&$sort): array {
            if (!array_is_list($item)) { ksort($item, SORT_STRING); }
            foreach ($item as &$element) { if (is_array($element)) { $element = $sort($element); } }
            return $item;
        };
        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public static function hash(array $value): string { return hash('sha256', self::json($value)); }
}
