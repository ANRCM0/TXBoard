<?php

namespace Tests\Feature\Txapi;

use App\Domains\Identity\Contracts\AccountIdentity;
use App\Domains\Identity\Contracts\AccountQueries;
use App\Domains\Identity\NativeAccountQueries;
use App\Domains\InternalApi\ServiceCredentials;
use App\Http\Controllers\Txapi\AccountController;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery;
use Tests\Support\NativeRuntimeTestCase;

final class AccountReadBoundaryTest extends NativeRuntimeTestCase
{
    private const FINGERPRINT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private array $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->activate();
        config(['internal_api.enabled' => true, 'internal_api.trusted_tls_proxies' => ['127.0.0.1']]);
        admin_setting(['secure_path' => 'account-read-admin', 'captcha_enable' => 0]);
        $this->service = app(ServiceCredentials::class)->issue('account-gateway', 'user', self::FINGERPRINT,
            ['identity.account.get', 'subscription.mine.get'], time() + 3600);
    }

    public function test_scoped_native_queries_keep_real_http_and_internal_entitlements_consistent_without_actor_caching(): void
    {
        $queries = app(AccountQueries::class);
        $this->assertInstanceOf(NativeAccountQueries::class, $queries);
        $this->assertSame($queries, app(AccountQueries::class));
        [$subscribed] = $this->subscribe();
        // Earlier reduced-schema fixtures can leave Eloquent's guardable-column cache populated.
        $subscribed->forceFill(['telegram_id' => 123456789])->saveOrFail();
        $this->assertSame(123456789, (int) $subscribed->fresh()->telegram_id);
        $other = $this->user();
        $tokens = [
            $subscribed->id => $subscribed->createToken('subscriber', ['*'])->plainTextToken,
            $other->id => $other->createToken('other-account', ['*'])->plainTextToken,
        ];
        $httpAccount = $this->http('/txapi/me', $tokens[$subscribed->id])->assertOk()->json('data');
        $httpSubscription = $this->http('/txapi/me/subscription', $tokens[$subscribed->id])->assertOk()
            ->assertJsonPath('data.delivery_endpoint', '/txapi/me/subscription-deliveries')->json('data');
        unset($httpSubscription['delivery_endpoint']);
        $internalAccount = $this->invoke('identity.account.get', $tokens[$subscribed->id])->assertOk();
        $internalSubscription = $this->invoke('subscription.mine.get', $tokens[$subscribed->id])->assertOk();
        $this->assertSame($this->decimalIdentifiers($httpAccount), $internalAccount->json('data'));
        $this->assertSame($this->decimalIdentifiers($httpSubscription), $internalSubscription->json('data'));
        $this->assertTrue($internalSubscription->json('data.authorized'));
        $this->assertArrayNotHasKey('delivery_endpoint', $internalSubscription->json('data'));
        $this->assertSame('123456789', $internalAccount->json('data.telegram_id'));
        foreach ([$subscribed, $other, $subscribed, $other] as $actor) {
            $token = $tokens[$actor->id];
            $this->invoke('identity.account.get', $token)->assertOk()
                ->assertJsonPath('data.id', (string) $actor->id)->assertJsonPath('data.email', $actor->email);
            $this->invoke('subscription.mine.get', $token)->assertOk()
                ->assertJsonPath('data.user_id', (string) $actor->id)
                ->assertJsonPath('data.authorized', $actor->id === $subscribed->id);
        }
        $this->assertSame(0, DB::table('tx_internal_request')->count());
        // Scope cleanup also removes the fixture's Provider binding; do it after all I/O consumers.
        $this->app->forgetScopedInstances();
        $this->assertNotSame($queries, app(AccountQueries::class));
    }

    public function test_both_entry_points_use_the_public_port_and_internal_reads_do_not_resolve_http_controllers(): void
    {
        $user = $this->user();
        $user->forceFill(['telegram_id' => 912345678])->saveOrFail();
        $this->assertSame(912345678, (int) $user->fresh()->telegram_id);
        $token = $user->createToken('port-owner', ['*'])->plainTextToken;
        $account = ['id' => (int) $user->id, 'email' => $user->email, 'telegram_id' => 912345678,
            'subscription' => ['schema' => 'port-test', 'authorized' => false, 'user_id' => (int) $user->id],
            'credential_status' => 'provider_managed'];
        $subscription = ['schema' => 'core-entitlement/v1', 'authorized' => false, 'reason' => 'ENTITLEMENT_SLOT_EMPTY', 'user_id' => (int) $user->id,
            'credential_status' => 'provider_managed', 'delivery_formats' => ['port-format']];
        $queries = Mockery::mock(AccountQueries::class);
        $queries->shouldReceive('account')->twice()->with(Mockery::on(static fn ($identity): bool =>
            $identity instanceof AccountIdentity && $identity->userId === (int) $user->id
            && $identity->email === $user->email && $identity->telegramId === 912345678))->andReturn($account);
        $queries->shouldReceive('subscription')->twice()->with((int) $user->id)->andReturn($subscription);
        $this->app->instance(AccountQueries::class, $queries);
        $this->assertSame($account, $this->http('/txapi/me?user_id=999999&authorized=true&at=1', $token)
            ->assertOk()->json('data'));
        $this->http('/txapi/me/subscription?user_id=999999&authorized=true&at=1', $token)->assertOk()
            ->assertJsonPath('data.schema', 'core-entitlement/v1')
            ->assertJsonPath('data.delivery_endpoint', '/txapi/me/subscription-deliveries');
        $this->app->bind(AccountController::class, static function () {
            throw new \LogicException('INTERNAL_READ_MUST_NOT_RESOLVE_ACCOUNT_CONTROLLER');
        });
        $this->invoke('identity.account.get', $token)->assertOk()
            ->assertJsonPath('data', $this->decimalIdentifiers($account));
        $response = $this->invoke('subscription.mine.get', $token)->assertOk()
            ->assertJsonPath('data', $this->decimalIdentifiers($subscription));
        $this->assertArrayNotHasKey('delivery_endpoint', $response->json('data'));
        $this->assertSame(0, DB::table('tx_internal_request')->count());
    }

    public function test_service_and_actor_authentication_and_closed_input_rejections_never_execute_queries(): void
    {
        $queries = Mockery::mock(AccountQueries::class);
        $queries->shouldNotReceive('account');
        $queries->shouldNotReceive('subscription');
        $this->app->instance(AccountQueries::class, $queries);
        $user = $this->user();
        $token = $user->createToken('rejection-owner', ['*'])->plainTextToken;
        foreach (['identity.account.get', 'subscription.mine.get'] as $operation) {
            foreach (['user_id' => (string) $user->id, 'authorized' => true, 'at' => time(),
                'handler' => 'AccountReadOperations', 'class' => AccountController::class] as $field => $value) {
                $this->invoke($operation, $token, [$field => $value])->assertStatus(422)
                    ->assertJsonPath('error.code', 'INVALID_REQUEST');
            }
            $this->invoke($operation)->assertStatus(401)->assertJsonPath('error.code', 'ACTOR_UNAUTHORIZED');
        }
        $this->invoke('identity.account.get', $token, headers: [
            'X-Core-Tls-Certificate-Sha256' => str_repeat('b', 64),
        ])->assertStatus(401)->assertJsonPath('error.code', 'SERVICE_UNAUTHORIZED');
        $this->invoke('identity.account.get', $token, headers: ['X-Core-Service-Epoch' => '999'])
            ->assertStatus(401)->assertJsonPath('error.code', 'SERVICE_UNAUTHORIZED');
        $this->invoke('identity.account.get', $token, domain: 'admin')->assertStatus(401)
            ->assertJsonPath('error.code', 'SERVICE_UNAUTHORIZED');
        $original = $this->service;
        $this->service = app(ServiceCredentials::class)->issue('limited-gateway', 'user', self::FINGERPRINT,
            ['system.capabilities.get'], time() + 3600);
        $this->invoke('identity.account.get', $token)->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
        $this->service = $original;
        app(ServiceCredentials::class)->rotate('account-gateway', self::FINGERPRINT, time() + 3600);
        $this->invoke('identity.account.get', $token)->assertStatus(401);
        $this->service = app(ServiceCredentials::class)->rotate('account-gateway', self::FINGERPRINT, time() + 3600);
        app(ServiceCredentials::class)->revoke('account-gateway');
        $this->invoke('identity.account.get', $token)->assertStatus(401);
        $this->service = app(ServiceCredentials::class)->issue('actor-gateway', 'user', self::FINGERPRINT,
            ['identity.account.get', 'subscription.mine.get'], time() + 3600);
        $user->update(['banned' => true]);
        $this->invoke('identity.account.get', $token)->assertStatus(403);
        $user->update(['banned' => false]);
        $user->tokens()->delete();
        $this->invoke('subscription.mine.get', $token)->assertStatus(401);
        $this->assertSame(0, DB::table('tx_internal_request')->count());
    }

    public function test_admin_reads_recheck_live_path_role_token_ability_and_permission_grants_before_queries(): void
    {
        $this->service = app(ServiceCredentials::class)->issue('admin-account-gateway', 'admin', self::FINGERPRINT,
            ['identity.admin.get'], time() + 3600);
        $admin = $this->user(['is_admin' => true]);
        $token = $admin->createToken('admin-account', ['identity.read'])->plainTextToken;
        $limited = $admin->createToken('wrong-ability', ['identity.sessions.manage'])->plainTextToken;
        $headers = ['X-Core-Admin-Path' => 'account-read-admin'];
        $queries = Mockery::mock(AccountQueries::class);
        $queries->shouldReceive('account')->once()->with(Mockery::on(static fn ($identity): bool =>
            $identity instanceof AccountIdentity && $identity->userId === (int) $admin->id))
            ->andReturn(['id' => (int) $admin->id, 'email' => $admin->email, 'telegram_id' => 1,
                'subscription' => ['authorized' => true], 'credential_status' => 'provider_managed']);
        $queries->shouldNotReceive('subscription');
        $this->app->instance(AccountQueries::class, $queries);
        $this->invoke('identity.admin.get', $token, headers: $headers, domain: 'admin')->assertStatus(403);
        DB::table('tx_internal_actor_grant')->insert([
            'user_id' => $admin->id, 'permission' => 'identity.read', 'created_at' => time(),
        ]);
        $this->invoke('identity.admin.get', $token, headers: ['X-Core-Admin-Path' => 'stale'], domain: 'admin')
            ->assertStatus(404);
        $this->invoke('identity.admin.get', $limited, headers: $headers, domain: 'admin')->assertStatus(403);
        $response = $this->invoke('identity.admin.get', $token, headers: $headers, domain: 'admin')->assertOk();
        $this->assertSame(['id' => (string) $admin->id, 'email' => $admin->email], $response->json('data'));
        DB::table('tx_internal_actor_grant')->where('user_id', $admin->id)->delete();
        $this->invoke('identity.admin.get', $token, headers: $headers, domain: 'admin')->assertStatus(403);
        DB::table('tx_internal_actor_grant')->insert([
            'user_id' => $admin->id, 'permission' => 'identity.read', 'created_at' => time(),
        ]);
        $admin->update(['is_admin' => false]);
        $this->invoke('identity.admin.get', $token, headers: $headers, domain: 'admin')->assertStatus(403);
        $admin->update(['is_admin' => true, 'banned' => true]);
        $this->invoke('identity.admin.get', $token, headers: $headers, domain: 'admin')->assertStatus(403);
        $this->assertSame(0, DB::table('tx_internal_request')->count());
    }

    public function test_fixed_read_handlers_preserve_projection_nested_redaction_decimal_types_and_response_limits(): void
    {
        $user = $this->user();
        $token = $user->createToken('projection-owner', ['*'])->plainTextToken;
        $secret = 'DO_NOT_DISCLOSE_ACCOUNT_READ_SECRET';
        $subscription = ['schema' => 'test', 'authorized' => true, 'user_id' => (int) $user->id,
            'subscription_id' => 71, 'quota_bytes' => 1000, 'consumed_bytes' => 17,
            'delivery_formats' => ['test-format'], 'delivery_endpoint' => '/not-internal',
            'password' => $secret, 'unexpected' => $secret,
            'credential_status' => ['state' => 'managed', 'token' => $secret,
                'nested' => ['private_key' => $secret, 'authorization' => $secret, 'count' => 2]]];
        $phase = 'projection';
        $queries = Mockery::mock(AccountQueries::class);
        $queries->shouldReceive('account')->twice()->andReturnUsing(function () use ($user, $secret, $subscription, &$phase): array {
            return ['id' => (int) $user->id, 'email' => $phase === 'limit' ? str_repeat('x', 2048) : $user->email,
                'telegram_id' => null, 'subscription' => $subscription, 'password' => $secret, 'unexpected' => $secret];
        });
        $queries->shouldReceive('subscription')->once()->with((int) $user->id)->andReturn($subscription);
        $this->app->instance(AccountQueries::class, $queries);
        $account = $this->invoke('identity.account.get', $token)->assertOk();
        $standalone = $this->invoke('subscription.mine.get', $token)->assertStatus(503)
            ->assertJsonPath('error.code', 'DEPENDENCY_UNAVAILABLE');
        $expected = ['schema' => 'test', 'authorized' => true, 'user_id' => (string) $user->id,
            'subscription_id' => '71', 'quota_bytes' => '1000', 'consumed_bytes' => '17',
            'delivery_formats' => ['test-format'],
            'credential_status' => ['state' => 'managed', 'nested' => ['count' => 2]]];
        $this->assertSame(['id' => (string) $user->id, 'email' => $user->email, 'telegram_id' => null,
            'subscription' => $expected], $account->json('data'));
        // The standalone operation now rejects a malformed nested contract instead of passing it through.
        $this->assertNull($standalone->json('data'));
        foreach ([$account, $standalone] as $response) {
            $this->assertStringNotContainsString($secret, $response->getContent());
            $this->assertTrue($response->headers->hasCacheControlDirective('private'));
            $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        }
        $phase = 'limit';
        config(['internal_api.max_response_bytes' => 1024]);
        $this->invoke('identity.account.get', $token)->assertStatus(503)
            ->assertJsonPath('error.code', 'DEPENDENCY_UNAVAILABLE');
        $this->assertSame(0, DB::table('tx_internal_request')->count());
        $this->assertStringNotContainsString($secret, json_encode(DB::table('tx_internal_audit')->get()));
    }

    public function test_query_dependency_failures_are_normalized_without_secret_details_or_write_receipts(): void
    {
        $user = $this->user();
        $token = $user->createToken('dependency-owner', ['*'])->plainTextToken;
        $secret = 'PRIVATE_ACCOUNT_QUERY_EXCEPTION';
        $queries = Mockery::mock(AccountQueries::class);
        $queries->shouldReceive('account')->twice()->with(Mockery::on(static fn ($identity): bool =>
            $identity instanceof AccountIdentity && $identity->userId === (int) $user->id))
            ->andThrow(new \RuntimeException($secret));
        $queries->shouldReceive('subscription')->once()->with((int) $user->id)->andThrow(new \RuntimeException($secret));
        $this->app->instance(AccountQueries::class, $queries);
        foreach (['identity.account.get', 'subscription.mine.get'] as $operation) {
            $response = $this->invoke($operation, $token)->assertStatus(503)
                ->assertJsonPath('error.code', 'DEPENDENCY_UNAVAILABLE');
            $this->assertStringNotContainsString($secret, $response->getContent());
            $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        }
        $user->update(['is_admin' => true]);
        DB::table('tx_internal_actor_grant')->insert([
            'user_id' => $user->id, 'permission' => 'identity.read', 'created_at' => time(),
        ]);
        $this->service = app(ServiceCredentials::class)->issue('admin-dependency-gateway', 'admin', self::FINGERPRINT,
            ['identity.admin.get'], time() + 3600);
        $adminResponse = $this->invoke('identity.admin.get', $token,
            headers: ['X-Core-Admin-Path' => 'account-read-admin'], domain: 'admin')->assertStatus(503)
            ->assertJsonPath('error.code', 'DEPENDENCY_UNAVAILABLE');
        $this->assertStringNotContainsString($secret, $adminResponse->getContent());
        $this->assertTrue($adminResponse->headers->hasCacheControlDirective('no-store'));
        $this->assertSame(0, DB::table('tx_internal_request')->count());
        $rows = DB::table('tx_internal_audit')->orderBy('id')->get();
        $this->assertCount(3, $rows);
        $this->assertSame(array_fill(0, 3, 'DEPENDENCY_UNAVAILABLE'), $rows->pluck('error_code')->all());
        $this->assertStringNotContainsString($secret, json_encode($rows));
        $this->assertStringNotContainsString($token, json_encode($rows));
    }

    private function invoke(string $operation, ?string $token = null, array $input = [], array $headers = [], string $domain = 'user')
    {
        $headers = array_replace([
            'X-Core-Service-Id' => $this->service['service_id'], 'X-Core-Service-Credential' => $this->service['credential'],
            'X-Core-Service-Epoch' => $this->service['epoch'], 'X-Core-Tls-Verified' => 'SUCCESS',
            'X-Core-Tls-Certificate-Sha256' => self::FINGERPRINT,
            'Authorization' => $token === null ? '' : 'Bearer '.$token,
        ], $headers);
        return $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTPS' => 'on'])
            ->postJson('https://localhost/txapi/internal/native/v1/'.$domain.'/operations/'.$operation, [
                'contract_version' => '1', 'request_id' => (string) Str::uuid(), 'deadline_at' => time() + 15,
                'input' => $input === [] ? (object) [] : $input,
            ], $headers);
    }

    private function http(string $path, string $token)
    {
        Auth::forgetGuards();
        return $this->getJson($path, ['Authorization' => 'Bearer '.$token]);
    }

    private function user(array $overrides = []): User
    {
        return User::withoutEvents(fn () => User::query()->create(array_replace([
            'email' => Str::uuid().'@example.test', 'password' => Hash::make('AccountRead2026'),
            'token' => Str::random(32), 'uuid' => (string) Str::uuid(), 'is_admin' => false, 'banned' => false,
        ], $overrides)));
    }

    private function decimalIdentifiers(array $data): array
    {
        foreach ($data as $key => &$value) {
            if (is_array($value)) $value = $this->decimalIdentifiers($value);
            elseif (is_int($value) && is_string($key)
                && ($key === 'id' || preg_match('/(?:_id|_bytes|_minor)\z/D', $key))) $value = (string) $value;
        }
        unset($value);
        return $data;
    }
}
