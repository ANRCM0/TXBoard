<?php

// Disposable B5.2/B5.3/B5.4/B5.5 fixture; never registered as an HTTP endpoint.
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
    'display-reads' => (function () use ($user): void {
        foreach ([[$user->id, 0], [$user->id, 1], [$user->id+100, 0]] as [$owner, $status]) {
            App\Models\Ticket::create(['user_id' => $owner, 'subject' => 'Fixture ticket', 'level' => 1, 'status' => $status]);
        }
        App\Models\User::create(['email' => 'internal-invited@example.test', 'password' => 'fixture-only',
            'uuid' => (string) Illuminate\Support\Str::uuid(), 'token' => 'private-invited-token', 'invite_user_id' => $user->id]);
        foreach ([['Disabled', false, 0, 0, 0], ['Fixed method', true, 2, 17, 2.5], ['Zero method', true, 1, 0, 0]]
                 as [$name, $enabled, $sort, $fee, $percent]) {
            App\Models\Payment::create(['uuid' => str_replace('-', '', (string) Illuminate\Support\Str::uuid()), 'name' => $name, 'payment' => 'NotAProvider', 'enable' => $enabled,
                'sort' => $sort, 'handling_fee_fixed' => $fee, 'handling_fee_percent' => $percent,
                'config' => ['secret' => 'must-never-return-provider-secret']]);
        }
    })(),
    'notice-reads' => (function (): void {
        foreach ([['Hidden', 0, false], ['First visible', 1, true], ['Second visible', 1, true], ['Last visible', 2, true]] as [$name, $sort, $show]) {
            $n = App\Models\Notice::create(['title' => $name, 'content' => '<p>Fixture content</p>', 'tags' => ['fixture'], 'sort' => $sort, 'show' => $show]);
            Illuminate\Support\Facades\DB::table('tx_notice')->where('id', $n->id)->update(['created_at' => 1791763200]);
        }
    })(),
    'summary-plan-missing' => $user->update(['plan_id' => $order->plan_id + 100]),
    'summary-plan-restore' => $user->update(['plan_id' => $order->plan_id]),
    'notice-oversize' => (function (): void {
        $updated = Illuminate\Support\Facades\DB::table('tx_notice')->where('title', 'Second visible')->update(['content' => str_repeat('x', 64000)]);
        // Both rows fit native TEXT while their combined response exceeds the envelope cap.
        $updated += Illuminate\Support\Facades\DB::table('tx_notice')->where('title', 'First visible')->update(['content' => str_repeat('x', 2000)]);
        if ($updated !== 2) throw new RuntimeException('Oversize fixture did not update both visible rows');
    })(),
    'notice-restore' => Illuminate\Support\Facades\DB::table('tx_notice')->whereIn('title', ['Second visible', 'First visible'])->update(['content' => '<p>Fixture content</p>']),
    'overflow' => $user->update(['u' => 9007199254740992]),
    'restore-values' => $user->update(['u' => 100]),
    'plan-missing' => $order->update(['plan_id' => $order->plan_id + 100]),
    'list-orders' => (function () use ($user, $order): void {
        foreach ([['internal-list-unpaid', 0, $order->plan_id, $user->id],
                  ['internal-list-missing-plan', 4, $order->plan_id + 100, $user->id],
                  ['internal-list-foreign', 4, $order->plan_id, $user->id + 100]] as [$trade, $status, $plan, $owner]) {
            App\Models\Order::create(['user_id' => $owner, 'plan_id' => $plan, 'trade_no' => $trade,
                'period' => App\Models\Plan::PERIOD_MONTHLY, 'type' => 1, 'status' => $status,
                'total_amount' => 1200, 'callback_no' => 'must-never-return-callback']);
        }
        Illuminate\Support\Facades\DB::table('tx_order')->update(['created_at' => 1791763200]);
    })(),
    default => throw new RuntimeException('Unknown fixture action'),
};
echo json_encode(['ok' => true]);
