<?php
namespace App\Domains\LegacyLinks;
final class Canonical
{
    public static function json(array $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (is_float($item) || is_object($item)) throw new LinkFailure('LINK_CANONICAL_INVALID');
            if (!is_array($item)) return $item;
            if (!array_is_list($item)) ksort($item, SORT_STRING);
            return array_map($normalize, $item);
        };
        return json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    public static function object(string $value, int $maximum = 2048): array
    {
        if (strlen($value) > $maximum) throw new LinkFailure('LINK_EVIDENCE_INVALID');
        try { return get_object_vars(\App\Domains\InternalReadOnly\StrictJson::object($value)); }
        catch (\Throwable) { throw new LinkFailure('LINK_EVIDENCE_INVALID'); }
    }
    public static function hash(array $value): string { return hash('sha256', self::json($value)); }
    public static function id(mixed $value, string $maximum = '9223372036854775807', bool $zero = false): string
    {
        if (!is_int($value) && !is_string($value)) throw new LinkFailure('LINK_REFERENCE_INVALID');
        $value = (string) $value;
        if (!preg_match($zero ? '/\A(?:0|[1-9][0-9]*)\z/D' : '/\A[1-9][0-9]*\z/D', $value)
            || strlen($value) > strlen($maximum) || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0))
            throw new LinkFailure('LINK_REFERENCE_INVALID');
        return $value;
    }
    public static function require(bool $condition, string $code): void { if (!$condition) throw new LinkFailure($code); }
}
