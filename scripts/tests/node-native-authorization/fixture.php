<?php

use App\Models\Plan;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\ServerMachine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

try {
require getenv('NATIVE_AUTHORIZATION_API') . '/vendor/autoload.php';
$app = require getenv('NATIVE_AUTHORIZATION_API') . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (app()->environment() !== 'testing' || config('database.default') !== 'mysql'
    || !in_array(DB::connection()->getDatabaseName(), ['txboard_native_authorization_test_singbox', 'txboard_native_authorization_test_xray'], true)
    || config('database.connections.mysql.host') !== '127.0.0.1'
    || config('database.connections.mysql.username') !== 'txboard_native_authorization'
    || config('authority_writer_fence.enabled') !== false
    || config('node_ws.native_enabled') !== true
    || config('native_node_authorization.enabled') !== true
    || config('native_traffic_receipts.enabled') !== true
    || config('queue.default') !== 'redis' || config('cache.default') !== 'redis'
    || !str_starts_with((string) config('database.redis.options.prefix'), 'native-authorization-')) {
    throw new RuntimeException('NATIVE_AUTHORIZATION_EXCLUSIVE_FIXTURE_REQUIRED');
}

$mode = $argv[1] ?? '';
$directory = getenv('NATIVE_AUTHORIZATION_DIR');
if ($mode === 'seed') {
    foreach (['tx_user', 'tx_plan', 'tx_server', 'tx_server_group', 'tx_server_machine',
        'tx_traffic_batch', 'tx_native_traffic_receipt', 'tx_node_authorization_state'] as $table) {
        if (DB::table($table)->exists()) {
            throw new RuntimeException('NATIVE_AUTHORIZATION_NONEMPTY_FIXTURE_REFUSED');
        }
    }
    $origin = (int) getenv('NATIVE_AUTHORIZATION_ORIGIN_PORT');
    $kernel = getenv('NATIVE_AUTHORIZATION_KERNEL');
    if (!in_array($kernel, ['singbox', 'xray'], true)) {
        throw new RuntimeException('NATIVE_AUTHORIZATION_KERNEL_REFUSED');
    }
    $originRoute = $kernel === 'xray'
        ? ['type' => 'field', 'network' => 'tcp', 'ip' => ['127.0.0.1/32'], 'port' => (string) $origin, 'outboundTag' => 'direct']
        : ['network' => 'tcp', 'ip_cidr' => ['127.0.0.1/32'], 'port' => [$origin], 'outbound' => 'direct'];
    $machine = ServerMachine::create(['name' => 'isolated-signed-authorization-machine',
        'token' => 'native-authorization-local-machine-only', 'is_active' => true, 'image_channel' => 'stable']);
    $nodes = [];
    foreach (['vless', 'vmess'] as $index => $protocol) {
        $port = (int) getenv('NATIVE_AUTHORIZATION_NODE_PORT_' . $index);
        if ($port < 1024 || $port > 65535 || $origin < 1024 || $origin > 65535 || $port === $origin) {
            throw new RuntimeException('NATIVE_AUTHORIZATION_PORT_REFUSED');
        }
        $group = ServerGroup::unguarded(fn () => ServerGroup::create(['name' => 'isolated-authorization-' . $protocol]));
        $plan = Plan::create(['name' => 'isolated-authorization-plan-' . $protocol, 'group_id' => $group->id,
            'transfer_enable' => 1, 'prices' => [Plan::PERIOD_MONTHLY => 1], 'show' => true, 'sell' => true,
            'renew' => true, 'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY]);
        $node = Server::withoutEvents(fn () => Server::create(['name' => 'isolated-authorization-node-' . $protocol,
            'machine_id' => $machine->id, 'type' => $protocol, 'host' => '127.0.0.1', 'port' => $port,
            'server_port' => $port, 'rate' => 1, 'group_ids' => [(string) $group->id], 'enabled' => true,
            'show' => true, 'protocol_settings' => ['tls' => 0],
            'custom_routes' => [$originRoute]]));
        $uuid = $index === 0 ? '00112233-4455-4677-8899-aabbccddeeff' : 'ffeeddcc-bbaa-4998-8776-554433221100';
        $user = User::withoutEvents(fn () => User::create(['email' => 'native-authorization-' . $protocol . '@example.test',
            'password' => Hash::make('SyntheticAuthorization2026'), 'uuid' => $uuid,
            'token' => md5('synthetic-authorization-' . $protocol), 'plan_id' => $plan->id,
            'group_id' => $group->id, 'transfer_enable' => 1073741824, 'u' => 0, 'd' => 0,
            'reset_count' => 0, 'expired_at' => time() + 3600, 'banned' => false]));
        $nodes[] = ['id' => (int) $node->id, 'user_id' => (int) $user->id,
            'protocol' => $protocol, 'port' => $port, 'uuid' => $uuid];
    }
    User::withoutEvents(fn () => User::create(['email' => 'native-authorization-admin@example.test',
        'password' => Hash::make('SyntheticAuthorizationAdmin2026'),
        'uuid' => '00112233-4455-4677-8899-aabbccddee00', 'token' => 'private-authorization-admin',
        'is_admin' => true, 'banned' => false, 'u' => 0, 'd' => 0]));
    admin_setting(['server_token' => 'native-authorization-unused-standalone', 'secure_path' => 'native_authorization_admin',
        'captcha_enable' => 0, 'password_limit_enable' => 0, 'server_pull_interval' => 60, 'server_push_interval' => 5]);
    echo json_encode(['machine_id' => (int) $machine->id, 'token' => $machine->token, 'nodes' => $nodes,
        'origin_port' => $origin, 'new_port' => (int) getenv('NATIVE_AUTHORIZATION_NEW_PORT')], JSON_THROW_ON_ERROR) . "\n";
    exit(0);
}

$fixture = json_decode(file_get_contents($directory . '/fixture.json'), true, 32, JSON_THROW_ON_ERROR);
if ($mode === 'state') {
    $nodeIds = array_column($fixture['nodes'], 'id');
    $userIds = array_column($fixture['nodes'], 'user_id');
    echo json_encode([
        'users' => DB::table('tx_user')->whereIn('id', $userIds)->orderBy('id')->get(['id', 'u', 'd', 'banned', 'reset_count']),
        'authorization' => DB::table('tx_node_authorization_state')->whereIn('node_id', $nodeIds)->orderBy('node_id')->get(),
        'receipts' => DB::table('tx_native_traffic_receipt')->whereIn('node_id', $nodeIds)->orderBy('id')->get(),
        'ledger' => DB::table('tx_traffic_batch')->whereIn('server_id', $nodeIds)->orderBy('id')->get(),
        'period_usage' => DB::table('tx_native_traffic_period_usage')->whereIn('user_id', $userIds)->orderBy('user_id')->get(),
    ], JSON_THROW_ON_ERROR) . "\n";
    exit(0);
}
throw new RuntimeException('NATIVE_AUTHORIZATION_MODE_REQUIRED');

} catch (Throwable $error) {
    // Laravel's console exception handler may otherwise render an error with
    // process exit0. Fail explicitly and keep SQL values/credentials private.
    fwrite(STDERR, "NATIVE_AUTHORIZATION_FIXTURE_FAILED\n");
    exit(1);
}
