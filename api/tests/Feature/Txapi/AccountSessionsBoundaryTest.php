<?php

namespace Tests\Feature\Txapi;

use App\Domains\Billing\OrderIdempotency;
use App\Domains\Identity\Contracts\AccountSessions;
use App\Domains\Identity\NativeAccountSessions;
use App\Domains\InternalApi\ServiceCredentials;
use App\Http\Controllers\Txapi\AuthController;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery;
use Tests\Support\NativeRuntimeTestCase;

final class AccountSessionsBoundaryTest extends NativeRuntimeTestCase
{
    private const FINGERPRINT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const OPERATIONS = ['identity.sessions.list', 'identity.sessions.revoke', 'identity.session.end'];
    private array $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->activate();
        config(['internal_api.enabled' => true, 'internal_api.trusted_tls_proxies' => ['127.0.0.1']]);
        admin_setting(['secure_path' => 'sessions-admin', 'captcha_enable' => 0]);
        $this->service = app(ServiceCredentials::class)->issue('sessions-gateway', 'user', self::FINGERPRINT,
            self::OPERATIONS, time() + 3600);
    }

    public function test_native_listing_uses_actual_alternating_bearer_owners_and_preserves_projection(): void
    {
        $port = app(AccountSessions::class);
        $this->assertInstanceOf(NativeAccountSessions::class, $port);
        $this->assertSame($port, app(AccountSessions::class));
        $actors = [$this->newUser(), $this->newUser()];
        $tokens = [];
        foreach ($actors as $actor) {
            $tokens[$actor->id] = $actor->createToken('actor-'.$actor->id, ['*'])->plainTextToken;
            $actor->createToken('extra-'.$actor->id, ['identity.read']);
        }
        foreach ([$actors[0], $actors[1], $actors[0], $actors[1]] as $actor) {
            $http = $this->http('GET', '/txapi/auth/sessions', $tokens[$actor->id])->assertOk()->json('data');
            $internal = $this->invoke('identity.sessions.list', $tokens[$actor->id])->assertOk();
            // Last-used timestamps are touched by authentication, so compare stable projection separately.
            $this->assertCount(2, $http);
            $this->assertSame(array_map('strval', array_column($http, 'id')), array_column($internal->json('data'), 'id'));
            $this->assertSame(array_column($http, 'name'), array_column($internal->json('data'), 'name'));
            $this->assertGreaterThan($http[1]['id'], $http[0]['id']);
            foreach ($http as $row) {
                $this->assertSame(['id', 'name', 'abilities', 'last_used_at', 'created_at', 'updated_at', 'expires_at'], array_keys($row));
                $this->assertIsInt($row['id']);
                $this->assertIsArray($row['abilities']);
                $this->assertNotNull($row['created_at']);
                $this->assertNull($row['expires_at']);
                $this->assertStringContainsString('-'.$actor->id, $row['name']);
            }
            $this->assertCount(1, $port->listing((int) $actor->id, 1));
        }
        $this->assertSame(0, DB::table('tx_internal_request')->count());
        $this->app->forgetScopedInstances();
        $this->assertNotSame($port, app(AccountSessions::class));
    }

    public function test_http_and_internal_share_replacement_port_and_internal_never_resolves_auth_controller(): void
    {
        $actor = $this->newUser();
        $current = $actor->createToken('actual-current', ['*']);
        $port = Mockery::mock(AccountSessions::class);
        $port->shouldReceive('listing')->twice()->withArgs(static fn ($id, $limit = null): bool => $id === (int) $actor->id && $limit === null)
            ->andReturn([['id' => 71, 'name' => 'port-session', 'abilities' => ['*']]]);
        $port->shouldReceive('revoke')->twice()->with((int) $actor->id, 72)->andReturn(true);
        $port->shouldReceive('end')->twice()->with((int) $actor->id, (int) $current->accessToken->id);
        $this->app->instance(AccountSessions::class, $port);
        $this->http('GET', '/txapi/auth/sessions?user_id=999999&limit=1', $current->plainTextToken)
            ->assertOk()->assertJsonPath('data.0.id', 71);
        $this->http('DELETE', '/txapi/auth/sessions/72', $current->plainTextToken)->assertOk();
        $this->http('POST', '/txapi/auth/logout', $current->plainTextToken,
            ['user_id' => '999999', 'session_id' => '999999'])->assertOk();
        $this->app->bind(AuthController::class, static function () {
            throw new \LogicException('INTERNAL_MUST_NOT_RESOLVE_AUTH_CONTROLLER');
        });
        $this->invoke('identity.sessions.list', $current->plainTextToken)->assertOk()->assertJsonPath('data.0.id', '71');
        $this->invoke('identity.sessions.revoke', $current->plainTextToken, ['session_id' => '72'])->assertOk();
        $this->invoke('identity.session.end', $current->plainTextToken)->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame(1, DB::table('tx_internal_request')->count());
    }

    public function test_native_owned_missing_cross_owner_and_current_token_deletion_are_identical_at_both_entries(): void
    {
        $actor = $this->newUser();
        $current = $actor->createToken('current', ['*']);
        $other = $this->newUser()->createToken('other-owner', ['*']);
        $target = $actor->createToken('target', ['*']);
        $internalTarget = $actor->createToken('internal-target', ['*']);
        $this->http('DELETE', '/txapi/auth/sessions/0', $current->plainTextToken)->assertNotFound();
        $this->invoke('identity.sessions.revoke', $current->plainTextToken,
            ['session_id' => (string) $internalTarget->accessToken->id])->assertOk()->assertJsonPath('data.ok', true);
        $this->assertNull($internalTarget->accessToken->fresh());
        foreach ([(string) $other->accessToken->id, '999999999'] as $id) {
            $this->http('DELETE', '/txapi/auth/sessions/'.$id, $current->plainTextToken)->assertNotFound();
            $this->invoke('identity.sessions.revoke', $current->plainTextToken, ['session_id' => $id])->assertNotFound();
        }
        $this->http('DELETE', '/txapi/auth/sessions/'.$target->accessToken->id, $current->plainTextToken)
            ->assertOk()->assertJsonPath('data.ok', true);
        $this->assertNull($target->accessToken->fresh());
        $port = app(AccountSessions::class);
        $port->end((int) $actor->id, null);
        $port->end((int) $actor->id, 999999999);
        $port->end((int) $actor->id, (int) $other->accessToken->id);
        $this->assertNotNull($other->accessToken->fresh());
        $this->assertNotNull($current->accessToken->fresh());
        $this->invoke('identity.session.end', $current->plainTextToken)->assertOk();
        $this->assertNull($current->accessToken->fresh());
        $this->invoke('identity.sessions.list', $current->plainTextToken)->assertUnauthorized();
        $this->assertNotNull($other->accessToken->fresh());
    }

    public function test_http_logout_ignores_supplied_target_and_self_revoke_receipt_cannot_restore_authentication(): void
    {
        $actor = $this->newUser();
        $current = $actor->createToken('http-current', ['*']);
        $spare = $actor->createToken('same-owner-spare', ['*']);
        $other = $this->newUser()->createToken('other-owner', ['*']);
        $this->http('POST', '/txapi/auth/logout', $current->plainTextToken,
            ['session_id' => (string) $other->accessToken->id, 'current_token_id' => (string) $spare->accessToken->id])
            ->assertOk()->assertJsonPath('data.ok', true);
        $this->assertNull($current->accessToken->fresh());
        $this->assertNotNull($spare->accessToken->fresh());
        $this->assertNotNull($other->accessToken->fresh());
        $this->http('GET', '/txapi/auth/sessions', $current->plainTextToken)->assertUnauthorized();
        $input = ['session_id' => (string) $spare->accessToken->id];
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $this->invoke('identity.sessions.revoke', $spare->plainTextToken, $input, $headers)
            ->assertOk()->assertJsonPath('data.ok', true);
        $this->assertNull($spare->accessToken->fresh());
        $this->assertSame('completed', DB::table('tx_internal_request')->first()->state);
        $this->invoke('identity.sessions.revoke', $spare->plainTextToken, $input, $headers)->assertUnauthorized();
        $this->assertSame(1, DB::table('tx_internal_request')->count());
        $this->assertNotNull($other->accessToken->fresh());
    }

    public function test_closed_input_service_scope_and_missing_or_revoked_actor_never_reach_port(): void
    {
        $actor = $this->newUser();
        $token = $actor->createToken('reject', ['*']);
        $port = Mockery::mock(AccountSessions::class);
        $port->shouldNotReceive('listing');
        $port->shouldNotReceive('revoke');
        $port->shouldNotReceive('end');
        $this->app->instance(AccountSessions::class, $port);
        foreach (self::OPERATIONS as $operation) {
            $base = $operation === 'identity.sessions.revoke' ? ['session_id' => '42'] : [];
            foreach (['user_id' => '1', 'current_token_id' => '2', 'limit' => 1, 'handler' => AuthController::class] as $key => $value) {
                $this->invoke($operation, $token->plainTextToken, $base + [$key => $value])->assertStatus(422);
            }
            $this->invoke($operation, null, $base)->assertUnauthorized();
        }
        $this->invoke('identity.sessions.list', $token->plainTextToken,
            headers: ['X-Core-Tls-Certificate-Sha256' => str_repeat('b', 64)])->assertUnauthorized();
        $this->invoke('identity.sessions.list', $token->plainTextToken, domain: 'admin')->assertUnauthorized();
        $this->service = app(ServiceCredentials::class)->issue('limited-sessions', 'user', self::FINGERPRINT,
            ['system.capabilities.get'], time() + 3600);
        $this->invoke('identity.sessions.list', $token->plainTextToken)->assertForbidden();
        $this->service = app(ServiceCredentials::class)->issue('fresh-sessions', 'user', self::FINGERPRINT,
            self::OPERATIONS, time() + 3600);
        $actor->forceFill(['banned' => true])->saveOrFail();
        $this->invoke('identity.sessions.list', $token->plainTextToken)->assertForbidden();
        $actor->forceFill(['banned' => false])->saveOrFail();
        $token->accessToken->delete();
        $this->invoke('identity.sessions.list', $token->plainTextToken)->assertUnauthorized();
        $this->assertSame(0, DB::table('tx_internal_request')->count());
    }

    public function test_revoke_workflow_replays_conflicts_and_started_outcomes_without_reexecution_and_rechecks_auth(): void
    {
        $actor = $this->newUser();
        $token = $actor->createToken('workflow', ['*']);
        $port = Mockery::mock(AccountSessions::class);
        $port->shouldReceive('revoke')->once()->with((int) $actor->id, 71)->andReturn(true);
        $this->app->instance(AccountSessions::class, $port);
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $input = ['session_id' => '71'];
        $first = $this->invoke('identity.sessions.revoke', $token->plainTextToken, $input, $headers)->assertOk();
        $replay = $this->invoke('identity.sessions.revoke', $token->plainTextToken, $input, $headers)->assertOk();
        $this->assertSame($first->json('data'), $replay->json('data'));
        $this->invoke('identity.sessions.revoke', $token->plainTextToken, ['session_id' => '72'], $headers)
            ->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        $key = (string) Str::uuid();
        DB::table('tx_internal_request')->insert([
            'key_hash' => hash('sha256', 'native-internal/v1:'.$actor->id.':identity.sessions.revoke:'.$key),
            'input_hash' => OrderIdempotency::requestHash($input), 'operation' => 'identity.sessions.revoke',
            'actor_id' => $actor->id, 'state' => 'started', 'created_at' => time(), 'updated_at' => time(),
        ]);
        $this->invoke('identity.sessions.revoke', $token->plainTextToken, $input, ['Idempotency-Key' => $key])
            ->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_IN_PROGRESS');
        $token->accessToken->delete();
        $this->invoke('identity.sessions.revoke', $token->plainTextToken, $input, $headers)->assertUnauthorized();
        $this->assertSame(2, DB::table('tx_internal_request')->count());
    }

    public function test_admin_path_role_ability_and_current_grants_are_checked_even_before_receipt_replay(): void
    {
        $this->service = app(ServiceCredentials::class)->issue('admin-sessions', 'admin', self::FINGERPRINT,
            self::OPERATIONS, time() + 3600);
        $admin = $this->newUser(['is_admin' => true]);
        $current = $admin->createToken('admin-current', ['identity.sessions.manage'])->plainTextToken;
        $limited = $admin->createToken('limited', ['identity.read'])->plainTextToken;
        $headers = ['X-Core-Admin-Path' => 'sessions-admin', 'Idempotency-Key' => (string) Str::uuid()];
        $port = Mockery::mock(AccountSessions::class);
        $port->shouldReceive('revoke')->once()->with((int) $admin->id, 71)->andReturn(true);
        $this->app->instance(AccountSessions::class, $port);
        $call = fn ($token, $h = []) => $this->invoke('identity.sessions.revoke', $token,
            ['session_id' => '71'], array_replace($headers, $h), 'admin');
        $call($current)->assertForbidden();
        DB::table('tx_internal_actor_grant')->insert(['user_id' => $admin->id,
            'permission' => 'identity.sessions.manage', 'created_at' => time()]);
        $call($current, ['X-Core-Admin-Path' => 'stale'])->assertNotFound();
        $call($limited)->assertForbidden();
        $call($current)->assertOk();
        DB::table('tx_internal_actor_grant')->where('user_id', $admin->id)->delete();
        $call($current)->assertForbidden();
        DB::table('tx_internal_actor_grant')->insert(['user_id' => $admin->id,
            'permission' => 'identity.sessions.manage', 'created_at' => time()]);
        $admin->forceFill(['is_admin' => false])->saveOrFail();
        $call($current)->assertForbidden();
        $this->assertSame(1, DB::table('tx_internal_request')->count());
    }

    public function test_session_projection_redacts_nested_secrets_bounds_results_and_sanitizes_dependency_failure(): void
    {
        $actor = $this->newUser();
        $token = $actor->createToken('projection', ['*'])->plainTextToken;
        $secret = 'SESSION_PRIVATE_SECRET';
        $port = Mockery::mock(AccountSessions::class);
        $port->shouldReceive('listing')->once()->withArgs(static fn ($id, $limit = null): bool => $id === (int) $actor->id && $limit === null)->andReturn([
            ['id' => 71, 'name' => 'safe', 'abilities' => ['nested' => ['token' => $secret, 'safe' => true]],
                'token' => $secret, 'unexpected' => $secret],
        ]);
        $port->shouldReceive('listing')->once()->withArgs(static fn ($id, $limit = null): bool => $id === (int) $actor->id && $limit === null)
            ->andReturn([['id' => 71, 'name' => str_repeat('x', 2048)]]);
        $port->shouldReceive('listing')->once()->withArgs(static fn ($id, $limit = null): bool => $id === (int) $actor->id && $limit === null)->andThrow(new \RuntimeException($secret));
        $port->shouldReceive('revoke')->once()->with((int) $actor->id, 71)->andThrow(new \RuntimeException($secret));
        $this->app->instance(AccountSessions::class, $port);
        $response = $this->invoke('identity.sessions.list', $token)->assertOk();
        $this->assertSame([['id' => '71', 'name' => 'safe', 'abilities' => ['nested' => ['safe' => true]]]], $response->json('data'));
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        config(['internal_api.max_response_bytes' => 1024]);
        $this->invoke('identity.sessions.list', $token)->assertStatus(503)->assertJsonPath('error.code', 'DEPENDENCY_UNAVAILABLE');
        foreach (['identity.sessions.list' => [], 'identity.sessions.revoke' => ['session_id' => '71']] as $operation => $input) {
            $headers = ['Idempotency-Key' => (string) Str::uuid()];
            $failure = $this->invoke($operation, $token, $input, $headers)->assertStatus(503)
                ->assertJsonPath('error.code', 'DEPENDENCY_UNAVAILABLE');
            if ($operation === 'identity.sessions.revoke') {
                $this->invoke($operation, $token, $input, $headers)->assertStatus(409)
                    ->assertJsonPath('error.code', 'IDEMPOTENCY_IN_PROGRESS');
            }
            $this->assertStringNotContainsString($secret, $failure->getContent());
            $this->assertTrue($failure->headers->hasCacheControlDirective('no-store'));
        }
        $rows = DB::table('tx_internal_audit')->get();
        $this->assertStringNotContainsString($secret, json_encode($rows));
        $this->assertStringNotContainsString($token, json_encode($rows));
        $this->assertSame('started', DB::table('tx_internal_request')->first()->state);
    }

    public function test_native_delete_rolls_back_on_failure_and_stale_installation_epoch_denies_mutation(): void
    {
        $actor = $this->newUser();
        $target = $actor->createToken('rollback-target', ['*'])->accessToken;
        $port = app(AccountSessions::class);
        $armed = true;
        $deleteLevel = null;
        DB::listen(static function ($query) use (&$armed, &$deleteLevel): void {
            if ($armed && preg_match('/\Adelete\s/i', $query->sql) && str_contains($query->sql, 'personal_access_tokens')) {
                $deleteLevel = DB::transactionLevel();
                $armed = false;
                throw new \RuntimeException('INJECTED_SESSION_DELETE_FAILURE');
            }
        });
        try {
            $port->revoke((int) $actor->id, (int) $target->id);
            $this->fail('Injected delete failure was not observed.');
        } catch (\RuntimeException $error) {
            $this->assertSame('INJECTED_SESSION_DELETE_FAILURE', $error->getMessage());
        }
        $this->assertFalse($armed);
        $this->assertSame(1, $deleteLevel);
        $this->assertNotNull($target->fresh());
        $this->assertSame(0, DB::transactionLevel());
        config(['core_installation.worker_epoch' => '1']);
        try {
            $port->end((int) $actor->id, (int) $target->id);
            $this->fail('Stale installation worker admitted session mutation.');
        } catch (\App\Domains\Installation\InstallationAuthorityException $error) {
            $this->assertNotSame('', $error->getMessage());
        }
        $this->assertNotNull($target->fresh());
        $this->assertSame(0, DB::transactionLevel());
    }

    private function invoke(string $operation, ?string $token = null, array $input = [], array $headers = [], string $domain = 'user')
    {
        Auth::forgetGuards();
        $headers = array_replace([
            'X-Core-Service-Id' => $this->service['service_id'], 'X-Core-Service-Credential' => $this->service['credential'],
            'X-Core-Service-Epoch' => $this->service['epoch'], 'X-Core-Tls-Verified' => 'SUCCESS',
            'X-Core-Tls-Certificate-Sha256' => self::FINGERPRINT, 'Authorization' => $token === null ? '' : 'Bearer '.$token,
            'Idempotency-Key' => (string) Str::uuid(),
        ], $headers);
        return $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTPS' => 'on'])
            ->postJson('https://localhost/txapi/internal/native/v1/'.$domain.'/operations/'.$operation, [
                'contract_version' => '1', 'request_id' => (string) Str::uuid(), 'deadline_at' => time() + 15,
                'input' => $input === [] ? (object) [] : $input,
            ], $headers);
    }

    private function http(string $method, string $path, string $token, array $input = [])
    {
        Auth::forgetGuards();
        return $this->json($method, $path, $input, ['Authorization' => 'Bearer '.$token]);
    }

    private function newUser(array $overrides = []): User
    {
        $actor = User::withoutEvents(function () use ($overrides): User {
            $user = new User();
            $user->forceFill(array_replace([
                'email' => Str::uuid().'@example.test', 'password' => Hash::make('AccountSessions2026'),
                'token' => Str::random(32), 'uuid' => (string) Str::uuid(), 'is_admin' => false, 'banned' => false,
            ], $overrides));
            $user->saveOrFail();
            return $user;
        });
        $fresh = $actor->fresh();
        $this->assertSame((bool) ($overrides['is_admin'] ?? false), (bool) $fresh->is_admin);
        $this->assertSame((bool) ($overrides['banned'] ?? false), (bool) $fresh->banned);
        return $fresh;
    }
}
