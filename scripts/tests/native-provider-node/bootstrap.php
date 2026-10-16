<?php

use App\Domains\Installation\InstallationAuthority;
use Illuminate\Support\Facades\DB;

$directory = getenv('NATIVE_PROVIDER_NODE_DIR');
$api = getenv('NATIVE_PROVIDER_NODE_API');
if (!is_string($directory) || !str_starts_with($directory, '/tmp/tx-native-provider-node-')
    || !is_dir($directory) || !is_string($api) || getenv('APP_ENV') !== 'testing'
    || getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_DATABASE') !== $directory.'/core.sqlite') {
    throw new RuntimeException('PROVIDER_NODE_EXCLUSIVE_FIXTURE_REQUIRED');
}
require $api.'/vendor/autoload.php';
$app = require $api.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config([
    'database.connections.sqlite.database' => $directory.'/core.sqlite',
    'credential_provider.providers.primary-provider' => [
        'endpoint' => getenv('NATIVE_PROVIDER_NODE_PROVIDER').'/operations',
        'instance_epoch' => '1', 'profiles' => ['vless-uuid/v1', 'vmess-uuid/v1'],
        'ca_file' => $directory.'/ca.pem', 'client_cert' => $directory.'/core.pem',
        'client_key' => $directory.'/core.key',
    ],
    'native_node_authorization.enabled' => true,
    'native_node_authorization.policy_version' => 'native-provider-authorization/v1',
    'native_node_authorization.core_instance_id' => '11111111-1111-4111-8111-111111111111',
    'native_node_authorization.key_id' => 'provider-node-key',
    'native_node_authorization.key_epoch' => '1',
    'native_node_authorization.private_seed' => getenv('NATIVE_NODE_AUTHORIZATION_PRIVATE_SEED'),
    'native_node_authorization.lease_seconds' => 30,
    'native_node_authorization.max_clock_skew_seconds' => 1,
    'native_provider_node.provider_id' => 'primary-provider',
    'native_provider_node.recipients' => [
        1 => ['machine_id' => null, 'recipient_sha256' => getenv('NATIVE_PROVIDER_NODE_FINGERPRINT')],
        2 => ['machine_id' => null, 'recipient_sha256' => getenv('NATIVE_PROVIDER_NODE_FINGERPRINT')],
    ],
    'cache.stores.redis' => ['driver' => 'array'],
    'node_ws.native_enabled' => false,
    'native_traffic_receipts.enabled' => false,
]);
DB::purge('sqlite');

// Only the not-yet-open installation admission is substituted. Routes, Node
// credentials, SQL domain operations, signatures and both runtimes remain real.
function providerNodeAdmitFixture(): void
{
    $authority = Mockery::mock(InstallationAuthority::class);
    $state = [
        'initialized' => true, 'mode' => 'native', 'phase' => 'open',
        'installation_id' => '11111111-1111-4111-8111-111111111111', 'authority_epoch' => '1',
    ];
    $authority->shouldReceive('inspect')->andReturn($state);
    $authority->shouldReceive('assertRuntime')->andReturnNull();
    $authority->shouldReceive('assertCatalogMutation')->andReturnUsing(static function (): void {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('PROVIDER_NODE_TRANSACTION_REQUIRED');
        }
    });
    foreach (['assertNativeRead', 'assertNativeMutation'] as $method) {
        $authority->shouldReceive($method)->andReturnUsing(static function () use ($state): array {
            if (DB::transactionLevel() < 1) {
                throw new RuntimeException('PROVIDER_NODE_TRANSACTION_REQUIRED');
            }
            return $state;
        });
    }
    app()->instance(InstallationAuthority::class, $authority);
}

return $app;
