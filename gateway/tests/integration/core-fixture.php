<?php
// CI only: all mutable fixture operations require the isolated snapshot and DB.
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
if ($action === 'user-service') {
    $operations = array_keys(\App\Domains\InternalApi\OperationSchemas::all()['operations']);
    $service = app(\App\Domains\InternalApi\ServiceCredentials::class)->issue('browser-user-fixture', 'user',
        $argv[3] ?? '', array_merge(['system.capabilities.get'], $operations), time() + 3600);
    // Provision isolated actors through Identity. Registration HTTP and its
    // throttle are already exercised by the preceding integration script.
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
if ($action === 'restrict-user-service') {
    Illuminate\Support\Facades\DB::table('tx_internal_service')->where('service_id', 'browser-user-fixture')
        ->update(['operations_json' => json_encode(['system.capabilities.get', 'catalog.offers.list'])]);
    echo "{}\n";
    exit;
}
if ($action === 'revoke-user-service') {
    app(\App\Domains\InternalApi\ServiceCredentials::class)->revoke('browser-user-fixture');
    echo "{}\n";
    exit;
}
if ($action === 'trust') {
    $trust = app(App\Domains\Network\NativeNodeAuthorization::class)->publicTrust();
    echo json_encode(array_intersect_key($trust, array_flip(['protocol_version', 'core_instance_id',
        'key_id', 'key_epoch', 'algorithm', 'public_key'])), JSON_THROW_ON_ERROR)."\n";
    exit;
}
if ($action === 'history' || $action === 'edit-plan') {
    app(App\Domains\Installation\InstallationAuthority::class)->assertNativeRuntime();
    $plan = App\Models\Plan::findOrFail((int) ($argv[$action === 'history' ? 4 : 3] ?? 0));
    if ($plan->name !== 'Gateway Native fixture') throw new RuntimeException('Dedicated fixture plan required');
    if ($action === 'edit-plan') {
        // Deliberately edit mutable source only; immutable publications remain untouched.
        $plan->forceFill(['prices' => ['monthly' => '99.00', 'quarterly' => '297.00']])->save();
        echo "{}\n";
        exit;
    }
    $user = App\Models\User::findOrFail((int) ($argv[3] ?? 0));
    if (!str_starts_with($user->email, 'gw-a-')) throw new RuntimeException('Dedicated fixture owner required');
    // Read-only pagination evidence. These historical rows are never used for native admission,
    // purchase, financial recovery or entitlement. Native purchased orders are tested separately.
    $rows = [];
    $prefix = 'gw-history-'.bin2hex(random_bytes(5));
    for ($i = 0; $i < 101; $i++) {
        $rows[] = ['user_id' => $user->id, 'plan_id' => $plan->id, 'period' => 'monthly',
            'trade_no' => $prefix.'-'.$i, 'type' => 1, 'status' => App\Models\Order::STATUS_DISCOUNTED,
            'total_amount' => 1200, 'created_at' => time() - 1000 - $i, 'updated_at' => time() - 1000 - $i];
    }
    Illuminate\Support\Facades\DB::table('tx_order')->insert($rows);
    echo json_encode(['trade_no' => $rows[0]['trade_no'], 'count' => count($rows)], JSON_THROW_ON_ERROR)."\n";
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
