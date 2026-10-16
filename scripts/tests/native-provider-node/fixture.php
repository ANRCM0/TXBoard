<?php

use App\Domains\Installation\InstallationAuthority;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\User;
use App\Domains\Subscription\Services\NativeCredentialProviderService;
use App\Domains\Subscription\Services\PlanPublicationService;
use App\Domains\Subscription\Services\PublishedOrderService;
use App\Domains\Subscription\Services\SubscriptionFulfillmentService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

try {
    $app = require __DIR__.'/bootstrap.php';
    $mode = $argv[1] ?? '';
    if ($mode === 'trust') {
        $seed = base64_decode(strtr(getenv('NATIVE_NODE_AUTHORIZATION_PRIVATE_SEED'), '-_', '+/'), true);
        $key = sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed));
        echo json_encode([
            'protocol_version' => 1, 'core_instance_id' => '11111111-1111-4111-8111-111111111111',
            'key_id' => 'provider-node-key', 'key_epoch' => '1', 'algorithm' => 'Ed25519',
            'public_key' => rtrim(strtr(base64_encode($key), '+/', '-_'), '='),
        ], JSON_THROW_ON_ERROR)."\n";
        exit(0);
    }
    if ($mode === 'seed') {
        Artisan::call('migrate', ['--force' => true]);
        app(InstallationAuthority::class)->initialize(
            '11111111-1111-4111-8111-111111111111', 'native', 'provider-node-fixture',
        );
        config(['core_installation.mode' => 'native']);
        providerNodeAdmitFixture();
        Bus::fake();
        $group = ServerGroup::unguarded(fn () => ServerGroup::create(['name' => 'Provider Node fixture']));
        $plan = Plan::withoutEvents(fn () => Plan::create([
            'name' => 'Native Provider plan', 'group_id' => $group->id,
            'transfer_enable' => 2, 'speed_limit' => 30, 'device_limit' => 4,
            'prices' => ['monthly' => '12.34'], 'reset_traffic_method' => 2,
            'show' => true, 'sell' => true, 'renew' => true, 'capacity_limit' => null,
        ]));
        $user = User::withoutEvents(fn () => User::create([
            'email' => 'provider-node@example.test', 'password' => 'not-a-real-password',
            'uuid' => '22222222-2222-4222-8222-222222222222', 'token' => 'unused-legacy-fixture',
            'banned' => false, 'is_admin' => false, 'plan_id' => null, 'group_id' => null,
            'transfer_enable' => 0, 'u' => 0, 'd' => 0, 'balance' => 0, 'commission_balance' => 0,
        ]));
        $publication = app(PlanPublicationService::class)->publish($plan->id, 'publish', 'USD', 'UTC');
        $offer = collect($publication['offers'])->firstWhere('offer_code', 'monthly');
        $order = app(PublishedOrderService::class)->create($user->id, $offer['offer_id'], 'purchase');
        $order->update(['status' => Order::STATUS_PROCESSING]);
        $subscription = app(SubscriptionFulfillmentService::class)->fulfill($order->id, time() - 1);
        $service = app(NativeCredentialProviderService::class);
        $service->register('primary-provider', 'primary-provider');
        $issued = $service->issue($user->id, $subscription['subscription_id'],
            'primary-provider', 'vless-uuid/v1', 'provider-node-issue', '1');
        if ($issued['status'] !== 'succeeded') {
            throw new RuntimeException('PROVIDER_NODE_ISSUANCE_FAILED');
        }
        $port = (int) getenv('NATIVE_PROVIDER_NODE_PORT');
        $secondaryPort = (int) getenv('NATIVE_PROVIDER_NODE_SECONDARY_PORT');
        $origin = (int) getenv('NATIVE_PROVIDER_NODE_ORIGIN_PORT');
        if ($port < 1024 || $secondaryPort < 1024 || $origin < 1024
            || count(array_unique([$port, $secondaryPort, $origin])) !== 3) {
            throw new RuntimeException('PROVIDER_NODE_PORT_INVALID');
        }
        $node = Server::withoutEvents(fn () => Server::create([
            'name' => 'Native Provider VLESS', 'type' => 'vless', 'host' => '127.0.0.1',
            'port' => $port, 'server_port' => $port, 'rate' => 1, 'enabled' => true, 'show' => true,
            'group_ids' => [(string) $group->id], 'protocol_settings' => ['tls' => 0],
            'custom_routes' => [[
                'network' => 'tcp', 'ip_cidr' => ['127.0.0.1/32'], 'port' => [$origin], 'outbound' => 'direct',
            ]],
        ]));
        if ((int) $node->id !== 1) {
            throw new RuntimeException('PROVIDER_NODE_FRESH_DATABASE_REQUIRED');
        }
        $secondary = Server::withoutEvents(function () use ($node, $secondaryPort) {
            $copy = $node->replicate();
            $copy->name = 'Native Provider VLESS sibling';
            $copy->port = $secondaryPort;
            $copy->server_port = $secondaryPort;
            $copy->save();
            return $copy;
        });
        if ((int) $secondary->id !== 2) {
            throw new RuntimeException('PROVIDER_NODE_FRESH_DATABASE_REQUIRED');
        }
        admin_setting([
            'server_token' => 'synthetic-provider-node-bearer',
            'server_pull_interval' => 60, 'server_push_interval' => 5,
        ]);
        file_put_contents($directory.'/fixture.json', json_encode([
            'node_id' => (int) $node->id, 'user_id' => (int) $user->id,
            'subject_id' => $issued['subject_id'], 'subject_version' => $issued['subject_version'],
            'port' => $port, 'secondary_port' => $secondaryPort, 'secondary_node_id' => (int) $secondary->id,
            'origin_port' => $origin, 'token' => 'synthetic-provider-node-bearer',
        ], JSON_THROW_ON_ERROR));
        echo "Native subscription and real Provider issuance prepared.\n";
        exit(0);
    }
    providerNodeAdmitFixture();
    $fixture = json_decode(file_get_contents($directory.'/fixture.json'), true, 32, JSON_THROW_ON_ERROR);
    if ($mode === 'revoke') {
        $result = app(NativeCredentialProviderService::class)->revoke($fixture['user_id'],
            (int) $fixture['subject_id'], 'revoke-real-node', $fixture['subject_version']);
        if ($result['status'] !== 'succeeded') {
            throw new RuntimeException('PROVIDER_NODE_REVOCATION_FAILED');
        }
        echo "Provider subject revoked.\n";
    } elseif ($mode === 'verify') {
        $user = DB::table('tx_user')->where('id', $fixture['user_id'])->first();
        if ((int) $user->u !== 0 || (int) $user->d !== 0 || (int) $user->transfer_enable !== 0) {
            throw new RuntimeException('PROVIDER_NODE_LEGACY_BILLING_CHANGED');
        }
        if (DB::table('tx_authority_writer_fence')->value('phase') !== 'initializing') {
            throw new RuntimeException('PROVIDER_NODE_ACTIVATION_FORBIDDEN');
        }
        echo "Legacy billing unchanged; durable native installation remains initializing.\n";
    } else {
        throw new RuntimeException('PROVIDER_NODE_FIXTURE_MODE_REQUIRED');
    }
} catch (Throwable $error) {
    // Keep payloads, SQL values and TLS/private key paths out of diagnostic output.
    $code = preg_match('/\A[A-Z][A-Z0-9_]{1,100}\z/D', $error->getMessage())
        ? $error->getMessage() : 'UNCLASSIFIED';
    foreach ($error->getTrace() as $frame) {
        if (($frame['file'] ?? null) === __FILE__) {
            $code .= ' FIXTURE_LINE_'.($frame['line'] ?? 0);
            break;
        }
    }
    fwrite(STDERR, 'PROVIDER_NODE_FIXTURE_FAILED '.get_class($error).' '
        .basename($error->getFile()).':'.$error->getLine().' '.$code."\n");
    exit(1);
}
