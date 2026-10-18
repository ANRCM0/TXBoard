<?php

namespace Tests\Feature\Txapi;

use App\Core\Http\TxapiErrorRenderer;
use App\Core\Http\TxapiResponse;
use App\Domains\InternalApi\ServiceCredentials;
use App\Domains\Billing\OrderIdempotency;
use App\Domains\Subscription\Services\NativeCredentialSynchronization;
use App\Models\User;
use App\Http\Middleware\NativeServiceIdentity;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Support\NativeRuntimeTestCase;

final class TxapiNativeInternalApiTest extends NativeRuntimeTestCase
{
    private const BASE = '/txapi/internal/native/v1/';
    private const FINGERPRINT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private array $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->activate();
        config(['internal_api.enabled' => true, 'internal_api.trusted_tls_proxies' => ['127.0.0.1']]);
        admin_setting(['secure_path' => 'internal-test-admin', 'captcha_enable' => 0]);
        $this->service = app(ServiceCredentials::class)->issue('user-gateway', 'user', self::FINGERPRINT,
            ['system.capabilities.get', 'identity.session.create', 'identity.sessions.list', 'identity.sessions.revoke',
                'commerce.orders.get'], time() + 3600);
    }

    public function test_production_channel_is_not_local_only_but_requires_verified_socket_peer_and_certificate_binding(): void
    {
        $this->app['env'] = 'production';
        $this->invoke('system.capabilities.get')->assertOk()
            ->assertJsonPath('data.contract', 'native-internal-api/v1');
        $this->invoke('system.capabilities.get', server: ['REMOTE_ADDR' => '203.0.113.7'])
            ->assertStatus(403)->assertJsonPath('error.code', 'SERVICE_UNAUTHORIZED');
        $this->invoke('system.capabilities.get', server: ['HTTPS' => 'off'])
            ->assertStatus(403);
        $this->invoke('system.capabilities.get', headers: ['X-Core-Tls-Certificate-Sha256' => str_repeat('b', 64)])
            ->assertStatus(401);
        $this->invoke('system.capabilities.get', headers: ['X-Forwarded-Proto' => 'https'], server: ['HTTPS' => 'off'])
            ->assertStatus(403);
    }

    public function test_service_rotation_epoch_revocation_and_security_domain_are_checked_on_every_call(): void
    {
        $old = $this->service;
        $this->service = app(ServiceCredentials::class)->rotate('user-gateway', self::FINGERPRINT, time() + 3600);
        $this->invoke('system.capabilities.get')->assertOk();
        $this->service = $old;
        $this->invoke('system.capabilities.get')->assertStatus(401);
        $this->invoke('system.capabilities.get', domain: 'admin')->assertStatus(401);
        app(ServiceCredentials::class)->revoke('user-gateway');
        $this->invoke('system.capabilities.get')->assertStatus(401);
        $stored = DB::table('tx_internal_service')->first();
        $this->assertNotSame($old['credential'], $stored->token_hash);
        $this->assertStringNotContainsString($old['credential'], json_encode((array) $stored));
    }

    public function test_unknown_operations_fields_deadlines_and_asserted_actor_are_rejected(): void
    {
        $this->invoke('unknown.operation')->assertStatus(404);
        $this->invoke('system.capabilities.get', ['user_id' => '1'])->assertStatus(422);
        $this->invoke('identity.sessions.list', headers: ['X-Actor-Id' => '1'])->assertStatus(401);
        $this->invoke('system.capabilities.get', envelope: ['deadline_at' => time() - 1])->assertStatus(504);
        $this->invoke('system.capabilities.get', envelope: ['target_url' => 'https://example.test'])->assertStatus(400);
        $this->assertSame(5, DB::table('tx_internal_audit')->count());
        $this->assertSame('unknown', DB::table('tx_internal_audit')->orderBy('id')->first()->operation);
        $this->assertSame(0, DB::table('tx_internal_audit')->where('state', 'started')->count());
    }

    public function test_input_must_be_an_object_and_client_trace_is_retained_for_valid_envelope_rejections(): void
    {
        $trace = (string) Str::uuid();
        $this->invoke('system.capabilities.get', envelope: ['input' => []])->assertStatus(400);
        $response = $this->invoke('system.capabilities.get', envelope: [
            'request_id' => $trace, 'deadline_at' => time() - 1,
        ])->assertStatus(504);
        $this->assertNotSame($trace, $response->json('request_id'));
        $this->assertSame($trace, DB::table('tx_internal_audit')->orderByDesc('id')->first()->request_id);
        $this->invoke('system.capabilities.get', envelope: ['deadline_at' => (string) (time() + 10)])
            ->assertStatus(400);
    }

    public function test_ingress_throttle_rejects_before_allocating_per_request_audit_storage(): void
    {
        $limit = RateLimiter::limiter('native-internal-ingress')($this->boundaryRequest());
        $key = md5('native-internal-ingress'.$limit->key);
        RateLimiter::increment($key, 60, $limit->maxAttempts);
        try {
            $response = $this->invoke('system.capabilities.get')->assertStatus(429)
                ->assertJsonPath('error.code', 'RATE_LIMITED')->assertHeader('Retry-After')
                ->assertHeader('X-RateLimit-Limit', '600')->assertHeader('X-RateLimit-Remaining', '0');
            $this->assertTrue($response->headers->hasCacheControlDirective('private'));
            $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
            $this->assertSame(0, DB::table('tx_internal_audit')->count());
        } finally {
            RateLimiter::clear($key);
        }
    }

    public function test_service_never_admits_legacy_mode_even_with_a_valid_service_credential(): void
    {
        config(['core_installation.mode' => 'legacy']);
        $this->invoke('system.capabilities.get')->assertStatus(503);
        $this->assertSame(0, DB::table('tx_internal_audit')->count());
    }

    public function test_response_exception_passthrough_requires_a_current_txapi_envelope(): void
    {
        $request = $this->boundaryRequest();
        $response = TxapiResponse::error($request, 'RATE_LIMITED', 'Service ingress limit exceeded', 429)
            ->withHeaders(['Retry-After' => '60', 'Cache-Control' => 'private, no-store']);
        $this->assertSame($response, TxapiErrorRenderer::render($request, new HttpResponseException($response)));
        $unstructured = response()->json(['exception' => 'PRIVATE_EXCEPTION_MATERIAL'], 429);
        $rejected = TxapiErrorRenderer::render($request, new HttpResponseException($unstructured));
        $this->assertSame(500, $rejected->getStatusCode());
        $this->assertSame('INTERNAL_ERROR', $rejected->getData(true)['error']['code']);
        $this->assertStringNotContainsString('PRIVATE_EXCEPTION_MATERIAL', $rejected->getContent());
        foreach ([
            TxapiResponse::error($request, 'RATE_LIMITED', 'PRIVATE_EXCEPTION_MATERIAL', 429),
            TxapiResponse::error($request, 'RATE_LIMITED', 'Service ingress limit exceeded', 200),
            TxapiResponse::error(Request::create('/other'), 'RATE_LIMITED', 'Service ingress limit exceeded', 429),
        ] as $invalid) {
            $sanitized = TxapiErrorRenderer::render($request, new HttpResponseException($invalid));
            $this->assertSame(500, $sanitized->getStatusCode());
            $this->assertSame('INTERNAL_ERROR', $sanitized->getData(true)['error']['code']);
            $this->assertStringNotContainsString('PRIVATE_EXCEPTION_MATERIAL', $sanitized->getContent());
        }
    }

    public function test_real_actor_sessions_are_rechecked_and_secret_fields_never_enter_audit(): void
    {
        $user = $this->user();
        $token = $user->createToken('internal-actor', ['*'])->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$token];
        $this->invoke('identity.sessions.list', headers: $headers)->assertOk()
            ->assertJsonPath('data.0.id', (string) $user->tokens()->first()->id);
        $user->update(['banned' => true]);
        $this->invoke('identity.sessions.list', headers: $headers)->assertStatus(403);
        $audit = json_encode(DB::table('tx_internal_audit')->get());
        $this->assertStringNotContainsString($token, $audit);
        $this->assertStringNotContainsString($this->service['credential'], $audit);
        $this->assertStringNotContainsString('authorization', $audit);
    }

    public function test_admin_requires_separate_service_current_role_path_and_live_permission(): void
    {
        $this->service = app(ServiceCredentials::class)->issue('admin-gateway', 'admin', self::FINGERPRINT,
            ['identity.sessions.list'], time() + 3600);
        $user = $this->user(['is_admin' => true]);
        $headers = ['Authorization' => 'Bearer '.$user->createToken('admin-actor', ['*'])->plainTextToken,
            'X-Core-Admin-Path' => 'internal-test-admin'];
        $this->invoke('identity.sessions.list', headers: $headers, domain: 'admin')->assertStatus(403);
        DB::table('tx_internal_actor_grant')->insert([
            'user_id' => $user->id, 'permission' => 'identity.sessions.manage', 'created_at' => time(),
        ]);
        $this->invoke('identity.sessions.list', headers: $headers, domain: 'admin')->assertOk();
        $this->invoke('identity.sessions.list', headers: array_replace($headers, ['X-Core-Admin-Path' => 'stale']),
            domain: 'admin')->assertStatus(404);
        DB::table('tx_internal_actor_grant')->delete();
        $this->invoke('identity.sessions.list', headers: $headers, domain: 'admin')->assertStatus(403);
        $user->update(['is_admin' => false]);
        $this->invoke('identity.sessions.list', headers: $headers, domain: 'admin')->assertStatus(403);
    }

    public function test_persistent_session_revoke_replays_original_result_and_rejects_changed_input(): void
    {
        $user = $this->user();
        $current = $user->createToken('current', ['*'])->plainTextToken;
        $target = $user->createToken('target', ['*'])->accessToken;
        $other = $this->user()->createToken('other-owner', ['*'])->accessToken;
        $headers = ['Authorization' => 'Bearer '.$current, 'Idempotency-Key' => (string) Str::uuid()];
        $this->invoke('identity.sessions.revoke', ['session_id' => (string) $target->id], $headers)
            ->assertOk()->assertJsonPath('data.ok', true);
        $this->invoke('identity.sessions.revoke', ['session_id' => (string) $target->id], $headers)
            ->assertOk()->assertJsonPath('data.ok', true);
        $this->invoke('identity.sessions.revoke', ['session_id' => (string) $other->id], $headers)
            ->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        $this->assertTrue($other->fresh() !== null);
        $this->assertSame(1, DB::table('tx_internal_request')->count());
    }

    public function test_unknown_workflow_outcome_never_reexecutes_the_business_mutation(): void
    {
        $user = $this->user();
        $current = $user->createToken('current', ['*'])->plainTextToken;
        $target = $user->createToken('not-yet-revoked', ['*'])->accessToken;
        $key = (string) Str::uuid();
        $input = ['session_id' => (string) $target->id];
        $this->unfinishedReceipt($user, 'identity.sessions.revoke', $key, $input);
        $this->invoke('identity.sessions.revoke', $input, [
            'Authorization' => 'Bearer '.$current, 'Idempotency-Key' => $key,
        ])->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_IN_PROGRESS');
        $this->assertNotNull($target->fresh());
        $this->assertSame('started', DB::table('tx_internal_request')->first()->state);
        $this->assertSame('inconclusive', DB::table('tx_internal_audit')->first()->state);
    }

    public function test_unfinished_domain_receipt_recovers_the_original_order_key_without_another_order(): void
    {
        $this->service = app(ServiceCredentials::class)->issue('order-gateway', 'user', self::FINGERPRINT,
            ['commerce.orders.create'], time() + 3600);
        $user = $this->user();
        $key = (string) Str::uuid();
        $input = ['offer_id' => (string) $this->offers[0]['offer_id']];
        $headers = ['Authorization' => 'Bearer '.$user->createToken('order-owner', ['*'])->plainTextToken,
            'Idempotency-Key' => $key];
        $first = $this->invoke('commerce.orders.create', $input, $headers)->assertCreated();
        DB::table('tx_internal_request')->update(['state' => 'started', 'response_json' => null, 'response_status' => null]);
        $recovered = $this->invoke('commerce.orders.create', $input, $headers)->assertCreated();
        $this->assertSame($first->json('data.trade_no'), $recovered->json('data.trade_no'));
        $this->assertSame(1, DB::table('tx_order')->where('user_id', $user->id)->count());
        $this->assertSame('completed', DB::table('tx_internal_request')->first()->state);
    }

    public function test_subscription_link_bearer_is_delivered_once_and_excluded_from_receipts_and_audit(): void
    {
        $this->service = app(ServiceCredentials::class)->issue('link-gateway', 'user', self::FINGERPRINT,
            ['subscription.links.issue'], time() + 3600);
        [$user, $subscription] = $this->subscribe();
        app(NativeCredentialSynchronization::class)->recover(100);
        $subject = DB::table('tx_access_subject')->where('subscription_id', $subscription['subscription_id'])
            ->where('status', 'active')->first();
        $this->assertNotNull($subject);
        $headers = ['Authorization' => 'Bearer '.$user->createToken('link-owner', ['*'])->plainTextToken,
            'Idempotency-Key' => (string) Str::uuid()];
        $input = ['access_subject_id' => (string) $subject->id, 'expires_at' => time() + 3600];
        $first = $this->invoke('subscription.links.issue', $input, $headers)->assertOk();
        $secret = $first->json('data.link_token');
        $this->assertMatchesRegularExpression('/\Aslt_[A-Za-z0-9_-]{43}\z/D', $secret);
        $this->assertTrue($first->json('data.token_delivered'));
        $stored = Crypt::decryptString(DB::table('tx_internal_request')->first()->response_json);
        $this->assertStringNotContainsString($secret, $stored);
        $this->assertStringNotContainsString($secret, json_encode(DB::table('tx_internal_audit')->get()));
        $this->invoke('subscription.links.issue', $input, $headers)->assertOk()
            ->assertJsonPath('data.link_token', null)->assertJsonPath('data.token_delivered', false)
            ->assertJsonPath('data.link_ref', $first->json('data.link_ref'));
    }

    public function test_wallet_and_reconciliation_preserve_the_native_money_projection(): void
    {
        $user = $this->user(['is_admin' => true]);
        $this->service = app(ServiceCredentials::class)->issue('wallet-gateway', 'user', self::FINGERPRINT,
            ['billing.wallet.get'], time() + 3600);
        $bearer = 'Bearer '.$user->createToken('wallet-owner', ['*'])->plainTextToken;
        $this->invoke('billing.wallet.get', headers: ['Authorization' => $bearer])->assertOk()
            ->assertJsonPath('data.schema', 'native-wallet/v1')->assertJsonPath('data.balances', []);
        $this->service = app(ServiceCredentials::class)->issue('reconciliation-gateway', 'admin', self::FINGERPRINT,
            ['billing.reconciliation.get'], time() + 3600);
        DB::table('tx_internal_actor_grant')->insert([
            'user_id' => $user->id, 'permission' => 'billing.reconcile', 'created_at' => time(),
        ]);
        $this->invoke('billing.reconciliation.get', ['user_id' => (string) $user->id], [
            'Authorization' => $bearer, 'X-Core-Admin-Path' => 'internal-test-admin',
        ], domain: 'admin')->assertOk()
            ->assertJsonPath('data.account_reconciliation.wallet.scope', 'selected_user_wallet_accounts')
            ->assertJsonPath('data.account_reconciliation.system.scope', 'installation_all_users_system_accounts');
    }

    public function test_empty_input_schema_is_an_object_and_registry_targets_are_current_closed_executables(): void
    {
        $response = $this->invoke('system.capabilities.get')->assertOk();
        $decoded = json_decode($response->getContent());
        $capabilities = collect($decoded->data->operations);
        $schema = $capabilities->firstWhere('id', 'system.capabilities.get')->input_schema;
        $this->assertInstanceOf(\stdClass::class, $schema->properties);
        $handlers = [];
        $commands = [];
        foreach (\App\Domains\InternalApi\OperationRegistry::all() as $operation => $spec) {
            if ($operation === 'system.capabilities.get') {
                $this->assertNull($spec['controller']);
                $this->assertNull($spec['handler']);
                $this->assertNull($spec['command']);
                $this->assertNull($spec['method']);
                continue;
            }
            if ($spec['handler'] !== null) {
                $this->assertNull($spec['controller']);
                $this->assertNull($spec['command']);
                $this->assertSame('read', $spec['mode']);
                $this->assertTrue(method_exists($spec['handler'], $spec['method']));
                $this->assertTrue((new \ReflectionMethod($spec['handler'], $spec['method']))->isPublic());
                $handlers[$operation] = [$spec['handler'], $spec['method']];
                continue;
            }
            if ($spec['command'] !== null) {
                $this->assertNull($spec['controller']);
                $this->assertSame(str_starts_with($operation, 'identity.session') ? 'authenticated' : 'user', $spec['actor']);
                $this->assertTrue((new \ReflectionMethod($spec['command'], $spec['method']))->isPublic());
                $commands[$operation] = [$spec['command'], $spec['method'], $spec['mode']];
                continue;
            }
            $this->assertNotNull($spec['controller']);
            $this->assertTrue(method_exists($spec['controller'], $spec['method']));
            $this->assertNotSame(\App\Http\Controllers\Txapi\TrafficController::class, $spec['controller']);
            $this->assertNotSame(\App\Http\Controllers\Txapi\Admin\PlanMutationController::class, $spec['controller']);
        }
        $account = \App\Domains\InternalApi\AccountReadOperations::class;
        $orders = \App\Domains\InternalApi\OrderReadOperations::class;
        $sessions = \App\Domains\InternalApi\SessionReadOperations::class;
        $usage = \App\Domains\InternalApi\UsageReadOperations::class;
        $catalog = \App\Domains\InternalApi\CatalogReadOperations::class;
        $billing = \App\Domains\InternalApi\BillingReadOperations::class;
        $this->assertSame(['identity.account.get' => [$account, 'account'], 'identity.admin.get' => [$account, 'account'],
            'identity.sessions.list' => [$sessions, 'listing'],
            'catalog.offers.list' => [$catalog, 'listing'],
            'subscription.mine.get' => [$account, 'subscription'], 'commerce.orders.list' => [$orders, 'listing'],
            'commerce.orders.get' => [$orders, 'find'],
            'billing.wallet.get' => [$billing, 'wallet'], 'billing.payment-attempts.get' => [$billing, 'attempt'],
            'billing.payment-methods.list' => [$billing, 'methods'],
            'billing.recharges.list' => [$billing, 'recharges'], 'billing.recharges.get' => [$billing, 'recharge'],
            'usage.mine.list' => [$usage, 'listing']], $handlers);
        $mutations = \App\Domains\InternalApi\SessionMutationOperations::class;
        $commerce = \App\Domains\InternalApi\CommerceMutationOperations::class;
        $this->assertSame(['identity.sessions.revoke' => [$mutations, 'revoke', 'workflow'],
            'identity.session.end' => [$mutations, 'end', 'once'],
            'commerce.orders.create' => [$commerce, 'purchase', 'domain'],
            'commerce.orders.checkout' => [$commerce, 'checkout', 'domain'],
            'commerce.orders.cancel' => [$commerce, 'cancel', 'workflow'],
            'billing.recharges.create' => [$commerce, 'recharge', 'domain'],
            'billing.recharges.checkout' => [$commerce, 'rechargeCheckout', 'domain'],
            'billing.payment-attempts.query' => [$commerce, 'queryAttempt', 'domain']], $commands);
    }

    public function test_all_boundary_rejections_are_audited_without_untrusted_service_operation_or_input_material(): void
    {
        $secret = 'never-persist-input-or-header-material';
        $this->invoke('unknown-'.$secret)->assertStatus(404);
        $this->invoke('system.capabilities.get', ['password' => $secret])->assertStatus(422);
        $this->invoke('identity.sessions.list')->assertStatus(401);
        $this->invoke('system.capabilities.get', headers: ['X-Core-Service-Id' => $secret,
            'X-Core-Service-Credential' => str_repeat('b', 64)])->assertStatus(401);
        $rows = DB::table('tx_internal_audit')->orderBy('id')->get();
        $this->assertCount(4, $rows);
        $this->assertSame(['UNKNOWN_OPERATION', 'INVALID_REQUEST', 'ACTOR_UNAUTHORIZED', 'SERVICE_UNAUTHORIZED'],
            $rows->pluck('error_code')->all());
        $this->assertSame('unauthenticated', $rows->last()->service_id);
        $this->assertStringNotContainsString($secret, json_encode($rows));
        $this->assertStringNotContainsString(str_repeat('b', 64), json_encode($rows));
    }

    public function test_admin_cached_receipt_is_not_an_authorization_cache_after_grant_revocation(): void
    {
        $this->service = app(ServiceCredentials::class)->issue('admin-replay', 'admin', self::FINGERPRINT,
            ['identity.sessions.revoke'], time() + 3600);
        $admin = $this->user(['is_admin' => true]);
        $current = $admin->createToken('current-admin', ['*'])->plainTextToken;
        $target = $admin->createToken('target-admin', ['*'])->accessToken;
        DB::table('tx_internal_actor_grant')->insert([
            'user_id' => $admin->id, 'permission' => 'identity.sessions.manage', 'created_at' => time(),
        ]);
        $headers = ['Authorization' => 'Bearer '.$current, 'X-Core-Admin-Path' => 'internal-test-admin',
            'Idempotency-Key' => (string) Str::uuid()];
        $this->invoke('identity.sessions.revoke', ['session_id' => (string) $target->id], $headers, domain: 'admin')->assertOk();
        DB::table('tx_internal_actor_grant')->delete();
        $this->invoke('identity.sessions.revoke', ['session_id' => (string) $target->id], $headers, domain: 'admin')
            ->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
        $this->assertSame((int) $admin->id, (int) DB::table('tx_internal_audit')->orderByDesc('id')->first()->actor_id);
        $this->assertSame(1, DB::table('tx_internal_request')->count());
    }

    public function test_admin_grant_does_not_replace_current_session_ability(): void
    {
        $this->service = app(ServiceCredentials::class)->issue('admin-ability', 'admin', self::FINGERPRINT,
            ['identity.sessions.list'], time() + 3600);
        $admin = $this->user(['is_admin' => true]);
        DB::table('tx_internal_actor_grant')->insert([
            'user_id' => $admin->id, 'permission' => 'identity.sessions.manage', 'created_at' => time(),
        ]);
        $this->invoke('identity.sessions.list', headers: [
            'Authorization' => 'Bearer '.$admin->createToken('limited-admin', ['identity.read'])->plainTextToken,
            'X-Core-Admin-Path' => 'internal-test-admin',
        ], domain: 'admin')->assertStatus(403);
    }

    public function test_audit_storage_failure_prevents_business_execution_and_never_returns_fake_success(): void
    {
        $request = $this->boundaryRequest();
        Schema::drop('tx_internal_audit');
        $executed = false;
        $response = app(NativeServiceIdentity::class)->handle($request, function () use (&$executed) {
            $executed = true;
            return response()->json(['data' => true]);
        });
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('AUDIT_UNAVAILABLE', $response->getData(true)['error']['code']);
        $this->assertFalse($executed);
    }

    public function test_audit_completion_loss_reports_unknown_result_instead_of_success(): void
    {
        $response = app(NativeServiceIdentity::class)->handle($this->boundaryRequest(), static function () {
            Schema::drop('tx_internal_audit');
            return response()->json(['data' => ['ok' => true]]);
        });
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('AUDIT_UNAVAILABLE', $response->getData(true)['error']['code']);
    }

    public function test_dependency_failure_is_audited_as_inconclusive_without_exception_material(): void
    {
        $response = app(NativeServiceIdentity::class)->handle($this->boundaryRequest(), static function () {
            throw new \RuntimeException('PRIVATE_SECRET_EXCEPTION_MATERIAL');
        });
        $this->assertSame(503, $response->getStatusCode());
        $this->assertStringNotContainsString('PRIVATE_SECRET_EXCEPTION_MATERIAL', $response->getContent());
        $audit = DB::table('tx_internal_audit')->first();
        $this->assertSame('inconclusive', $audit->state);
        $this->assertSame('DEPENDENCY_UNAVAILABLE', $audit->error_code);
        $this->assertStringNotContainsString('PRIVATE_SECRET_EXCEPTION_MATERIAL', json_encode((array) $audit));
    }

    public function test_grant_ownership_rejects_orphans_and_protects_account_evidence(): void
    {
        try {
            DB::table('tx_internal_actor_grant')->insert([
                'user_id' => 2147483647, 'permission' => 'identity.read', 'created_at' => time(),
            ]);
            $this->fail('Orphan grant was accepted');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
        $admin = $this->user(['is_admin' => true]);
        DB::table('tx_internal_actor_grant')->insert([
            'user_id' => $admin->id, 'permission' => 'identity.read', 'created_at' => time(),
        ]);
        try {
            DB::table('tx_user')->where('id', $admin->id)->delete();
            $this->fail('Account with authorization evidence was deleted');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_populated_internal_migrations_refuse_destructive_rollback(): void
    {
        foreach (['2026_10_16_000011_create_native_internal_services.php', '2026_10_16_000012_create_native_internal_requests.php'] as $file) {
            $this->invoke('system.capabilities.get')->assertOk();
            try {
                (require database_path('migrations/'.$file))->down();
                $this->fail('Populated rollback was accepted');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('POPULATED_ROLLBACK_REFUSED', $exception->getMessage());
            }
        }
    }

    private function boundaryRequest(): Request
    {
        $request = Request::create('https://localhost'.self::BASE.'user/operations/system.capabilities.get', 'POST', server: [
            'REMOTE_ADDR' => '127.0.0.1', 'HTTPS' => 'on',
            'HTTP_X_CORE_SERVICE_ID' => $this->service['service_id'],
            'HTTP_X_CORE_SERVICE_CREDENTIAL' => $this->service['credential'],
            'HTTP_X_CORE_SERVICE_EPOCH' => $this->service['epoch'],
            'HTTP_X_CORE_TLS_VERIFIED' => 'SUCCESS',
            'HTTP_X_CORE_TLS_CERTIFICATE_SHA256' => self::FINGERPRINT,
        ]);
        $route = new Route('POST', 'internal/{domain}/operations/{operation}', static fn () => null);
        $route->bind(Request::create('/internal/user/operations/system.capabilities.get', 'POST'));
        $request->setRouteResolver(static fn () => $route);
        return $request;
    }

    private function unfinishedReceipt(User $actor, string $operation, string $key, array $input): void
    {
        DB::table('tx_internal_request')->insert([
            'key_hash' => hash('sha256', 'native-internal/v1:'.$actor->id.':'.$operation.':'.$key),
            'input_hash' => OrderIdempotency::requestHash($input), 'operation' => $operation,
            'actor_id' => $actor->id, 'state' => 'started', 'created_at' => time(), 'updated_at' => time(),
        ]);
    }

    private function invoke(string $operation, array $input = [], array $headers = [], string $domain = 'user',
        array $server = [], array $envelope = [])
    {
        $headers = array_replace([
            'X-Core-Service-Id' => $this->service['service_id'],
            'X-Core-Service-Credential' => $this->service['credential'],
            'X-Core-Service-Epoch' => $this->service['epoch'],
            'X-Core-Tls-Verified' => 'SUCCESS', 'X-Core-Tls-Certificate-Sha256' => self::FINGERPRINT,
        ], $headers);
        $server = array_replace(['REMOTE_ADDR' => '127.0.0.1', 'HTTPS' => 'on'], $server);
        // Symfony derives HTTPS from the URI scheme, overriding the server bag.
        $scheme = in_array(strtolower((string) $server['HTTPS']), ['on', '1'], true) ? 'https' : 'http';
        return $this->withServerVariables($server)
            ->postJson($scheme.'://localhost'.self::BASE.$domain.'/operations/'.$operation, array_replace([
                'contract_version' => '1', 'request_id' => (string) Str::uuid(),
                'deadline_at' => time() + 15, 'input' => $input === [] ? (object) [] : $input,
            ], $envelope), $headers);
    }

    private function user(array $overrides = []): User
    {
        return User::withoutEvents(fn () => User::query()->create(array_replace([
            'email' => Str::uuid().'@example.test', 'password' => Hash::make('InternalPassword2026'),
            'token' => Str::random(32), 'uuid' => (string) Str::uuid(),
            'is_admin' => false, 'banned' => false,
        ], $overrides)));
    }
}
