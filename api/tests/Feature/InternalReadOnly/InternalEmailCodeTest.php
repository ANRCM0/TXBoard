<?php

namespace Tests\Feature\InternalReadOnly;

use App\Http\Requests\Passport\CommSendEmailVerify;
use App\Models\MailLog;
use App\Models\MailTemplate;
use App\Models\User;
use App\Support\Setting;
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

final class InternalEmailCodeTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'email-code-owner@example.test';
    private string $directory;
    private string $private = '';
    private ArrayTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'cache.setting_store' => 'array',
            'queue.default' => 'sync', 'queue.connections.sync.after_commit' => false,
            'mail.driver' => 'array', 'mail.from.address' => 'fixture-sender@example.test',
            'mail.username' => 'secret-email-smtp-user', 'mail.password' => 'secret-email-smtp-password']);
        Cache::flush();
        $this->app->forgetInstance(Setting::class);
        admin_setting(['captcha_enable' => 0, 'email_whitelist_enable' => 0,
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
        config(['internal_api_slice.enabled' => true, 'internal_api_slice.email_code_enabled' => true,
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
        parent::tearDown();
    }

    public function test_actual_sync_job_array_message_code_cooldown_ttl_and_closed_technical_audit(): void
    {
        $jobs = [];
        $this->app['events']->listen(JobProcessing::class, static function (JobProcessing $event) use (&$jobs): void {
            $jobs[] = [$event->connectionName, $event->job->resolveName()];
        });
        $response = $this->send()->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data', ['ok' => true])
            ->assertJsonPath('meta', ['current_only' => true, 'slice' => 'internal-isolated-email-code/1']);
        $this->assertSame([['sync', \App\Jobs\SendEmailJob::class]], $jobs);
        $this->assertCount(1, $this->transport->messages());
        $code = Cache::get($this->codeKey());
        $this->assertIsInt($code);
        $this->assertGreaterThanOrEqual(100000, $code);
        $this->assertLessThanOrEqual(999999, $code);
        $message = $this->transport->messages()->sole()->getOriginalMessage();
        $this->assertSame(self::EMAIL, $message->getTo()[0]->getAddress());
        $this->assertStringContainsString((string) $code, $message->getHtmlBody());
        $this->assertStringContainsString('Local Email Code Fixture', $message->getHtmlBody());
        $this->assertNull(MailLog::sole()->error);
        $this->assertSame('mail.default.verify', MailLog::sole()->template_name);
        $items = Cache::getStore()->all();
        $now = microtime(true);
        $this->assertEqualsWithDelta($now+300, $items[$this->codeKey()]['expiresAt'], 3);
        $this->assertEqualsWithDelta($now+60, $items[$this->cooldownKey()]['expiresAt'], 3);
        $audit = DB::table('tx_internal_readonly_audits')->sole();
        $this->assertSame(0, (int) $audit->actor_id);
        $this->assertSame('auth.email-code.send', $audit->operation);
        $this->assertSame('user-gateway', $audit->service);
        $this->assertSame(200, (int) $audit->status);
        foreach ([self::EMAIL, (string) $code, 'secret-email-smtp-user', 'secret-email-smtp-password'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
            $this->assertStringNotContainsString($secret, json_encode($audit));
        }
        $this->assertSame(0, User::count());
        $this->assertSame(0, PersonalAccessToken::count());
        $this->send()->assertStatus(400)->assertJsonPath('error.code', 'EMAIL_CODE_REJECTED');
        $this->assertCount(1, $this->transport->messages());
        $this->assertSame($code, Cache::get($this->codeKey()));
        $this->travel(61)->seconds();
        $this->assertNull(Cache::get($this->cooldownKey()));
        $this->send()->assertOk();
        $this->assertCount(2, $this->transport->messages());
        $this->travel(301)->seconds();
        $this->assertNull(Cache::get($this->codeKey()));
        Http::assertNothingSent();
    }

    public function test_native_form_validation_exact_synthetic_domain_and_signed_shape_bounds(): void
    {
        foreach (['', '   ', 'not-an-email'] as $email) {
            $response = $this->send(['email' => $email])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
            $this->assertSame(['email'], $response->json('error.fields'));
        }
        foreach (['somebody@example.com', 'somebody@sub.example.test', 'somebody@EXAMPLE.TEST'] as $email) {
            $this->send(['email' => $email])->assertStatus(400)->assertJsonPath('error.code', 'RECIPIENT_NOT_ALLOWED');
        }
        foreach ([[], ['email' => null], ['email' => 7], ['email' => []], ['email' => str_repeat('x', 255)],
            ['email' => self::EMAIL, 'code' => '123456'], ['email' => self::EMAIL, 'remote_ip' => '203.0.113.7'],
            ['email' => self::EMAIL, 'turnstile_token' => str_repeat('x', 4097)]] as $input) {
            $this->send($input)->assertStatus(400);
        }
        $this->send(version: '2')->assertStatus(400);
        $this->send(rawInput: '{"email":"'.self::EMAIL.'","\\u0065mail":"other@example.test"}')->assertStatus(400);
        $this->send(rawInput: '[]')->assertStatus(400);
        $this->send(rawInput: '{"email":"'.str_repeat('x', 16384).'"}')->assertStatus(413);
        $this->assertNoDelivery();
    }

    public function test_gate_exact_boolean_environment_and_other_flags_never_enable_email_code(): void
    {
        foreach (['internal_api_slice.email_code_enabled', 'core_shadow.isolated_copy', 'internal_api_slice.enabled'] as $key) {
            foreach ([false, 'false', 'true', 1, null] as $value) {
                config([$key => $value, 'internal_api_slice.login_enabled' => true, 'internal_api_slice.public_read_enabled' => true]);
                $this->send()->assertNotFound();
            }
            config([$key => true]);
        }
        $this->app->detectEnvironment(static fn () => 'production');
        $this->send()->assertNotFound();
        $this->assertSame(0, DB::table('tx_internal_readonly_nonces')->count());
        $this->assertNoDelivery();
    }

    public function test_absent_authorization_empty_actor_and_exact_user_gateway_proof_are_required(): void
    {
        foreach (['', 'Bearer user-token', 'Bearer admin-token', 'invalid'] as $authorization) $this->send(authorization: $authorization)->assertUnauthorized();
        $this->send(service: 'admin-gateway')->assertUnauthorized();
        foreach ([['actor_sha256' => hash('sha256', 'someone')], ['body_sha256' => hash('sha256', '{}')],
            ['path' => '/txapi/internal/v1/operations/auth.login'], ['epoch' => 2], ['method' => 'GET']] as $claims) {
            $this->send(claims: $claims)->assertUnauthorized();
        }
        $this->send(input: [], operation: 'user.profile.get', version: '2')->assertUnauthorized();
        $this->send(operation: 'admin.order.get', input: ['id' => '1', 'admin_path' => 'admin'])->assertUnauthorized();
        $this->send(operation: 'auth.register')->assertStatus(400)->assertJsonPath('error.code', 'UNKNOWN_OPERATION');
        $this->assertSame(0, DB::table('tx_internal_readonly_nonces')->count());
        $this->assertNoDelivery();
    }

    public function test_fresh_native_form_trim_null_no_old_actor_and_outer_request_restoration(): void
    {
        $user = $this->account();
        $token = 'Bearer '.$user->createToken('current-user', ['*'], now()->addHour())->plainTextToken;
        $this->send(operation: 'user.profile.get', version: '2', input: [], authorization: $token)->assertOk();
        Auth::guard('api')->setUser($user);
        $seen = [];
        $this->app['events']->listen(MessageSending::class, static function () use (&$seen): void {
            $seen = [request()::class, request()->getPathInfo(), request()->all(),
                request() === RequestFacade::getFacadeRoot(), Auth::user(), Auth::guard('sanctum')->user(), request()->user()];
        });
        $this->send(['email' => '  '.self::EMAIL.'  ', 'turnstile_token' => '  fixture-captcha  ',
            'recaptcha_data' => '   '])->assertOk();
        $this->assertSame([CommSendEmailVerify::class, '/txapi/auth/email-code', ['email' => self::EMAIL,
            'turnstile_token' => 'fixture-captcha', 'recaptcha_data' => null], true, null, null, null], $seen);
        $this->assertSame('/txapi/internal/v1/operations/auth.email-code.send', request()->getPathInfo());
        $this->assertSame(request(), RequestFacade::getFacadeRoot());
        $this->send(operation: 'user.profile.get', version: '2', input: [], authorization: $token)->assertOk()
            ->assertJsonPath('data.id', (string) $user->id);
        $this->assertSame(1, User::count());
        $this->assertSame(1, PersonalAccessToken::count());
    }

    public function test_native_and_internal_email_code_share_original_ten_attempt_ip_budget(): void
    {
        for ($i = 0; $i < 10; $i++) {
            ($i % 2 === 0 ? $this->postJson('/txapi/auth/email-code', ['email' => 'invalid-email'])
                : $this->send(['email' => 'invalid-email']))->assertStatus(422);
        }
        $response = $this->send()->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
        $this->assertGreaterThanOrEqual(1, (int) $response->headers->get('Retry-After'));
        $this->assertLessThanOrEqual(60, (int) $response->headers->get('Retry-After'));
        $this->postJson('/txapi/auth/email-code', ['email' => self::EMAIL])->assertStatus(429);
        $this->assertNoDelivery();
    }

    public function test_proof_replay_and_service_budget_are_distinct_from_native_cooldown(): void
    {
        [$path, $body, $server] = $this->requestData(['email' => self::EMAIL]);
        $this->call('POST', $path, [], [], [], $server, $body)->assertOk();
        $this->call('POST', $path, [], [], [], $server, $body)->assertStatus(409)->assertJsonPath('error.code', 'SERVICE_REPLAY');
        $this->send()->assertStatus(400)->assertJsonPath('error.code', 'EMAIL_CODE_REJECTED');
        config(['internal_api_slice.requests_per_minute' => 2]);
        $this->send(['email' => 'other@example.test'])->assertStatus(429)->assertJsonPath('error.code', 'SERVICE_RATE_LIMITED');
        $this->assertCount(1, $this->transport->messages());
        $this->assertNull(Cache::get($this->codeKey('other@example.test')));
    }

    public function test_current_or_cached_smtp_and_unknown_mailer_or_transport_refuse_before_issuer(): void
    {
        config(['mail.driver' => 'smtp']);
        $this->send()->assertStatus(503)->assertJsonPath('error.code', 'DEPENDENCY_UNAVAILABLE');
        config(['mail.driver' => 'array']);
        $mailer = Mail::mailer();
        $mailer->setSymfonyTransport(new EsmtpTransport('127.0.0.1', 1, false)); // Never invoked or connected.
        $this->send()->assertStatus(503)->assertJsonPath('error.code', 'DEPENDENCY_UNAVAILABLE');
        $mailer->setSymfonyTransport(new class extends ArrayTransport {});
        $this->send()->assertStatus(503);
        $mailer->setSymfonyTransport($this->transport);
        $manager = Mail::getFacadeRoot();
        Mail::swap(new Mailer('unknown-mailer', $this->app['view'], $this->transport));
        try { $this->send()->assertStatus(503); } finally { Mail::swap($manager); }
        $this->assertNoDelivery();
    }

    public function test_actual_queue_class_and_current_or_cached_after_commit_are_refused(): void
    {
        config(['queue.default' => 'redis']);
        $this->send()->assertStatus(503);
        config(['queue.default' => 'sync', 'queue.connections.sync.after_commit' => true]);
        $this->send()->assertStatus(503);
        config(['queue.connections.sync.after_commit' => false]);
        $queue = Queue::connection('sync');
        $queue->setConfig(['driver' => 'sync', 'after_commit' => true]);
        $this->send()->assertStatus(503);
        $queue->setConfig(['driver' => 'sync', 'after_commit' => false]);
        $manager = Queue::getFacadeRoot();
        Queue::swap(new class {
            public function connection($name) { return new class extends SyncQueue {}; }
        });
        try { $this->send()->assertStatus(503); } finally { Queue::swap($manager); }
        $this->assertNoDelivery();
    }

    public function test_enabled_or_stale_sql_captcha_refuses_without_external_request(): void
    {
        foreach (['turnstile', 'recaptcha-v3', 'recaptcha', 'unknown-provider'] as $type) {
            admin_setting(['captcha_enable' => 1, 'captcha_type' => $type]);
            $this->send(['email' => self::EMAIL, 'turnstile_token' => 'secret-captcha-material'])->assertStatus(400)
                ->assertJsonPath('error.code', 'CAPTCHA_INVALID');
        }
        admin_setting(['captcha_enable' => 0]);
        admin_setting('captcha_enable');
        DB::table('tx_settings')->where('name', 'captcha_enable')->update(['value' => '1']);
        $this->send()->assertStatus(400)->assertJsonPath('error.code', 'CAPTCHA_INVALID');
        $this->assertNoDelivery();
        Http::assertNothingSent();
    }

    public function test_native_whitelist_unregistered_rejection_and_registered_exemption_remain(): void
    {
        admin_setting(['email_whitelist_enable' => 1, 'email_whitelist_suffix' => ['allowed.example.test']]);
        $this->send()->assertStatus(400)->assertJsonPath('error.code', 'EMAIL_CODE_REJECTED');
        $this->assertNoDelivery();
        $this->account();
        $this->send()->assertOk();
        $this->assertCount(1, $this->transport->messages());
        $this->assertSame(1, User::count());
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_unknown_current_sql_captcha_flag_is_dependency_failure_even_with_cached_disabled_policy(): void
    {
        admin_setting('captcha_enable');
        Log::shouldReceive('error')->never();
        foreach (['2', 'unknown-policy-secret', '[]', '{}', 'null', '-1', null] as $value) {
            DB::table('tx_settings')->where('name', 'captcha_enable')->update(['value' => $value]);
            $response = $this->send()->assertStatus(503)->assertJsonPath('error.code', 'DEPENDENCY_UNAVAILABLE');
            $this->assertStringNotContainsString('unknown-policy-secret', $response->getContent());
        }
        $this->assertNoDelivery();
        Http::assertNothingSent();
    }

    public function test_cached_whitelist_flag_suffix_or_nonarray_drift_never_guesses_disabled_policy(): void
    {
        admin_setting('captcha_enable');
        $cache = Cache::store(config('cache.setting_store'));
        $initial = $cache->get(Setting::CACHE_KEY);
        foreach ([null, false, 'invalid-cache', [...$initial, 'email_whitelist_enable' => '1'],
            [...$initial, 'email_whitelist_suffix' => '["other.test"]'], [...$initial, 'captcha_enable' => 'unknown']] as $value) {
            $cache->forever(Setting::CACHE_KEY, $value);
            $this->send()->assertStatus(503)->assertJsonPath('error.code', 'DEPENDENCY_UNAVAILABLE');
        }
        $this->assertNoDelivery();
    }

    public function test_swallowed_preload_settings_outage_then_recovery_cannot_disable_whitelist(): void
    {
        admin_setting(['email_whitelist_enable' => 1, 'email_whitelist_suffix' => ['other.test']]);
        $store = $this->faultStore(settingsOnly: true);
        $store->failGet = true;
        $this->assertNull(admin_setting('app_url')); // Setting.load swallowed the outage into this request's [].
        $this->assertSame(0, (int) admin_setting('email_whitelist_enable', 0));
        $store->failGet = false;
        $store->forever(Setting::CACHE_KEY, ['captcha_enable' => '0', 'email_whitelist_enable' => '1',
            'email_whitelist_suffix' => '["other.test"]']);
        $this->send()->assertStatus(503)->assertJsonPath('error.code', 'DEPENDENCY_UNAVAILABLE');
        $store->failGet = true;
        $this->send()->assertStatus(503)->assertJsonPath('error.code', 'DEPENDENCY_UNAVAILABLE');
        $this->assertNoDelivery();
    }

    public function test_native_false_code_cache_put_can_return_success_after_local_message_without_code(): void
    {
        $store = $this->faultStore();
        $store->codeMode = 'false';
        $this->send()->assertOk()->assertJsonPath('data', ['ok' => true]);
        $this->assertCount(1, $this->transport->messages());
        $this->assertNull(Cache::get($this->codeKey()));
        $this->assertNotNull(Cache::get($this->cooldownKey()));
        $this->assertNull(MailLog::sole()->error);
    }

    public function test_native_cache_exception_after_send_keeps_message_clears_cooldown_and_safe_response(): void
    {
        $oldIni = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        $store = $this->faultStore();
        $store->codeMode = 'throw';
        Log::shouldReceive('error')->never();
        try {
            $response = $this->send()->assertStatus(500)->assertJsonPath('error.code', 'INTERNAL_ERROR');
        } finally { ini_set('zend.exception_ignore_args', $oldIni); }
        $this->assertCount(1, $this->transport->messages());
        $this->assertNull(Cache::get($this->codeKey()));
        $this->assertNull(Cache::get($this->cooldownKey()));
        $message = $this->transport->messages()->sole()->getOriginalMessage();
        preg_match('/\b[1-9][0-9]{5}\b/', $message->getHtmlBody(), $code);
        foreach ([self::EMAIL, $code[0], 'secret-cache-email-error', 'secret-captcha-material'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
            $this->assertStringNotContainsString($secret, DB::table('tx_internal_readonly_audits')->get()->toJson());
        }
        $this->assertSame('/txapi/internal/v1/operations/auth.email-code.send', request()->getPathInfo());
        $this->assertSame(request(), RequestFacade::getFacadeRoot());
    }

    public function test_audit_failure_can_hide_already_emitted_message_and_cached_code(): void
    {
        DB::unprepared(DB::connection()->getDriverName() === 'sqlite'
            ? "CREATE TRIGGER reject_email_code_audit BEFORE INSERT ON tx_internal_readonly_audits BEGIN SELECT RAISE(ABORT, 'fixture-audit-failure'); END"
            : "CREATE TRIGGER reject_email_code_audit BEFORE INSERT ON tx_internal_readonly_audits FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture-audit-failure'");
        try {
            $response = $this->send()->assertStatus(503)->assertJsonPath('error.code', 'DEPENDENCY_UNAVAILABLE')->assertJsonMissingPath('data');
        } finally { DB::unprepared('DROP TRIGGER reject_email_code_audit'); }
        $this->assertCount(1, $this->transport->messages());
        $code = Cache::get($this->codeKey());
        $this->assertIsInt($code);
        $this->assertStringNotContainsString((string) $code, $response->getContent());
        $this->send()->assertStatus(400)->assertJsonPath('error.code', 'EMAIL_CODE_REJECTED');
        $this->assertCount(1, $this->transport->messages());
    }

    public function test_deadline_after_native_side_effect_is_unknown_not_cancellation(): void
    {
        $deadline = time()+5;
        $entered = false;
        $this->app['events']->listen(MessageSending::class, static function () use ($deadline, &$entered): void {
            $entered = true;
            // Cross the actual signed deadline after native sending starts;
            // the operation's body/deadline is never rewritten.
            while (time() < $deadline) {
                usleep(50_000);
            }
        });
        $this->send(deadlineAt: $deadline)->assertStatus(504)->assertJsonPath('error.code', 'DEADLINE_EXCEEDED')->assertJsonMissingPath('data');
        $this->assertTrue($entered);
        $this->assertCount(1, $this->transport->messages());
        $this->assertIsInt(Cache::get($this->codeKey()));
    }

    public function test_caught_mail_send_exception_keeps_native_success_and_code_without_local_message(): void
    {
        $oldIni = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        $this->app['events']->listen(MessageSending::class, static function (MessageSending $event): void {
            throw new \RuntimeException('secret-email-send-failure '.$event->data['code'], 0,
                new \RuntimeException('secret-email-smtp-password '.self::EMAIL));
        });
        Log::shouldReceive('error')->once()->with('MAIL_SEND_FAILED');
        try {
            $response = $this->send()->assertOk()->assertJsonPath('data', ['ok' => true]);
        } finally { ini_set('zend.exception_ignore_args', $oldIni); }
        $this->assertCount(0, $this->transport->messages());
        $code = Cache::get($this->codeKey());
        $this->assertIsInt($code);
        $this->assertSame('MAIL_SEND_FAILED', MailLog::sole()->error);
        foreach ([(string) $code, self::EMAIL, 'secret-email-send-failure', 'secret-email-smtp-password'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
            $this->assertStringNotContainsString($secret, DB::table('tx_internal_readonly_audits')->get()->toJson());
        }
    }

    private function assertNoDelivery(): void
    {
        $this->assertCount(0, $this->transport->messages());
        $this->assertSame(0, MailLog::count());
        $this->assertNull(Cache::get($this->codeKey()));
        $this->assertNull(Cache::get($this->cooldownKey()));
    }

    private function account(): User
    {
        return User::create(['email' => self::EMAIL, 'password' => Hash::make('fixture-password'),
            'uuid' => (string) Str::uuid(), 'token' => 'private-email-owner-token', 'banned' => false]);
    }

    private function codeKey(string $email = self::EMAIL): string { return CacheKey::get('EMAIL_VERIFY_CODE', $email); }
    private function cooldownKey(): string { return CacheKey::get('LAST_SEND_EMAIL_VERIFY_TIMESTAMP', self::EMAIL); }

    private function faultStore(bool $settingsOnly = false): InternalEmailCodeFaultStore
    {
        $store = new InternalEmailCodeFaultStore;
        config(['cache.stores.email_code_fixture' => ['driver' => 'email-code-fixture']]);
        Cache::extend('email-code-fixture', function ($app, array $config) use ($store) {
            return Cache::repository($store, $config);
        });
        config([$settingsOnly ? 'cache.setting_store' : 'cache.default' => 'email_code_fixture']);
        if ($settingsOnly) $this->app->forgetInstance(Setting::class);
        return $store;
    }

    private function send(?array $input = null, string $operation = 'auth.email-code.send', string $version = '1',
        ?string $authorization = null, array $claims = [], ?string $rawInput = null, string $service = 'user-gateway', ?int $deadlineAt = null)
    {
        [$path, $body, $server] = $this->requestData($input ?? ['email' => self::EMAIL], $operation, $version,
            $authorization, $claims, $rawInput, $service, $deadlineAt);
        return $this->call('POST', $path, [], [], [], $server, $body);
    }

    private function requestData(array $input, string $operation = 'auth.email-code.send', string $version = '1',
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

final class InternalEmailCodeFaultStore extends ArrayStore
{
    public bool $failGet = false;
    public string $codeMode = '';

    public function get($key)
    {
        if ($this->failGet) throw new \RuntimeException('secret-settings-cache-unavailable');
        return parent::get($key);
    }

    public function put($key, $value, $seconds)
    {
        if (str_starts_with($key, 'EMAIL_VERIFY_CODE_')) {
            if ($this->codeMode === 'false') return false;
            if ($this->codeMode === 'throw') {
                throw new \RuntimeException('secret-cache-email-error '.$key.' '.$value, 0,
                    new \RuntimeException('secret-captcha-material'));
            }
        }
        return parent::put($key, $value, $seconds);
    }
}
