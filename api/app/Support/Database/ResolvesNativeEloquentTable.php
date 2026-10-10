<?php

declare(strict_types=1);

namespace App\Support\Database;

/**
 * Opt-in Eloquent table name translation. Defaults to legacy v2_*.
 * Never auto-detects schema state: operators must coordinate application and DDL.
 */
trait ResolvesNativeEloquentTable
{
    public function getTable()
    {
        $table = parent::getTable();
        if (!is_string($table) || !str_starts_with($table, 'v2_')) {
            return $table;
        }

        return NativeTableName::resolve(
            $table,
            (bool) config('database_native.native_tables', false)
        );
    }
}
