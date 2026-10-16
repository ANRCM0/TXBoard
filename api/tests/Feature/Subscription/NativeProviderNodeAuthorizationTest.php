<?php

namespace Tests\Feature\Subscription;

use App\Domains\CredentialProvider\ProviderError;
use App\Domains\CredentialProvider\ProviderCodec;
use App\Domains\CredentialProvider\ProviderTransport;
use App\Domains\Installation\InstallationAuthority;
use App\Domains\Network\NativeNodeAuthorization;
use App\Domains\Network\NativeProviderNodeAuthorization;
use App\Models\Order;
use App\Models\Server;
use App\Services\Plugin\PluginManager;
use App\Services\Subscription\NativeCredentialProviderService;
use App\Services\Subscription\PublishedOrderService;
use App\Services\Subscription\SubscriptionFulfillmentService;
use App\Services\Subscription\SubscriptionLifecycleService;
use App\Services\Subscription\SubscriptionEntitlementResolver;
use DomainException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\Support\NativeSubscriptionTestCase;

final class NativeProviderNodeAuthorizationTest extends NativeSubscriptionTestCase
{
    private Server $node;
    private int $metadataCalls = 0;
    private mixed $duringMetadata = null;
    private array $metadataRequests = [];
    private array $credential;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['2026_10_16_000001_enable_native_credential_provider.php',
            '2026_10_16_000002_enable_native_credential_rotation.php',
            '2026_10_14_000010_create_native_node_authorization_state.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        Schema::create('tx_server', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('type');
            $table->boolean('enabled');
            $table->unsignedBigInteger('machine_id')->nullable();
            $table->json('group_ids');
        });
        Schema::create('tx_settings', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('name')->unique();
            $table->string('value');
        });
        Schema::create('tx_server_machine', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->boolean('is_active');
            $table->string('token');
        });
        DB::table('tx_settings')->insert(['name' => 'server_token', 'value' => 'fixture-node-token']);
        $this->credential = ['kind' => 'node', 'machine_id' => null,
            'credential_sha256' => hash('sha256', 'fixture-node-token')];
        $id = DB::table('tx_server')->insertGetId(['type' => 'vless', 'enabled' => true, 'machine_id' => null, 'group_ids' => '[7]']);
        $this->node = Server::findOrFail($id);
        config([
            'native_node_authorization.enabled' => true,
            'native_node_authorization.policy_version' => NativeNodeAuthorization::PROVIDER_POLICY_VERSION,
            'native_node_authorization.core_instance_id' => self::INSTALLATION,
            'native_node_authorization.key_id' => 'fixture-key', 'native_node_authorization.key_epoch' => '1',
            'native_node_authorization.private_seed' => rtrim(strtr(base64_encode(str_repeat('s', 32)), '+/', '-_'), '='),
            'native_provider_node.provider_id' => 'fixture-provider',
            'native_provider_node.recipients' => [$id => ['machine_id' => null, 'recipient_sha256' => str_repeat('d', 64)]],
        ]);
        $transport = Mockery::mock(ProviderTransport::class);
        $transport->shouldReceive('configuration')->with('fixture-provider')->andReturn([
            'provider_id' => 'fixture-provider', 'provider_instance_epoch' => '1', 'config_digest' => str_repeat('a', 64),
            'profiles' => ['vless-uuid/v1', 'vmess-uuid/v1'],
        ]);
        $transport->shouldReceive('call')->andReturnUsing(function (array $registration, array $request): array {
            $this->assertSame(0, DB::transactionLevel());
            $input = $request['input'];
            $result = ['access_subject_id' => $input['access_subject_id'], 'provider_subject_ref' => $input['provider_subject_ref']];
            $result += $request['operation'] === 'issue' ? [
                'credential_profile_id' => $input['credential_profile_id'], 'credential_ref' => 'credential_'.bin2hex(random_bytes(16)),
                'credential_generation' => '1', 'issued_at' => time(), 'not_before_at' => $input['not_before_at'],
                'credential_expires_at' => $input['credential_expires_at'],
            ] : ['accepted_revocation_fact_version' => $input['revocation_fact_version'], 'provider_revoked_at' => time()];
            return ['status' => 'succeeded', 'result' => $result, 'error' => null];
        });
        $transport->shouldReceive('metadata')->andReturnUsing(function (array $registration, array $request): array {
            $this->assertSame(0, DB::transactionLevel(), 'No Provider network call may hold SQL locks.');
            $this->metadataCalls++;
            $this->metadataRequests[] = $request;
            $credentials = [];
            foreach ($request['subjects'] as $subject) {
                $credentials[] = array_intersect_key($subject, array_flip(['access_subject_id', 'credential_ref', 'credential_generation']))
                    + ['credential_sha256' => hash('sha256', 'fixture-'.$subject['access_subject_id']),
                        'credential_expires_at' => (int) DB::table('tx_access_subject')->where('id', $subject['access_subject_id'])->value('credential_expires_at')];
            }
            if ($this->duringMetadata !== null) { ($this->duringMetadata)(); }
            unset($request['subjects']);
            return $request + ['credentials' => $credentials];
        });
        $this->app->instance(ProviderTransport::class, $transport);
        app(NativeCredentialProviderService::class)->register('fixture-provider', 'registration');
    }

    public function test_signed_snapshot_uses_native_entitlement_and_nonsecret_commitment_with_monotonic_revision(): void
    {
        [$user, $subscription, $issue] = $this->issued();
        $legacy = $user->fresh()->getAttributes();
        $first = $this->snapshot();
        $payload = $this->verify($first['authorization']);
        $this->assertSame(ProviderCodec::canonical([['id' => $user->id, 'uuid' => 'sha256:'.hash('sha256', 'fixture-'.$issue['subject_id']),
            'speed_limit' => 30, 'device_limit' => 4]]), ProviderCodec::canonical($payload['users']));
        $this->assertSame(self::INSTALLATION, $payload['provider']['installation_id']);
        $this->assertSame((string) $subscription->id, $payload['provider']['subjects'][0]['subscription_id']);
        $this->assertSame('1', $payload['revision']);
        $this->assertLessThanOrEqual(120, $payload['expires_at'] - $payload['issued_at']);
        $this->assertLessThanOrEqual((int) $subscription->expires_at, $payload['expires_at']);
        $this->assertSame('2', $this->verify($this->snapshot()['authorization'])['revision']);
        $this->assertSame($legacy, $user->fresh()->getAttributes());
        $this->assertStringNotContainsString($user->uuid, json_encode($first));
        $this->assertStringNotContainsString($user->token, json_encode($first));
        $this->assertSame(2, $this->metadataCalls);
        $this->assertSame(['id', 'node_id', 'machine_id', 'key_id', 'key_epoch', 'revision', 'issued_at',
            'snapshot_digest', 'updated_at'], array_keys((array) DB::table('tx_node_authorization_state')->first()));
        $this->expectExceptionMessage('NATIVE_NODE_AUTHORIZATION_INVALID');
        NativeNodeAuthorization::verify($first['authorization'], $this->service()->publicTrust()['public_key'], [
            'policy_version' => NativeNodeAuthorization::POLICY_VERSION, 'core_instance_id' => self::INSTALLATION,
            'node_id' => (int) $this->node->id, 'machine_id' => null, 'key_id' => 'fixture-key', 'key_epoch' => '1',
        ], time());
    }

    public function test_ban_during_metadata_cannot_authorize_or_advance_revision(): void
    {
        [$user] = $this->issued();
        $this->duringMetadata = fn () => DB::table('tx_user')->where('id', $user->id)->update(['banned' => true]);
        $this->rejectSnapshot('NATIVE_PROVIDER_NODE_AUTHORITY_CHANGED');
        $this->assertSame(0, DB::table('tx_node_authorization_state')->count());
    }

    public function test_unlimited_native_limits_are_zero_in_the_node_dto_and_null_is_refused(): void
    {
        $this->issued(['speed_limit' => null, 'device_limit' => null]);
        $payload = $this->verify($this->snapshot()['authorization']);
        $this->assertSame(0, $payload['users'][0]['speed_limit']);
        $this->assertSame(0, $payload['users'][0]['device_limit']);
        $payload['users'][0]['speed_limit'] = null;
        $this->expectExceptionMessage('NATIVE_NODE_AUTHORIZATION_PROVIDER_INVALID');
        DB::transaction(function () use ($payload): void {
            $node = Server::query()->whereKey($this->node->id)->lockForUpdate()->firstOrFail();
            app(NativeNodeAuthorization::class)->issueProviderLocked($node, $payload['users'], $payload['provider'],
                $payload['expires_at'], time());
        });
    }

    public function test_node_limit_integer_boundary_matches_the_shared_go_contract(): void
    {
        $this->issued(['speed_limit' => 2147483647, 'device_limit' => 2147483647]);
        $payload = $this->verify($this->snapshot()['authorization']);
        $this->assertSame(2147483647, $payload['users'][0]['speed_limit']);
        $this->assertSame(2147483647, $payload['users'][0]['device_limit']);
        $this->issued(['speed_limit' => 2147483648]);
        $this->rejectSnapshot('NATIVE_PROVIDER_NODE_LIMIT_UNSUPPORTED');
        $this->assertSame(1, $this->metadataCalls);
    }

    public function test_quota_change_during_metadata_cannot_authorize(): void
    {
        [, $subscription] = $this->issued();
        $period = DB::table('tx_usage_period')->where('subscription_id', $subscription->id)->first();
        $this->duringMetadata = fn () => DB::table('tx_usage_period')->where('id', $period->id)
            ->update(['billable_bytes' => $period->quota_bytes]);
        $this->rejectSnapshot('NATIVE_PROVIDER_NODE_AUTHORITY_CHANGED');
    }

    public function test_revoke_during_metadata_cannot_authorize(): void
    {
        [$user, , $issue] = $this->issued();
        $this->duringMetadata = fn () => app(NativeCredentialProviderService::class)->revoke(
            $user->id, (int) $issue['subject_id'], 'revoke', $issue['subject_version']);
        $this->rejectSnapshot('NATIVE_PROVIDER_NODE_AUTHORITY_CHANGED');
    }

    public function test_target_group_change_during_metadata_refuses_snapshot(): void
    {
        $this->issued();
        $this->duringMetadata = fn () => DB::table('tx_server')->where('id', $this->node->id)->update(['group_ids' => '[8]']);
        $this->rejectSnapshot('NATIVE_PROVIDER_NODE_AUTHORITY_CHANGED');
    }

    public function test_machine_disable_during_metadata_invalidates_the_authenticated_http_request(): void
    {
        $this->issued();
        $machineId = $this->machineIdentity();
        $this->duringMetadata = fn () => DB::table('tx_server_machine')->where('id', $machineId)->update(['is_active' => false]);
        $this->getJson('/txapi/node/v1/users', $this->httpHeaders($machineId, 'fixture-machine-token'))->assertStatus(503)
            ->assertJsonPath('error.code', 'NODE_AUTHORIZATION_UNAVAILABLE');
        $this->assertSame(1, $this->metadataCalls);
        $this->assertSame(0, DB::table('tx_node_authorization_state')->count());
    }

    public function test_machine_token_rotation_during_metadata_does_not_renew_a_previous_lease(): void
    {
        $this->issued();
        $machineId = $this->machineIdentity();
        $this->assertSame('1', $this->verifyMachine($this->snapshot()['authorization'], $machineId)['revision']);
        $this->duringMetadata = fn () => DB::table('tx_server_machine')->where('id', $machineId)->update(['token' => 'rotated-machine-token']);
        $this->getJson('/txapi/node/v1/users', $this->httpHeaders($machineId, 'fixture-machine-token'))->assertStatus(503)
            ->assertJsonPath('error.code', 'NODE_AUTHORIZATION_UNAVAILABLE');
        $this->assertSame('1', (string) DB::table('tx_node_authorization_state')->value('revision'));
    }

    public function test_standalone_token_rotation_during_metadata_uses_current_sql_not_request_cache(): void
    {
        $this->issued();
        $this->duringMetadata = fn () => DB::table('tx_settings')->where('name', 'server_token')
            ->update(['value' => 'rotated-global-node-token']);
        $this->getJson('/txapi/node/v1/users', $this->httpHeaders())->assertStatus(503)
            ->assertJsonPath('error.code', 'NODE_AUTHORIZATION_UNAVAILABLE');
        $this->assertSame(1, $this->metadataCalls);
        $this->assertSame(0, DB::table('tx_node_authorization_state')->count());
    }

    public function test_damaged_or_unknown_issue_evidence_cannot_become_an_empty_snapshot(): void
    {
        $this->issued();
        DB::table('tx_credential_provider_operation')->update(['result_hash' => str_repeat('0', 64)]);
        $this->rejectSnapshot('NATIVE_CREDENTIAL_OPERATION_DAMAGED');
        $this->assertSame(0, $this->metadataCalls);
        DB::table('tx_credential_provider_operation')->update(['status' => 'unknown', 'result_hash' => null, 'result_snapshot' => null]);
        DB::table('tx_access_subject')->update(['status' => 'pending']);
        $this->rejectSnapshot('NATIVE_CREDENTIAL_OPERATION_DAMAGED');
        $this->assertSame(0, $this->metadataCalls);
    }

    public function test_provider_outage_does_not_sign_empty_or_advance_revision(): void
    {
        $this->issued();
        $this->duringMetadata = static fn () => throw new ProviderError('PROVIDER_TRANSPORT_FAILED', true);
        try {
            $this->snapshot();
            $this->fail('Outage must refuse snapshot');
        } catch (ProviderError $error) {
            $this->assertSame('PROVIDER_TRANSPORT_FAILED', $error->codeName);
        }
        $this->assertSame(0, DB::table('tx_node_authorization_state')->count());
    }

    public function test_empty_and_out_of_group_snapshots_never_create_slots_or_use_legacy_users(): void
    {
        $this->duringMetadata = static fn () => throw new ProviderError('PROVIDER_TRANSPORT_FAILED', true);
        $this->user(['plan_id' => 1, 'group_id' => 7, 'transfer_enable' => 999999, 'uuid' => 'legacy-only-secret']);
        $this->assertSame([], $this->verify($this->snapshot()['authorization'])['users']);
        $this->assertSame(0, DB::table('tx_subscription_slot')->count());
        $this->issued();
        DB::table('tx_server')->where('id', $this->node->id)->update(['group_ids' => '[9]']);
        $this->assertSame([], $this->verify($this->snapshot()['authorization'])['users']);
        $this->assertSame([], $this->metadataRequests);
        $this->assertSame(0, $this->metadataCalls);
        $this->assertSame(1, DB::table('tx_subscription_slot')->count());
    }

    public function test_recipient_machine_and_persistent_installation_pin_are_mandatory(): void
    {
        $this->issued();
        config(['native_provider_node.recipients.'.$this->node->id.'.machine_id' => 1]);
        $this->rejectSnapshot('NATIVE_PROVIDER_NODE_RECIPIENT_INVALID');
        config(['native_provider_node.recipients.'.$this->node->id.'.machine_id' => null,
            'native_node_authorization.core_instance_id' => '22222222-2222-4222-8222-222222222222']);
        $this->rejectSnapshot('NATIVE_PROVIDER_NODE_TRUST_INVALID');
        $this->assertSame(0, $this->metadataCalls);
    }

    public function test_candidate_overflow_refuses_before_network(): void
    {
        $this->issued();
        $template = (array) DB::table('tx_access_subject')->first();
        // Synthetic scan candidates cannot reuse another Subject's operation ownership.
        unset($template['id'], $template['current_credential_operation_ref']);
        for ($i = 0; $i < 1024; $i++) {
            DB::table('tx_access_subject')->insert(array_replace($template, [
                'provider_subject_ref' => 'subject_'.bin2hex(random_bytes(16)),
            ]));
        }
        $this->rejectSnapshot('NATIVE_PROVIDER_NODE_SCAN_CAPACITY_EXCEEDED');
        $this->assertSame(0, $this->metadataCalls);
    }

    public function test_other_groups_do_not_consume_the_nodes_128_user_budget(): void
    {
        [$included] = $this->issued();
        [, , $outside] = $this->issued(['group_id' => 8]);
        $template = (array) DB::table('tx_access_subject')->where('id', $outside['subject_id'])->first();
        unset($template['id'], $template['current_credential_operation_ref']);
        for ($i = 0; $i < 129; $i++) {
            DB::table('tx_access_subject')->insert(array_replace($template, [
                'provider_subject_ref' => 'subject_'.bin2hex(random_bytes(16)),
            ]));
        }
        $payload = $this->verify($this->snapshot()['authorization']);
        $this->assertSame([$included->id], array_column($payload['users'], 'id'));
        $this->assertCount(1, $this->metadataRequests[0]['subjects']);
    }

    public function test_closed_subscription_subject_does_not_block_a_replacement_current_slot(): void
    {
        [$user, $old, $oldIssue] = $this->issued();
        app(SubscriptionLifecycleService::class)->revoke($user->id, (int) $old->id, 'close-subscription', '1', time());
        $this->assertSame('active', DB::table('tx_access_subject')->where('id', $oldIssue['subject_id'])->value('status'));
        $offer = $this->publishedOffer($this->plan(), key: 'replacement');
        $order = app(PublishedOrderService::class)->create($user->id, $offer, 'replacement-purchase');
        DB::table('tx_order')->where('id', $order->id)->update(['status' => Order::STATUS_PROCESSING]);
        $replacement = app(SubscriptionFulfillmentService::class)->fulfill($order->id, time());
        $issue = app(NativeCredentialProviderService::class)->issue($user->id, (int) $replacement['subscription_id'],
            'fixture-provider', 'vless-uuid/v1', 'replacement-issue', '1');
        $payload = $this->verify($this->snapshot()['authorization']);
        $this->assertSame([$user->id], array_column($payload['users'], 'id'));
        $this->assertSame($issue['subject_id'], $payload['provider']['subjects'][0]['access_subject_id']);
    }

    public function test_subject_terms_and_operation_owner_foreign_keys_remain_enforced(): void
    {
        [$firstUser, , $first] = $this->issued();
        [, $otherSubscription] = $this->issued();
        foreach ([
            fn () => DB::table('tx_access_subject')->where('id', $first['subject_id'])->update(['bound_terms_id' => $otherSubscription->current_terms_id]),
            fn () => DB::table('tx_credential_provider_operation')->where('operation_ref', $first['operation_ref'])
                ->update(['user_id' => $firstUser->id + 1]),
        ] as $write) {
            try {
                DB::transaction($write);
                $this->fail('Foreign owner accepted');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_node_routes_use_provider_policy_without_legacy_fallback_and_refuse_old_billing(): void
    {
        [$user] = $this->issued();
        $headers = $this->httpHeaders();
        $this->getJson('/txapi/node/v1/users')->assertUnauthorized();
        $this->getJson('/txapi/node/v1/users', $headers + ['If-None-Match' => '"cached"'])
            ->assertOk()->assertJsonPath('data.users.0.uuid', 'sha256:'.hash('sha256', 'fixture-1'))
            ->assertHeader('Cache-Control', 'no-store, private');
        $handshake = $this->postJson('/txapi/node/v1/handshake', [], $headers)->assertOk()
            ->assertJsonPath('data.authorization_trust.policy_version', NativeNodeAuthorization::PROVIDER_POLICY_VERSION);
        $this->assertContains('node_provider_credential_delivery_v1', $handshake->json('data.capabilities'));
        $this->assertNotContains('traffic_batch_v1', $handshake->json('data.capabilities'));
        $this->assertNotContains('traffic_receipt_durable_v1', $handshake->json('data.capabilities'));
        $this->postJson('/txapi/node/v1/report', ['protocol_version' => 1, 'traffic_batch_id' => 'fixture-batch-01',
            'traffic' => [(string) $user->id => [12, 34]]], $headers)->assertStatus(503)
            ->assertJsonPath('error.code', 'NATIVE_TRAFFIC_SETTLEMENT_UNAVAILABLE');
        $this->assertSame(0, (int) $user->fresh()->u);
        config(['native_node_authorization.enabled' => false]);
        $this->getJson('/txapi/node/v1/users', $headers)->assertStatus(503)
            ->assertJsonPath('error.code', 'NODE_AUTHORIZATION_UNAVAILABLE');
    }

    public function test_locked_resolver_refuses_without_transaction_and_does_not_create_slots(): void
    {
        $user = $this->user();
        $result = DB::transaction(fn () => app(SubscriptionEntitlementResolver::class)->resolveLocked($user->id, time()));
        $this->assertFalse($result['authorized']);
        $this->assertSame(0, DB::table('tx_subscription_slot')->count());
        $this->expectExceptionMessage('ENTITLEMENT_TRANSACTION_REQUIRED');
        app(SubscriptionEntitlementResolver::class)->resolveLocked($user->id, time());
    }

    private function issued(array $planOverrides = []): array
    {
        $user = $this->user();
        $offer = $this->publishedOffer($this->plan($planOverrides), key: 'plan-'.$user->id);
        $order = app(PublishedOrderService::class)->create($user->id, $offer, 'purchase');
        DB::table('tx_order')->where('id', $order->id)->update(['status' => Order::STATUS_PROCESSING]);
        $fulfilled = app(SubscriptionFulfillmentService::class)->fulfill($order->id, time() - 5);
        $subscription = DB::table('tx_subscription')->where('id', $fulfilled['subscription_id'])->first();
        $issue = app(NativeCredentialProviderService::class)->issue($user->id, (int) $subscription->id,
            'fixture-provider', 'vless-uuid/v1', 'issue', (string) $subscription->row_version);
        return [$user, $subscription, $issue];
    }

    private function verify(array $authorization): array
    {
        return $this->verifyMachine($authorization, null);
    }

    private function verifyMachine(array $authorization, ?int $machineId): array
    {
        return NativeNodeAuthorization::verify($authorization, $this->service()->publicTrust()['public_key'], [
            'policy_version' => NativeNodeAuthorization::PROVIDER_POLICY_VERSION, 'core_instance_id' => self::INSTALLATION,
            'node_id' => (int) $this->node->id, 'machine_id' => $machineId, 'key_id' => 'fixture-key', 'key_epoch' => '1',
        ], time());
    }

    private function snapshot(): array
    {
        return $this->service()->snapshot($this->node, $this->credential);
    }

    private function machineIdentity(): int
    {
        $id = DB::table('tx_server_machine')->insertGetId(['is_active' => true, 'token' => 'fixture-machine-token']);
        DB::table('tx_server')->where('id', $this->node->id)->update(['machine_id' => $id]);
        $this->node = $this->node->fresh();
        $this->credential = ['kind' => 'machine', 'machine_id' => $id,
            'credential_sha256' => hash('sha256', 'fixture-machine-token')];
        config(['native_provider_node.recipients.'.$this->node->id.'.machine_id' => $id]);
        return $id;
    }

    private function httpHeaders(?int $machineId = null, string $token = 'fixture-node-token'): array
    {
        app(InstallationAuthority::class)->shouldReceive('assertRuntime')->andReturnNull();
        $plugins = Mockery::mock(PluginManager::class);
        $plugins->shouldReceive('initializeEnabledPlugins')->andReturnNull();
        $this->app->instance(PluginManager::class, $plugins);
        $this->settings['server_token'] = 'fixture-node-token';
        $headers = ['Authorization' => 'Bearer '.$token, 'X-TX-Node-ID' => (string) $this->node->id];
        if ($machineId !== null) { $headers['X-TX-Machine-ID'] = (string) $machineId; }
        return $headers;
    }

    private function service(): NativeProviderNodeAuthorization
    {
        return app(NativeProviderNodeAuthorization::class);
    }

    private function rejectSnapshot(string $message): void
    {
        try {
            $this->snapshot();
            $this->fail('Expected snapshot refusal');
        } catch (DomainException $error) {
            $this->assertSame($message, $error->getMessage());
        }
    }
}
