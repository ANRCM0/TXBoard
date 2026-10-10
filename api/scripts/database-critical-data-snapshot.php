<?php

declare(strict_types=1);

/**
 * Read-only cutover baseline. Aggregate counts/sums only; no user identifiers,
 * payment references, credentials, individual records or mutable operations.
 */
require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$options = getopt('', ['output:']);
if (!isset($options['output']) || !is_string($options['output'])) {
    fwrite(STDERR, "Usage: php scripts/database-critical-data-snapshot.php --output=artifacts/critical-data.json\n");
    exit(2);
}
if (DB::connection()->getDriverName() !== 'mysql') {
    fwrite(STDERR, "MySQL required for cutover evidence\n");
    exit(2);
}

$tables = [
    'user' => ['v2_user', ['balance', 'commission_balance', 'u', 'd', 'transfer_enable']],
    'order' => ['v2_order', ['total_amount', 'balance_amount', 'handling_amount', 'discount_amount']],
    'wallet_recharge' => ['v2_wallet_recharge', ['amount_minor', 'fee_minor']],
    'commission_log' => ['v2_commission_log', ['get_amount']],
    'traffic_batch' => ['v2_traffic_batch', []],
    'stat_user' => ['v2_stat_user', ['u', 'd']],
    'stat_server' => ['v2_stat_server', ['u', 'd']],
];
$report = ['schema_version' => 1, 'read_only' => true, 'scope' => 'aggregate-cutover-evidence',
    'not_a_row_level_proof' => true, 'tables' => []];
foreach ($tables as $domain => [$table, $amounts]) {
    if (!Schema::hasTable($table)) {
        fwrite(STDERR, "Missing required critical table: {$table}\n");
        exit(1);
    }
    $metrics = ['rows' => (string) DB::table($table)->count()];
    foreach ($amounts as $column) {
        if (!Schema::hasColumn($table, $column)) {
            fwrite(STDERR, "Missing critical column: {$table}.{$column}\n");
            exit(1);
        }
        // Preserve database DECIMAL/large integer precision; never coerce to float.
        $value = DB::table($table)->selectRaw('SUM(' . $column . ') AS aggregate_total')->first();
        $metrics['sum_' . $column] = (string) ($value->aggregate_total ?? '0');
    }
    $report['tables'][$domain] = $metrics;
}
$output = $options['output'];
$dir = dirname($output);
if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
    throw new RuntimeException('Could not create output directory');
}
file_put_contents($output, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", LOCK_EX);
@chmod($output, 0600);
fwrite(STDOUT, "Critical aggregate baseline captured for " . count($report['tables']) . " tables. This is not a row-level integrity proof.\n");
