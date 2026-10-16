<?php
$api = getenv('NATIVE_AUTHORITY_CORE_API');
if (!is_string($api) || !str_starts_with($api, '/')) throw new RuntimeException('NATIVE_AUTHORITY_API_REQUIRED');
require $api.'/vendor/autoload.php';
$app = require $api.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\Plan;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\User;

if (!app()->environment('testing') || config('database.default') !== 'mysql'
    || DB::connection()->getDatabaseName() !== 'txboard_native_control_test'
    || config('database.connections.mysql.host') !== '127.0.0.1'
    || config('database.connections.mysql.username') !== 'txboard_native_control'
    || config('node_ws.native_enabled') !== false) {
    fwrite(STDERR, "NATIVE_AUTHORITY_EXCLUSIVE_FIXTURE_REQUIRED\n"); exit(2);
}
$directory = getenv('NATIVE_AUTHORITY_DIR');
if (!is_string($directory) || !str_starts_with($directory, '/')) throw new RuntimeException('NATIVE_AUTHORITY_DIRECTORY_REQUIRED');
$mode = $argv[1] ?? '';
if ($mode === 'seed') {
    $protocol = getenv('NATIVE_AUTHORITY_PROTOCOL');
    $port = getenv('NATIVE_AUTHORITY_NODE_PORT');
    $origin = getenv('NATIVE_AUTHORITY_ORIGIN_PORT');
    if (!in_array($protocol, ['vless','vmess'], true) || !ctype_digit($port) || (int)$port < 1024 || (int)$port > 65535
        || !ctype_digit($origin) || (int)$origin < 1024 || (int)$origin > 65535 || $origin === $port) {
        throw new RuntimeException('NATIVE_AUTHORITY_PROTOCOL_REQUIRED');
    }
    foreach (['tx_user','tx_plan','tx_server','tx_server_group','tx_traffic_batch'] as $table) {
        if (DB::table($table)->exists()) throw new RuntimeException('NATIVE_AUTHORITY_NONEMPTY_FIXTURE_REFUSED');
    }
    $group = ServerGroup::unguarded(fn () => ServerGroup::create(['name' => 'native-control-isolated-group']));
    $plan = Plan::create(['name' => 'native-control-isolated-plan', 'group_id' => $group->id,
        'transfer_enable' => 1, 'prices' => [Plan::PERIOD_MONTHLY => 1], 'show' => true,
        'sell' => true, 'renew' => true, 'reset_traffic_method' => Plan::RESET_TRAFFIC_NEVER]);
    $node = Server::withoutEvents(fn () => Server::create(['name' => 'native-control-isolated-node',
        'type' => $protocol, 'host' => '127.0.0.1', 'port' => (int)$port, 'server_port' => (int)$port,
        'rate' => 1, 'group_ids' => [(string)$group->id], 'enabled' => true,
        'protocol_settings' => ['tls' => 0], 'show' => true]));
    $user = User::withoutEvents(fn () => User::create(['email' => 'native-control-user@example.test',
        'password' => Hash::make('SyntheticUserControl2026'),
        'uuid' => '00112233-4455-6677-8899-aabbccddeeff', 'token' => 'native-control-private-user',
        'plan_id' => $plan->id, 'group_id' => $group->id, 'transfer_enable' => 1073741824,
        'u' => 0, 'd' => 0, 'expired_at' => time()+3600, 'banned' => false]));
    User::withoutEvents(fn () => User::create(['email' => 'native-control-admin@example.test',
        'password' => Hash::make('SyntheticAdminControl2026'),
        'uuid' => '00112233-4455-6677-8899-aabbccddee00', 'token' => 'native-control-private-admin',
        'is_admin' => true, 'banned' => false, 'u' => 0, 'd' => 0]));
    admin_setting(['server_token' => 'native-control-local-test-only', 'secure_path' => 'native_control_admin',
        'captcha_enable' => 0, 'password_limit_enable' => 0, 'server_pull_interval' => 1, 'server_push_interval' => 60]);
    echo json_encode(['node_id' => (int)$node->id, 'user_id' => (int)$user->id,
        'protocol' => $protocol, 'node_port' => (int)$port, 'origin_port' => (int)$origin,
        'uuid' => $user->uuid], JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
    exit(0);
}
if ($mode === 'assert-disabled') {
    $fixture = json_decode(file_get_contents($directory.'/fixture.json'), true, 32, JSON_THROW_ON_ERROR);
    $node = Server::findOrFail($fixture['node_id']);
    if ($node->enabled || $node->type !== $fixture['protocol'] || (int)$node->server_port !== $fixture['node_port']) {
        throw new RuntimeException('NATIVE_AUTHORITY_DISABLED_SQL_REQUIRED');
    }
    $user = User::findOrFail($fixture['user_id']);
    if ($user->uuid !== $fixture['uuid'] || $user->banned) throw new RuntimeException('NATIVE_AUTHORITY_USER_UNCHANGED_REQUIRED');
    echo json_encode(['node_id' => (int)$node->id, 'enabled' => false, 'user_id' => (int)$user->id,
        'legacy_u' => (string)$user->u, 'legacy_d' => (string)$user->d], JSON_THROW_ON_ERROR)."\n";
    exit(0);
}
throw new RuntimeException('NATIVE_AUTHORITY_MODE_REQUIRED');
