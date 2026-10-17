<?php

namespace App\Core\Host\Contracts;

use InvalidArgumentException;

/** The closed JSON Schema vocabulary emitted by the contract generator. No coercion. */
final class TransportSchema
{
    public function __construct(private readonly array $definitions = []) {}

    public static function assertDefinition(array $schema, int $depth = 0): void
    {
        $allowed = ['anyOf', 'const', 'enum', 'type', 'properties', 'required', 'additionalProperties',
            'items', 'minItems', 'maxItems', 'minLength', 'maxLength', 'pattern', 'minimum', 'maximum', 'x-decimal-maximum'];
        if ($depth > 20 || array_diff(array_keys($schema), $allowed)) throw new InvalidArgumentException('CONTRACT_SCHEMA_INVALID');
        if (isset($schema['anyOf'])) {
            if (count($schema) !== 1 || !is_array($schema['anyOf']) || !array_is_list($schema['anyOf']) || count($schema['anyOf']) < 2 || count($schema['anyOf']) > 8) {
                throw new InvalidArgumentException('CONTRACT_SCHEMA_INVALID');
            }
            foreach ($schema['anyOf'] as $variant) self::assertDefinition($variant, $depth + 1);
            return;
        }
        if (array_key_exists('const', $schema)) {
            if (count($schema) !== 1 || !is_scalar($schema['const']) && $schema['const'] !== null || is_float($schema['const'])
                || is_int($schema['const']) && abs($schema['const']) > 9007199254740991) throw new InvalidArgumentException('CONTRACT_SCHEMA_INVALID');
            return;
        }
        if (!in_array($schema['type'] ?? null, ['object', 'array', 'string', 'integer', 'boolean', 'null'], true)) {
            throw new InvalidArgumentException('CONTRACT_SCHEMA_INVALID');
        }
        foreach (['minLength', 'maxLength', 'minItems', 'maxItems', 'minimum', 'maximum'] as $field) {
            if (array_key_exists($field, $schema) && (!is_int($schema[$field]) || abs($schema[$field]) > 9007199254740991
                || !in_array($field, ['minimum', 'maximum'], true) && $schema[$field] < 0)) throw new InvalidArgumentException('CONTRACT_SCHEMA_INVALID');
        }
        foreach ([['minLength', 'maxLength'], ['minItems', 'maxItems'], ['minimum', 'maximum']] as [$minimum, $maximum]) {
            if (isset($schema[$minimum], $schema[$maximum]) && $schema[$minimum] > $schema[$maximum]) throw new InvalidArgumentException('CONTRACT_SCHEMA_INVALID');
        }
        if (array_key_exists('enum', $schema)) {
            if (!is_array($schema['enum']) || !array_is_list($schema['enum']) || count($schema['enum']) < 1 || count($schema['enum']) > 64) throw new InvalidArgumentException('CONTRACT_SCHEMA_INVALID');
            foreach ($schema['enum'] as $value) if (!is_scalar($value) && $value !== null || is_float($value)
                || is_int($value) && abs($value) > 9007199254740991) throw new InvalidArgumentException('CONTRACT_SCHEMA_INVALID');
        }
        if (array_key_exists('x-decimal-maximum', $schema) && ($schema['type'] !== 'string' || !is_string($schema['x-decimal-maximum'])
            || preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/D', $schema['x-decimal-maximum']) !== 1)) throw new InvalidArgumentException('CONTRACT_SCHEMA_INVALID');
        if ($schema['type'] === 'object') {
            if (($schema['additionalProperties'] ?? null) !== false || !is_array($schema['properties'] ?? null)
                || count($schema['properties']) > 64 || !is_array($schema['required'] ?? null) || !array_is_list($schema['required'])
                || count(array_filter($schema['required'], 'is_string')) !== count($schema['required'])
                || count(array_unique($schema['required'], SORT_STRING)) !== count($schema['required'])
                || array_diff($schema['required'], array_keys($schema['properties']))) throw new InvalidArgumentException('CONTRACT_SCHEMA_INVALID');
            foreach ($schema['properties'] as $name => $property) {
                if (!is_string($name) || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $name) !== 1) throw new InvalidArgumentException('CONTRACT_SCHEMA_INVALID');
                self::assertDefinition($property, $depth + 1);
            }
        }
        if ($schema['type'] === 'array') {
            if (!is_int($schema['maxItems'] ?? null) || $schema['maxItems'] < 0 || $schema['maxItems'] > 1000 || !is_array($schema['items'] ?? null)) {
                throw new InvalidArgumentException('CONTRACT_SCHEMA_INVALID');
            }
            self::assertDefinition($schema['items'], $depth + 1);
        }
        if (array_key_exists('pattern', $schema) && (!is_string($schema['pattern']) || strlen($schema['pattern']) > 512
            || @preg_match('~'.str_replace('~', '\\~', $schema['pattern']).'~Du', '') === false)) throw new InvalidArgumentException('CONTRACT_SCHEMA_INVALID');
    }

    public function acceptsWire(mixed $value, array $schema): bool { return $this->accepts($value, $schema, wire: true); }

    public static function wireDefinition(array $schema): array
    {
        if (isset($schema['properties'])) {
            $properties = [];
            foreach ($schema['properties'] as $field => $definition) $properties[$field] = self::wireDefinition($definition);
            $schema['properties'] = (object) $properties;
        }
        if (isset($schema['items'])) $schema['items'] = self::wireDefinition($schema['items']);
        if (isset($schema['anyOf'])) $schema['anyOf'] = array_map(self::wireDefinition(...), $schema['anyOf']);
        return $schema;
    }

    public function accepts(mixed $value, array $schema, int $depth = 0, bool $wire = false): bool
    {
        if ($depth > 24) return false;
        $schema = $this->dereference($schema);
        if (isset($schema['anyOf'])) {
            foreach ($schema['anyOf'] as $variant) if ($this->accepts($value, $variant, $depth + 1, $wire)) return true;
            return false;
        }
        if (array_key_exists('const', $schema)) return $value === $schema['const'];
        if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) return false;
        $valid = match ($schema['type'] ?? null) {
            'null' => $value === null,
            'boolean' => is_bool($value),
            'string' => is_string($value),
            'integer' => is_int($value) && abs($value) <= 9007199254740991,
            'object' => $value instanceof \stdClass || !$wire && is_array($value) && ($value === [] || !array_is_list($value)),
            'array' => is_array($value) && array_is_list($value),
            default => false,
        };
        if (!$valid) return false;
        if ($value instanceof \stdClass) $value = get_object_vars($value);
        if (is_string($value)) {
            $length = mb_strlen($value, 'UTF-8');
            if ($length < ($schema['minLength'] ?? 0) || $length > ($schema['maxLength'] ?? PHP_INT_MAX)) return false;
            if (isset($schema['pattern']) && preg_match('~'.str_replace('~', '\\~', $schema['pattern']).'~Du', $value) !== 1) return false;
            if (isset($schema['x-decimal-maximum'])) {
                if (preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) !== 1) return false;
                $maximum = $schema['x-decimal-maximum'];
                if (strlen($value) > strlen($maximum) || strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0) return false;
            }
        }
        if (is_int($value) && ($value < ($schema['minimum'] ?? PHP_INT_MIN) || $value > ($schema['maximum'] ?? PHP_INT_MAX))) return false;
        if (($schema['type'] ?? null) === 'array') {
            if (count($value) < ($schema['minItems'] ?? 0) || count($value) > ($schema['maxItems'] ?? 10000)) return false;
            foreach ($value as $item) if (!$this->accepts($item, $schema['items'], $depth + 1, $wire)) return false;
        }
        if (($schema['type'] ?? null) === 'object') {
            if (array_diff($schema['required'] ?? [], array_keys($value))) return false;
            if (($schema['additionalProperties'] ?? true) !== false || array_diff(array_keys($value), array_keys($schema['properties']))) return false;
            foreach ($value as $field => $item) if (!$this->accepts($item, $schema['properties'][$field], $depth + 1, $wire)) return false;
        }
        return true;
    }

    /** Output projection is recursive and schema-owned; unlisted nested fields never escape. */
    public function project(mixed $value, array $schema, int $depth = 0): mixed
    {
        if ($depth > 24) throw new InvalidArgumentException('CONTRACT_OUTPUT_INVALID');
        $schema = $this->dereference($schema);
        if ($value instanceof \stdClass) $value = get_object_vars($value);
        if (isset($schema['anyOf'])) {
            foreach ($schema['anyOf'] as $variant) {
                try {
                    $projected = $this->project($value, $variant, $depth + 1);
                    if ($this->accepts($projected, $variant)) return $projected;
                } catch (InvalidArgumentException) {
                }
            }
            throw new InvalidArgumentException('CONTRACT_OUTPUT_INVALID');
        }
        if (($schema['type'] ?? null) === 'object' && is_array($value)) {
            $value = array_intersect_key($value, $schema['properties']);
            foreach ($value as $field => &$item) $item = $this->project($item, $schema['properties'][$field], $depth + 1);
            unset($item);
        } elseif (($schema['type'] ?? null) === 'array' && is_array($value)) {
            $value = array_map(fn ($item) => $this->project($item, $schema['items'], $depth + 1), $value);
        }
        if (!$this->accepts($value, $schema)) throw new InvalidArgumentException('CONTRACT_OUTPUT_INVALID');
        return ($schema['type'] ?? null) === 'object' && $value === [] ? new \stdClass : $value;
    }

    private function dereference(array $schema): array
    {
        if (!isset($schema['$ref'])) return $schema;
        $prefix = '#/definitions/';
        if (!str_starts_with($schema['$ref'], $prefix)) throw new InvalidArgumentException('CONTRACT_SCHEMA_INVALID');
        return $this->definitions[substr($schema['$ref'], strlen($prefix))]
            ?? throw new InvalidArgumentException('CONTRACT_SCHEMA_INVALID');
    }
}
