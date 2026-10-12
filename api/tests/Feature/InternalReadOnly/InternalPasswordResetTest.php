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

final class InternalPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'recovery-owner@example.test';
    private const PASSWORD = 'fixture-new-password';
    private const OLD_PASSWORD = 'fixture-old-password';
    private User $user;
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
        config(['internal_api_slice.enabled' => true, 'internal_api_slice.email_code_enabled' => false, 'internal_api_slice.register_enabled' => false, 'internal_api_slice.password_reset_enabled' => true,
            'internal_api_slice.public_read_enabled' => false, 'internal_api_slice.login_enabled' => false,
            'internal_api_slice.order_write_enabled' => false, 'core_shadow.isolated_copy' => true,
            'internal_api_slice.trust_file' => $this->directory.'/trust.json']);
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
        $this->user = User::create(['email' => self::EMAIL, 'password' => Hash::make(self::OLD_PASSWORD),
            'uuid' => (string) Str::uuid(), 'token' => 'private-recovery-user-token', 'password_algo' => 'fixture-old-algo', 'password_salt' => 'private-old-salt']);
        for ($i = 0; $i < 3; $i++) $this->user->createToken('fixture-session-'.$i);
        Cache::put($this->codeKey(), 123456, 300);
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

    public function test_native_password_session_revocation_consumed_code_and_guest_delivery(): void
    {
        Auth::guard('sanctum')->setUser($this->user);
        $seen = null;
        HookManager::register('user.password.reset.after', static function (User $user) use (&$seen): void {
            $seen = [request()->getPathInfo(), request()->user(), Auth::guard('sanctum')->user()];
        });
        $response = $this->send()->assertOk()->assertJsonPath('data', ['ok' => true])
            ->assertJsonPath('meta', ['current_only' => true, 'slice' => 'internal-isolated-password-reset/1'])
            ->assertHeader('Cache-Control', 'no-store, private');
        $user = $this->user->fresh();
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertFalse(Hash::check(self::OLD_PASSWORD, $user->password));
        $this->assertNull($user->password_algo);
        $this->assertNull($user->password_salt);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertNull(Cache::get($this->codeKey()));
        $this->assertSame(['/txapi/auth/password/forgot', null, null], $seen);
        $this->assertSame('/txapi/internal/v1/operations/auth.password.reset', request()->getPathInfo());
        $this->assertSame(app('request'), RequestFacade::getFacadeRoot());
        $this->assertSame(1, User::count());
        $audit = DB::table('tx_internal_readonly_audits')->sole();
        $this->assertSame('auth.password.reset', $audit->operation);
        $this->assertSame(0, (int) $audit->actor_id);
        $this->assertSame(200, (int) $audit->status);
        foreach ([self::EMAIL, self::PASSWORD, self::OLD_PASSWORD, '123456', $user->token] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
            $this->assertStringNotContainsString($secret, json_encode($audit));
        }
        Http::assertNothingSent();
    }

    public function test_native_bad_code_budget_blocks_correct_code_after_three_failures(): void
    {
        $key = CacheKey::get('FORGET_REQUEST_LIMIT', self::EMAIL);
        for ($i = 1; $i <= 3; $i++) {
            $this->send([...$this->input(), 'email_code' => '000000'])->assertStatus(400)->assertJsonPath('error.code', 'RESET_REJECTED');
            $this->assertSame($i, Cache::get($key));
        }
        $this->send()->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
        $this->assertUnchanged();
        $items = Cache::getStore()->all();
        $this->assertEqualsWithDelta(microtime(true)+300, $items[$key]['expiresAt'], 3);
        $this->travel(301)->seconds();
        $this->assertNull(Cache::get($key));
    }

    public function test_existing_cache_lock_serializes_the_native_recovery_attempt(): void
    {
        $lock = Cache::lock('password-reset:'.hash('sha256', self::EMAIL), 15);
        $this->assertTrue($lock->get());
        try { $this->send()->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED'); }
        finally { $lock->release(); }
        $this->assertUnchanged();
        $this->send()->assertOk();
        $this->assertSame(0, $this->user->tokens()->count());
    }

    public function test_independent_exact_gates_other_flags_and_production_do_not_open_recovery(): void
    {
        config(['internal_api_slice.login_enabled' => true, 'internal_api_slice.register_enabled' => true,
            'internal_api_slice.email_code_enabled' => true, 'internal_api_slice.public_read_enabled' => true]);
        foreach (['internal_api_slice.password_reset_enabled', 'internal_api_slice.enabled', 'core_shadow.isolated_copy'] as $key) {
            foreach ([false, 'true', 1] as $disabled) {
                config([$key => $disabled]); $this->send()->assertNotFound();
            }
            config([$key => true]);
        }
        $this->app->detectEnvironment(static fn () => 'production');
        $this->send()->assertNotFound();
        $this->assertUnchanged();
        $this->assertSame(0, DB::table('tx_internal_readonly_nonces')->count());
    }

    public function test_absent_actor_exact_proof_and_replay_do_not_authorize_another_reset(): void
    {
        foreach (['', 'Bearer user', 'Bearer admin', 'Bearer node'] as $authorization) $this->send(authorization: $authorization)->assertUnauthorized();
        $this->send(service: 'admin-gateway')->assertUnauthorized();
        foreach ([['actor_sha256' => hash('sha256', 'Bearer somebody')], ['body_sha256' => hash('sha256', '{}')],
            ['path' => '/txapi/auth/password/forgot'], ['epoch' => 2]] as $claims) $this->send(claims: $claims)->assertUnauthorized();
        $this->assertUnchanged();
        [$path, $body, $server] = $this->requestData($this->input());
        $this->call('POST', $path, [], [], [], $server, $body)->assertOk();
        Cache::put($this->codeKey(), 123456, 300);
        $this->call('POST', $path, [], [], [], $server, $body)->assertStatus(409)->assertJsonPath('error.code', 'SERVICE_REPLAY');
        $this->assertSame(123456, Cache::get($this->codeKey()));
    }

    public function test_closed_shape_native_form_domain_and_duplicate_validation(): void
    {
        foreach ([['password' => null], ['email_code' => 123456], ['email' => []], ['actor_id' => '1'],
            ['invite_code' => 'not-recovery'], ['remote_ip' => '203.0.113.1'], ['password' => str_repeat('x', 1025)],
            ['email_code' => str_repeat('x', 129)], ['turnstile_token' => str_repeat('x', 4097)]] as $patch) {
            $this->send([...$this->input(), ...$patch])->assertStatus(400);
        }
        $this->send(['email' => self::EMAIL, 'password' => self::PASSWORD])->assertStatus(400);
        $this->send(version: '2')->assertStatus(400);
        $raw = json_encode($this->input());
        $this->send(rawInput: substr($raw, 0, -1).',"\\u0065mail_code":"different"}')->assertStatus(400);
        foreach ([['password' => 'short'], ['email_code' => '12345'], ['email' => 'bad']] as $patch) {
            $this->send([...$this->input(), ...$patch])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        }
        $this->send([...$this->input(), 'email' => 'owner@sub.example.test'])->assertStatus(400)->assertJsonPath('error.code', 'RECIPIENT_NOT_ALLOWED');
        $this->assertUnchanged();
    }

    public function test_native_and_internal_share_throttle_service_limit_remains_separate(): void
    {
        for ($i = 0; $i < 9; $i++) $this->postJson('/txapi/auth/password/forgot', ['email' => 'bad'])->assertStatus(422);
        $this->send([...$this->input(), 'password' => 'short'])->assertStatus(422);
        $this->send()->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
        config(['internal_api_slice.requests_per_minute' => 1]);
        $this->send()->assertStatus(429)->assertJsonPath('error.code', 'SERVICE_RATE_LIMITED');
        $this->assertUnchanged();
    }

    public function test_captcha_known_enabled_unknown_or_sql_cache_drift_never_calls_provider(): void
    {
        admin_setting(['captcha_enable' => 1, 'captcha_type' => 'turnstile']);
        $this->send()->assertStatus(400)->assertJsonPath('error.code', 'CAPTCHA_INVALID');
        admin_setting(['captcha_enable' => 'unknown']);
        $this->send()->assertStatus(503)->assertJsonPath('error.code', 'DEPENDENCY_UNAVAILABLE');
        admin_setting(['captcha_enable' => 0]); admin_setting('captcha_enable');
        \App\Models\Setting::query()->where('name', 'captcha_enable')->update(['value' => '1']);
        $this->send()->assertStatus(503);
        $this->assertUnchanged();
        Http::assertNothingSent();
    }

    public function test_native_reset_still_runs_without_any_internal_flag(): void
    {
        config(['internal_api_slice.enabled' => false, 'internal_api_slice.password_reset_enabled' => false, 'core_shadow.isolated_copy' => false]);
        $this->send()->assertNotFound();
        $this->postJson('/txapi/auth/password/forgot', $this->input())->assertOk()->assertJsonPath('data', ['ok' => true]);
        $this->assertTrue(Hash::check(self::PASSWORD, $this->user->fresh()->password));
        $this->assertSame(0, $this->user->tokens()->count());
        $this->assertSame(0, DB::table('tx_internal_readonly_audits')->count());
    }

    public function test_false_native_save_keeps_password_sessions_and_code(): void
    {
        User::saving(fn (User $user) => $user->id === $this->user->id ? false : null);
        $this->send()->assertStatus(400)->assertJsonPath('error.code', 'RESET_REJECTED');
        $this->assertUnchanged();
    }

    public function test_token_deletion_failure_preserves_new_password_and_old_sessions_and_code(): void
    {
        DB::unprepared("CREATE TRIGGER reject_reset_session_delete BEFORE DELETE ON personal_access_tokens BEGIN SELECT RAISE(ABORT, 'private-delete-error'); END");
        try { $response = $this->send()->assertStatus(500)->assertJsonMissingPath('data'); }
        finally { DB::unprepared('DROP TRIGGER reject_reset_session_delete'); }
        $this->assertTrue(Hash::check(self::PASSWORD, $this->user->fresh()->password));
        $this->assertSame(3, $this->user->tokens()->count());
        $this->assertSame(123456, Cache::get($this->codeKey()));
        $this->assertStringNotContainsString('private-delete-error', $response->getContent());
    }

    public function test_after_hook_failure_has_native_partial_state_safe_diagnostics_and_request_restoration(): void
    {
        Log::spy();
        HookManager::register('user.password.reset.after', static function (User $user): void {
            throw new \RuntimeException('private-password-reset-error '.request()->input('password').' '.request()->input('email_code'));
        });
        $response = $this->send()->assertStatus(500)->assertJsonMissingPath('data');
        $this->assertTrue(Hash::check(self::PASSWORD, $this->user->fresh()->password));
        $this->assertSame(0, $this->user->tokens()->count());
        $this->assertSame(123456, Cache::get($this->codeKey()));
        $this->assertSame('/txapi/internal/v1/operations/auth.password.reset', request()->getPathInfo());
        $this->assertSame(app('request'), RequestFacade::getFacadeRoot());
        Log::shouldNotHaveReceived('error');
        foreach ([self::PASSWORD, '123456', 'private-password-reset-error'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
            $this->assertStringNotContainsString($secret, DB::table('tx_internal_readonly_audits')->get()->toJson());
        }
        $this->app->instance('hook.actions', $this->initialActions);
        $this->send([...$this->input(), 'password' => 'second-deliberate-password'])->assertOk();
        $this->assertTrue(Hash::check('second-deliberate-password', $this->user->fresh()->password));
        $this->assertNull(Cache::get($this->codeKey()));
    }

    public function test_audit_failure_hides_completed_password_reset(): void
    {
        DB::unprepared("CREATE TRIGGER reject_password_reset_audit BEFORE INSERT ON tx_internal_readonly_audits BEGIN SELECT RAISE(ABORT, 'private-audit-error'); END");
        try { $this->send()->assertStatus(503)->assertJsonPath('error.code', 'DEPENDENCY_UNAVAILABLE'); }
        finally { DB::unprepared('DROP TRIGGER reject_password_reset_audit'); }
        $this->assertTrue(Hash::check(self::PASSWORD, $this->user->fresh()->password));
        $this->assertSame(0, $this->user->tokens()->count());
        $this->assertNull(Cache::get($this->codeKey()));
        $this->send()->assertStatus(400)->assertJsonPath('error.code', 'RESET_REJECTED');
    }

    public function test_signed_deadline_can_hide_completed_reset(): void
    {
        $deadline = time()+5; $entered = false;
        HookManager::register('user.password.reset.after', static function () use ($deadline, &$entered): void {
            $entered = true; while (time() < $deadline) usleep(50_000);
        });
        $this->send(deadlineAt: $deadline)->assertStatus(504)->assertJsonPath('error.code', 'DEADLINE_EXCEEDED');
        $this->assertTrue($entered);
        $this->assertTrue(Hash::check(self::PASSWORD, $this->user->fresh()->password));
        $this->assertSame(0, $this->user->tokens()->count());
        $this->assertNull(Cache::get($this->codeKey()));
    }

    private function assertUnchanged(): void
    {
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $this->user->fresh()->password));
        $this->assertSame(3, $this->user->tokens()->count());
        $this->assertSame(123456, Cache::get($this->codeKey()));
    }

    public function test_false_code_delete_keeps_native_success_with_reusable_code(): void
    {
        $store = $this->faultStore('false');
        $this->send()->assertOk()->assertJsonPath('data', ['ok' => true]);
        $this->assertTrue(Hash::check(self::PASSWORD, $this->user->fresh()->password));
        $this->assertSame(0, $this->user->tokens()->count());
        $this->assertSame(123456, $store->get($this->codeKey()));
    }

    public function test_code_delete_exception_hides_updated_password_and_revoked_sessions_without_secret_log(): void
    {
        Log::spy();
        $store = $this->faultStore('throw');
        $response = $this->send()->assertStatus(500)->assertJsonMissingPath('data');
        $this->assertTrue(Hash::check(self::PASSWORD, $this->user->fresh()->password));
        $this->assertSame(0, $this->user->tokens()->count());
        $this->assertSame(123456, $store->get($this->codeKey()));
        $this->assertStringNotContainsString('private-cache-delete', $response->getContent());
        Log::shouldNotHaveReceived('error');
    }

    private function faultStore(string $mode): InternalPasswordResetFaultStore
    {
        $store = new InternalPasswordResetFaultStore($mode);
        config(['cache.stores.reset_fixture' => ['driver' => 'reset-fixture'], 'cache.default' => 'reset_fixture']);
        Cache::extend('reset-fixture', fn ($app, array $config) => Cache::repository($store, $config));
        $store->put($this->codeKey(), 123456, 300);
        return $store;
    }

    private function input(): array { return ['email' => self::EMAIL, 'password' => self::PASSWORD, 'email_code' => '123456']; }

    private function codeKey(): string { return CacheKey::get('EMAIL_VERIFY_CODE', self::EMAIL); }

    private function send(?array $input = null, string $operation = 'auth.password.reset', string $version = '1',
        ?string $authorization = null, array $claims = [], ?string $rawInput = null, string $service = 'user-gateway', ?int $deadlineAt = null)
    {
        [$path, $body, $server] = $this->requestData($input ?? $this->input(), $operation, $version,
            $authorization, $claims, $rawInput, $service, $deadlineAt);
        return $this->call('POST', $path, [], [], [], $server, $body);
    }

    private function requestData(array $input, string $operation = 'auth.password.reset', string $version = '1',
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

final class InternalPasswordResetFaultStore extends ArrayStore
{
    public function __construct(private string $mode) { parent::__construct(); }

    public function forget($key)
    {
        if (str_starts_with($key, 'EMAIL_VERIFY_CODE_')) {
            if ($this->mode === 'false') return false;
            throw new \RuntimeException('private-cache-delete '.$key.' 123456');
        }
        return parent::forget($key);
    }
}
