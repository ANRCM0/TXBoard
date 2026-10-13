<?php

namespace Tests\Feature\InternalReadOnly;

use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Support\Setting;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Request as RequestFacade;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

final class InternalAdminLoginTest extends TestCase
{
    use RefreshDatabase;
    private const PASSWORD = 'fixture-admin-password';
    private string $directory;
    private string $private = '';
    private User $admin;
    private array $actions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actions = HookManager::getActions();
        config(['cache.default' => 'array', 'cache.setting_store' => 'array', 'sanctum.token_prefix' => '']);
        Cache::flush(); $this->app->forgetInstance(Setting::class);
        $this->directory = sys_get_temp_dir().'/txboard-internal-admin-login-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        openssl_pkey_export($key, $this->private);
        file_put_contents($this->directory.'/proof.pub', openssl_pkey_get_details($key)['key']);
        $trust = ['proof_keys' => [], 'services' => []];
        foreach (['user', 'admin'] as $role) {
            $trust['proof_keys'][$role.'-1'] = ['epoch' => 1, 'service' => $role.'-gateway', 'revoked' => false,
                'not_before' => time()-60, 'not_after' => time()+3600, 'public_key_path' => $this->directory.'/proof.pub'];
            $trust['services'][$role.'-gateway']['certificates'][str_repeat('a', 64)] = [
                'revoked' => false, 'not_before' => time()-60, 'not_after' => time()+3600];
        }
        file_put_contents($this->directory.'/trust.json', json_encode($trust, JSON_THROW_ON_ERROR));
        config(['internal_api_slice.enabled' => true, 'internal_api_slice.admin_login_enabled' => true,
            'internal_api_slice.admin_identity_enabled' => true, 'core_shadow.isolated_copy' => true,
            'internal_api_slice.trust_file' => $this->directory.'/trust.json']);
        admin_setting(['captcha_enable' => 0, 'password_limit_enable' => true, 'password_limit_count' => 5,
            'password_limit_expire' => 60, 'secure_path' => 'private-admin-login-fixture']);
        Http::preventStrayRequests();
        $this->admin = $this->account('admin-login@example.test', ['is_admin' => true]);
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
    }

    protected function tearDown(): void
    {
        $this->app->instance('hook.actions', $this->actions);
        foreach (glob($this->directory.'/*') ?: [] as $file) unlink($file);
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_native_admin_session_and_path_have_private_delivery_and_anonymous_audit(): void
    {
        $response = $this->send()->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(['auth_data', 'is_admin', 'secure_path'], array_keys($response->json('data')));
        $response->assertJsonPath('data.is_admin', true)->assertJsonPath('data.secure_path', 'private-admin-login-fixture');
        $bearer = $response->json('data.auth_data');
        $token = PersonalAccessToken::findToken(substr($bearer, 7));
        $this->assertSame((int) $this->admin->id, (int) $token->tokenable_id);
        $this->assertSame(['*'], $token->abilities);
        $this->assertGreaterThan(0, (int) $this->admin->fresh()->last_login_at);
        $audit = DB::table('tx_internal_readonly_audits')->sole();
        $this->assertSame(0, (int) $audit->actor_id);
        $this->assertSame('admin-gateway', $audit->service);
        foreach ([$bearer, substr($bearer, 7), self::PASSWORD, $this->admin->email, 'private-admin-login-fixture'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($audit));
        }
        $this->assertStringNotContainsString($this->admin->token, $response->getContent());
        $this->send(input: ['admin_path' => 'private-admin-login-fixture'], operation: 'admin.identity.get',
            authorization: $bearer)->assertOk()->assertJsonPath('data.id', (string) $this->admin->id);
    }

    public function test_correct_password_for_user_or_staff_cannot_issue_admin_session_or_path(): void
    {
        foreach ([false, true] as $staff) {
            $user = $this->account('admin-login-'.(int) $staff.'@example.test', ['is_staff' => $staff]);
            $response = $this->send(['email' => $user->email, 'password' => self::PASSWORD])
                ->assertForbidden()->assertJsonPath('error.code', 'ADMIN_ACCESS_DENIED');
            $this->assertStringNotContainsString('private-admin-login-fixture', $response->getContent());
            // Preserve native LoginService before the admin-only issuance check.
            $this->assertGreaterThan(0, (int) $user->fresh()->last_login_at);
        }
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_default_off_isolated_and_production_gates_issue_nothing(): void
    {
        foreach (['internal_api_slice.admin_login_enabled', 'core_shadow.isolated_copy', 'internal_api_slice.enabled'] as $name) {
            foreach ([false, 'true', 1, null] as $value) {config([$name => $value]); $this->send()->assertNotFound();}
            config([$name => true]);
        }
        $this->app->detectEnvironment(static fn () => 'production'); $this->send()->assertNotFound();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('tx_internal_readonly_nonces', 0);
    }

    public function test_wrong_service_authorization_and_caller_role_cannot_open_login(): void
    {
        $this->send(role: 'user')->assertUnauthorized();
        foreach (['', 'Bearer fake-admin', 'invalid'] as $authorization) $this->send(authorization: $authorization)->assertUnauthorized();
        $this->send(['email' => $this->admin->email, 'password' => self::PASSWORD, 'is_admin' => true])->assertStatus(400);
        $this->send(['email' => 'real-admin@example.com', 'password' => self::PASSWORD])->assertForbidden()
            ->assertJsonPath('error.code', 'ACTOR_NOT_ALLOWED');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_wrong_missing_and_banned_accounts_preserve_native_rejection(): void
    {
        $this->send(['email' => $this->admin->email, 'password' => 'wrong-password'])->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
        $this->send(['email' => 'missing-admin@example.test', 'password' => self::PASSWORD])->assertUnauthorized();
        $this->admin->update(['banned' => true]); $this->send()->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_password_spaces_and_hook_request_are_preserved_and_binding_restored(): void
    {
        $password = '  fixture-password-with-spaces  ';
        $this->admin->update(['password' => Hash::make($password)]);
        $seen = [];
        HookManager::register('user.login.after', function ($user) use (&$seen) {
            $seen = [request()->getPathInfo(), request()->input('password'), Auth::guard('sanctum')->user()];
        });
        $this->send(['email' => ' '.$this->admin->email.' ', 'password' => $password])->assertOk();
        $this->assertSame(['/txapi/auth/admin/login', $password, null], $seen);
        $this->assertSame('/txapi/internal/v1/operations/auth.admin.login', request()->getPathInfo());
        $this->assertSame(request(), RequestFacade::getFacadeRoot());
    }

    public function test_legacy_hashes_and_form_validation_remain_native(): void
    {
        foreach (['sha256', 'md5salt'] as $algorithm) {
            $salt = 'fixture-salt';
            $user = $this->account('admin-'.$algorithm.'@example.test', ['is_admin' => true,
                'password_algo' => $algorithm, 'password_salt' => $salt,
                'password' => $algorithm === 'sha256' ? hash('sha256', self::PASSWORD) : md5(self::PASSWORD.$salt)]);
            $this->send(['email' => $user->email, 'password' => self::PASSWORD])->assertOk();
        }
        $this->send(['email' => $this->admin->email, 'password' => 'short'])->assertStatus(422);
        $this->assertDatabaseCount('personal_access_tokens', 2);
    }

    public function test_known_disabled_captcha_and_cache_drift_fail_without_external_requests(): void
    {
        admin_setting(['captcha_enable' => 1, 'captcha_type' => 'turnstile']);
        $this->send()->assertStatus(400)->assertJsonPath('error.code', 'CAPTCHA_INVALID');
        admin_setting(['captcha_enable' => 0]);
        $this->assertSame(0, (int) admin_setting('captcha_enable'));
        Cache::store('array')->put(Setting::CACHE_KEY, ['captcha_enable' => 1]);
        $this->send()->assertStatus(503)->assertJsonPath('error.code', 'DEPENDENCY_UNAVAILABLE');
        Http::assertNothingSent(); $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_native_password_budget_is_shared_with_admin_login(): void
    {
        for ($i=0; $i<5; $i++) $this->send(['email' => $this->admin->email, 'password' => 'wrong-password'])->assertUnauthorized();
        $this->postJson('/txapi/auth/admin/login', ['email' => $this->admin->email, 'password' => self::PASSWORD])
            ->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_same_verified_proof_never_issues_a_second_session(): void
    {
        [$raw, $proof] = $this->signed(['email' => $this->admin->email, 'password' => self::PASSWORD], 'admin', 'auth.admin.login', null);
        $this->raw($raw, $proof)->assertOk(); $this->raw($raw, $proof)->assertStatus(409);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_audit_failure_can_hide_a_committed_native_session_without_compensation(): void
    {
        DB::unprepared("CREATE TRIGGER reject_admin_login_audit BEFORE INSERT ON tx_internal_readonly_audits BEGIN SELECT RAISE(ABORT, 'synthetic-private-admin-failure'); END");
        $response = $this->send()->assertStatus(503)->assertJsonPath('error.code', 'DEPENDENCY_UNAVAILABLE');
        $this->assertDatabaseCount('personal_access_tokens', 1);
        foreach ([self::PASSWORD, $this->admin->email, 'private-admin-login-fixture', 'synthetic-private-admin-failure', $this->admin->token] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
    }

    private function account(string $email, array $fields = []): User
    {
        return User::create(array_replace(['email' => $email, 'password' => Hash::make(self::PASSWORD),
            'uuid' => (string) Str::uuid(), 'token' => 'private-subscribe-'.$email, 'is_admin' => false, 'is_staff' => false, 'banned' => false], $fields));
    }
    private function signed(array $input, string $role, string $operation, ?string $authorization): array
    {
        $raw = json_encode(['contract_version' => '1', 'request_id' => (string) Str::uuid(), 'deadline_at' => time()+5, 'input' => (object) $input]);
        $now = time();
        $proof = JWT::encode(['v' => 'internal-readonly/1', 'epoch' => 1, 'iss' => 'txboard-local-tls-boundary', 'aud' => 'txboard-core-local',
            'service' => $role.'-gateway', 'cert_sha256' => str_repeat('a',64), 'method' => 'POST',
            'path' => '/txapi/internal/v1/operations/'.$operation, 'body_sha256' => hash('sha256',$raw),
            'actor_sha256' => hash('sha256',$authorization ?? ''), 'iat' => $now, 'nbf' => $now-2, 'exp' => $now+10,
            'jti' => bin2hex(random_bytes(16))], $this->private, 'RS256', $role.'-1');
        return [$raw, $proof];
    }
    private function send(?array $input = null, string $role = 'admin', string $operation = 'auth.admin.login', ?string $authorization = null)
    {
        [$raw,$proof] = $this->signed($input ?? ['email' => $this->admin->email,'password' => self::PASSWORD],$role,$operation,$authorization);
        return $this->raw($raw,$proof,$operation,$authorization);
    }
    private function raw(string $raw, string $proof, string $operation = 'auth.admin.login', ?string $authorization = null)
    {
        Auth::forgetGuards();
        $headers=['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json','HTTP_X_TXBOARD_SERVICE_PROOF'=>$proof];
        if ($authorization !== null) $headers['HTTP_AUTHORIZATION']=$authorization;
        return $this->call('POST','/txapi/internal/v1/operations/'.$operation,[],[],[],$headers,$raw);
    }
}
