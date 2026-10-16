<?php

namespace Tests\Feature\Subscription;

use App\Domains\CredentialProvider\ProviderTransport;
use App\Domains\CredentialProvider\RenderGrantCodec;
use App\Models\Order;
use App\Models\Server;
use App\Services\Subscription\NativeCredentialProviderService;
use App\Services\Subscription\NativeSubscriptionDelivery;
use App\Services\Subscription\PublishedOrderService;
use App\Services\Subscription\SubscriptionFulfillmentService;
use DomainException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\Support\NativeSubscriptionTestCase;

final class NativeSubscriptionDeliveryTest extends NativeSubscriptionTestCase
{
    private int $owner;
    private int $subject;
    private mixed $duringMetadata = null;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['2026_10_16_000001_enable_native_credential_provider.php',
            '2026_10_16_000002_enable_native_credential_rotation.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        Schema::create('tx_server', function (Blueprint $table): void {
            $table->bigIncrements('id');
            foreach (['name', 'host', 'type'] as $key) { $table->string($key); }
            $table->unsignedInteger('port');
            $table->boolean('enabled');
            $table->boolean('show');
            $table->unsignedBigInteger('machine_id')->nullable();
            $table->json('group_ids');
            $table->json('protocol_settings');
        });
        $id = DB::table('tx_server')->insertGetId([
            'name' => 'Native connection', 'host' => 'node.example.test', 'type' => 'vless', 'port' => 443,
            'enabled' => true, 'show' => true, 'machine_id' => null, 'group_ids' => '[7]',
            'protocol_settings' => '{"tls":0,"network":"tcp"}',
        ]);
        config([
            'native_node_authorization.enabled' => true,
            'native_node_authorization.policy_version' => 'native-provider-authorization/v1',
            'native_node_authorization.core_instance_id' => self::INSTALLATION,
            'native_node_authorization.key_id' => 'render-key', 'native_node_authorization.key_epoch' => '1',
            'native_node_authorization.private_seed' => RenderGrantCodec::base64url(str_repeat('r', 32)),
            'native_provider_node.provider_id' => 'render-provider',
            'native_provider_node.recipients' => [$id => ['machine_id' => null, 'recipient_sha256' => str_repeat('d', 64)]],
            'native_subscription_delivery.providers.render-provider.endpoint' => 'https://render.example.test/subscription-render',
        ]);
        $transport = Mockery::mock(ProviderTransport::class);
        $transport->shouldReceive('configuration')->with('render-provider')->andReturn([
            'provider_id' => 'render-provider', 'provider_instance_epoch' => '1',
            'config_digest' => str_repeat('a', 64), 'profiles' => ['vless-uuid/v1'],
        ]);
        $transport->shouldReceive('call')->andReturnUsing(function ($registration, $request): array {
            $this->assertSame(0, DB::transactionLevel());
            $input = $request['input'];
            return ['status' => 'succeeded', 'result' => [
                'access_subject_id' => $input['access_subject_id'], 'provider_subject_ref' => $input['provider_subject_ref'],
                'credential_profile_id' => $input['credential_profile_id'],
                'credential_ref' => 'credential_'.str_repeat('b', 32), 'credential_generation' => '1',
                'issued_at' => time(), 'not_before_at' => $input['not_before_at'],
                'credential_expires_at' => $input['credential_expires_at'],
            ], 'error' => null];
        });
        $transport->shouldReceive('metadata')->andReturnUsing(function ($registration, $request): array {
            $this->assertSame(0, DB::transactionLevel());
            $subject = $request['subjects'][0];
            if ($this->duringMetadata !== null) { ($this->duringMetadata)(); }
            unset($request['subjects']);
            return $request + ['credentials' => [[
                'access_subject_id' => $subject['access_subject_id'], 'credential_ref' => $subject['credential_ref'],
                'credential_generation' => $subject['credential_generation'], 'credential_sha256' => str_repeat('c', 64),
                'credential_expires_at' => (int) DB::table('tx_access_subject')->where('id', $subject['access_subject_id'])->value('credential_expires_at'),
            ]]];
        });
        $this->app->instance(ProviderTransport::class, $transport);
        $this->owner = (int) $this->user()->id;
        $offer = $this->publishedOffer($this->plan());
        $order = app(PublishedOrderService::class)->create($this->owner, $offer, 'render-purchase');
        $order->update(['status' => Order::STATUS_PROCESSING]);
        $subscription = app(SubscriptionFulfillmentService::class)->fulfill($order->id, time() - 1);
        $credentials = app(NativeCredentialProviderService::class);
        $credentials->register('render-provider', 'render-register');
        $issued = $credentials->issue($this->owner, (int) $subscription['subscription_id'],
            'render-provider', 'vless-uuid/v1', 'render-issue', '1');
        $this->assertSame('succeeded', $issued['status']);
        $this->subject = (int) $issued['subject_id'];
    }

    public function test_grant_contains_only_non_secret_policy_and_is_bound_to_recipient(): void
    {
        $recipient = RenderGrantCodec::base64url(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));
        $result = app(NativeSubscriptionDelivery::class)->authorize($this->owner, $this->subject, $recipient);
        $raw = base64_decode(strtr($result['authorization']['payload'], '-_', '+/'));
        $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($recipient, $payload['recipient_public_key']);
        $this->assertSame(RenderGrantCodec::POLICY, $payload['policy_version']);
        $this->assertSame((string) $this->owner, $payload['user_id']);
        $this->assertSame((string) $this->subject, $payload['subject']['access_subject_id']);
        $this->assertSame(str_repeat('c', 64), $payload['credential_sha256']);
        $this->assertLessThanOrEqual(30, $payload['expires_at'] - $payload['issued_at']);
        $this->assertArrayNotHasKey('uuid', $payload);
        $this->assertArrayNotHasKey('private_key', $payload);
        $this->assertSame('node.example.test', $payload['nodes'][0]['host']);
        $public = sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair(str_repeat('r', 32)));
        $signature = base64_decode(strtr($result['authorization']['signature'], '-_', '+/'));
        $this->assertTrue(sodium_crypto_sign_verify_detached($signature, $raw, $public));
    }

    public function test_another_account_cannot_request_subject_material(): void
    {
        $other = $this->user();
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('NATIVE_SUBSCRIPTION_DELIVERY_SUBJECT_MISSING');
        app(NativeSubscriptionDelivery::class)->authorize($other->id, $this->subject, RenderGrantCodec::base64url(str_repeat('k', 32)));
    }

    public function test_ban_during_metadata_does_not_sign_a_delivery(): void
    {
        $this->duringMetadata = fn () => DB::table('tx_user')->where('id', $this->owner)->update(['banned' => true]);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('NATIVE_SUBSCRIPTION_DELIVERY_ENTITLEMENT_DENIED');
        app(NativeSubscriptionDelivery::class)->authorize($this->owner, $this->subject, RenderGrantCodec::base64url(str_repeat('k', 32)));
    }

    public function test_target_change_during_metadata_does_not_sign_a_delivery(): void
    {
        $this->duringMetadata = fn () => DB::table('tx_server')->update(['host' => 'changed.example.test']);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('NATIVE_SUBSCRIPTION_DELIVERY_AUTHORITY_CHANGED');
        app(NativeSubscriptionDelivery::class)->authorize($this->owner, $this->subject, RenderGrantCodec::base64url(str_repeat('k', 32)));
    }

    public function test_transport_with_unimplemented_client_semantics_is_rejected(): void
    {
        $node = Server::findOrFail(1);
        $node->protocol_settings = ['tls' => 2, 'network' => 'tcp'];
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('NATIVE_SUBSCRIPTION_DELIVERY_NODE_UNSUPPORTED');
        NativeSubscriptionDelivery::connection($node);
    }
}
