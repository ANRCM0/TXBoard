<?php

use Illuminate\Foundation\Bootstrap\LoadConfiguration;

$directory = getenv('NATIVE_PROVIDER_NODE_DIR');
$api = getenv('NATIVE_PROVIDER_NODE_API');
if (!is_string($directory) || !str_starts_with($directory, '/tmp/tx-native-provider-node-')
    || !is_dir($directory) || !is_string($api) || getenv('APP_ENV') !== 'testing'
    || getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_DATABASE') !== $directory.'/core.sqlite') {
    throw new RuntimeException('NATIVE_C2_EXCLUSIVE_FIXTURE_REQUIRED');
}
require $api.'/vendor/autoload.php';
$app = require $api.'/bootstrap/app.php';
$app->afterBootstrapping(LoadConfiguration::class, static function ($app) use ($directory): void {
    $app->make('config')->set([
        'database.connections.sqlite.database' => $directory.'/core.sqlite',
        'credential_provider.providers.primary-provider' => [
            'endpoint' => getenv('NATIVE_PROVIDER_NODE_PROVIDER').'/operations',
            'instance_epoch' => '1', 'profiles' => ['vless-uuid/v1', 'vmess-uuid/v1'],
            'ca_file' => $directory.'/ca.pem', 'client_cert' => $directory.'/core.pem',
            'client_key' => $directory.'/core.key',
        ],
        'native_node_authorization.enabled' => true,
        'native_node_authorization.policy_version' => 'native-provider-authorization/v2',
        'native_node_authorization.core_instance_id' => '11111111-1111-4111-8111-111111111111',
        'native_node_authorization.key_id' => 'provider-node-key', 'native_node_authorization.key_epoch' => '1',
        'native_node_authorization.private_seed' => getenv('NATIVE_NODE_AUTHORIZATION_PRIVATE_SEED'),
        'native_node_authorization.lease_seconds' => 30, 'native_node_authorization.max_clock_skew_seconds' => 1,
        'native_provider_node.provider_id' => 'primary-provider',
        'native_provider_node.recipients' => [
            1 => ['machine_id' => null, 'recipient_sha256' => getenv('NATIVE_PROVIDER_NODE_FINGERPRINT')],
            2 => ['machine_id' => null, 'recipient_sha256' => getenv('NATIVE_PROVIDER_NODE_FINGERPRINT')],
        ],
        'native_subscription_delivery.providers.primary-provider.endpoint' => getenv('NATIVE_PROVIDER_NODE_RENDER').'/subscription-render',
        'core_usage_v2.core_instance_id' => '11111111-1111-4111-8111-111111111111',
        'core_usage_v2.isolated_copy' => false,
        'cache.stores.redis' => ['driver' => 'array'],
        'database.redis.default.database' => 14, 'database.redis.cache.database' => 15,
        'queue.default' => 'redis', 'node_ws.native_enabled' => false,
        'native_traffic_receipts.enabled' => false,
    ]);
});
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
return $app;
