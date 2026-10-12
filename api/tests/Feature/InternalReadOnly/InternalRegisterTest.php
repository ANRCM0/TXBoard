<?php

namespace Tests\Feature\InternalReadOnly;

use App\Http\Requests\Passport\CommSendEmailVerify;
use App\Models\MailLog;
use App\Models\MailTemplate;
use App\Models\User;
use App\Support\Setting;
use App\Services\Plugin\HookManager;
use App\Utils\CacheKey;
use Firebase\JWT\JWT;
use Illuminate\Cache\ArrayStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Request as RequestFacade;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Tests\TestCase;

final class InternalRegisterTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'registered-owner@example.test';
    private const PASSWORD = 'fixture-registration-password';
    private array $initialActions;
    private string $directory;
    private string $private = '';
    private ArrayTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initialActions = HookManager::getActions();
        config(['cache.default' => 'array', 'cache.setting_store' => 'array',
            'queue.default' => 'sync', 'queue.connections.sync.after_commit' => false,
            'mail.driver' => 'array', 'mail.from.address' => 'fixture-sender@example.test',
            'mail.username' => 'secret-email-smtp-user', 'mail.password' => 'secret-email-smtp-password']);
        Cache::flush();
        $this->app->forgetInstance(Setting::class);
        admin_setting(['captcha_enable' => 0, 'email_whitelist_enable' => 0, 'stop_register' => 0,
            'email_whitelist_suffix' => ['example.test'], 'email_host' => '',
            'app_name' => 'Local Email Code Fixture', 'app_url' => 'https://example.test']);
        Mail::purge();
        $this->transport = Mail::mailer()->getSymfonyTransport();
        $this->assertSame(ArrayTransport::class, $this->transport::class);
        $this->assertSame(SyncQueue::class, Queue::connection('sync')::class);
        Http::preventStrayRequests();
        $this->directory = sys_get_temp_dir().'/txboard-internal-email-code-'.bin2hex(random_bytes(8));
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
        file_put_contents($this->directory.'/trust.json', json_encode(['proof_keys' => $keys, 'services' => $services], JSON_THROW_ON_ERROR));
        config(['internal_api_slice.enabled' => true, 'internal_api_slice.email_code_enabled' => false, 'internal_api_slice.register_enabled' => true,
            'internal_api_slice.public_read_enabled' => false, 'internal_api_slice.login_enabled' => false,
            'internal_api_slice.order_write_enabled' => false, 'core_shadow.isolated_copy' => true,
            'internal_api_slice.trust_file' => $this->directory.'/trust.json']);
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory)) {
            foreach (glob($this->directory.'/*') ?: [] as $file) unlink($file);
            rmdir($this->directory);
        }
        $this->app->instance('hook.actions', $this->initialActions);
        parent::tearDown();
    }

    public function test_native_account_session_closed_delivery_and_guest_audit(): void
    {
        $nativeCaptcha = app(\App\Services\CaptchaService::class);
        $nativeRegister = app(\App\Services\Auth\RegisterService::class);
        $seen = [];
        HookManager::register('user.register.before', static function ($request) use (&$seen): void {
            $seen = [$request->getPathInfo(), $request->input('email'), $request->user(), Auth::guard('sanctum')->user()];
        });
        $response = $this->send()->assertCreated()->assertJsonPath('meta',
            ['current_only' => true, 'slice' => 'internal-isolated-registration/1'])
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(['auth_data'], array_keys($response->json('data')));
        $user = User::sole();
        $this->assertSame(self::EMAIL, $user->email);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertNotEmpty($user->uuid);
        $this->assertNotEmpty($user->token);
        $this->assertNotNull($user->last_login_at);
        $this->assertFalse((bool) $user->is_admin);
        $this->assertSame(1, PersonalAccessToken::count());
        $this->assertSame((int) $user->id, (int) \App\Services\AuthService::findUserByBearerToken($response->json('data.auth_data'))->id);
        $this->assertSame(['/txapi/auth/register', self::EMAIL, null, null], $seen);
        $this->assertSame($nativeCaptcha::class, app(\App\Services\CaptchaService::class)::class);
        $this->assertSame($nativeRegister::class, app(\App\Services\Auth\RegisterService::class)::class);
        $this->assertSame('/txapi/internal/v1/operations/auth.register', request()->getPathInfo());
        $this->assertSame(app('request'), RequestFacade::getFacadeRoot());
        $this->assertNull(Auth::guard('sanctum')->user());
        $audit = DB::table('tx_internal_readonly_audits')->sole();
        $this->assertSame('auth.register', $audit->operation);
        $this->assertSame(0, (int) $audit->actor_id);
        $this->assertSame(201, (int) $audit->status);
        foreach ([self::EMAIL, self::PASSWORD, $user->token, $user->uuid] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
            $this->assertStringNotContainsString($secret, json_encode($audit));
        }
        Http::assertNothingSent();
    }

    public function test_independent_exact_gates_production_and_other_auth_flags(): void
    {
        config(['internal_api_slice.login_enabled' => true, 'internal_api_slice.email_code_enabled' => true,
            'internal_api_slice.public_read_enabled' => true]);
        foreach (['internal_api_slice.register_enabled', 'internal_api_slice.enabled', 'core_shadow.isolated_copy'] as $key) {
            foreach ([false, 'true', 1] as $disabled) {
                config([$key => $disabled]);
                $this->send()->assertNotFound();
            }
            config([$key => true]);
        }
        $this->app->detectEnvironment(static fn () => 'production');
        $this->send()->assertNotFound();
        $this->assertSame(0, User::count());
        $this->assertSame(0, DB::table('tx_internal_readonly_nonces')->count());
    }

    public function test_absent_actor_closed_service_proof_and_nonce_replay(): void
    {
        foreach (['', 'Bearer user', 'Bearer admin', 'Bearer node'] as $authorization) {
            $this->send(authorization: $authorization)->assertUnauthorized();
        }
        $this->send(service: 'admin-gateway')->assertUnauthorized();
        foreach ([['body_sha256' => hash('sha256', '{}')], ['actor_sha256' => hash('sha256', 'Bearer actor')],
            ['path' => '/txapi/auth/register'], ['epoch' => 2]] as $claims) {
            $this->send(claims: $claims)->assertUnauthorized();
        }
        [$path, $body, $server] = $this->requestData($this->input());
        $this->call('POST', $path, [], [], [], $server, $body)->assertCreated();
        $this->call('POST', $path, [], [], [], $server, $body)->assertStatus(409)->assertJsonPath('error.code', 'SERVICE_REPLAY');
        $this->assertSame(1, User::count());
        $this->assertSame(1, PersonalAccessToken::count());
    }

    public function test_strict_signed_fields_duplicates_and_native_form(): void
    {
        foreach ([['password' => null], ['email' => []], ['invite_code' => 1], ['email_code' => []],
            ['role' => 'admin'], ['plan_id' => 1], ['remote_ip' => '203.0.113.1'],
            ['password' => str_repeat('x', 1025)], ['invite_code' => str_repeat('x', 129)],
            ['turnstile_token' => str_repeat('x', 4097)]] as $patch) {
            $this->send([...$this->input(), ...$patch])->assertStatus(400);
        }
        $this->send(['email' => self::EMAIL])->assertStatus(400);
        $this->send(version: '2')->assertStatus(400);
        $raw = json_encode($this->input());
        $this->send(rawInput: substr($raw, 0, -1).',"\\u0070assword":"different-password"}')->assertStatus(400);
        foreach ([['email' => 'not-email'], ['password' => 'short']] as $patch) {
            $this->send([...$this->input(), ...$patch])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        }
        $this->send([...$this->input(), 'email' => 'owner@sub.example.test'])->assertStatus(400)->assertJsonPath('error.code', 'RECIPIENT_NOT_ALLOWED');
        $this->assertSame(0, User::count());
    }

    public function test_native_stop_whitelist_alias_invite_and_enabled_captcha_rejections(): void
    {
        foreach ([['stop_register' => 1], ['email_whitelist_enable' => 1, 'email_whitelist_suffix' => ['allowed.test']],
            ['invite_force' => 1], ['captcha_enable' => 1, 'captcha_type' => 'turnstile']] as $settings) {
            admin_setting($settings);
            $this->send()->assertStatus(422)->assertJsonPath('error.code', 'REGISTRATION_REJECTED');
            admin_setting(['stop_register' => 0, 'email_whitelist_enable' => 0, 'invite_force' => 0, 'captcha_enable' => 0]);
        }
        admin_setting(['email_gmail_limit_enable' => 1]);
        $this->send([...$this->input(), 'email' => 'alias+test@example.test'])->assertStatus(422);
        $this->assertSame(0, User::count());
        Http::assertNothingSent();
    }

    public function test_native_invite_email_verification_trial_and_duplicate_semantics(): void
    {
        $inviter = User::create(['email' => 'inviter@example.test', 'password' => Hash::make(self::PASSWORD),
            'uuid' => (string) Str::uuid(), 'token' => 'private-inviter-token']);
        $invite = \App\Models\InviteCode::forceCreate(['user_id' => $inviter->id, 'code' => 'fixture-invite', 'status' => 0]);
        $plan = \App\Models\Plan::create(['name' => 'Fixture trial', 'group_id' => 1, 'transfer_enable' => 7,
            'speed_limit' => 23, 'prices' => ['month_price' => 1], 'show' => 1, 'sell' => 1]);
        admin_setting(['invite_force' => 1, 'email_verify' => 1, 'try_out_plan_id' => $plan->id, 'try_out_hour' => 2]);
        Cache::put($this->codeKey(), 123456, 300);
        $this->send([...$this->input(), 'invite_code' => $invite->code, 'email_code' => '000000'])->assertStatus(422);
        $this->assertSame(0, (int) $invite->fresh()->status);
        $this->assertSame(123456, Cache::get($this->codeKey()));
        $response = $this->send([...$this->input(), 'invite_code' => $invite->code, 'email_code' => '123456'])->assertCreated();
        $user = User::where('email', self::EMAIL)->sole();
        $this->assertSame((int) $inviter->id, (int) $user->invite_user_id);
        $this->assertSame((int) $plan->id, (int) $user->plan_id);
        $this->assertSame(7*1073741824, (int) $user->transfer_enable);
        $this->assertSame(23, (int) $user->speed_limit);
        $this->assertEqualsWithDelta(time()+7200, (int) $user->expired_at, 3);
        $this->assertSame(1, (int) $invite->fresh()->status);
        $this->assertNull(Cache::get($this->codeKey()));
        $this->assertStringNotContainsString($invite->code, $response->getContent());
        admin_setting(['email_verify' => 0]);
        $this->send([...$this->input(), 'invite_code' => $invite->code])->assertStatus(409)->assertJsonPath('error.code', 'CONFLICT');
        $this->assertSame(1, PersonalAccessToken::count());
    }

    public function test_native_ip_quota_counts_only_success_and_caller_ip_cannot_replace_boundary(): void
    {
        admin_setting(['register_limit_by_ip_enable' => 1, 'register_limit_count' => 1, 'register_limit_expire' => 5]);
        $this->send()->assertCreated();
        $key = CacheKey::get('REGISTER_IP_RATE_LIMIT', '127.0.0.1');
        $this->assertSame(1, Cache::get($key));
        $this->send([...$this->input(), 'email' => 'another@example.test'])->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
        $this->assertSame(1, User::count());
    }

    public function test_native_and_internal_share_route_throttle_separate_from_service_limit(): void
    {
        for ($i = 0; $i < 9; $i++) {
            $this->postJson('/txapi/auth/register', ['email' => 'bad', 'password' => 'short'])->assertStatus(422);
        }
        $this->send([...$this->input(), 'password' => 'short'])->assertStatus(422);
        $this->send()->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
        config(['internal_api_slice.requests_per_minute' => 1]);
        $this->send()->assertStatus(429)->assertJsonPath('error.code', 'SERVICE_RATE_LIMITED');
        $this->assertSame(0, User::count());
    }

    public function test_sql_and_loaded_cache_policy_drift_fail_before_user_creation(): void
    {
        admin_setting(['stop_register' => 0]);
        $this->assertSame(0, (int) admin_setting('stop_register'));
        \App\Models\Setting::query()->where('name', 'stop_register')->update(['value' => '1']);
        $this->send()->assertStatus(503)->assertJsonPath('error.code', 'DEPENDENCY_UNAVAILABLE');
        $this->assertSame(0, User::count());
        admin_setting(['stop_register' => 0]);
        admin_setting('stop_register');
        $cached = Cache::get(Setting::CACHE_KEY);
        $cached['invite_force'] = 1;
        Cache::forever(Setting::CACHE_KEY, $cached);
        $this->send()->assertStatus(503);
        $this->assertSame(0, User::count());
    }

    public function test_native_invite_consumption_can_precede_failed_account_save(): void
    {
        $inviter = User::create(['email' => 'inviter@example.test', 'password' => Hash::make(self::PASSWORD),
            'uuid' => (string) Str::uuid(), 'token' => 'private-inviter-token']);
        $invite = \App\Models\InviteCode::forceCreate(['user_id' => $inviter->id, 'code' => 'fixture-invite', 'status' => 0]);
        User::creating(static fn (User $user) => $user->email === self::EMAIL ? false : null);
        $this->send([...$this->input(), 'invite_code' => $invite->code])->assertStatus(422);
        $this->assertSame(1, (int) $invite->fresh()->status);
        $this->assertSame(1, User::count());
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_after_hook_failure_preserves_account_and_code_and_restores_request_without_secret_logs(): void
    {
        admin_setting(['email_verify' => 1]);
        Cache::put($this->codeKey(), 123456, 300);
        Log::spy();
        HookManager::register('user.register.after', static function (User $user): void {
            throw new \RuntimeException('private-registration-error '.self::PASSWORD.' '.$user->email);
        });
        $response = $this->send([...$this->input(), 'email_code' => '123456'])->assertStatus(500)->assertJsonMissingPath('data');
        $this->assertSame(1, User::count());
        $this->assertSame(0, PersonalAccessToken::count());
        $this->assertSame(123456, Cache::get($this->codeKey()));
        $this->assertSame('/txapi/internal/v1/operations/auth.register', request()->getPathInfo());
        $this->assertSame(app('request'), RequestFacade::getFacadeRoot());
        Log::shouldNotHaveReceived('error');
        foreach ([self::EMAIL, self::PASSWORD, '123456', 'private-registration-error'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
            $this->assertStringNotContainsString($secret, DB::table('tx_internal_readonly_audits')->get()->toJson());
        }
    }

    public function test_audit_failure_hides_committed_account_and_token_no_automatic_replay(): void
    {
        DB::unprepared("CREATE TRIGGER reject_register_audit BEFORE INSERT ON tx_internal_readonly_audits BEGIN SELECT RAISE(ABORT, 'fixture-audit-failure'); END");
        try {
            $this->send()->assertStatus(503)->assertJsonPath('error.code', 'DEPENDENCY_UNAVAILABLE')->assertJsonMissingPath('data');
        } finally { DB::unprepared('DROP TRIGGER reject_register_audit'); }
        $this->assertSame(1, User::count());
        $this->assertSame(1, PersonalAccessToken::count());
        $this->send()->assertStatus(409)->assertJsonPath('error.code', 'CONFLICT');
        $this->assertSame(1, PersonalAccessToken::count());
    }

    public function test_deadline_can_hide_committed_account_and_token(): void
    {
        $deadline = time()+5;
        $entered = false;
        HookManager::register('user.register.after', static function () use ($deadline, &$entered): void {
            $entered = true;
            while (time() < $deadline) usleep(50_000);
        });
        $this->send(deadlineAt: $deadline)->assertStatus(504)->assertJsonPath('error.code', 'DEADLINE_EXCEEDED');
        $this->assertTrue($entered);
        $this->assertSame(1, User::count());
        $this->assertSame(1, PersonalAccessToken::count());
    }

    private function input(): array { return ['email' => self::EMAIL, 'password' => self::PASSWORD]; }

    private function codeKey(): string { return CacheKey::get('EMAIL_VERIFY_CODE', self::EMAIL); }

    private function send(?array $input = null, string $operation = 'auth.register', string $version = '1',
        ?string $authorization = null, array $claims = [], ?string $rawInput = null, string $service = 'user-gateway', ?int $deadlineAt = null)
    {
        [$path, $body, $server] = $this->requestData($input ?? $this->input(), $operation, $version,
            $authorization, $claims, $rawInput, $service, $deadlineAt);
        return $this->call('POST', $path, [], [], [], $server, $body);
    }

    private function requestData(array $input, string $operation = 'auth.register', string $version = '1',
        ?string $authorization = null, array $claims = [], ?string $rawInput = null, string $service = 'user-gateway', ?int $deadlineAt = null): array
    {
        $body = json_encode(['contract_version' => $version, 'request_id' => (string) Str::uuid(),
            'deadline_at' => $deadlineAt ?? time()+5, 'input' => (object) $input], JSON_THROW_ON_ERROR);
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
