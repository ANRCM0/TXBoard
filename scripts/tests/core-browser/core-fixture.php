<?php
// CI only: all mutable fixture operations require the isolated snapshot and DB.
$root = realpath($argv[1] ?? '');
if (!$root || !is_file($root.'/artisan') || !is_file($root.'/.core-browser-integration-snapshot')) {
    throw new RuntimeException('A standalone integration Core snapshot is required');
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, 'CORE_FIXTURE_FAILED: '.get_class($error)."\n");
    exit(1);
});
if (config('database.connections.mysql.database') !== 'txboard_browser_test'
    || config('app.env') !== 'testing' || config('mail.driver') !== 'log') {
    throw new RuntimeException('Refusing to mutate any other database or environment');
}
$action = $argv[2] ?? 'seed';
if ($action === 'browser-user') {
    $service = [];
    // Provision isolated actors through Identity; browser login policy is
    // independently exercised through the direct HTTP administrator path.
    foreach (['owner', 'outsider'] as $name) {
        [$created, $user] = app(\App\Services\Auth\NativeIdentityService::class)
            ->create('gw-sdk-'.$name.'-'.bin2hex(random_bytes(6)).'@example.test', 'integration-password-2026');
        if (!$created) throw new RuntimeException('SDK fixture actor creation failed');
        $service[$name] = 'Bearer '.$user->createToken('browser-sdk-'.$name, ['*'])->plainTextToken;
        if ($name === 'owner') $service['secondary'] = (string) $user->createToken('browser-sdk-secondary', ['*'])->accessToken->id;
    }
    echo json_encode($service, JSON_THROW_ON_ERROR)."\n";
    exit;
}
if ($action === 'browser-admin') {
    $service = [];
    [$created, $admin] = app(\App\Services\Auth\NativeIdentityService::class)
        ->create('gw-admin-'.bin2hex(random_bytes(6)).'@example.test', 'admin-integration-password');
    if (!$created) throw new RuntimeException('Admin fixture creation failed');
    $admin->forceFill(['is_admin' => true])->saveOrFail();
    foreach (['identity.read', 'identity.sessions.manage', 'network.nodes.read', 'network.machines.read', 'settings.read',
        'identity.accounts.read', 'identity.accounts.manage', 'commerce.orders.read', 'commerce.orders.manage', 'network.groups.read', 'content.manage', 'content.read',
        'support.tickets.read', 'support.tickets.write', 'billing.coupons.read', 'billing.coupons.manage',
        'billing.gift-cards.read', 'billing.gift-cards.manage', 'billing.commissions.read', 'billing.commissions.manage'] as $permission) {
        Illuminate\Support\Facades\DB::table('tx_internal_actor_grant')->insert([
            'user_id' => $admin->id, 'permission' => $permission, 'created_at' => time()]);
    }
    admin_setting(['secure_path' => 'sdk_admin_fixture', 'password_limit_enable' => 0]);
    echo json_encode($service + ['email' => $admin->email, 'id' => (string) $admin->id,
        'secondary' => (string) $admin->createToken('admin-secondary', ['*'])->accessToken->id], JSON_THROW_ON_ERROR)."\n";
    exit;
}
if ($action === 'financial-fund') {
    foreach ([$argv[3] ?? '', $argv[4] ?? ''] as $id) {
        $user = App\Models\User::findOrFail((int) $id);
        if (!str_starts_with($user->email, 'gw-sdk-')) throw new RuntimeException('Dedicated SDK financial actor required');
        app(App\Domains\Billing\NativeMoneyLedger::class)->post($user->id, [
            'business_type' => 'wallet_credit', 'business_ref' => 'http-financial-fixture',
            'idempotency_key' => (string) Illuminate\Support\Str::uuid(), 'currency' => 'CNY', 'occurred_at' => (string) time(),
            'postings' => [
                ['owner_id' => '0', 'purpose' => 'provider_clearing', 'side' => 'debit', 'amount_minor' => '5000'],
                ['owner_id' => (string) $user->id, 'purpose' => 'wallet_available', 'side' => 'credit', 'amount_minor' => '5000'],
            ],
        ]);
    }
    echo "{}\n"; exit;
}
if ($action === 'financial-recover') {
    $billing = app(App\Domains\Billing\NativeBillingRecovery::class)->recover();
    $commissions = app(App\Domains\Billing\NativeCommissions::class)->recover(100);
    echo json_encode(compact('billing', 'commissions'), JSON_THROW_ON_ERROR)."\n"; exit;
}
if ($action === 'financial-revoke') {
    Illuminate\Support\Facades\DB::table('tx_internal_actor_grant')->where('user_id', (int) ($argv[3] ?? 0))
        ->whereIn('permission', ['billing.coupons.manage', 'billing.commissions.manage'])->delete();
    echo "{}\n"; exit;
}
if ($action === 'admin-order-target') {
    [$created, $user] = app(\App\Services\Auth\NativeIdentityService::class)
        ->create('gw-order-'.bin2hex(random_bytes(6)).'@example.test', 'order-integration-password');
    if (!$created || !ctype_digit($argv[3] ?? '')) throw new RuntimeException('Order fixture creation failed');
    $order = app(\App\Domains\Subscription\Services\PublishedOrderService::class)
        ->create($user->id, (int) $argv[3], (string) \Illuminate\Support\Str::uuid());
    echo json_encode(['trade_no' => $order->trade_no], JSON_THROW_ON_ERROR)."\n";
    exit;
}
if ($action === 'delivery-owner' || $action === 'revoke-render-subject') {
    $owner = App\Models\User::findOrFail((int) ($argv[3] ?? 0));
    if (!str_starts_with($owner->email, 'gw-sdk-owner-')) throw new RuntimeException('Dedicated delivery owner required');
    // Checkout commits accepted payment first; the production recovery owner completes fulfillment.
    if ($action === 'delivery-owner') app(App\Domains\Billing\NativeFreeCheckout::class)->recover(100);
    $subscription = Illuminate\Support\Facades\DB::table('tx_subscription')->where('user_id', $owner->id)->where('status', 'active')->first();
    if (!$subscription) throw new RuntimeException('A real active purchased subscription is required');
    if ($action === 'delivery-owner') {
        $result = app(App\Domains\Subscription\Services\NativeCredentialSynchronization::class)->synchronize($owner->id, $subscription->id);
        if ($result['completed'] !== true) throw new RuntimeException('Delivery credential synchronization failed');
        $slot = Illuminate\Support\Facades\DB::table('tx_subscription_slot')->where('user_id', $owner->id)->first();
        $entitlement = Illuminate\Support\Facades\DB::transaction(fn () => app(App\Domains\Subscription\Internal\LockedEntitlementQueries::class)->resolveLocked($owner->id, time()));
        echo json_encode(['user_id' => (string) $owner->id, 'subscription_id' => (string) $subscription->id,
            'slot_subscription_id' => $slot?->subscription_id === null ? null : (string) $slot->subscription_id,
            'entitlement_reason' => $entitlement['reason'],
            'runtime_version' => app(App\Domains\Installation\InstallationAuthority::class)->inspect()['runtime_version'] ?? null], JSON_THROW_ON_ERROR)."\n";
        exit;
    } else {
        $subject = Illuminate\Support\Facades\DB::table('tx_access_subject')->where('subscription_id', $subscription->id)
            ->where('id', (int) ($argv[4] ?? 0))->where('credential_profile_id', 'vmess-uuid/v1')->first();
        if (!$subject) throw new RuntimeException('Dedicated VMess subject required');
        $result = app(App\Domains\Subscription\Services\NativeCredentialProviderService::class)->revoke(
            $owner->id, $subject->id, (string) Illuminate\Support\Str::uuid(), (string) $subject->row_version, 'MANUAL');
        if ($result['status'] !== 'succeeded') throw new RuntimeException('Actual Provider revoke required');
    }
    echo "{}\n"; exit;
}
if ($action === 'admin-revoke-orders') {
    Illuminate\Support\Facades\DB::table('tx_internal_actor_grant')->where('permission', 'commerce.orders.manage')->delete();
    echo "{}\n"; exit;
}
if ($action === 'admin-revoke-support') {
    Illuminate\Support\Facades\DB::table('tx_internal_actor_grant')->where('permission', 'support.tickets.write')->delete();
    echo "{}\n"; exit;
}
if ($action === 'admin-restrict') {
    Illuminate\Support\Facades\DB::table('tx_internal_actor_grant')->where('permission', 'network.nodes.read')->delete();
    echo "{}\n"; exit;
}
if ($action === 'admin-rotate-path') {
    admin_setting(['secure_path' => 'sdk_admin_rotated']);
    echo "{}\n"; exit;
}
if ($action === 'trust') {
    $trust = app(App\Domains\Network\NativeNodeAuthorization::class)->publicTrust();
    echo json_encode(array_intersect_key($trust, array_flip(['protocol_version', 'core_instance_id',
        'key_id', 'key_epoch', 'algorithm', 'public_key'])), JSON_THROW_ON_ERROR)."\n";
    exit;
}
if ($action !== 'seed') throw new RuntimeException('Unknown fixture operation');
$authority = app(App\Domains\Installation\InstallationAuthority::class);
// Initialization must run against fresh application data before creating catalog/node inventory.
$authority->initialize('11111111-1111-4111-8111-111111111111', 'native', 'gateway-native-bootstrap');
admin_setting(['app_name' => 'Gateway integration', 'app_url' => 'http://127.0.0.1:18081',
    'captcha_enable' => 0, 'stop_register' => 0, 'email_verify' => 0, 'invite_force' => 0,
    'email_whitelist_enable' => 0, 'register_limit_by_ip_enable' => 0,
    'server_token' => 'synthetic-gateway-native-node-token']);
