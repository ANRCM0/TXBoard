<?php

// Disposable fixture only. It is not registered in the HTTP router.
require getenv('INTERNAL_CORE_API').'/vendor/autoload.php';
$app = require getenv('INTERNAL_CORE_API').'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!$app->environment('testing') || config('database.default') !== 'mysql'
    || config('database.connections.mysql.database') !== (getenv('INTERNAL_REGISTRATION_SHADOW_VERIFY') === 'true'
        ? 'txboard_registration_transport_test' : 'txboard_internal_test')) {
    throw new RuntimeException('Exclusive disposable txboard_internal_test required');
}
$mode = $argv[1] ?? 'seed';
if ($mode === 'rate-fill' || $mode === 'rate-reset') {
    $window = intdiv(time(), 60) * 60;
    Illuminate\Support\Facades\DB::table('tx_internal_readonly_windows')->upsert([
        'service' => 'user-gateway', 'window_start' => $window,
        'requests' => $mode === 'rate-fill' ? 60 : 0, 'expires_at' => $window + 120,
    ], ['service', 'window_start'], ['requests', 'expires_at']);
    exit;
}
if ($mode === 'database-version') {
    echo Illuminate\Support\Facades\DB::selectOne('SELECT VERSION() AS version')->version;
    exit;
}
if ($mode === 'nonce-count') {
    echo Illuminate\Support\Facades\DB::table('tx_internal_readonly_nonces')->count();
    exit;
}
if ($mode === 'audit-check') {
    $db = Illuminate\Support\Facades\DB::class;
    $rows = $db::table('tx_internal_readonly_audits')->get()->all();
    $serialized = json_encode($rows);
    foreach (['Bearer ', 'PRIVATE KEY', 'must-never-return-callback', 'private-subscribe-token'] as $needle) {
        if (str_contains($serialized, $needle)) throw new RuntimeException('Audit leaked secret');
    }
    echo json_encode(['audit_rows' => count($rows), 'nonce_rows' => $db::table('tx_internal_readonly_nonces')->count()]);
    exit;
}
if ($mode === 'storage-off') {
    Illuminate\Support\Facades\Schema::rename('tx_internal_readonly_nonces', 'tx_internal_readonly_nonces_off');
    exit;
}
if ($mode === 'storage-on') {
    Illuminate\Support\Facades\Schema::rename('tx_internal_readonly_nonces_off', 'tx_internal_readonly_nonces');
    exit;
}
if ($mode === 'mutate') {
    $actor = App\Models\User::query()->where('email', 'internal-'.$argv[2].'@example.test')->firstOrFail();
    match ($argv[3]) {
        'ban' => $actor->update(['banned' => true]),
        'unban' => $actor->update(['banned' => false]),
        'demote' => $actor->update(['is_admin' => false]),
        'promote' => $actor->update(['is_admin' => true]),
        'revoke' => $actor->tokens()->delete(),
        default => throw new RuntimeException('Unknown fixture mutation'),
    };
    exit;
}
if ($mode !== 'seed' || App\Models\User::query()->count() !== 0) {
    throw new RuntimeException('Seed requires empty disposable application tables');
}
$actors = [];
$credentials = [];
foreach (['user', 'other', 'admin'] as $role) {
    $actors[$role] = App\Models\User::create([
        'email' => 'internal-'.$role.'@example.test', 'password' => Illuminate\Support\Facades\Hash::make('fixture-password-only'),
        'uuid' => (string) Illuminate\Support\Str::uuid(), 'token' => 'private-subscribe-token-'.$role,
        'is_admin' => $role === 'admin', 'banned' => false,
    ]);
    $credentials[$role] = 'Bearer '.$actors[$role]->createToken('internal-fixture-'.$role, ['*'], now()->addHour())->plainTextToken;
}
$credentials['expired'] = 'Bearer '.$actors['user']->createToken('expired', ['*'], now()->subMinute())->plainTextToken;
$plan = App\Models\Plan::create(['name' => 'Internal Read Plan', 'group_id' => 1, 'transfer_enable' => 1,
    'show' => true, 'sell' => true, 'renew' => true, 'prices' => [App\Models\Plan::PERIOD_MONTHLY => 12]]);
$order = App\Models\Order::create(['user_id' => $actors['user']->id, 'plan_id' => $plan->id,
    'trade_no' => 'internal-discounted-order', 'period' => App\Models\Plan::PERIOD_MONTHLY,
    'status' => App\Models\Order::STATUS_DISCOUNTED, 'type' => App\Models\Order::TYPE_NEW_PURCHASE,
    'total_amount' => 1200, 'callback_no' => 'must-never-return-callback']);
echo json_encode(['credentials' => $credentials, 'order_id' => (string) $order->id, 'trade_no' => $order->trade_no,
    'user_id' => (string) $actors['user']->id, 'admin_path' => hash('crc32b', config('app.key'))], JSON_THROW_ON_ERROR);
