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


$owner = \App\Models\User::create([
    'email' => 'tx-cutover-owner@example.test',
    'password' => password_hash('fixture-only', PASSWORD_BCRYPT),
    'uuid' => '00000000-0000-0000-0000-000000007701',
    'token' => str_repeat('7', 32),
    'balance' => 8450, 'commission_balance' => 275,
    'u' => 0, 'd' => 0, 'transfer_enable' => 1073741824,
    'created_at' => time(), 'updated_at' => time(),
]);
$planModel = \App\Models\Plan::create([
    'name' => 'Cutover fidelity plan', 'group_id' => 1,
    'transfer_enable' => 2, 'show' => 1, 'sort' => 0,
    'sell' => 1, 'renew' => 1,
    'prices' => [\App\Models\Plan::PERIOD_MONTHLY => 10],
    'reset_traffic_method' => \App\Models\Plan::RESET_TRAFFIC_MONTHLY,
    'created_at' => time(), 'updated_at' => time(),
]);
$payment = \App\Models\Payment::create([
    'uuid' => 'tx-cutover-payment-7701',
    'payment' => 'EPay', 'name' => 'Synthetic cutover gateway',
    'enable' => true, 'config' => ['key' => 'fixture-only'],
    'created_at' => time(), 'updated_at' => time(),
]);
\App\Models\Order::create([
    'user_id' => $owner->id, 'plan_id' => $planModel->id,
    'payment_id' => $payment->id,
    'trade_no' => 'TX-CUTOVER-CI-ORDER-7701',
    'period' => \App\Models\Plan::PERIOD_MONTHLY,
    'type' => \App\Models\Order::TYPE_NEW_PURCHASE,
    'status' => \App\Models\Order::STATUS_COMPLETED,
    'total_amount' => 1299,
    'created_at' => time(), 'updated_at' => time(),
]);
DB::table('v2_wallet_recharge')->insert([
    'user_id' => $owner->id, 'payment_id' => $payment->id,
    'trade_no' => 'TX-CUTOVER-CI-RECHARGE-7701',
    'request_key' => '00000000-0000-0000-0000-000000007702',
    'amount_minor' => 2500, 'fee_minor' => 0,
    'status' => 0, 'created_at' => time(), 'updated_at' => time(),
]);
$server = \App\Models\Server::create([
    'name' => 'cutover-ci-node', 'type' => \App\Models\Server::TYPE_VMESS,
    'host' => '127.0.0.1', 'port' => '443', 'server_port' => 443,
    'rate' => 2, 'group_ids' => [1], 'enabled' => true,
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


    // Verify actual model and raw-SQL reads while every live application
    // table has the native name. The financial amounts are in minor units.
    $nativeOwner = \App\Models\User::findOrFail($owner->id);
    if ((int) $nativeOwner->balance !== 8450 ||
        (int) $nativeOwner->commission_balance !== 275 ||
        (int) \App\Models\Order::where('trade_no', 'TX-CUTOVER-CI-ORDER-7701')->value('total_amount') !== 1299 ||
        (int) \App\Models\WalletRecharge::where('trade_no', 'TX-CUTOVER-CI-RECHARGE-7701')->value('amount_minor') !== 2500 ||
        (string) \App\Models\Setting::where('name', 'tx_cutover_ci_fixture')->value('value') !== 'preserved') {
        throw new RuntimeException('Native financial or settings read-path validation failed');
    }

    // Exercise the production traffic job against tx_* tables, including
    // idempotent replay, rather than merely checking table names.
    $job = new \App\Jobs\TrafficBatchJob(
        ['id' => $server->id, 'rate' => 2],
        [$owner->id => [100, 300]],
        'vmess',
        strtotime(date('Y-m-d')),
        'tx-native-settlement-7701'
    );
    $job->handle();
    $job->handle();
    $settledOwner = \App\Models\User::findOrFail($owner->id);
    if ((int) $settledOwner->u !== 200 || (int) $settledOwner->d !== 600 ||
        (int) \App\Models\Server::findOrFail($server->id)->u !== 100 ||
        (int) \App\Models\Server::findOrFail($server->id)->d !== 300 ||
        DB::table(NativeTableName::runtime('v2_traffic_batch'))
            ->where('batch_id', 'tx-native-settlement-7701')->count() !== 1 ||
        DB::table(NativeTableName::runtime('v2_stat_user'))
            ->where('user_id', $owner->id)->count() !== 1 ||
        DB::table(NativeTableName::runtime('v2_stat_server'))
            ->where('server_id', $server->id)->count() !== 1) {
        throw new RuntimeException('Native transaction or idempotent traffic settlement failed');
    }

    // Re-run a shipped idempotent settings migration after the rename.
    // Its table lookup must hit tx_settings; a hardcoded v2_settings would
    // silently skip the cleanup, even when ordinary model tests pass.
    $settingsTable = NativeTableName::runtime('v2_settings');
    DB::table($settingsTable)->insert([
        'name' => 'server_ws_enable', 'value' => '1',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $cleanup = require dirname(__DIR__) . '/database/migrations/2026_10_10_000001_purge_retired_node_and_captcha_settings.php';
    $cleanup->up();
    if (DB::table($settingsTable)->where('name', 'server_ws_enable')->exists()) {
        throw new RuntimeException('Prefix-aware native migration failed to purge obsolete settings');
    }

    // The reverse rename must preserve successful native writes, not merely
    // the original pre-cutover rows. Never simulate rollback by dropping data.
    $afterNativeWrites = [];
    foreach ($legacy as $old) {
        $afterNativeWrites[$old] = $fingerprint(NativeTableName::resolve($old, true));
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
            $afterNativeWrites[$old] !== $fingerprint($old)) {
            throw new RuntimeException('Rollback row-level fidelity failed for ' . $old);
        }
    }
    echo "PASS: " . count($legacy) . " MySQL 8.4 tables, financial rows, native traffic writes and rollback fingerprints verified.\n";
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
