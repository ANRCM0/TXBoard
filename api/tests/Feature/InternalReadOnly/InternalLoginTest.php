<?php

namespace Tests\Feature\InternalReadOnly;

use App\Http\Requests\Passport\AuthLogin;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Support\Setting;
use App\Utils\CacheKey;
use Firebase\JWT\JWT;
use Illuminate\Cache\ArrayStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request as RequestFacade;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

final class InternalLoginTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'fixture-login-password';
    private string $directory;
    private string $private = '';
    private array $initialActions;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initialActions = HookManager::getActions();
        config(['cache.default' => 'array', 'cache.setting_store' => 'array', 'sanctum.token_prefix' => '']);
        Cache::flush();
        $this->app->forgetInstance(Setting::class);
        $this->directory = sys_get_temp_dir().'/txboard-internal-login-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        openssl_pkey_export($key, $this->private);
        file_put_contents($this->directory.'/proof.pub', openssl_pkey_get_details($key)['key']);
        $keys = $services = [];
        foreach (['user' => 'user-gateway', 'admin' => 'admin-gateway'] as $name => $service) {
            $keys[$name.'-1'] = ['epoch' => 1, 'service' => $service, 'revoked' => false,
                'not_before' => time()-60, 'not_after' => time()+3600,
                'public_key_path' => $this->directory.'/proof.pub'];
            $services[$service] = ['certificates' => [str_repeat('a', 64) => ['revoked' => false,
                'not_before' => time()-60, 'not_after' => time()+3600]]];
        }
        file_put_contents($this->directory.'/trust.json', json_encode([
            'proof_keys' => $keys, 'services' => $services], JSON_THROW_ON_ERROR));
        config(['internal_api_slice.enabled' => true, 'internal_api_slice.login_enabled' => true,
            'internal_api_slice.public_read_enabled' => false, 'internal_api_slice.order_write_enabled' => false,
            'core_shadow.isolated_copy' => true, 'internal_api_slice.trust_file' => $this->directory.'/trust.json']);
        admin_setting(['captcha_enable' => 0, 'password_limit_enable' => true,
            'password_limit_count' => 5, 'password_limit_expire' => 60,
            'secure_path' => 'private-admin-login-path']);
        Http::preventStrayRequests();
        $this->user = $this->account('login-owner@example.test');
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
    }

    protected function tearDown(): void
    {
        $this->app->instance('hook.actions', $this->initialActions);
        if (isset($this->directory)) {
            foreach (glob($this->directory.'/*') ?: [] as $file) unlink($file);
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    public function test_issues_a_real_native_session_with_private_delivery_and_technical_actor_audit(): void
    {
        $response = $this->send()->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('meta', ['current_only' => true, 'slice' => 'internal-isolated-login/1']);
        $this->assertSame(['auth_data'], array_keys($response->json('data')));
        $bearer = $response->json('data.auth_data');
        $this->assertMatchesRegularExpression('/\ABearer [A-Za-z0-9]{48}\z/D', $bearer);
        $token = PersonalAccessToken::findToken(substr($bearer, 7));
        $this->assertNotNull($token);
        $this->assertSame((int) $this->user->id, (int) $token->tokenable_id);
        $this->assertSame(['*'], $token->abilities);
        $this->assertSame(hash('sha256', substr($bearer, 7)), $token->token);
        $this->assertSame(20, strlen($token->name));
        $this->assertEqualsWithDelta(now()->addYear()->timestamp, $token->expires_at->timestamp, 2);
        $this->assertGreaterThan(0, (int) $this->user->fresh()->last_login_at);
        $this->assertSame(1, PersonalAccessToken::count());
        $audit = DB::table('tx_internal_readonly_audits')->sole();
        $this->assertSame(0, (int) $audit->actor_id);
        $this->assertSame('auth.login', $audit->operation);
        $this->assertSame('user-gateway', $audit->service);
        $this->assertSame(200, (int) $audit->status);
        foreach ([$bearer, substr($bearer, 7), self::PASSWORD, $this->user->email, $this->user->token] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($audit));
        }
        foreach ([$this->user->token, 'private-admin-login-path', self::PASSWORD, 'is_admin', 'secure_path'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->send(operation: 'user.profile.get', version: '2', authorization: $bearer)->assertOk()
            ->assertJsonPath('data.id', (string) $this->user->id);
        $this->getJson('/txapi/auth/sessions', ['Authorization' => $bearer])->assertOk()
            ->assertJsonPath('data.0.id', (int) $token->id);
        $this->deleteJson('/txapi/auth/sessions/'.$token->id, [], ['Authorization' => $bearer])->assertOk();
        $this->send(operation: 'user.profile.get', version: '2', authorization: $bearer)->assertUnauthorized();
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_generic_admin_login_issues_native_session_but_user_operations_remain_forbidden(): void
    {
        $admin = $this->account('login-admin@example.test', ['is_admin' => true]);
        $response = $this->send($this->input($admin))->assertOk();
        $this->assertSame(['auth_data'], array_keys($response->json('data')));
        $bearer = $response->json('data.auth_data');
        $token = PersonalAccessToken::findToken(substr($bearer, 7));
        $this->assertSame((int) $admin->id, (int) $token->tokenable_id);
        $this->send(operation: 'user.profile.get', version: '2', authorization: $bearer)->assertForbidden();
        $this->assertStringNotContainsString('private-admin-login-path', $response->getContent());
        $this->assertStringNotContainsString($admin->token, $response->getContent());
        $this->assertSame(1, PersonalAccessToken::count());
    }

    public function test_wrong_unknown_and_banned_accounts_keep_uniform_native_rejection_without_session(): void
    {
        $before = $this->user->fresh()->last_login_at;
        foreach ([['password' => 'wrong-fixture-password'], ['email' => 'missing-login@example.test']] as $patch) {
            $this->send([...$this->input(), ...$patch])->assertUnauthorized()
                ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
        }
        $this->user->update(['banned' => true]);
        $this->send()->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
        $this->assertSame($before, $this->user->fresh()->last_login_at);
        $this->assertSame(0, PersonalAccessToken::count());
        $this->assertSame(1, (int) Cache::get(CacheKey::get('PASSWORD_ERROR_LIMIT', $this->user->email)));
        $this->assertNull(Cache::get(CacheKey::get('PASSWORD_ERROR_LIMIT', 'missing-login@example.test')));
    }

    public function test_legacy_password_algorithms_remain_owned_by_native_login_service(): void
    {
        foreach (['sha256', 'md5salt'] as $algorithm) {
            $salt = 'fixture-salt';
            $hash = $algorithm === 'sha256' ? hash('sha256', self::PASSWORD) : md5(self::PASSWORD.$salt);
            $legacy = $this->account('legacy-'.$algorithm.'@example.test', [
                'password_algo' => $algorithm, 'password_salt' => $salt, 'password' => $hash]);
            $bearer = $this->send($this->input($legacy))->assertOk()->json('data.auth_data');
            $this->assertSame((int) $legacy->id, (int) PersonalAccessToken::findToken(substr($bearer, 7))->tokenable_id);
        }
        $this->assertSame(2, PersonalAccessToken::count());
    }

    public function test_native_form_request_validates_email_and_minimum_password_after_transport_shape(): void
    {
        foreach ([['email' => 'not-an-email'], ['password' => 'short']] as $patch) {
            $response = $this->send([...$this->input(), ...$patch])->assertStatus(422)
                ->assertJsonPath('error.code', 'VALIDATION_FAILED');
            $this->assertSame([array_key_first($patch)], $response->json('error.fields'));
            $this->assertStringNotContainsString(reset($patch), $response->getContent());
            $this->assertSame('/txapi/internal/v1/operations/auth.login', request()->getPathInfo());
            $this->assertSame(request(), RequestFacade::getFacadeRoot());
        }
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_signed_root_normalization_preserves_password_spaces_and_binds_hook_request_without_old_actor(): void
    {
        $password = '  fixture-password-with-spaces  ';
        $this->user->update(['password' => Hash::make($password)]);
        $old = $this->account('old-admin-login@example.test', ['is_admin' => true]);
        $oldToken = 'Bearer '.$old->createToken('old-actor', ['*'], now()->addHour())->plainTextToken;
        $this->send(operation: 'user.profile.get', version: '2', authorization: $oldToken)->assertForbidden();
        Auth::guard('api')->setUser($old);
        $seen = [];
        HookManager::register('user.login.after', static function (User $user) use (&$seen): void {
            $seen = [get_class(request()), request()->getPathInfo(), request()->all(),
                request() === RequestFacade::getFacadeRoot(), Auth::user(), Auth::guard('sanctum')->user(), request()->user()];
        });
        $response = $this->send(['email' => '  '.$this->user->email.'  ', 'password' => $password,
            'turnstile_token' => '  captcha-root  ', 'email_code' => '   '])->assertOk();
        $this->assertSame([AuthLogin::class, '/txapi/auth/login', ['email' => $this->user->email,
            'password' => $password, 'turnstile_token' => 'captcha-root', 'email_code' => null], true, null, null, null], $seen);
        $this->assertSame('/txapi/internal/v1/operations/auth.login', request()->getPathInfo());
        $this->assertSame(request(), RequestFacade::getFacadeRoot());
        $this->send(operation: 'user.profile.get', version: '2', authorization: $response->json('data.auth_data'))->assertOk()
            ->assertJsonPath('data.id', (string) $this->user->id);
    }

    public function test_default_off_isolated_and_environment_gates_do_not_admit_or_issue_credentials(): void
    {
        foreach (['internal_api_slice.login_enabled', 'core_shadow.isolated_copy', 'internal_api_slice.enabled'] as $key) {
            foreach ([false, 'false', 'true', 1, null] as $disabled) {
                config([$key => $disabled, 'internal_api_slice.public_read_enabled' => true]);
                $this->send()->assertNotFound();
            }
            config([$key => true]);
        }
        $this->app->detectEnvironment(static fn () => 'production');
        $this->send()->assertNotFound();
        $this->assertSame(0, PersonalAccessToken::count());
        $this->assertSame(0, DB::table('tx_internal_readonly_nonces')->count());
        $this->assertSame(0, DB::table('tx_internal_readonly_audits')->count());
    }

    public function test_login_requires_absent_authorization_empty_actor_and_exact_service_body_path_proof(): void
    {
        foreach (['', 'Bearer user-token', 'Bearer admin-token', 'invalid'] as $authorization) {
            $this->send(authorization: $authorization)->assertUnauthorized();
        }
        $this->send(service: 'admin-gateway')->assertUnauthorized();
        foreach ([['actor_sha256' => hash('sha256', 'Bearer somebody')], ['body_sha256' => hash('sha256', '{}')],
            ['path' => '/txapi/internal/v1/operations/public.plan.list'], ['epoch' => 2], ['method' => 'GET']] as $claims) {
            $this->send(claims: $claims)->assertUnauthorized();
        }
        $this->send(operation: 'user.profile.get', version: '2')->assertUnauthorized();
        $this->assertSame(0, PersonalAccessToken::count());
        $this->assertSame(0, DB::table('tx_internal_readonly_nonces')->count());
    }

    public function test_strict_shape_duplicate_keys_and_bounds_are_rejected_before_credential_execution(): void
    {
        foreach ([['password' => null], ['password' => 42], ['email' => []], ['actor_id' => '1'],
            ['remote_ip' => '203.0.113.7'], ['password' => str_repeat('x', 1025)], ['email_code' => str_repeat('x', 129)],
            ['turnstile_token' => str_repeat('x', 4097)]] as $patch) {
            $this->send([...$this->input(), ...$patch])->assertStatus(400);
        }
        $this->send(['email' => $this->user->email])->assertStatus(400);
        $this->send(version: '2')->assertStatus(400);
        $raw = json_encode($this->input(), JSON_THROW_ON_ERROR);
        $this->send(rawInput: substr($raw, 0, -1).',"\\u0070assword":"other-password"}')->assertStatus(400);
        $this->send(rawInput: '[]')->assertStatus(400);
        $this->send(rawInput: '{"email":"'.str_repeat('x', 16384).'"}')->assertStatus(413);
        $this->assertSame(0, PersonalAccessToken::count());
        $this->assertSame(0, DB::table('tx_internal_readonly_nonces')->count());
    }

    public function test_native_and_internal_login_share_original_ten_attempt_route_budget(): void
    {
        admin_setting(['password_limit_enable' => false]);
        for ($i = 0; $i < 10; $i++) {
            $input = [...$this->input(), 'password' => 'wrong-fixture-password'];
            ($i % 2 === 0 ? $this->postJson('/txapi/auth/login', $input) : $this->send($input))
                ->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
        }
        $limited = $this->send()->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
        $this->assertGreaterThanOrEqual(1, (int) $limited->headers->get('Retry-After'));
        $this->assertLessThanOrEqual(60, (int) $limited->headers->get('Retry-After'));
        $this->postJson('/txapi/auth/login', $this->input())->assertStatus(429);
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_native_password_budget_is_shared_and_service_budget_has_distinct_safe_code(): void
    {
        admin_setting(['password_limit_count' => 1]);
        $this->postJson('/txapi/auth/login', [...$this->input(), 'password' => 'wrong-fixture-password'])->assertUnauthorized();
        $this->send()->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
        $this->assertSame(0, PersonalAccessToken::count());
        config(['internal_api_slice.requests_per_minute' => 1]);
        $this->send()->assertStatus(429)->assertJsonPath('error.code', 'SERVICE_RATE_LIMITED');
        $this->assertSame(1, DB::table('tx_internal_readonly_nonces')->count());
    }

    public function test_proof_replay_cannot_issue_a_second_session_and_a_new_login_is_not_idempotent(): void
    {
        [$path, $body, $server] = $this->requestData($this->input());
        $first = $this->call('POST', $path, [], [], [], $server, $body)->assertOk()->json('data.auth_data');
        $this->call('POST', $path, [], [], [], $server, $body)->assertStatus(409);
        $this->assertSame(1, PersonalAccessToken::count());
        $second = $this->send()->assertOk()->json('data.auth_data');
        $this->assertNotSame($first, $second);
        $this->assertSame(2, PersonalAccessToken::count());
    }

    public function test_enabled_captcha_types_never_call_external_suppliers_or_issue_sessions(): void
    {
        foreach (['turnstile', 'recaptcha-v3', 'recaptcha', 'unknown-provider'] as $type) {
            admin_setting(['captcha_enable' => 1, 'captcha_type' => $type]);
            $this->send([...$this->input(), 'turnstile_token' => 'secret-captcha-fixture',
                'recaptcha_v3_token' => 'secret-captcha-fixture', 'recaptcha_data' => 'secret-captcha-fixture'])
                ->assertStatus(400)->assertJsonPath('error.code', 'CAPTCHA_INVALID');
        }
        $this->assertSame(0, PersonalAccessToken::count());
        Http::assertNothingSent();
    }

    public function test_current_sql_enabled_captcha_rejects_stale_disabled_loaded_and_cached_policy(): void
    {
        $this->assertSame(0, (int) admin_setting('captcha_enable'));
        DB::table('tx_settings')->where('name', 'captcha_enable')->update(['value' => '1']);
        $this->send()->assertStatus(400)->assertJsonPath('error.code', 'CAPTCHA_INVALID');
        $this->assertSame(0, PersonalAccessToken::count());
        Http::assertNothingSent();
    }

    public function test_shared_cache_and_loaded_password_policy_drift_refuses_instead_of_using_defaults(): void
    {
        $this->assertSame(5, (int) admin_setting('password_limit_count'));
        $cache = Cache::store(config('cache.setting_store'));
        $initial = $cache->get(Setting::CACHE_KEY);
        foreach (['password_limit_enable' => '0', 'password_limit_count' => '1', 'password_limit_expire' => '1'] as $key => $value) {
            $cache->forever(Setting::CACHE_KEY, [...$initial, $key => $value]);
            $this->send()->assertStatus(503)->assertJsonPath('error.code', 'DEPENDENCY_UNAVAILABLE');
            $this->assertSame(0, PersonalAccessToken::count());
        }
        $cache->forever(Setting::CACHE_KEY, $initial);
        $this->send()->assertOk();
        Http::assertNothingSent();
    }

    public function test_missing_nonarray_and_throwing_settings_cache_refuse_without_native_side_effect(): void
    {
        admin_setting('captcha_enable');
        $before = $this->user->fresh()->last_login_at;
        $cache = Cache::store(config('cache.setting_store'));
        foreach ([null, false, 'untrusted-policy'] as $value) {
            $cache->forever(Setting::CACHE_KEY, $value);
            $this->send()->assertStatus(503)->assertJsonPath('error.code', 'DEPENDENCY_UNAVAILABLE');
        }
        $store = $this->faultingSettingsStore();
        $store->fail = true;
        $this->send()->assertStatus(503)->assertJsonPath('error.code', 'DEPENDENCY_UNAVAILABLE');
        $this->assertSame($before, $this->user->fresh()->last_login_at);
        $this->assertSame(0, PersonalAccessToken::count());
        Http::assertNothingSent();
    }

    public function test_swallowed_preload_cache_outage_then_recovery_cannot_weaken_known_password_budget(): void
    {
        admin_setting(['password_limit_count' => 1]);
        $store = $this->faultingSettingsStore();
        $store->fail = true;
        $this->assertNull(admin_setting('app_url')); // Same load used by ApplyRuntimeSettings, now fixed to [].
        $this->assertSame(5, (int) admin_setting('password_limit_count', 5));
        $store->fail = false;
        $store->forever(Setting::CACHE_KEY, ['captcha_enable' => '0', 'password_limit_enable' => '1',
            'password_limit_count' => '1', 'password_limit_expire' => '60']);
        $this->send()->assertStatus(503)->assertJsonPath('error.code', 'DEPENDENCY_UNAVAILABLE');
        $this->assertSame(0, PersonalAccessToken::count());
        Http::assertNothingSent();
    }

    public function test_hook_exception_with_argument_traces_does_not_leak_credentials_and_restores_request(): void
    {
        $oldIni = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        Log::spy();
        HookManager::register('user.login.after', function (User $user): void {
            $this->throwWithSecretArguments(request()->input('password'), request()->input('turnstile_token'));
        });
        try {
            $response = $this->send([...$this->input(), 'turnstile_token' => 'private-captcha-exception-sentinel'])
                ->assertStatus(500)->assertJsonPath('error.code', 'INTERNAL_ERROR');
            foreach ([self::PASSWORD, 'private-captcha-exception-sentinel', 'secret-error-message'] as $secret) {
                $this->assertStringNotContainsString($secret, $response->getContent());
                $this->assertStringNotContainsString($secret, DB::table('tx_internal_readonly_audits')->get()->toJson());
            }
            Log::shouldNotHaveReceived('error');
            $this->assertSame('/txapi/internal/v1/operations/auth.login', request()->getPathInfo());
            $this->assertSame(request(), RequestFacade::getFacadeRoot());
            $this->assertSame(0, PersonalAccessToken::count());
        } finally {
            ini_set('zend.exception_ignore_args', $oldIni);
            $this->app->instance('hook.actions', $this->initialActions);
        }
        $bearer = 'Bearer '.$this->user->createToken('next-real-request', ['*'], now()->addHour())->plainTextToken;
        $this->send(operation: 'user.profile.get', version: '2', authorization: $bearer)->assertOk()
            ->assertJsonPath('data.id', (string) $this->user->id);
    }

    public function test_audit_failure_can_hide_committed_token_and_existing_native_sessions_can_revoke_it(): void
    {
        DB::unprepared(DB::connection()->getDriverName() === 'sqlite'
            ? "CREATE TRIGGER reject_login_audit BEFORE INSERT ON tx_internal_readonly_audits BEGIN SELECT RAISE(ABORT, 'fixture-audit-failure'); END"
            : "CREATE TRIGGER reject_login_audit BEFORE INSERT ON tx_internal_readonly_audits FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture-audit-failure'");
        try {
            $response = $this->send()->assertStatus(503)->assertJsonPath('error.code', 'DEPENDENCY_UNAVAILABLE')
                ->assertJsonMissingPath('data');
        } finally {
            DB::unprepared('DROP TRIGGER reject_login_audit');
        }
        $unknown = PersonalAccessToken::sole();
        $this->assertSame((int) $this->user->id, (int) $unknown->tokenable_id);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/D', $unknown->token);
        $this->assertStringNotContainsString($unknown->token, $response->getContent());
        $this->assertGreaterThan(0, (int) $this->user->fresh()->last_login_at);
        $bearer = $this->send()->assertOk()->json('data.auth_data'); // Deliberate new login, never automatic recovery.
        $this->assertSame(2, PersonalAccessToken::count());
        $sessions = $this->getJson('/txapi/auth/sessions', ['Authorization' => $bearer])->assertOk();
        $this->assertContains((int) $unknown->id, array_column($sessions->json('data'), 'id'));
        $this->deleteJson('/txapi/auth/sessions/'.$unknown->id, [], ['Authorization' => $bearer])->assertOk();
        $this->assertSame(1, PersonalAccessToken::count());
        $this->assertNotNull(PersonalAccessToken::findToken(substr($bearer, 7)));
    }

    private function account(string $email, array $attributes = []): User
    {
        return User::create([...['email' => $email, 'password' => Hash::make(self::PASSWORD),
            'uuid' => (string) Str::uuid(), 'token' => 'private-login-'.bin2hex(random_bytes(12)),
            'banned' => false, 'is_admin' => false], ...$attributes]);
    }

    private function input(?User $user = null): array
    {
        return ['email' => ($user ?? $this->user)->email, 'password' => self::PASSWORD];
    }

    private function throwWithSecretArguments(string $password, string $captcha): never
    {
        throw new \RuntimeException('secret-error-message', 0, new \RuntimeException($password.$captcha));
    }

    private function faultingSettingsStore(): ArrayStore
    {
        $store = new class extends ArrayStore {
            public bool $fail = false;
            public function get($key)
            {
                if ($this->fail) throw new \RuntimeException('fixture-settings-store-down');
                return parent::get($key);
            }
        };
        config(['cache.stores.login_policy_fixture' => ['driver' => 'login-policy-fixture'],
            'cache.setting_store' => 'login_policy_fixture']);
        Cache::extend('login-policy-fixture', function ($app, array $config) use ($store) {
            return Cache::repository($store, $config);
        });
        $this->app->forgetInstance(Setting::class);
        return $store;
    }

    private function send(?array $input = null, string $operation = 'auth.login', string $version = '1',
        ?string $authorization = null, array $claims = [], ?string $rawInput = null, string $service = 'user-gateway')
    {
        [$path, $body, $server] = $this->requestData($input ?? ($operation === 'auth.login' ? $this->input() : []),
            $operation, $version, $authorization, $claims, $rawInput, $service);
        return $this->call('POST', $path, [], [], [], $server, $body);
    }

    private function requestData(array $input, string $operation = 'auth.login', string $version = '1',
        ?string $authorization = null, array $claims = [], ?string $rawInput = null, string $service = 'user-gateway'): array
    {
        $body = json_encode(['contract_version' => $version, 'request_id' => (string) Str::uuid(),
            'deadline_at' => time()+5, 'input' => (object) $input], JSON_THROW_ON_ERROR);
        if ($rawInput !== null) $body = substr($body, 0, strpos($body, '"input":')+8).$rawInput.'}';
        $path = '/txapi/internal/v1/operations/'.$operation;
        $proof = JWT::encode([...['v' => 'internal-readonly/1', 'epoch' => 1,
            'iss' => 'txboard-local-tls-boundary', 'aud' => 'txboard-core-local', 'service' => $service,
            'cert_sha256' => str_repeat('a', 64), 'method' => 'POST', 'path' => $path,
            'body_sha256' => hash('sha256', $body), 'actor_sha256' => hash('sha256', $authorization ?? ''),
            'iat' => time(), 'nbf' => time()-2, 'exp' => time()+10, 'jti' => bin2hex(random_bytes(16))], ...$claims],
            $this->private, 'RS256', $service === 'user-gateway' ? 'user-1' : 'admin-1');
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_TXBOARD_SERVICE_PROOF' => $proof];
        if ($authorization !== null) $server['HTTP_AUTHORIZATION'] = $authorization;
        return [$path, $body, $server];
    }
}
