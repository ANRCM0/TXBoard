<?php

namespace Tests\Feature\Txapi;

use App\Domains\CredentialProvider\ProviderCodec;
use App\Domains\Installation\InstallationAuthorityException;
use App\Domains\InternalApi\ServiceCredentials;
use App\Domains\Subscription\Services\PublishedOrderService;
use App\Domains\Subscription\Services\SubscriptionFulfillmentService;
use App\Domains\Subscription\Services\SubscriptionLifecycleService;
use App\Domains\UsageV2\Contracts\UsagePage;
use App\Domains\UsageV2\Contracts\UsageQueries;
use App\Domains\UsageV2\Contracts\UsageQuery;
use App\Domains\UsageV2\Contracts\UsageTargetMissing;
use App\Domains\UsageV2\NativeUsageQueries;
use App\Domains\UsageV2\UsagePayload;
use App\Domains\UsageV2\UsageReceipts;
use App\Http\Controllers\Txapi\NativeUsageController;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery;
use Tests\Support\NativeRuntimeTestCase;

final class UsageReadBoundaryTest extends NativeRuntimeTestCase
{
    private const FINGERPRINT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private array $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->activate();
        config(['internal_api.enabled' => true, 'internal_api.trusted_tls_proxies' => ['127.0.0.1']]);
        $this->service = app(ServiceCredentials::class)->issue('usage-read-gateway', 'user', self::FINGERPRINT,
            ['usage.mine.list'], time() + 3600);
    }

    public function test_scoped_native_owner_uses_actual_alternating_bearers_and_current_slot(): void
    {
        $queries = app(UsageQueries::class);
        $this->assertInstanceOf(NativeUsageQueries::class, $queries);
        $this->assertSame($queries, app(UsageQueries::class));
        [$first, $firstSubscription] = $this->subscribe();
        [$second, $secondSubscription] = $this->subscribe();
        $tokens = [$first->id => $first->createToken('first', ['*'])->plainTextToken,
            $second->id => $second->createToken('second', ['*'])->plainTextToken];
        foreach ([[$first, $firstSubscription], [$second, $secondSubscription], [$first, $firstSubscription],
            [$second, $secondSubscription]] as [$actor, $subscription]) {
            $http = $this->http($tokens[$actor->id])->assertOk();
            $internal = $this->invoke($tokens[$actor->id])->assertOk();
            $this->assertSame($http->json('data'), $internal->json('data'));
            $this->assertSame($http->json('meta'), $internal->json('meta'));
            $internal->assertJsonPath('data.0.subscription_id', $subscription['subscription_id'])
                ->assertJsonPath('data.0.id', $subscription['usage_period_id'])
                ->assertJsonPath('meta.schema', 'native-usage-periods/v1')->assertJsonPath('meta.next_after_id', null);
            $item = $internal->json('data.0');
            foreach (['id', 'subscription_id', 'terms_id', 'period_no', 'quota_bytes', 'upload_bytes',
                'download_bytes', 'billable_bytes', 'consumed_bytes', 'row_version'] as $field) {
                $this->assertIsString($item[$field]);
            }
            $this->assertIsInt($item['starts_at']);
            foreach ([$http, $internal] as $response) {
                $this->assertTrue($response->headers->hasCacheControlDirective('private'));
                $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
            }
        }
        $this->assertSame(0, DB::table('tx_internal_request')->count());
        $this->app->forgetScopedInstances();
        $this->assertNotSame($queries, app(UsageQueries::class));
    }

    public function test_owned_history_cursor_limit_and_empty_page_keep_full_period_projection(): void
    {
        [$actor, $old] = $this->subscribe();
        $token = $actor->createToken('history', ['*'])->plainTextToken;
        $start = (int) DB::table('tx_usage_period')->where('id', $old['usage_period_id'])->value('starts_at');
        $reset = app(SubscriptionLifecycleService::class)->reset($actor->id, (int) $old['subscription_id'],
            'usage-query-reset', $old['subscription_version'], $start + 1);
        app(SubscriptionLifecycleService::class)->revoke($actor->id, (int) $old['subscription_id'],
            'usage-query-revoke', $reset['subscription_version'], $start + 2);
        $offer = collect($this->offers)->firstWhere('offer_code', 'monthly');
        $order = app(PublishedOrderService::class)->create($actor->id, (int) $offer['offer_id'], 'usage-query-new-order');
        app(PublishedOrderService::class)->transitionOrder($order->id,
            static fn (Order $locked) => $locked->update(['status' => Order::STATUS_PROCESSING]));
        $current = app(SubscriptionFulfillmentService::class)->fulfill($order->id, $start + 3);
        $this->invoke($token)->assertOk()->assertJsonPath('data.0.subscription_id', $current['subscription_id']);
        $input = ['subscription_id' => $old['subscription_id'], 'limit' => 1];
        $first = $this->invoke($token, $input)->assertOk()
            ->assertJsonPath('data.0.id', $old['usage_period_id'])->assertJsonPath('data.0.status', 'closed')
            ->assertJsonPath('meta.next_after_id', $old['usage_period_id']);
        $this->assertCount(1, $first->json('data'));
        $this->assertSame($first->json('data'), $this->http($token, $input)->assertOk()->json('data'));
        $second = $this->invoke($token, $input + ['after_id' => $old['usage_period_id']])->assertOk()
            ->assertJsonPath('data.0.id', $reset['usage_period_id'])->assertJsonPath('meta.next_after_id', null);
        $this->assertCount(1, $second->json('data'));
        foreach ([0, 1] as $entry) {
            $response = $entry === 0
                ? $this->http($token, $input + ['after_id' => $reset['usage_period_id']])
                : $this->invoke($token, $input + ['after_id' => $reset['usage_period_id']]);
            $response->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.next_after_id', null);
        }
        $this->assertSame(0, DB::table('tx_internal_request')->count());
    }

    public function test_missing_and_cross_owner_targets_roll_back_initial_slot_but_empty_current_read_commits_it(): void
    {
        $actor = $this->newUser();
        [, $other] = $this->subscribe();
        $token = $actor->createToken('empty-owner', ['*'])->plainTextToken;
        foreach ([$other['subscription_id'], '999999999'] as $id) {
            $this->http($token, ['subscription_id' => $id])->assertNotFound();
            $this->assertSame(0, DB::table('tx_subscription_slot')->where('user_id', $actor->id)->count());
            $this->invoke($token, ['subscription_id' => $id])->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
            $this->assertSame(0, DB::table('tx_subscription_slot')->where('user_id', $actor->id)->count());
        }
        try {
            app(UsageQueries::class)->listing((int) $actor->id, new UsageQuery(999999999));
            $this->fail('Missing target was not thrown inside the transaction.');
        } catch (UsageTargetMissing $error) {
            $this->assertSame('NATIVE_USAGE_SUBSCRIPTION_NOT_FOUND', $error->getMessage());
        }
        $this->assertSame(0, DB::table('tx_subscription_slot')->where('user_id', $actor->id)->count());
        $this->assertSame(0, DB::transactionLevel());
        $this->http($token)->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.next_after_id', null);
        $this->assertSame(1, DB::table('tx_subscription_slot')->where('user_id', $actor->id)->count());
        $this->invoke($token)->assertOk()->assertJsonPath('data', []);
        $this->assertSame(0, DB::table('tx_internal_request')->count());
    }

    public function test_usage_projection_includes_unsettled_reservations_without_settling_or_changing_periods(): void
    {
        [$actor, $subscription] = $this->subscribe();
        $token = $actor->createToken('reserved-owner', ['*'])->plainTextToken;
        $snapshot = $this->snapshot();
        $grant = $snapshot['usage_v2']['grants'][0];
        $payload = ['protocol_version' => 2, 'node_id' => (string) $this->node->id,
            'source_epoch' => $snapshot['usage_v2']['source_epoch'], 'batch_id' => 'usage-query-reserved',
            'started_at' => $snapshot['issued_at'], 'ended_at' => $snapshot['issued_at'] + 1,
            'entries' => [array_intersect_key($grant, array_flip([
                'access_subject_id', 'auth_snapshot_id', 'tariff_revision_id', 'terms_id', 'usage_period_id',
            ])) + ['raw_up_bytes' => '3', 'raw_down_bytes' => '5']]];
        $wire = app(UsagePayload::class)->submission(json_encode(['payload' => $payload,
            'payload_digest' => 'sha256:'.hash('sha256', ProviderCodec::canonical($payload))], JSON_THROW_ON_ERROR));
        app(UsageReceipts::class)->submit((int) $this->node->id, $wire, $snapshot['issued_at'] + 2);
        $period = DB::table('tx_usage_period')->where('id', $subscription['usage_period_id'])->first();
        foreach ([$this->http($token), $this->invoke($token)] as $response) {
            $response->assertOk()->assertJsonPath('data.0.consumed_bytes', '11')->assertJsonPath('data.0.billable_bytes', '0');
        }
        $this->assertEquals($period, DB::table('tx_usage_period')->where('id', $subscription['usage_period_id'])->first());
        $this->assertSame('11', (string) DB::table('tx_native_usage_reservation')->value('reserved_bytes'));
        $this->assertSame(0, DB::table('tx_usage_entry')->count());
        $this->assertSame(0, DB::table('tx_internal_request')->count());
    }

    public function test_corrupt_period_outside_requested_cursor_and_limit_never_becomes_empty_success(): void
    {
        [$actor, $subscription] = $this->subscribe();
        $token = $actor->createToken('integrity-owner', ['*'])->plainTextToken;
        $start = (int) DB::table('tx_usage_period')->where('id', $subscription['usage_period_id'])->value('starts_at');
        $reset = app(SubscriptionLifecycleService::class)->reset($actor->id, (int) $subscription['subscription_id'],
            'usage-query-corruption-reset', $subscription['subscription_version'], $start + 1);
        DB::table('tx_usage_period')->where('id', $subscription['usage_period_id'])->update(['boundary_kind' => 'damaged']);
        $input = ['after_id' => $reset['usage_period_id'], 'limit' => 1];
        $this->invoke($token, $input)->assertStatus(503)->assertJsonPath('error.code', 'DEPENDENCY_UNAVAILABLE');
        $this->http($token, $input)->assertStatus(500);
        try {
            app(UsageQueries::class)->listing((int) $actor->id, new UsageQuery(null, (int) $reset['usage_period_id'], 1));
            $this->fail('Corruption before the requested page was ignored.');
        } catch (\DomainException $error) {
            $this->assertSame('NATIVE_PERIOD_HISTORY_INVALID', $error->getMessage());
        }
        DB::table('tx_usage_period')->where('id', $subscription['usage_period_id'])->update(['boundary_kind' => 'known']);
        DB::table('tx_subscription_terms')->where('subscription_id', $subscription['subscription_id'])
            ->update(['content_hash' => str_repeat('0', 64)]);
        $this->invoke($token, $input)->assertStatus(503)->assertJsonPath('error.code', 'DEPENDENCY_UNAVAILABLE');
        $this->http($token, $input)->assertStatus(500);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(0, DB::table('tx_internal_request')->count());
    }

    public function test_installation_rejection_precedes_account_slot_initialization(): void
    {
        $actor = $this->newUser();
        $token = $actor->createToken('admission-owner', ['*'])->plainTextToken;
        config(['core_installation.worker_epoch' => '1']);
        try {
            app(UsageQueries::class)->listing((int) $actor->id, new UsageQuery());
            $this->fail('Stale installation worker admitted usage read.');
        } catch (InstallationAuthorityException $error) {
            $this->assertNotSame('', $error->getMessage());
        }
        $this->invoke($token)->assertStatus(503)->assertJsonPath('error.code', 'DEPENDENCY_UNAVAILABLE');
        $this->assertSame(0, DB::table('tx_subscription_slot')->where('user_id', $actor->id)->count());
        $this->assertSame(0, DB::table('tx_internal_request')->count());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_http_and_internal_share_public_port_and_internal_does_not_resolve_controller(): void
    {
        $actor = $this->newUser();
        $token = $actor->createToken('port-owner', ['*'])->plainTextToken;
        $items = [['id' => '71', 'subscription_id' => '12', 'consumed_bytes' => '19', 'starts_at' => 123]];
        $port = Mockery::mock(UsageQueries::class);
        $port->shouldReceive('listing')->twice()->with((int) $actor->id, Mockery::on(static fn ($query): bool =>
            $query instanceof UsageQuery && $query->subscriptionId === 12 && $query->afterId === 70 && $query->limit === 1))
            ->andReturn(new UsagePage($items, '71'));
        $this->app->instance(UsageQueries::class, $port);
        $input = ['subscription_id' => '12', 'after_id' => '70', 'limit' => 1];
        $http = $this->http($token, $input)->assertOk();
        $this->assertSame($items, $http->json('data'));
        $this->app->bind(NativeUsageController::class, static function () {
            throw new \LogicException('INTERNAL_READ_MUST_NOT_RESOLVE_USAGE_CONTROLLER');
        });
        $internal = $this->invoke($token, $input)->assertOk();
        $this->assertSame($http->json('data'), $internal->json('data'));
        $this->assertSame($http->json('meta'), $internal->json('meta'));
        $this->assertSame(0, DB::table('tx_internal_request')->count());
    }

    public function test_closed_input_wire_bounds_service_scope_and_current_actor_policy_precede_port(): void
    {
        $actor = $this->newUser();
        $token = $actor->createToken('rejected-owner', ['*']);
        $port = Mockery::mock(UsageQueries::class);
        $port->shouldNotReceive('listing');
        $this->app->instance(UsageQueries::class, $port);
        foreach ([['user_id' => '999'], ['handler' => NativeUsageController::class], ['limit' => 0], ['limit' => 101],
            ['after_id' => '-1'], ['subscription_id' => '0']] as $input) {
            $this->http($token->plainTextToken, $input)->assertStatus(422);
            $this->invoke($token->plainTextToken, $input)->assertStatus(422);
        }
        $this->invoke($token->plainTextToken, ['subscription_id' => 12])->assertStatus(422);
        $this->invoke($token->plainTextToken, ['after_id' => 1])->assertStatus(422);
        $this->invoke()->assertUnauthorized();
        $this->invoke($token->plainTextToken, headers: ['X-Core-Tls-Certificate-Sha256' => str_repeat('b', 64)])->assertUnauthorized();
        $this->invoke($token->plainTextToken, domain: 'admin')->assertUnauthorized();
        $this->service = app(ServiceCredentials::class)->issue('usage-scope-limited', 'user', self::FINGERPRINT,
            ['system.capabilities.get'], time() + 3600);
        $this->invoke($token->plainTextToken)->assertForbidden();
        $this->service = app(ServiceCredentials::class)->issue('usage-scope-fresh', 'user', self::FINGERPRINT,
            ['usage.mine.list'], time() + 3600);
        $actor->forceFill(['banned' => true])->saveOrFail();
        $this->invoke($token->plainTextToken)->assertForbidden();
        $actor->forceFill(['banned' => false])->saveOrFail();
        $token->accessToken->delete();
        $this->invoke($token->plainTextToken)->assertUnauthorized();
        $this->assertSame(0, DB::table('tx_internal_request')->count());
    }

    public function test_internal_projection_redaction_bounds_and_dependency_errors_rejoin_safe_pipeline(): void
    {
        $actor = $this->newUser();
        $token = $actor->createToken('projection-owner', ['*'])->plainTextToken;
        $secret = 'USAGE_QUERY_PRIVATE_SECRET';
        $port = Mockery::mock(UsageQueries::class);
        $port->shouldReceive('listing')->once()->andReturn(new UsagePage([
            ['id' => 71, 'subscription_id' => 12, 'consumed_bytes' => 19,
                'status' => ['state' => 'open', 'token' => $secret, 'nested' => ['private_key' => $secret, 'safe' => true]],
                'password' => $secret, 'unexpected' => $secret],
        ], '71'));
        $port->shouldReceive('listing')->once()->andReturn(new UsagePage([['id' => '71', 'status' => str_repeat('x', 2048)]], null));
        $port->shouldReceive('listing')->once()->andThrow(new \RuntimeException($secret));
        $this->app->instance(UsageQueries::class, $port);
        $response = $this->invoke($token)->assertOk();
        $this->assertSame([['id' => '71', 'subscription_id' => '12', 'consumed_bytes' => '19',
            'status' => ['state' => 'open', 'nested' => ['safe' => true]]]], $response->json('data'));
        $response->assertJsonPath('meta.schema', 'native-usage-periods/v1')->assertJsonPath('meta.next_after_id', '71');
        $this->assertStringNotContainsString($secret, $response->getContent());
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        config(['internal_api.max_response_bytes' => 1024]);
        $this->invoke($token)->assertStatus(503)->assertJsonPath('error.code', 'DEPENDENCY_UNAVAILABLE');
        $failure = $this->invoke($token)->assertStatus(503)->assertJsonPath('error.code', 'DEPENDENCY_UNAVAILABLE');
        $this->assertStringNotContainsString($secret, $failure->getContent());
        $this->assertTrue($failure->headers->hasCacheControlDirective('no-store'));
        $this->assertStringNotContainsString($secret, json_encode(DB::table('tx_internal_audit')->get()));
        $this->assertStringNotContainsString($token, json_encode(DB::table('tx_internal_audit')->get()));
        $this->assertSame(0, DB::table('tx_internal_request')->count());
    }

    private function invoke(?string $token = null, array $input = [], array $headers = [], string $domain = 'user')
    {
        Auth::forgetGuards();
        $headers = array_replace([
            'X-Core-Service-Id' => $this->service['service_id'], 'X-Core-Service-Credential' => $this->service['credential'],
            'X-Core-Service-Epoch' => $this->service['epoch'], 'X-Core-Tls-Verified' => 'SUCCESS',
            'X-Core-Tls-Certificate-Sha256' => self::FINGERPRINT, 'Authorization' => $token === null ? '' : 'Bearer '.$token,
        ], $headers);
        return $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTPS' => 'on'])
            ->postJson('https://localhost/txapi/internal/native/v1/'.$domain.'/operations/usage.mine.list', [
                'contract_version' => '1', 'request_id' => (string) Str::uuid(), 'deadline_at' => time() + 15,
                'input' => $input === [] ? (object) [] : $input,
            ], $headers);
    }

    private function http(string $token, array $input = [])
    {
        Auth::forgetGuards();
        return $this->getJson('/txapi/me/usage-periods'.($input === [] ? '' : '?'.http_build_query($input)),
            ['Authorization' => 'Bearer '.$token]);
    }

    private function newUser(): User
    {
        $actor = User::withoutEvents(function (): User {
            $user = new User();
            $user->forceFill(['email' => Str::uuid().'@example.test', 'password' => Hash::make('UsageQueries2026'),
                'token' => Str::random(32), 'uuid' => (string) Str::uuid(), 'is_admin' => false, 'banned' => false]);
            $user->saveOrFail();
            return $user;
        });
        $fresh = $actor->fresh();
        $this->assertFalse((bool) $fresh->is_admin);
        $this->assertFalse((bool) $fresh->banned);
        return $fresh;
    }
}
