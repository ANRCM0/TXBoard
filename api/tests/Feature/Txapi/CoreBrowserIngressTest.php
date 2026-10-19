<?php

namespace Tests\Feature\Txapi;

use App\Models\Setting;
use App\Domains\Billing\OrderIdempotency;
use App\Services\Auth\NativeIdentityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Database\Events\QueryExecuted;
use Tests\Support\NativeRuntimeTestCase;

final class CoreBrowserIngressTest extends NativeRuntimeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->activate();
        config(['browser.enabled' => true, 'browser.user_enabled' => true, 'browser.admin_enabled' => true,
            'browser.commerce_enabled' => true, 'browser.origin' => 'https://core.example.test', 'browser.rate_store' => 'array']);
        admin_setting(['secure_path' => 'browser_admin', 'captcha_enable' => 0, 'password_limit_enable' => 0]);
    }

    public function test_capabilities_are_compiled_public_metadata_and_disable_gates_match_dispatch_without_service_credentials(): void
    {
        $response = $this->getJson('https://core.example.test/gateway/v1/core/admin/capabilities')->assertOk()
            ->assertJsonPath('ok', true)->assertJsonPath('data.contract', 'core-admin/v1');
        $this->assertContains('settings.configuration.update', array_column($response->json('data.operations'), 'id'));
        $this->assertStringNotContainsString('credential', $response->getContent());
        $this->assertSame(0, DB::table('tx_internal_service')->count());
        $this->assertDatabaseHas('tx_internal_audit', ['service_id' => 'browser', 'service_epoch' => 0, 'state' => 'completed']);
        config(['browser.commerce_enabled' => false]);
        $operations = $this->getJson('https://core.example.test/gateway/v1/core/user/capabilities')->assertOk()->json('data.operations');
        $this->assertNotContains('commerce.orders.create', array_column($operations, 'id'));
        $this->operation('user', 'commerce.orders.create', [])->assertNotFound();
        config(['browser.admin_enabled' => false]);
        $this->getJson('https://core.example.test/gateway/v1/core/admin/capabilities')->assertOk()->assertJsonPath('data.operations', []);
        $this->operation('admin', 'settings.configuration.get', [])->assertNotFound();
    }

    public function test_strict_browser_envelope_rejects_duplicate_keys_arrays_queries_unsafe_headers_and_cross_origin(): void
    {
        foreach (['{"input":{},"input":{}}', '{"input":[],"extra":true}', '{"input":{"page":1,"p\\u0061ge":2}}', '[]'] as $body) {
            $this->raw('catalog.offers.list', $body)->assertStatus(400)->assertJsonPath('ok', false);
        }
        $this->raw('catalog.offers.list', '{"input":{},"extra":true}')->assertStatus(422);
        $this->operation('user', 'catalog.offers.list', [], ['Origin' => 'https://evil.example.test'])->assertForbidden();
        $this->operation('user', 'catalog.offers.list', [], ['Origin' => 'null'])->assertForbidden();
        $this->operation('user', 'catalog.offers.list', [], ['X-Core-Tls-Verified' => 'SUCCESS'])->assertForbidden();
        $this->operation('user', 'catalog.offers.list', [], ['X-Core-Service-Id' => 'fake-service'])->assertForbidden();
        $this->postJson('https://core.example.test/gateway/v1/core/user/operations/catalog.offers.list?page=1', ['input' => (object) []])->assertStatus(400);
        config(['browser.max_request_bytes' => 32]);
        $this->raw('catalog.offers.list', '{"input":{"unexpected":"'.str_repeat('x', 80).'"}}')->assertStatus(413);
        $this->assertSame(0, DB::table('tx_internal_service')->count());
    }

    public function test_original_actor_grants_and_admin_path_are_rechecked_before_receipt_replay_and_dirty_settings_keep_secrets(): void
    {
        [, $actor] = app(NativeIdentityService::class)->create('browser-admin@example.test', 'admin-password');
        $actor->forceFill(['is_admin' => true])->saveOrFail();
        foreach (['settings.read', 'settings.write'] as $permission) DB::table('tx_internal_actor_grant')->insert([
            'user_id' => $actor->id, 'permission' => $permission, 'created_at' => time()]);
        $headers = ['Authorization' => 'Bearer '.$actor->createToken('browser', ['*'])->plainTextToken, 'X-Core-Admin-Path' => 'browser_admin'];
        Setting::createOrUpdate('email_password', 'never-reflect-browser-secret');
        $snapshot = $this->operation('admin', 'settings.configuration.get', [], $headers)->assertOk()->assertJsonPath('ok', true);
        $this->assertStringNotContainsString('never-reflect-browser-secret', $snapshot->getContent());
        $key = (string) Str::uuid();
        $input = ['expected_version' => $snapshot->json('data.version'), 'values' => ['app_name' => 'Browser update']];
        $this->operation('admin', 'settings.configuration.update', $input, $headers + ['Idempotency-Key' => $key])->assertOk();
        $this->operation('admin', 'settings.configuration.update', $input, $headers + ['Idempotency-Key' => $key])->assertOk();
        $this->assertSame('Browser update', Setting::where('name', 'app_name')->firstOrFail()->value);
        $this->assertSame('never-reflect-browser-secret', Setting::where('name', 'email_password')->firstOrFail()->value);
        $this->operation('admin', 'settings.configuration.get', [], array_replace($headers, ['X-Core-Admin-Path' => 'wrong-path']))->assertNotFound();
        DB::table('tx_internal_actor_grant')->where('user_id', $actor->id)->where('permission', 'settings.write')->delete();
        $this->operation('admin', 'settings.configuration.update', $input, $headers + ['Idempotency-Key' => $key])->assertForbidden();
        $this->assertDatabaseHas('tx_internal_audit', ['service_id' => 'browser', 'actor_id' => $actor->id, 'operation' => 'settings.configuration.update', 'http_status' => 403]);
        $this->assertSame(0, DB::table('tx_internal_service')->count());
    }

    public function test_bootstrap_only_projects_public_captcha_and_cookie_only_requests_never_authenticate(): void
    {
        admin_setting(['captcha_enable' => 1, 'captcha_type' => 'turnstile', 'turnstile_site_key' => 'public-site-key',
            'turnstile_secret_key' => 'private-captcha-secret', 'email_password' => 'private-mail-secret']);
        $bootstrap = $this->getJson('https://core.example.test/gateway/v1/bootstrap')->assertOk()
            ->assertJsonPath('data.security.captcha', ['enabled' => true, 'type' => 'turnstile', 'siteKey' => 'public-site-key']);
        $this->assertStringNotContainsString('private-captcha-secret', $bootstrap->getContent());
        $this->assertStringNotContainsString('private-mail-secret', $bootstrap->getContent());
        $this->operation('user', 'identity.account.get', [], ['Cookie' => 'laravel_session=forged'])->assertUnauthorized();
        $this->operation('user', 'identity.session.create', ['email' => 'unknown@example.test', 'password' => 'bad-password'],
            ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(428)->assertJsonPath('error.code', 'CAPTCHA_REQUIRED');
    }

    public function test_login_limiter_uses_account_and_socket_ip_and_does_not_trust_forwarded_identity(): void
    {
        $input = ['email' => 'limited@example.test', 'password' => 'bad-password'];
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $this->operation('user', 'identity.session.create', $input, ['Idempotency-Key' => (string) Str::uuid()])->assertUnauthorized();
        }
        $this->operation('user', 'identity.session.create', $input,
            ['Idempotency-Key' => (string) Str::uuid(), 'X-Forwarded-For' => '198.51.100.99'])->assertStatus(429);
    }

    public function test_purchase_original_key_recovery_keeps_one_owner_record_and_current_session_checks_precede_replay(): void
    {
        [, $owner] = app(NativeIdentityService::class)->create('browser-owner@example.test', 'owner-password');
        [, $other] = app(NativeIdentityService::class)->create('browser-other@example.test', 'other-password');
        $ownerToken = $owner->createToken('browser-owner', ['*']);
        $headers = ['Authorization' => 'Bearer '.$ownerToken->plainTextToken];
        $otherHeaders = ['Authorization' => 'Bearer '.$other->createToken('browser-other', ['*'])->plainTextToken];
        $key = (string) Str::uuid();
        $input = ['offer_id' => (string) $this->offers[0]['offer_id']];
        $first = $this->operation('user', 'commerce.orders.create', $input, $headers + ['Idempotency-Key' => $key])->assertOk();
        $recovered = $this->operation('user', 'commerce.orders.create', $input, $headers + ['Idempotency-Key' => $key])->assertOk();
        $this->assertSame($first->json('data'), $recovered->json('data'));
        $this->assertSame(1, DB::table('tx_order')->where('user_id', $owner->id)->count());
        $trade = $first->json('data.trade_no');
        $this->operation('user', 'commerce.orders.get', ['trade_no' => $trade], $otherHeaders)->assertNotFound();
        $this->operation('user', 'commerce.orders.get', ['trade_no' => $trade], $headers)->assertOk();
        $this->assertNotSame($first->json('meta.requestId'), $recovered->json('meta.requestId'));
        $this->assertSame($recovered->json('meta.requestId'), $recovered->json('meta.coreRequestId'));
        $this->assertStringContainsString('no-store', (string) $recovered->headers->get('Cache-Control'));
        $ownerToken->accessToken->delete();
        $this->operation('user', 'commerce.orders.create', $input, $headers + ['Idempotency-Key' => $key])->assertUnauthorized();
        $this->assertSame(1, DB::table('tx_order')->where('user_id', $owner->id)->count());
        $this->assertSame(0, DB::table('tx_internal_service')->count());
    }

    public function test_browser_credentials_cannot_enter_internal_mtls_boundary_and_response_budget_fails_closed(): void
    {
        config(['internal_api.enabled' => true, 'internal_api.trusted_tls_proxies' => ['127.0.0.1']]);
        $this->postJson('https://core.example.test/txapi/internal/native/v1/user/operations/system.capabilities.get', [
            'contract_version' => '1', 'request_id' => (string) Str::uuid(), 'deadline_at' => time() + 15, 'input' => (object) [],
        ])->assertForbidden()->assertJsonPath('error.code', 'SERVICE_UNAUTHORIZED');
        config(['browser.max_response_bytes' => 64]);
        $this->getJson('https://core.example.test/gateway/v1/core/user/capabilities')->assertStatus(502)
            ->assertJsonPath('error.code', 'UPSTREAM_ERROR')->assertJsonMissingPath('data');
    }

    public function test_only_exact_trusted_proxy_can_supply_a_single_canonical_browser_client_ip(): void
    {
        config(['browser.trusted_proxies' => ['127.0.0.1']]);
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
        foreach (['198.51.100.1,198.51.100.2', 'not-an-ip', '2001:0db8::1'] as $ip) {
            $this->operation('user', 'catalog.offers.list', [], ['X-Core-Browser-Client-IP' => $ip])->assertStatus(400);
        }
        $this->operation('user', 'catalog.offers.list', [], ['X-Core-Browser-Client-IP' => '2001:db8::1'])->assertOk();
        // An untrusted socket cannot split its source budget by rotating client-controlled headers.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.17']);
        for ($attempt = 1; $attempt <= 30; $attempt++) {
            $this->operation('user', 'identity.session.create', ['email' => 'proxy-'.$attempt.'@example.test', 'password' => 'bad-password'],
                ['Idempotency-Key' => (string) Str::uuid(), 'X-Core-Browser-Client-IP' => '203.0.113.'.$attempt])->assertUnauthorized();
        }
        $this->operation('user', 'identity.session.create', ['email' => 'proxy-31@example.test', 'password' => 'bad-password'],
            ['Idempotency-Key' => (string) Str::uuid(), 'X-Core-Browser-Client-IP' => '203.0.113.31'])->assertStatus(429);
        $this->assertSame(0, DB::table('tx_internal_service')->count());
    }

    public function test_browser_origin_admission_checks_raw_host_instead_of_forwarded_host(): void
    {
        $this->operation('user', 'catalog.offers.list', [], ['Host' => 'evil.example.test', 'X-Forwarded-Host' => 'core.example.test'])->assertForbidden();
        $this->operation('user', 'catalog.offers.list', [], ['Sec-Fetch-Site' => 'cross-site'])->assertForbidden();
        config(['browser.origin' => 'http://core.example.test']);
        $this->getJson('https://core.example.test/gateway/v1/bootstrap')->assertStatus(503)->assertJsonMissingPath('data');
    }

    public function test_ingress_limit_rejects_before_audit_insertion_and_bootstrap_requires_an_empty_body(): void
    {
        config(['browser.ingress_per_minute' => 2]);
        $baseline = DB::table('tx_internal_audit')->count();
        $this->getJson('https://core.example.test/gateway/v1/bootstrap')->assertOk();
        $this->getJson('https://core.example.test/gateway/v1/core/user/capabilities')->assertOk();
        $this->assertSame($baseline + 2, DB::table('tx_internal_audit')->count());
        $this->getJson('https://core.example.test/gateway/v1/bootstrap')->assertStatus(429)->assertHeader('Retry-After', '60');
        $this->assertSame($baseline + 2, DB::table('tx_internal_audit')->count());
        $this->call('GET', 'https://core.example.test/gateway/v1/bootstrap', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertStatus(400);
        $this->operation('user', 'catalog.offers.list', [], ['Origin' => 'https://evil.example.test'])->assertForbidden();
        $this->assertSame($baseline + 2, DB::table('tx_internal_audit')->count());
    }

    public function test_unknown_browser_routes_and_wrong_methods_return_safe_json_envelopes(): void
    {
        $this->getJson('https://core.example.test/gateway/v1/unknown')->assertNotFound()->assertJsonPath('ok', false)->assertJsonMissingPath('exception');
        $this->getJson('https://core.example.test/gateway/v1/core/user/operations/catalog.offers.list')->assertStatus(405)->assertJsonPath('ok', false);
        $this->postJson('https://core.example.test/gateway/v1/bootstrap', [])->assertStatus(405)->assertJsonPath('ok', false);
    }

    public function test_audit_completion_failure_keeps_business_receipt_recoverable_with_the_same_original_key(): void
    {
        [, $actor] = app(NativeIdentityService::class)->create('audit-recovery@example.test', 'admin-password');
        $actor->forceFill(['is_admin' => true])->saveOrFail();
        foreach (['settings.read', 'settings.write'] as $permission) DB::table('tx_internal_actor_grant')->insert([
            'user_id' => $actor->id, 'permission' => $permission, 'created_at' => time()]);
        $headers = ['Authorization' => 'Bearer '.$actor->createToken('audit-recovery', ['*'])->plainTextToken, 'X-Core-Admin-Path' => 'browser_admin'];
        $version = $this->operation('admin', 'settings.configuration.get', [], $headers)->assertOk()->json('data.version');
        $input = ['expected_version' => $version, 'values' => ['app_name' => 'Committed before response loss']];
        $key = (string) Str::uuid();
        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$armed): void {
            if ($armed && str_contains($query->sql, 'tx_internal_audit') && str_contains($query->sql, 'completed_at')
                && in_array('completed', $query->bindings, true)) {
                $armed = false;
                throw new \RuntimeException('private-audit-error-must-not-escape');
            }
        });
        $failed = $this->operation('admin', 'settings.configuration.update', $input, $headers + ['Idempotency-Key' => $key])
            ->assertStatus(503)->assertJsonPath('error.code', 'UPSTREAM_UNAVAILABLE');
        $this->assertFalse($armed);
        $this->assertStringNotContainsString('private-audit-error-must-not-escape', $failed->getContent());
        $this->assertSame('Committed before response loss', Setting::where('name', 'app_name')->firstOrFail()->value);
        $this->operation('admin', 'settings.configuration.update', $input, $headers + ['Idempotency-Key' => $key])->assertOk();
        $this->operation('admin', 'settings.configuration.update', ['expected_version' => $version,
            'values' => ['app_name' => 'Stale overwrite']], $headers + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(409)->assertJsonPath('error.code', 'BUSINESS_REJECTED');
        $this->assertSame('Committed before response loss', Setting::where('name', 'app_name')->firstOrFail()->value);
    }

    public function test_lost_logout_response_keeps_the_token_revoked_and_retry_requires_a_new_session(): void
    {
        [, $actor] = app(NativeIdentityService::class)->create('logout-recovery@example.test', 'user-password');
        $token = $actor->createToken('logout-recovery', ['*']);
        $headers = ['Authorization' => 'Bearer '.$token->plainTextToken, 'Idempotency-Key' => (string) Str::uuid()];
        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$armed): void {
            if ($armed && str_contains($query->sql, 'tx_internal_audit') && str_contains($query->sql, 'completed_at')
                && in_array('completed', $query->bindings, true)) { $armed = false; throw new \RuntimeException('lost logout response'); }
        });
        $this->operation('user', 'identity.session.end', [], $headers)->assertStatus(503);
        $this->assertFalse($armed);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
        $this->operation('user', 'identity.session.end', [], $headers)->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHORIZED');
    }

    public function test_unknown_workflow_receipt_preserves_retry_after_without_reexecuting_a_write(): void
    {
        [, $actor] = app(NativeIdentityService::class)->create('pending-browser@example.test', 'admin-password');
        $actor->forceFill(['is_admin' => true])->saveOrFail();
        DB::table('tx_internal_actor_grant')->insert(['user_id' => $actor->id, 'permission' => 'settings.write', 'created_at' => time()]);
        $key = (string) Str::uuid();
        $input = ['app_name' => 'Must not repeat uncertain write'];
        DB::table('tx_internal_request')->insert([
            'key_hash' => hash('sha256', 'native-internal/v1:'.$actor->id.':settings.site.update:'.$key),
            'input_hash' => OrderIdempotency::requestHash($input), 'operation' => 'settings.site.update',
            'actor_id' => $actor->id, 'state' => 'started', 'created_at' => time(), 'updated_at' => time(),
        ]);
        $headers = ['Authorization' => 'Bearer '.$actor->createToken('pending-browser', ['*'])->plainTextToken,
            'X-Core-Admin-Path' => 'browser_admin', 'Idempotency-Key' => $key];
        $this->operation('admin', 'settings.site.update', $input, $headers)->assertStatus(409)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_IN_PROGRESS')->assertHeader('Retry-After', '2');
        $this->assertNotSame('Must not repeat uncertain write', Setting::where('name', 'app_name')->first()?->value);
        $this->operation('admin', 'settings.site.update', ['app_name' => 'Changed original input'], $headers)->assertStatus(409)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
    }

    private function operation(string $domain, string $operation, array $input, array $headers = [])
    {
        return $this->postJson('https://core.example.test/gateway/v1/core/'.$domain.'/operations/'.$operation,
            ['input' => $input === [] ? (object) [] : $input], $headers + ['Origin' => 'https://core.example.test']);
    }

    private function raw(string $operation, string $body)
    {
        return $this->call('POST', 'https://core.example.test/gateway/v1/core/user/operations/'.$operation,
            [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body);
    }
}
