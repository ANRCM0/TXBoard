<?php

namespace Tests\Feature\Txapi;

use App\Domains\CredentialProvider\ProviderTransport;
use App\Domains\Installation\InstallationAuthority;
use App\Models\Order;
use App\Services\Plugin\PluginManager;
use App\Services\Subscription\NativeCredentialProviderService;
use App\Services\Subscription\PublishedOrderService;
use App\Services\Subscription\SubscriptionFulfillmentService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\Support\NativeSubscriptionTestCase;

final class TxapiNativeCredentialProviderTest extends NativeSubscriptionTestCase
{
    private int $providerCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_10_16_000001_enable_native_credential_provider.php'))->up();
        (require database_path('migrations/2026_10_16_000002_enable_native_credential_rotation.php'))->up();
        app(InstallationAuthority::class)->shouldReceive('assertRuntime')->andReturnNull();
        $plugins = Mockery::mock(PluginManager::class);
        $plugins->shouldReceive('initializeEnabledPlugins')->andReturnNull();
        $this->app->instance(PluginManager::class, $plugins);
        $transport = Mockery::mock(ProviderTransport::class);
        $transport->shouldReceive('configuration')->with('primary-provider')->andReturn([
            'provider_id' => 'primary-provider', 'provider_instance_epoch' => '1',
            'profiles' => ['vless-uuid/v1'], 'config_digest' => str_repeat('a', 64),
        ]);
        $transport->shouldReceive('call')->andReturnUsing(function (array $registration, array $request): array {
            $this->assertSame(0, DB::transactionLevel());
            $this->providerCalls++;
            $input = $request['input'];
            $result = ['access_subject_id' => $input['access_subject_id'], 'provider_subject_ref' => $input['provider_subject_ref']];
            $result += in_array($request['operation'], ['issue', 'rotate'], true) ? [
                'credential_profile_id' => $input['credential_profile_id'],
                'credential_ref' => 'credential_'.substr(hash('sha256', $request['operation_id']), 0, 32),
                'credential_generation' => $request['operation'] === 'rotate'
                    ? (string) ((int) $input['expected_credential_generation'] + 1) : '1',
                'issued_at' => time(), 'not_before_at' => $input['not_before_at'],
                'credential_expires_at' => $input['credential_expires_at'],
            ] : [
                'accepted_revocation_fact_version' => $input['revocation_fact_version'], 'provider_revoked_at' => time(),
            ];
            if ($request['operation'] === 'rotate') {
                $result += ['previous_credential_ref' => $input['credential_ref'],
                    'previous_generation' => $input['expected_credential_generation'],
                    'previous_valid_until' => $result['issued_at'], 'rotation_mode' => 'immediate'];
            }
            return ['status' => 'succeeded', 'result' => $result, 'error' => null];
        });
        $this->app->instance(ProviderTransport::class, $transport);
        app(NativeCredentialProviderService::class)->register('primary-provider', 'api-provider');
    }

    public function test_authenticated_issue_replay_read_and_revoke_use_the_owner_and_non_secret_receipts(): void
    {
        [$user, $subscription] = $this->subscription();
        Sanctum::actingAs($user);
        $body = $this->issueBody($subscription['subscription_id']);
        $created = $this->postJson('/txapi/me/access-subjects', $body, ['Idempotency-Key' => 'api-issue'])
            ->assertOk()->assertJsonPath('data.subject_status', 'active');
        $operation = $created->json('data');
        $this->assertStringNotContainsString($user->uuid, $created->getContent());
        $this->assertStringNotContainsString($user->token, $created->getContent());
        $this->assertStringContainsString('no-store', $created->headers->get('Cache-Control'));
        $this->postJson('/txapi/me/access-subjects', $body, ['Idempotency-Key' => 'api-issue'])
            ->assertOk()->assertJsonPath('data.operation_ref', $operation['operation_ref']);
        $this->getJson('/txapi/me/credential-operations/'.$operation['operation_ref'])->assertOk();
        $this->assertSame(1, $this->providerCalls);
        $this->postJson('/txapi/me/access-subjects/'.$operation['subject_id'].'/revoke',
            ['expected_version' => $operation['subject_version']], ['Idempotency-Key' => 'api-revoke'])
            ->assertOk()->assertJsonPath('data.subject_status', 'revoked');
        $this->assertSame(2, $this->providerCalls);
    }

    public function test_other_account_cannot_issue_read_recover_or_revoke_an_owners_subject(): void
    {
        [$owner, $subscription] = $this->subscription();
        $operation = app(NativeCredentialProviderService::class)->issue($owner->id,
            (int) $subscription['subscription_id'], 'primary-provider', 'vless-uuid/v1', 'owner-issue', '1');
        Sanctum::actingAs($this->user());
        $this->postJson('/txapi/me/access-subjects', $this->issueBody($subscription['subscription_id']),
            ['Idempotency-Key' => 'cross-account'])->assertNotFound();
        $this->getJson('/txapi/me/credential-operations/'.$operation['operation_ref'])->assertNotFound();
        $this->postJson('/txapi/me/credential-operations/'.$operation['operation_ref'].'/recover')->assertNotFound();
        $this->postJson('/txapi/me/access-subjects/'.$operation['subject_id'].'/revoke',
            ['expected_version' => $operation['subject_version']], ['Idempotency-Key' => 'cross-revoke'])->assertNotFound();
        $this->postJson('/txapi/me/access-subjects/'.$operation['subject_id'].'/rotate',
            ['expected_version' => $operation['subject_version']], ['Idempotency-Key' => 'cross-rotate'])->assertNotFound();
        $this->assertSame(1, $this->providerCalls);
        $this->assertSame('active', DB::table('tx_access_subject')->value('status'));
    }

    public function test_authenticated_rotation_is_immediate_idempotent_and_has_closed_inputs(): void
    {
        [$owner, $subscription] = $this->subscription();
        $operation = app(NativeCredentialProviderService::class)->issue($owner->id,
            (int) $subscription['subscription_id'], 'primary-provider', 'vless-uuid/v1', 'rotate-fixture', '1');
        Sanctum::actingAs($owner);
        $url = '/txapi/me/access-subjects/'.$operation['subject_id'].'/rotate';
        $body = ['expected_version' => $operation['subject_version']];
        $this->postJson($url, $body)->assertStatus(422)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REQUIRED');
        $this->postJson($url, $body + ['rotation_mode' => 'overlap'], ['Idempotency-Key' => 'rotation-closed'])
            ->assertStatus(422);
        $rotated = $this->postJson($url, $body, ['Idempotency-Key' => 'rotation'])
            ->assertOk()->assertJsonPath('data.credential_generation', '2')
            ->assertJsonPath('data.subject_id', $operation['subject_id'])->json('data');
        $this->assertNotSame($operation['credential_ref'], $rotated['credential_ref']);
        $this->postJson($url, $body, ['Idempotency-Key' => 'rotation'])
            ->assertOk()->assertJsonPath('data.operation_ref', $rotated['operation_ref']);
        $this->getJson('/txapi/me/credential-operations/'.$operation['operation_ref'])->assertOk();
        $this->assertSame(2, $this->providerCalls);
        $this->assertSame(1, DB::table('tx_access_subject')->count());
    }

    public function test_authentication_key_and_closed_inputs_are_enforced_before_provider_calls(): void
    {
        $body = $this->issueBody('1');
        $this->postJson('/txapi/me/access-subjects', $body)->assertUnauthorized();
        Sanctum::actingAs($this->user());
        $this->postJson('/txapi/me/access-subjects', $body)->assertStatus(422)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REQUIRED');
        foreach (['user_id', 'endpoint', 'private_key', 'provider_subject_ref'] as $extra) {
            $this->postJson('/txapi/me/access-subjects', $body + [$extra => 'untrusted'],
                ['Idempotency-Key' => 'extra-'.$extra])->assertStatus(422);
        }
        $this->assertSame(0, $this->providerCalls);
        $this->assertSame(0, DB::table('tx_credential_provider_operation')->count());
    }

    private function subscription(): array
    {
        $user = $this->user();
        $order = app(PublishedOrderService::class)->create($user->id, $this->publishedOffer($this->plan()), 'subscription');
        $order->update(['status' => Order::STATUS_PROCESSING]);
        return [$user, app(SubscriptionFulfillmentService::class)->fulfill($order->id, time() - 1)];
    }

    private function issueBody(string $subscriptionId): array
    {
        return ['subscription_id' => $subscriptionId, 'provider_id' => 'primary-provider',
            'credential_profile_id' => 'vless-uuid/v1', 'expected_version' => '1'];
    }
}
