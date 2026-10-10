<?php

declare(strict_types=1);

/**
 * Read-only, row-level SHA-256 evidence for critical tables.
 * Only digests and row counts are written; no personal or financial row values.
 */
require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$options = getopt('', ['output:', 'prefix:']);
$prefix = $options['prefix'] ?? 'v2';
if (!isset($options['output']) || !is_string($options['output']) || !in_array($prefix, ['v2', 'tx'], true)) {
    fwrite(STDERR, "Usage: php scripts/database-critical-row-fingerprints.php --output=PATH [--prefix=v2|tx]\n");
    exit(2);
}
if (DB::connection()->getDriverName() !== 'mysql') {
    fwrite(STDERR, "MySQL required for row-level evidence\n");
    exit(2);
}

$domains = ['user', 'order', 'wallet_recharge', 'commission_log', 'traffic_batch', 'stat_user', 'stat_server'];
$report = ['schema_version' => 1, 'read_only' => true, 'kind' => 'critical-row-fingerprints',
    'table_prefix' => $prefix, 'tables' => []];
foreach ($domains as $domain) {
    $table = $prefix . '_' . $domain;
    if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'id')) {
        throw new RuntimeException("Missing table or id column: {$table}");
    }
    $columns = Schema::getColumnListing($table);
    sort($columns, SORT_STRING);
    $state = hash_init('sha256');
    $count = 0;
    $previousId = null;
    // Stable ordering, bounded memory, and strict monotonic id checks.
    foreach (DB::table($table)->orderBy('id')->cursor() as $row) {
        $record = (array) $row;
        if ($previousId !== null && (string) $record['id'] === (string) $previousId) {
            throw new RuntimeException("Duplicate id encountered: {$table}");
        }
        $previousId = $record['id'];
        $ordered = [];
        foreach ($columns as $column) {
            $ordered[$column] = $record[$column] === null ? null : (string) $record[$column];
        }
        hash_update($state, hash('sha256', json_encode($ordered, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)) . "\n");
        $count++;
    }
    $report['tables'][$domain] = [
        'rows' => (string) $count,
        'columns' => $columns,
        'sha256' => hash_final($state),
    ];
}
$output = $options['output'];
$dir = dirname($output);
if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
    throw new RuntimeException('Cannot create output directory');
}
file_put_contents($output, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n", LOCK_EX);
@chmod($output, 0600);
fwrite(STDOUT, "Read-only critical row fingerprints captured for " . count($domains) . " tables.\n");
