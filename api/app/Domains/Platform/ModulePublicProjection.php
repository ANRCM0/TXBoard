<?php

namespace App\Domains\Platform;

/** Host display projection after the extension's declared output schema has been applied. */
final class ModulePublicProjection
{
    public static function sensitive(string $name): bool
    {
        return preg_match('/(?:password|passwd|secret|private_key|authorization|token|credential|ciphertext|api_key|access_key|signing_key)/i', $name) === 1;
    }

    public static function data(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 24) throw new \InvalidArgumentException('MODULE_DISPLAY_LIMIT');
        $object = $value instanceof \stdClass;
        if ($object) $value = get_object_vars($value);
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && self::sensitive($key)) continue;
                $result[$key] = self::data($item, $depth + 1);
            }
            return $object ? (object) $result : $result;
        }
        if (is_string($value)) {
            if (str_contains($value, 'secret:')) return '[REDACTED]';
            foreach (config('core_modules.secrets', []) as $resolved) {
                if (is_string($resolved) && $resolved !== '' && str_contains($value, $resolved)) return '[REDACTED]';
            }
        }
        return $value;
    }

    public static function schema(array $schema): array
    {
        if (isset($schema['properties'])) {
            $properties = [];
            foreach ($schema['properties'] as $key => $property) {
                if (!self::sensitive($key)) $properties[$key] = self::schema($property);
            }
            $schema['properties'] = $properties;
            $schema['required'] = array_values(array_intersect($schema['required'] ?? [], array_keys($properties)));
        }
        if (isset($schema['items'])) $schema['items'] = self::schema($schema['items']);
        if (isset($schema['anyOf'])) $schema['anyOf'] = array_map(self::schema(...), $schema['anyOf']);
        return $schema;
    }
}
