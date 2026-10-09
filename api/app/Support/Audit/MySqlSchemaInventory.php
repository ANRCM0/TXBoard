<?php

namespace App\Support\Audit;

use RuntimeException;

/**
 * Normalize MySQL information_schema metadata. Never export defaults, comments,
 * raw data, connection settings, cardinality, table sizes or database name.
 */
final class MySqlSchemaInventory
{
    public static function build(array $columnRows, array $indexRows, array $foreignKeyRows = []): array
    {
        $tables = [];
        foreach ($columnRows as $row) {
            $row = (array) $row;
            $name = (string) $row['table_name'];
            $tables[$name] ??= ['table' => $name, 'domain' => self::domain($name),
                'columns' => [], 'indexes' => [], 'foreign_keys' => []];
            $tables[$name]['columns'][] = [
                'name' => (string) $row['column_name'],
                'type' => (string) $row['data_type'],
                'nullable' => (string) $row['is_nullable'] === 'YES',
            ];
        }

        $indexes = [];
        foreach ($indexRows as $row) {
            $row = (array) $row;
            $table = (string) $row['table_name'];
            if (!isset($tables[$table])) {
                throw new RuntimeException('Index belongs to unknown table');
            }
            $index = (string) $row['index_name'];
            $indexes[$table][$index] ??= [
                'name' => $index, 'unique' => (int) $row['non_unique'] === 0,
                'columns' => [],
            ];
            $indexes[$table][$index]['columns'][(int) $row['seq_in_index']] = (string) $row['column_name'];
        }

        foreach ($indexes as $table => $items) {
            ksort($items);
            foreach ($items as &$item) {
                ksort($item['columns'], SORT_NUMERIC);
                $item['columns'] = array_values($item['columns']);
            }
            unset($item);
            $tables[$table]['indexes'] = array_values($items);
        }

        foreach ($foreignKeyRows as $row) {
            $row = (array) $row;
            $table = (string) $row['table_name'];
            if (!isset($tables[$table])) {
                throw new RuntimeException('Foreign key belongs to unknown table');
            }
            $tables[$table]['foreign_keys'][] = [
                'column' => (string) $row['column_name'],
                'references_table' => (string) $row['referenced_table_name'],
                'references_column' => (string) $row['referenced_column_name'],
            ];
        }

        ksort($tables);
        return ['schema_version' => 1, 'source' => 'mysql-information-schema',
            'tables' => array_values($tables), 'invariants' => self::validate($tables)];
    }

    public static function validate(array $tables): array
    {
        $requirements = [
            'v2_user' => [['email']],
            'v2_order' => [['trade_no']],
            'v2_traffic_batch' => [['server_id', 'batch_id']],
        ];
        $results = [];
        foreach ($requirements as $table => $keys) {
            foreach ($keys as $key) {
                $found = false;
                foreach ($tables[$table]['indexes'] ?? [] as $index) {
                    if ($index['unique'] && $index['columns'] === $key) {
                        $found = true;
                        break;
                    }
                }
                $results[] = [
                    'table' => $table,
                    'unique_columns' => $key,
                    'passed' => $found,
                ];
            }
        }
        return $results;
    }

    private static function domain(string $table): string
    {
        if (preg_match('/order|payment|commission|coupon|gift_card/', $table)) return 'Billing';
        if (preg_match('/traffic|stat_|server|machine|reset_log/', $table)) return 'Network/Traffic';
        if (preg_match('/user|token|invite/', $table)) return 'Identity';
        if (preg_match('/plugin|theme|module/', $table)) return 'Extensions';
        return 'Other';
    }
}
