<?php

// Disposable B5.2 fixture; never registered as an HTTP endpoint.
$api = realpath($argv[1] ?? '');
if (!$api || !is_file($api.'/vendor/autoload.php')) throw new RuntimeException('Pass isolated Core api');
require $api.'/vendor/autoload.php';
$app = require $api.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!$app->environment('testing') || config('database.default') !== 'mysql'
    || config('database.connections.mysql.database') !== 'txboard_internal_test') {
    throw new RuntimeException('Exclusive disposable txboard_internal_test required');
}
$user = App\Models\User::query()->where('email', 'internal-user@example.test')->firstOrFail();
$order = App\Models\Order::query()->where('trade_no', 'internal-discounted-order')->firstOrFail();
match ($argv[2] ?? '') {
    'decorate' => (function () use ($user, $order): void {
        $user->update(['telegram_id' => -1234567, 'plan_id' => $order->plan_id, 'balance' => 1234,
            'commission_balance' => 567, 'u' => 100, 'd' => 200, 'transfer_enable' => 1073741824, 'expired_at' => time()+86400]);
        $order->update(['payment_id' => 7, 'balance_amount' => 300, 'discount_amount' => 150]);
    })(),
    'overflow' => $user->update(['u' => 9007199254740992]),
    'restore-values' => $user->update(['u' => 100]),
    'plan-missing' => $order->update(['plan_id' => $order->plan_id + 100]),
    default => throw new RuntimeException('Unknown fixture action'),
};
echo json_encode(['ok' => true]);
