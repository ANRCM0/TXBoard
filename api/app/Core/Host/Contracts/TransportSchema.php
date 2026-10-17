<?php

namespace App\Core\Host\Contracts;

use InvalidArgumentException;

/** The closed JSON Schema vocabulary emitted by the contract generator. No coercion. */
final class TransportSchema
{
    public function __construct(private readonly array $definitions = []) {}

    public function accepts(mixed $value, array $schema, int $depth = 0): bool
    {
        if ($depth > 24) return false;
        $schema = $this->dereference($schema);
        if (isset($schema['anyOf'])) {
            foreach ($schema['anyOf'] as $variant) if ($this->accepts($value, $variant, $depth + 1)) return true;
            return false;
        }
        if (array_key_exists('const', $schema)) return $value === $schema['const'];
        if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) return false;
        $valid = match ($schema['type'] ?? null) {
            'null' => $value === null,
            'boolean' => is_bool($value),
            'string' => is_string($value),
            'integer' => is_int($value) && abs($value) <= 9007199254740991,
            'object' => is_array($value) && ($value === [] || !array_is_list($value)),
            'array' => is_array($value) && array_is_list($value),
            default => false,
        };
        if (!$valid) return false;
        if (is_string($value)) {
            $length = mb_strlen($value, 'UTF-8');
            if ($length < ($schema['minLength'] ?? 0) || $length > ($schema['maxLength'] ?? PHP_INT_MAX)) return false;
            if (isset($schema['pattern']) && preg_match('~'.str_replace('~', '\\~', $schema['pattern']).'~Du', $value) !== 1) return false;
            if (isset($schema['x-decimal-maximum'])) {
                $maximum = $schema['x-decimal-maximum'];
                if (strlen($value) > strlen($maximum) || strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0) return false;
            }
        }
        if (is_int($value) && ($value < ($schema['minimum'] ?? PHP_INT_MIN) || $value > ($schema['maximum'] ?? PHP_INT_MAX))) return false;
        if (($schema['type'] ?? null) === 'array') {
            if (count($value) < ($schema['minItems'] ?? 0) || count($value) > ($schema['maxItems'] ?? 10000)) return false;
            foreach ($value as $item) if (!$this->accepts($item, $schema['items'], $depth + 1)) return false;
        }
        if (($schema['type'] ?? null) === 'object') {
            if (array_diff($schema['required'] ?? [], array_keys($value))) return false;
            if (($schema['additionalProperties'] ?? true) !== false || array_diff(array_keys($value), array_keys($schema['properties']))) return false;
            foreach ($value as $field => $item) if (!$this->accepts($item, $schema['properties'][$field], $depth + 1)) return false;
        }
        return true;
    }

    /** Output projection is recursive and schema-owned; unlisted nested fields never escape. */
    public function project(mixed $value, array $schema, int $depth = 0): mixed
    {
        if ($depth > 24) throw new InvalidArgumentException('CONTRACT_OUTPUT_INVALID');
        $schema = $this->dereference($schema);
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
        return $value;
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
