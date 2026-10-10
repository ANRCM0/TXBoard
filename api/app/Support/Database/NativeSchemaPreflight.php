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
        $unqualifiedTables = [];
        foreach ($existingTables as $name) {
            if (!is_string($name)) {
                throw new RuntimeException('Unexpected database table metadata');
            }
            // Laravel/MySQL may return schema-qualified names (e.g. db.tx_user).
            // Normalize before checking BOTH retired prefixes and native markers.
            $table = trim(substr($name, (strrpos($name, '.') ?: -1) + 1), " \`");
            $unqualifiedTables[] = $table;
            if (preg_match('/^v[0-9]+_[a-z]/i', $table)) {
                throw new RuntimeException('Unsupported historical database tables detected. Use a separately verified offline migration.');
            }
        }

        if (in_array('tx_user', $unqualifiedTables, true)) {
            return;
        }

        $frameworkTables = ['migrations', 'failed_jobs', 'personal_access_tokens'];
        if ($hasMigrationHistory || array_diff($unqualifiedTables, $frameworkTables) !== []) {
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
