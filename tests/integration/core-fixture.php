<?php
// This script can mutate only the dedicated integration database.
$root = realpath($argv[1] ?? '');
if (!$root || !is_file($root.'/artisan') || !is_file($root.'/.gateway-integration-snapshot')) {
    throw new RuntimeException('A standalone integration Core snapshot is required');
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.connections.mysql.database') !== 'txboard_gateway_test'
    || config('app.env') !== 'testing' || config('mail.driver') !== 'log') {
    throw new RuntimeException('Refusing to mutate any other database or environment');
}
$action = $argv[2] ?? 'seed';
if ($action === 'captcha-on' || $action === 'captcha-off') {
    admin_setting(['captcha_enable' => $action === 'captcha-on' ? 1 : 0, 'captcha_type' => 'turnstile']);
    echo "{}\n";
    exit;
}
if ($action === 'history' || $action === 'activate-subscription') {
    $user = App\Models\User::findOrFail((int) ($argv[3] ?? 0));
    $plan = App\Models\Plan::findOrFail((int) ($argv[4] ?? 0));
    $expectedPrefix = $action === 'history' ? 'gw-a-' : 'gw-b-';
    if (!str_starts_with($user->email, $expectedPrefix) || $plan->name !== 'Gateway Native fixture') {
        throw new RuntimeException('Only the dedicated integration user and plan may receive historical fixtures');
    }
    if ($action === 'activate-subscription') {
        $user->forceFill(['plan_id' => $plan->id, 'group_id' => $plan->group_id,
            'expired_at' => time() + 86400, 'transfer_enable' => 10 * 1073741824,
            'u' => 123, 'd' => 456, 'device_limit' => $plan->device_limit,
            'speed_limit' => $plan->speed_limit])->save();
        echo "{}\n";
        exit;
    }
    $rows = [];
    $prefix = 'gw-history-'.bin2hex(random_bytes(5));
    for ($i = 0; $i < 101; $i++) {
        $rows[] = [
            'user_id' => $user->id, 'plan_id' => $plan->id, 'period' => 'monthly',
            'trade_no' => $prefix.'-'.$i, 'type' => 1,
            'status' => App\Models\Order::STATUS_DISCOUNTED, 'total_amount' => 1200,
            'created_at' => time() - 1000 - $i, 'updated_at' => time() - 1000 - $i,
        ];
    }
    Illuminate\Support\Facades\DB::table('tx_order')->insert($rows);
    echo json_encode(['trade_no' => $rows[0]['trade_no'], 'count' => count($rows)], JSON_THROW_ON_ERROR)."\n";
    exit;
}
if ($action !== 'seed') throw new RuntimeException('Unknown fixture operation');
admin_setting([
    'app_name' => 'Gateway integration', 'app_url' => 'http://127.0.0.1:18081',
    'captcha_enable' => 0, 'stop_register' => 0, 'email_verify' => 0,
    'invite_force' => 0, 'email_whitelist_enable' => 0, 'register_limit_by_ip_enable' => 0,
]);
if (admin_setting('app_name') !== 'Gateway integration') {
    throw new RuntimeException('Fixture settings must be readable through the real Core settings repository');
}
$plan = App\Models\Plan::create([
    'group_id' => 1, 'name' => 'Gateway Native fixture', 'transfer_enable' => 10,
    'show' => true, 'sell' => true, 'renew' => true, 'sort' => 0,
    'content' => 'Dedicated integration plan', 'prices' => ['monthly' => 12, 'quarterly' => 36],
    'tags' => ['integration'], 'device_limit' => 2, 'speed_limit' => 100, 'capacity_limit' => null,
]);
$payment = App\Models\Payment::create([
    'uuid' => bin2hex(random_bytes(16)), 'name' => 'Gateway display fixture',
    'payment' => 'Stripe', 'config' => ['secret_key' => 'test-fixture-never-expose'],
    'enable' => true, 'sort' => 0, 'handling_fee_fixed' => 20, 'handling_fee_percent' => 1.5,
]);
$notice = App\Models\Notice::create([
    'title' => 'Visible integration notice', 'content' => 'Public notice content',
    'show' => true, 'sort' => 0, 'tags' => ['integration'],
]);
App\Models\Notice::create(['title' => 'Hidden fixture notice', 'content' => 'hidden-notice-secret', 'show' => false]);
$article = App\Models\Knowledge::create([
    'language' => 'en-US', 'category' => 'Integration', 'title' => 'Subscription gated article',
    'body' => 'Public instructions <!--access start-->paid-fixture-secret<!--access end-->',
    'show' => true, 'sort' => 0,
]);
echo json_encode(['plan_id' => $plan->id, 'payment_id' => $payment->id,
    'notice_id' => $notice->id, 'article_id' => $article->id,
    'database_version' => Illuminate\Support\Facades\DB::selectOne('SELECT VERSION() AS version')->version,
], JSON_THROW_ON_ERROR)."\n";
