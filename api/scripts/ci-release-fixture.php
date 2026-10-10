<?php

declare(strict_types=1);

/**
 * Synthetic release/restore fixture. Hard guards prevent accidental writes
 * against real environments. Never use this script for production migrations.
 */
require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$phase = $argv[1] ?? '';
$db = DB::connection()->getDatabaseName();
if (getenv('TXBOARD_CI_RESTORE') !== '1' ||
    config('app.env') !== 'testing' ||
    DB::connection()->getDriverName() !== 'mysql' ||
    !in_array($db, ['txboard_release_ci', 'txboard_restore_ci'], true)) {
    fwrite(STDERR, "Refusing fixture outside isolated testing MySQL database.\n");
    exit(2);
}
if (!in_array($phase, ['seed-old', 'verify-upgrade', 'seed-ledgers', 'verify-restore'], true)) {
    fwrite(STDERR, "Usage: php scripts/ci-release-fixture.php seed-old|verify-upgrade|seed-ledgers|verify-restore\n");
    exit(2);
}
$fail = static function (string $message): never {
    fwrite(STDERR, "Release fixture violation: {$message}\n");
    exit(1);
};
$requireEqual = static function ($actual, $expected, string $label) use ($fail): void {
    if ($actual !== $expected) {
        $fail($label . ' does not match expected synthetic value');
    }
};
$email = 'ci-release-upgrade@example.test';
$trade = 'TX-RELEASE-CI-ORDER-0001';
$now = time();
$old = $phase === 'seed-old';

if ($old) {
    if (Schema::hasTable('tx_wallet_recharge') || Schema::hasTable('tx_traffic_batch')) {
        $fail('old baseline already contains new release ledger schema');
    }
    if (DB::table('tx_user')->where('email', $email)->exists()) {
        $fail('fixture already seeded');
    }
    DB::transaction(static function () use ($email, $trade, $now): void {
        $planId = DB::table('tx_plan')->insertGetId([
            'name' => 'Synthetic CI Release Plan', 'group_id' => 1,
            'transfer_enable' => 2, 'sort' => 0, 'show' => 1,
            'sell' => 1, 'renew' => 1,
            'prices' => json_encode(['monthly' => 12], JSON_THROW_ON_ERROR),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $userId = DB::table('tx_user')->insertGetId([
            'email' => $email,
            'password' => password_hash('fixture-only-do-not-use', PASSWORD_BCRYPT),
            'token' => 'c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1',
            'uuid' => '00000000-0000-0000-0000-000000009991',
            'plan_id' => $planId, 'group_id' => 1,
            'balance' => 8450, 'commission_balance' => 275,
            'u' => 1024, 'd' => 2048, 'transfer_enable' => 2147483648,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('tx_order')->insert([
            'trade_no' => $trade, 'user_id' => $userId, 'plan_id' => $planId,
            'period' => 'monthly', 'type' => 1, 'status' => 3,
            'total_amount' => 1299, 'commission_balance' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('tx_settings')->insert([
            ['name' => 'app_name', 'value' => 'TXBoard CI Restore',
             'created_at' => now(), 'updated_at' => now()],
            ['name' => 'frontend_theme_color', 'value' => 'legacy-color-to-purge',
             'created_at' => now(), 'updated_at' => now()],
        ]);
    });
    echo "Seeded synthetic legacy user, order, balances and appearance setting.\n";
    exit(0);
}

$user = DB::table('tx_user')->where('email', $email)->first();
if (!$user) $fail('synthetic user missing');
$requireEqual((int) $user->balance, 8450, 'wallet balance in minor currency units');
$requireEqual((int) $user->commission_balance, 275, 'commission balance in minor currency units');
$requireEqual((int) $user->u, 1024, 'user upstream traffic');
$requireEqual((int) $user->d, 2048, 'user downstream traffic');
$requireEqual((int) $user->transfer_enable, 2147483648, 'user traffic entitlement');
$order = DB::table('tx_order')->where('trade_no', $trade)->first();
if (!$order) $fail('historical order missing');
$requireEqual((int) $order->user_id, (int) $user->id, 'historical order owner');
$requireEqual((int) $order->plan_id, (int) $user->plan_id, 'historical order plan');
$requireEqual((int) $order->total_amount, 1299, 'historical order amount');
$requireEqual((int) $order->status, 3, 'historical order status');
$requireEqual((int) DB::table('tx_settings')->where('name', 'frontend_theme_color')->count(),
    0, 'legacy presentation setting purge');
$requireEqual((string) DB::table('tx_settings')->where('name', 'app_name')->value('value'),
    'TXBoard CI Restore', 'non-legacy setting retention');

foreach (['tx_traffic_batch', 'tx_wallet_recharge'] as $table) {
    if (!Schema::hasTable($table)) $fail($table . ' missing after migration');
}

if ($phase === 'seed-ledgers') {
    if (DB::table('tx_wallet_recharge')->exists() || DB::table('tx_traffic_batch')->exists()) {
        $fail('new ledgers already seeded');
    }
    DB::table('tx_traffic_batch')->insert([
        'server_id' => 1, 'batch_id' => 'ci-release-batch',
        'payload_hash' => str_repeat('a', 64), 'created_at' => $now,
    ]);
    DB::table('tx_wallet_recharge')->insert([
        'user_id' => $user->id, 'payment_id' => 1,
        'trade_no' => 'TX-CI-RECHARGE-0001',
        'request_key' => '00000000-0000-0000-0000-000000009992',
        'amount_minor' => 2500, 'fee_minor' => 0,
        'status' => 0, 'created_at' => $now, 'updated_at' => $now,
    ]);
    echo "Seeded pending synthetic recharge and traffic idempotency ledgers.\n";
    exit(0);
}
if ($phase === 'verify-restore') {
    $batch = DB::table('tx_traffic_batch')->where('batch_id', 'ci-release-batch')->first();
    $recharge = DB::table('tx_wallet_recharge')
        ->where('trade_no', 'TX-CI-RECHARGE-0001')->first();
    if (!$batch || !$recharge) $fail('new idempotency ledger data missing');
    $requireEqual((int) $recharge->user_id, (int) $user->id, 'recharge owner');
    $requireEqual((int) $recharge->amount_minor, 2500, 'recharge amount');
    $requireEqual((int) $recharge->status, 0, 'pending recharge not accidentally credited');
    $requireEqual((int) DB::table('tx_wallet_recharge')->count(), 1, 'recharge count');
    $requireEqual((int) DB::table('tx_traffic_batch')->count(), 1, 'traffic batch count');
}
echo "PASS {$phase}: synthetic balances, paid order, settings and ledger schema intact.\n";