$group = App\Models\ServerGroup::unguarded(fn () => App\Models\ServerGroup::create(['name' => 'Gateway native group']));
$plan = App\Models\Plan::withoutEvents(fn () => App\Models\Plan::create([
    'group_id' => $group->id, 'name' => 'Gateway Native fixture', 'transfer_enable' => 10,
    'show' => true, 'sell' => true, 'renew' => true, 'sort' => 0, 'reset_traffic_method' => 2,
    'content' => 'Dedicated integration plan', 'prices' => ['monthly' => '12.00', 'quarterly' => '36.00', 'onetime' => '0.00'],
    'tags' => ['integration'], 'device_limit' => 2, 'speed_limit' => 100, 'capacity_limit' => null,
]));
$publication = app(App\Domains\Subscription\Services\PlanPublicationService::class)
    ->publish($plan->id, 'gateway-native-publication', 'CNY', 'UTC');
$node = App\Models\Server::withoutEvents(fn () => App\Models\Server::create([
    'name' => 'Gateway readiness VLESS', 'type' => 'vless', 'host' => '127.0.0.1',
    'port' => 18086, 'server_port' => 18086, 'rate' => 1, 'enabled' => true, 'show' => true,
    'group_ids' => [(string) $group->id], 'protocol_settings' => ['tls' => 0, 'network' => 'tcp'],
]));
if ((int) $node->id !== 1) throw new RuntimeException('Fresh dedicated database required');
$vmess = App\Models\Server::withoutEvents(fn () => App\Models\Server::create([
    'name' => 'Gateway VMess, = [Rule] # injection', 'type' => 'vmess', 'host' => '127.0.0.1',
    'port' => 18086, 'server_port' => 18086, 'rate' => 1, 'enabled' => true, 'show' => true,
    'group_ids' => [(string) $group->id], 'protocol_settings' => ['tls' => 0, 'network' => 'tcp'],
]));
if ((int) $vmess->id !== 2) throw new RuntimeException('Fresh dedicated VMess node required');
app(App\Domains\Subscription\Services\NativeCredentialProviderService::class)
    ->register('primary-provider', 'gateway-native-provider');
$ready = $authority->readiness();
$activated = $authority->activate('gateway-native-activate', '1', $ready['configuration_digest']);
if ($activated['phase'] !== 'open' || $activated['authority_epoch'] !== '2') {
    throw new RuntimeException('Real native activation required');
}
echo json_encode(['plan_id' => (string) $plan->id, 'offers' => $publication['offers'],
    'authority_epoch' => $activated['authority_epoch'], 'native_activation' => true,
    'database_version' => Illuminate\Support\Facades\DB::selectOne('SELECT VERSION() AS version')->version,
], JSON_THROW_ON_ERROR)."\n";
