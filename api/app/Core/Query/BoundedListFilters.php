<?php

namespace App\Core\Query;

use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/** Owners supply field definitions; browser data never supplies SQL identifiers or operators. */
final class BoundedListFilters
{
    public static function validate(array $rules, array $fields): void
    {
        if (!array_is_list($rules) || count($rules) > 10) self::invalid();
        foreach ($rules as $rule) {
            if (!is_array($rule) || array_diff(array_keys($rule), ['field', 'operator', 'value'])
                || !is_string($rule['field'] ?? null) || !isset($fields[$rule['field']])
                || !is_string($rule['operator'] ?? null) || !is_string($rule['value'] ?? null)) self::invalid();
            $definition = $fields[$rule['field']]; $operator = $rule['operator']; $value = $rule['value'];
            $type = $definition['type'];
            $operators = match ($type) { 'text' => ['eq', 'contains', 'starts_with'], 'boolean', 'enum' => ['eq'], default => ['eq', 'gt', 'gte', 'lt', 'lte'] };
            if (($definition['nullable'] ?? false) && in_array($operator, ['is_null', 'not_null'], true)) {
                if ($value !== '') self::invalid();
                continue;
            }
            if (!in_array($operator, $operators, true)) self::invalid();
            if ($type === 'text' && ($value === '' || strlen($value) > ($definition['max'] ?? 255) || preg_match('/[\x00-\x1f\x7f]/', $value))) self::invalid();
            if ($type === 'boolean' && !in_array($value, ['0', '1'], true)) self::invalid();
            if ($type === 'enum' && !in_array($value, $definition['values'], true)) self::invalid();
            if (in_array($type, ['number', 'timestamp'], true)
                && (!preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/D', $value)
                    || bccomp($value, $type === 'timestamp' ? '9999999999' : (string) PHP_INT_MAX, 0) > 0
                    || (($definition['positive'] ?? false) && $value === '0'))) self::invalid();
        }
    }

    public static function apply(Builder $query, string $column, array $rule): void
    {
        $operator = $rule['operator']; $value = $rule['value'];
        if ($operator === 'is_null') { $query->whereNull($column); return; }
        if ($operator === 'not_null') { $query->whereNotNull($column); return; }
        if (in_array($operator, ['contains', 'starts_with'], true)) {
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], strtolower($value));
            // The caller's constant identifier is quoted by the connection grammar.
            $quoted = $query->getQuery()->getGrammar()->wrap($column);
            $query->whereRaw('LOWER('.$quoted.") LIKE ? ESCAPE '!'", [($operator === 'contains' ? '%' : '').$escaped.'%']);
            return;
        }
        $query->where($column, ['eq' => '=', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='][$operator], $value);
    }

    private static function invalid(): never { throw new InvalidArgumentException('ADMIN_FILTER_INVALID'); }
}
