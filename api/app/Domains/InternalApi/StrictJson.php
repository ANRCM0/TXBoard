<?php

namespace App\Domains\InternalApi;

use JsonException;

/** JSON validity plus duplicate decoded key rejection, including escaped keys. */
final class StrictJson
{
    public static function object(string $text, array $objectFields = []): array
    {
        $decoded = json_decode($text, false, 32, JSON_THROW_ON_ERROR);
        if (!$decoded instanceof \stdClass) throw new JsonException('OBJECT_REQUIRED');
        foreach ($objectFields as $field) {
            if (!property_exists($decoded, $field) || !$decoded->$field instanceof \stdClass) {
                throw new JsonException('OBJECT_FIELD_REQUIRED');
            }
        }
        $value = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
        $offset = 0;
        $length = strlen($text);
        $space = static function () use (&$offset, $length, $text): void {
            while ($offset < $length && str_contains(" \t\r\n", $text[$offset])) $offset++;
        };
        $string = static function () use (&$offset, $length, $text): string {
            $start = $offset++;
            while ($offset < $length) {
                $char = $text[$offset++];
                if ($char === '\\') $offset++;
                elseif ($char === '"') return json_decode(substr($text, $start, $offset - $start), true, flags: JSON_THROW_ON_ERROR);
            }
            throw new JsonException('INVALID_STRING');
        };
        $scan = static function (int $depth) use (&$scan, &$offset, $length, $text, $space, $string): void {
            if ($depth > 30) throw new JsonException('DEPTH_LIMIT');
            $space();
            $char = $text[$offset] ?? '';
            if ($char === '"') { $string(); return; }
            if ($char === '{' || $char === '[') {
                $object = $char === '{';
                $end = $object ? '}' : ']';
                $offset++;
                $space();
                $seen = [];
                if (($text[$offset] ?? '') === $end) { $offset++; return; }
                while ($offset < $length) {
                    $space();
                    if ($object) {
                        $key = $string();
                        // Prefix avoids PHP's numeric-string array-key coercion.
                        if (isset($seen['key:'.$key])) throw new JsonException('DUPLICATE_KEY');
                        $seen['key:'.$key] = true;
                        $space();
                        $offset++; // json_decode already verified the colon.
                    }
                    $scan($depth + 1);
                    $space();
                    if (($text[$offset++] ?? '') === $end) return;
                }
                throw new JsonException('INVALID_CONTAINER');
            }
            $start = $offset;
            while ($offset < $length && !str_contains(" \t\r\n,]}", $text[$offset])) $offset++;
            if ($start === $offset) throw new JsonException('INVALID_VALUE');
        };
        $scan(0);
        $space();
        if ($offset !== $length) throw new JsonException('TRAILING_INPUT');
        return $value;
    }
}
