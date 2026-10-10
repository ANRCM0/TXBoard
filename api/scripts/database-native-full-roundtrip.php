<?php

declare(strict_types=1);

/**
 * Isolated MySQL CI ONLY. Seeds test data, executes an approved all-table
 * atomic forward rename, checks every row, then executes reverse rename.
 * No production plan or live database may be supplied to this script.
 */
require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\Database\NativeTableName;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (getenv('APP_ENV') !== 'testing' ||
    DB::connection()->getDriverName() !== 'mysql' ||
    DB::connection()->getDatabaseName() !== 'txboard_ci') {
    throw new RuntimeException('Full cutover roundtrip only runs on isolated txboard_ci in testing mode');
}

$list = static function (): array {
    return array_map(static fn (object $x): string => (string) $x->table_name,
        DB::select("SELECT TABLE_NAME AS table_name FROM information_schema.TABLES
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"));
};
$legacy = array_values(array_filter($list(), static fn (string $s): bool => str_starts_with($s, 'v2_')));
sort($legacy, SORT_STRING);
if (count($legacy) < 20 || in_array('tx_user', $list(), true)) {
    throw new RuntimeException('Expected an empty-schema-replay V2 test database with no native targets');
}
foreach (['v2_settings', 'v2_user', 'v2_order', 'v2_wallet_recharge', 'v2_traffic_batch'] as $key) {
    if (!in_array($key, $legacy, true)) {
        throw new RuntimeException("Required schema missing: {$key}");
    }
}
DB::table('v2_settings')->insert([
    'name' => 'tx_cutover_ci_fixture',
    'value' => 'preserved',
    'created_at' => now(),
    'updated_at' => now(),
]);
DB::table('v2_traffic_batch')->insert([
    'server_id' => 987, 'batch_id' => 'tx-cutover-ci-batch',
    'payload_hash' => str_repeat('f', 64), 'created_at' => time(),
]);

// CI schemas are small. Full row snapshots are explicitly NOT for production.
$fingerprint = static function (string $table): string {
    $rows = [];
    foreach (DB::table($table)->get() as $row) {
        $values = (array) $row;
        ksort($values, SORT_STRING);
        $rows[] = json_encode($values, JSON_THROW_ON_ERROR);
    }
    sort($rows, SORT_STRING);
    return hash('sha256', implode("\n", $rows));
};
$before = [];
foreach ($legacy as $table) {
    $before[$table] = $fingerprint($table);
}

$plan = [
    'schemaVersion' => 1,
    'kind' => 'native-table-cutover-plan',
    'executable' => true, 'requiresManualApproval' => false, 'blockers' => [],
    'proposedRenames' => array_map(
        static fn (string $name): array => ['from' => $name, 'to' => NativeTableName::resolve($name, true)],
        $legacy
    ),
];
$path = tempnam(sys_get_temp_dir(), 'tx-native-test-plan-');
if ($path === false) {
    throw new RuntimeException('Failed to allocate isolated test plan');
}
file_put_contents($path, json_encode($plan, JSON_THROW_ON_ERROR));
putenv('TXBOARD_CUTOVER_APPROVED=1');
putenv('TXBOARD_BACKUP_VERIFIED=1');
$up = false;
try {
    if (Artisan::call('txboard:database-cutover', ['--plan' => $path, '--direction' => 'up']) !== 0) {
        throw new RuntimeException('Read-only preflight rejected the synthetic approved plan');
    }
    Artisan::call('down');
    if (Artisan::call('txboard:database-cutover', [
        '--plan' => $path, '--direction' => 'up', '--execute' => true
    ]) !== 0) {
        throw new RuntimeException('Forward full-schema atomic rename failed: ' . Artisan::output());
    }
    $up = true;
    Config::set('database_native.native_tables', true);
    if ((new \App\Models\Setting())->getTable() !== 'tx_settings') {
        throw new RuntimeException('Eloquent was not switched to native naming');
    }
    foreach ($legacy as $old) {
        $new = NativeTableName::resolve($old, true);
        if (Schema::hasTable($old) || !Schema::hasTable($new) || $before[$old] !== $fingerprint($new)) {
            throw new RuntimeException('Native row-level fidelity failed for ' . $old);
        }
    }

    if (Artisan::call('txboard:database-cutover', [
        '--plan' => $path, '--direction' => 'down', '--execute' => true
    ]) !== 0) {
        throw new RuntimeException('Reverse full-schema atomic rename failed: ' . Artisan::output());
    }
    $up = false;
    Config::set('database_native.native_tables', false);
    foreach ($legacy as $old) {
        if (!Schema::hasTable($old) || Schema::hasTable(NativeTableName::resolve($old, true)) ||
            $before[$old] !== $fingerprint($old)) {
            throw new RuntimeException('Rollback row-level fidelity failed for ' . $old);
        }
    }
    echo "PASS: " . count($legacy) . " real MySQL 8.4 application tables renamed in one DDL statement, rows preserved and rolled back.\n";
} finally {
    if ($up) {
        // The CI DB is disposable; nevertheless try to restore its legacy shape.
        Artisan::call('txboard:database-cutover', [
            '--plan' => $path, '--direction' => 'down', '--execute' => true
        ]);
    }
    Artisan::call('up');
    @unlink($path);
    putenv('TXBOARD_CUTOVER_APPROVED');
    putenv('TXBOARD_BACKUP_VERIFIED');
}
