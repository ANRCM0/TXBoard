<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Blocks a native-only deployment from silently creating parallel tx_* tables
 * next to a different already-populated application schema. Never changes data.
 */
final class NativeSchemaPreflight
{
    public static function inspect(array $existingTables, bool $hasMigrationHistory): void
    {
        foreach ($existingTables as $name) {
            if (!is_string($name)) {
                throw new RuntimeException('Unexpected database table metadata');
            }
            if (preg_match('/^v[0-9]+_[a-z]/i', $name)) {
                throw new RuntimeException('Unsupported historical database tables detected. Use a separately verified offline migration.');
            }
        }

        if (in_array('tx_user', $existingTables, true)) {
            return;
        }

        $frameworkTables = ['migrations', 'failed_jobs', 'personal_access_tokens'];
        if ($hasMigrationHistory || array_diff($existingTables, $frameworkTables) !== []) {
            throw new RuntimeException('Nonempty database has no tx_user table. Refusing native initialization to protect existing data.');
        }
    }

    public static function assertReady(): void
    {
        $tables = Schema::getTableListing();
        $hasHistory = Schema::hasTable('migrations') && DB::table('migrations')->exists();
        self::inspect($tables, $hasHistory);
    }
}
