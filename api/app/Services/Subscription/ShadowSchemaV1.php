<?php

namespace App\Services\Subscription;

use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Additive DDL replay validator. Never drops or replaces an existing table. */
final class ShadowSchemaV1
{
    public function create(string $table, Closure $definition): void
    {
        if (!Schema::hasTable($table)) {
            Schema::create($table, $definition);
        }
        $expected = new Blueprint(DB::connection(), $table);
        $expected->create();
        $definition($expected);
        $expected->toSql();
        $actualColumns = array_column(Schema::getColumns($table), null, 'name');
        $sqlite = DB::getDriverName() === 'sqlite';
        $enums = [];
        foreach ($expected->getColumns() as $column) {
            $actual = $actualColumns[$column->name] ?? null;
            $types = match ($column->type) {
                'integer' => ['int', 'integer'], 'bigInteger' => $sqlite ? ['integer'] : ['bigint'],
                'tinyInteger' => $sqlite ? ['integer'] : ['tinyint'], 'boolean' => $sqlite ? ['tinyint'] : ['tinyint'],
                'string' => ['varchar'], 'char' => $sqlite ? ['varchar'] : ['char'],
                'longText' => $sqlite ? ['text'] : ['longtext'],
                'enum' => $sqlite ? ['varchar'] : ['enum'], 'json' => $sqlite ? ['text'] : ['json', 'longtext'],
                default => [],
            };
            if ($actual === null || !in_array($actual['type_name'], $types, true)
                || (!$column->autoIncrement && $actual['nullable'] !== (bool) $column->nullable)
                || (!$sqlite && (bool) $actual['auto_increment'] !== (bool) $column->autoIncrement)
                || (!$sqlite && in_array($column->type, ['integer', 'bigInteger', 'tinyInteger'], true)
                    && str_contains($actual['type'], 'unsigned') !== (bool) $column->unsigned)
                || (!$sqlite && in_array($column->type, ['string', 'char'], true)
                    && $actual['type'] !== $actual['type_name'].'('.$column->length.')')) {
                throw new RuntimeException("Shadow schema mismatch: {$table}.{$column->name}");
            }
            $actualDefault = $actual['default'] === null || strtoupper((string) $actual['default']) === 'NULL'
                ? null : trim((string) $actual['default'], "()'\"");
            $default = $column->default === null ? null : (string) $column->default;
            if ($actualDefault !== $default) {
                throw new RuntimeException("Shadow default mismatch: {$table}.{$column->name}");
            }
            if ($column->autoIncrement && !Schema::hasIndex($table, [$column->name], 'primary')) {
                throw new RuntimeException("Shadow primary key mismatch: {$table}");
            }
            if ($column->type === 'enum') {
                $enums[] = $column;
            }
        }
        $sqliteSql = $sqlite ? (string) DB::table('sqlite_master')->where('type', 'table')->where('name', $table)->value('sql') : '';
        foreach ($enums as $column) {
            $allowed = implode(',', array_map(fn ($value) => "'".str_replace("'", "''", $value)."'", $column->allowed));
            $actual = $actualColumns[$column->name];
            if ($sqlite) {
                $name = preg_quote($column->name, '/');
                preg_match('/check\s*\(\s*["`]?'.$name.'["`]?\s+in\s*\(([^)]*)\)\s*\)/i', $sqliteSql, $matches);
                if (!$matches) {
                    $this->alter($table, fn (Blueprint $blueprint) => null, $enums);
                    $sqliteSql = (string) DB::table('sqlite_master')->where('type', 'table')->where('name', $table)->value('sql');
                    preg_match('/check\s*\(\s*["`]?'.$name.'["`]?\s+in\s*\(([^)]*)\)\s*\)/i', $sqliteSql, $matches);
                }
                $actualAllowed = isset($matches[1]) ? preg_replace('/\s+/', '', $matches[1]) : null;
            } else {
                $actualAllowed = substr($actual['type'], 5, -1);
            }
            if ($actualAllowed !== $allowed) {
                throw new RuntimeException("Shadow enum mismatch: {$table}.{$column->name}");
            }
        }
        foreach (Schema::getForeignKeys($table) as $key) {
            if (!in_array($key['on_delete'], ['restrict', 'no action'], true)
                || !in_array($key['on_update'], ['restrict', 'no action'], true)
                || in_array($key['foreign_table'], ['tx_user', 'tx_plan'], true)) {
                throw new RuntimeException("Shadow foreign key does not preserve compatibility/history: {$table}");
            }
        }
        foreach ($expected->getCommands() as $command) {
            if (in_array($command->name, ['primary', 'unique', 'index'], true)) {
                if (Schema::hasIndex($table, $command->columns, $command->name === 'index' ? null : $command->name)) {
                    continue;
                }
                if (Schema::hasIndex($table, $command->index)) {
                    throw new RuntimeException("Shadow index mismatch: {$table}.{$command->index}");
                }
                Schema::table($table, function (Blueprint $blueprint) use ($command) {
                    $method = $command->name;
                    $blueprint->$method($command->columns, $command->index);
                });
            } elseif ($command->name === 'foreign') {
                $found = false;
                foreach (Schema::getForeignKeys($table) as $key) {
                    if ($key['columns'] === $command->columns || $key['name'] === $command->index) {
                        if ($key['columns'] !== $command->columns || $key['foreign_table'] !== $command->on
                            || $key['foreign_columns'] !== (array) $command->references) {
                            throw new RuntimeException("Shadow foreign key mismatch: {$table}.{$command->index}");
                        }
                        $found = true;
                    }
                }
                if (!$found) {
                    $this->alter($table, function (Blueprint $blueprint) use ($command) {
                        $blueprint->foreign($command->columns, $command->index)->references($command->references)
                            ->on($command->on)->restrictOnDelete();
                    }, $enums);
                }
            }
        }
    }

    private function alter(string $table, Closure $alter, array $enums): void
    {
        Schema::table($table, function (Blueprint $blueprint) use ($alter, $enums) {
            $alter($blueprint);
            if (DB::getDriverName() === 'sqlite') {
                foreach ($enums as $column) {
                    $definition = $blueprint->enum($column->name, $column->allowed)->nullable((bool) $column->nullable)->change();
                    if ($column->default !== null) {
                        $definition->default($column->default);
                    }
                }
            }
        });
    }
}
