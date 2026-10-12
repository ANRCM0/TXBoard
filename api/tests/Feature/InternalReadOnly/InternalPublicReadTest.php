<?php

namespace Tests\Feature\InternalReadOnly;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\Plugin\HookManager;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

final class InternalPublicReadTest extends TestCase
{
    use RefreshDatabase;

    private const SITE_FIELDS = ['app_name', 'app_description', 'app_url', 'logo', 'frontend_theme',
        'theme_config', 'is_captcha', 'captcha_type', 'turnstile_site_key', 'recaptcha_site_key', 'recaptcha_v3_site_key'];

    private string $directory;
    private string $themeDirectory;
    private string $private = '';
    private array $initialFilters;
    private User $user;
    private User $admin;
    private string $userToken;
    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initialFilters = HookManager::getFilters();
        $suffix = bin2hex(random_bytes(8));
        $this->directory = sys_get_temp_dir().'/txboard-public-read-'.$suffix;
        mkdir($this->directory, 0700);
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        openssl_pkey_export($key, $this->private);
        file_put_contents($this->directory.'/proof.pub', openssl_pkey_get_details($key)['key']);
        $keys = $services = [];
        foreach (['user' => 'user-gateway', 'admin' => 'admin-gateway'] as $name => $service) {
            $keys[$name.'-1'] = ['epoch' => 1, 'service' => $service, 'revoked' => false,
                'not_before' => time()-60, 'not_after' => time()+3600, 'public_key_path' => $this->directory.'/proof.pub'];
            $services[$service] = ['certificates' => [str_repeat('a', 64) => ['revoked' => false,
                'not_before' => time()-60, 'not_after' => time()+3600]]];
        }
        file_put_contents($this->directory.'/trust.json', json_encode(['proof_keys' => $keys, 'services' => $services], JSON_THROW_ON_ERROR));
        config(['internal_api_slice.enabled' => true, 'internal_api_slice.public_read_enabled' => true,
            'internal_api_slice.order_write_enabled' => false, 'core_shadow.isolated_copy' => false,
            'internal_api_slice.trust_file' => $this->directory.'/trust.json']);
        $this->user = $this->account('public-owner@example.test');
        $this->admin = $this->account('public-admin@example.test', ['is_admin' => true]);
        $this->userToken = 'Bearer '.$this->user->createToken('public-real-user', ['*'], now()->addHour())->plainTextToken;
        $this->adminToken = 'Bearer '.$this->admin->createToken('public-real-admin', ['*'], now()->addHour())->plainTextToken;

        // Exercise the actual legacy theme public-field projection, without installing a package.
        $theme = 'public-read-'.$suffix;
        $this->themeDirectory = base_path('storage/theme/'.$theme);
        mkdir($this->themeDirectory, 0700, true);
        file_put_contents($this->themeDirectory.'/config.json', json_encode(['configs' => [
            ['field_name' => 'accent', 'field_type' => 'text', 'public' => true],
            ['field_name' => 'private_display', 'field_type' => 'text', 'public' => false],
            ['field_name' => 'credential', 'field_type' => 'password', 'public' => true],
        ]], JSON_THROW_ON_ERROR));
        admin_setting(['frontend_theme' => $theme, 'theme_'.$theme => [
            'accent' => 'blue', 'private_display' => 'theme-private-fixture', 'credential' => 'theme-password-fixture'],
            'app_name' => 'Public Fixture', 'app_description' => null, 'app_url' => 'https://site.example.test', 'logo' => null,
            'captcha_enable' => 1, 'captcha_type' => 'turnstile', 'turnstile_site_key' => 'public-site-key',
            'turnstile_secret_key' => 'private-captcha-fixture']);
    }

    protected function tearDown(): void
    {
        $this->app->instance('hook.filters', $this->initialFilters);
        if (isset($this->themeDirectory) && is_dir($this->themeDirectory)) {
            unlink($this->themeDirectory.'/config.json');
            rmdir($this->themeDirectory);
        }
        foreach (glob($this->directory.'/*') ?: [] as $file) unlink($file);
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_site_delegates_native_guest_and_public_theme_then_strips_extra_hook_fields(): void
    {
        HookManager::registerFilter('guest_comm_config', static function (array $data): array {
            $data['extraHookSecret'] = 'private-hook-fixture';
            $data['app_name'] = 'Hook Display Name';
            return $data;
        });
        $native = $this->getJson('/txapi/public/site-config')->assertOk()->json('data');
        $before = $this->businessState();
        $response = $this->send('public.site-config.get')->assertOk()
            ->assertJsonPath('meta', ['current_only' => true, 'slice' => 'internal-public-readonly/1'])
            ->assertJsonPath('data.app_name', 'Hook Display Name')->assertJsonPath('data.theme_config', ['accent' => 'blue'])
            ->assertJsonPath('data.turnstile_site_key', 'public-site-key')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(array_intersect_key($native, array_flip(self::SITE_FIELDS)), $response->json('data'));
        $this->assertCount(11, $response->json('data'));
        foreach (['private-hook-fixture', 'extraHookSecret', 'theme-private-fixture', 'theme-password-fixture',
            'private-captcha-fixture', $this->userToken, $this->adminToken, $this->user->token] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->assertSame($before, $this->businessState());
        $audit = DB::table('tx_internal_readonly_audits')->sole();
        $this->assertSame(0, (int) $audit->actor_id);
        $this->assertSame('public.site-config.get', $audit->operation);
        $this->assertSame('user-gateway', $audit->service);
        $this->assertSame(200, (int) $audit->status);
        $this->assertFalse(User::whereKey(0)->exists());
        $this->assertStringNotContainsString('private-hook-fixture', json_encode($audit));
    }

    public function test_real_user_and_admin_requests_do_not_leave_an_actor_in_anonymous_guest_hooks(): void
    {
        $observations = [];
        HookManager::registerFilter('guest_comm_config', static function (array $data) use (&$observations): array {
            $observations[] = [Auth::user(), Auth::guard('api')->user(), Auth::guard('sanctum')->user(),
                request()->user(), request()->user('sanctum')];
            return $data;
        });
        foreach ([[$this->user, $this->userToken, 200], [$this->admin, $this->adminToken, 403]] as [$actor, $token, $status]) {
            $this->send('user.profile.get', version: '2', authorization: $token)->assertStatus($status);
            $this->assertSame((int) $actor->id, (int) Auth::guard('sanctum')->user()->id);
            // Also prewarm the independently named default guard, as a guest hook may use it.
            Auth::guard('api')->setUser($actor);
            $this->send('public.site-config.get')->assertOk();
            $this->send('user.profile.get', version: '2', authorization: $this->userToken)->assertOk()
                ->assertJsonPath('data.id', (string) $this->user->id);
        }
        $this->assertSame([[null, null, null, null, null], [null, null, null, null, null]], $observations);
        $this->user->tokens()->delete();
        $this->send('public.site-config.get')->assertOk();
        $this->send('user.profile.get', version: '2', authorization: $this->userToken)->assertUnauthorized();
    }

    public function test_two_public_operations_require_exact_enabled_flags_and_testing_environment(): void
    {
        foreach (['public.site-config.get', 'public.plan.list'] as $operation) {
            foreach ([false, 'false', 'true', 1, null] as $disabled) {
                config(['internal_api_slice.public_read_enabled' => $disabled]);
                $this->send($operation)->assertNotFound();
            }
            config(['internal_api_slice.public_read_enabled' => true, 'internal_api_slice.enabled' => false]);
            $this->send($operation)->assertNotFound();
            config(['internal_api_slice.enabled' => 'true']);
            $this->send($operation)->assertNotFound();
            config(['internal_api_slice.enabled' => true]);
        }
        $this->app->detectEnvironment(static fn () => 'production');
        $this->send('public.site-config.get')->assertNotFound();
        $this->send('public.plan.list')->assertNotFound();
        $this->assertSame(0, DB::table('tx_internal_readonly_nonces')->count());
        $this->assertSame(0, DB::table('tx_internal_readonly_audits')->count());
    }

    public function test_anonymous_operations_refuse_even_empty_authorization_and_other_services(): void
    {
        foreach (['public.site-config.get', 'public.plan.list'] as $operation) {
            foreach (['', $this->userToken, $this->adminToken, 'Bearer node-credential', 'invalid'] as $authorization) {
                $this->send($operation, authorization: $authorization)->assertUnauthorized()
                    ->assertJsonPath('error.code', 'SERVICE_UNAUTHORIZED');
            }
            $this->send($operation, service: 'admin-gateway')->assertUnauthorized();
        }
        $this->send('user.profile.get', version: '2')->assertUnauthorized();
        $this->send('admin.order.get', ['id' => '1', 'admin_path' => 'fixture'], service: 'admin-gateway')->assertUnauthorized();
        $this->assertSame(0, DB::table('tx_internal_readonly_audits')->count());
    }

    public function test_proof_is_bound_to_empty_actor_body_method_and_exact_operation_path(): void
    {
        foreach ([['actor_sha256' => hash('sha256', $this->userToken)], ['body_sha256' => hash('sha256', '{}')],
            ['path' => '/txapi/internal/v1/operations/user.profile.get'], ['method' => 'GET'], ['epoch' => 2],
            ['exp' => time()-1], ['cert_sha256' => str_repeat('b', 64)]] as $claims) {
            $this->send('public.site-config.get', claims: $claims)->assertUnauthorized();
        }
        [$path, $body, $server] = $this->requestData('public.plan.list');
        $server['HTTP_X_TXBOARD_SERVICE_PROOF'] .= 'a';
        $this->call('POST', $path, [], [], [], $server, $body)->assertUnauthorized();
        $this->assertSame(0, DB::table('tx_internal_readonly_nonces')->count());
        $this->assertSame(0, DB::table('tx_internal_readonly_audits')->count());
    }

    public function test_exact_empty_input_versions_and_unknown_operations_are_rejected_before_admit(): void
    {
        foreach (['public.site-config.get', 'public.plan.list'] as $operation) {
            foreach ([['actor_id' => '1'], ['role' => 'admin'], ['page' => 1]] as $input) {
                $this->send($operation, $input)->assertStatus(400);
            }
            $this->send($operation, version: '2')->assertStatus(400);
            $this->send($operation, rawInput: '[]')->assertStatus(400);
            $this->send($operation, rawInput: '{"actor_id":"1","\u0061ctor_id":"2"}')->assertStatus(400);
        }
        $this->send('public.payment-methods.list')->assertStatus(400)->assertJsonPath('error.code', 'UNKNOWN_OPERATION');
        $this->send('identity.session.create')->assertStatus(400)->assertJsonPath('error.code', 'UNKNOWN_OPERATION');
        [$path, $body, $server] = $this->requestData('public.plan.list');
        // Both values are individually valid: accepting last-key-wins would wrongly admit this request.
        $body = str_replace('"contract_version":"1"', '"contract_version":"1","\u0063ontract_version":"1"', $body);
        $server['HTTP_X_TXBOARD_SERVICE_PROOF'] = $this->proof($path, $body);
        $this->call('POST', $path, [], [], [], $server, $body)->assertStatus(400);
        $this->assertSame(0, DB::table('tx_internal_readonly_nonces')->count());
        $this->assertSame(0, Order::count());
    }

    public function test_request_body_limit_and_expired_deadline_reject_before_durable_admit(): void
    {
        $this->send('public.plan.list', ['padding' => str_repeat('x', 16384)])->assertStatus(413);
        [$path, $body, $server] = $this->requestData('public.plan.list');
        $decoded = json_decode($body);
        $decoded->deadline_at = time()-1;
        $body = json_encode($decoded, JSON_THROW_ON_ERROR);
        $server['HTTP_X_TXBOARD_SERVICE_PROOF'] = $this->proof($path, $body);
        $this->call('POST', $path, [], [], [], $server, $body)->assertStatus(504)->assertJsonPath('error.code', 'DEADLINE_EXCEEDED');
        $this->assertSame(0, DB::table('tx_internal_readonly_nonces')->count());
    }

    public function test_plan_list_preserves_native_visibility_capacity_order_and_price_minor_values(): void
    {
        $first = $this->plan('First', ['sort' => 1, 'transfer_enable' => 3, 'speed_limit' => 0, 'device_limit' => 2,
            'reset_traffic_method' => 4, 'tags' => ['display'], 'content' => '<p>Native</p>',
            'prices' => ['monthly' => 12.345, 'onetime' => 7.25, 'quarterly' => 0]]);
        $second = $this->plan('Tie', ['sort' => 1, 'renew' => false]);
        $expired = $this->plan('Expired slot is available', ['sort' => 2, 'capacity_limit' => 1]);
        $this->account('public-expired@example.test', ['plan_id' => $expired->id, 'expired_at' => time()-3600]);
        $permanent = $this->plan('Full permanent', ['capacity_limit' => 1]);
        $this->user->update(['plan_id' => $permanent->id, 'expired_at' => null]);
        $future = $this->plan('Full future', ['capacity_limit' => 1]);
        $this->admin->update(['plan_id' => $future->id, 'expired_at' => time()+3600]);
        $this->plan('Hidden', ['show' => false]);
        $this->plan('Not sold', ['sell' => false]);
        $this->plan('No slots', ['capacity_limit' => 0]);
        $last = $this->plan('Last unlimited', ['sort' => 9]);
        $native = $this->getJson('/txapi/plans')->assertOk()->json('data');
        $before = $this->businessState();
        $response = $this->send('public.plan.list')->assertOk()->assertJsonPath('meta.slice', 'internal-public-readonly/1');
        $rows = $response->json('data');
        $this->assertSame(array_map('strval', [$first->id, $second->id, $expired->id, $last->id]), array_column($rows, 'id'));
        foreach ($native as $index => $row) {
            foreach (['id', 'traffic_limit_bytes', 'speed_limit_mbps', 'device_limit', 'capacity_limit', 'reset_traffic_method'] as $field) {
                if ($row[$field] !== null) $row[$field] = (string) $row[$field];
            }
            foreach ($row['prices'] as &$price) $price['amount_minor'] = (string) $price['amount_minor'];
            unset($price);
            $this->assertSame($row, $rows[$index]);
        }
        $this->assertSame('3221225472', $rows[0]['traffic_limit_bytes']);
        $this->assertSame('0', $rows[0]['speed_limit_mbps']);
        $this->assertSame('4', $rows[0]['reset_traffic_method']);
        $this->assertSame([['period' => 'monthly', 'amount_minor' => '1235'], ['period' => 'onetime', 'amount_minor' => '725']], $rows[0]['prices']);
        $this->assertNull($rows[1]['device_limit']);
        $this->assertFalse($rows[1]['renewable']);
        $this->assertSame($before, $this->businessState());
    }

    public function test_empty_catalog_and_sql_nonce_replay_are_distinct_and_never_mutate_users(): void
    {
        $before = $this->businessState();
        [$path, $body, $server] = $this->requestData('public.plan.list');
        $this->call('POST', $path, [], [], [], $server, $body)->assertOk()->assertJsonPath('data', []);
        $this->call('POST', $path, [], [], [], $server, $body)->assertStatus(409)->assertJsonPath('error.code', 'SERVICE_REPLAY');
        $this->assertSame(1, DB::table('tx_internal_readonly_nonces')->count());
        $this->assertSame(1, DB::table('tx_internal_readonly_audits')->count());
        $this->assertSame($before, $this->businessState());
    }

    public function test_public_and_protected_reads_share_sql_service_rate_budget(): void
    {
        config(['internal_api_slice.requests_per_minute' => 2]);
        $this->send('public.plan.list')->assertOk();
        $this->send('user.profile.get', version: '2', authorization: $this->userToken)->assertOk();
        $limited = $this->send('public.site-config.get')->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
        $this->assertGreaterThanOrEqual(1, (int) $limited->headers->get('Retry-After'));
        $this->assertLessThanOrEqual(60, (int) $limited->headers->get('Retry-After'));
        $this->assertSame(2, (int) DB::table('tx_internal_readonly_windows')->sum('requests'));
        $this->assertSame(2, DB::table('tx_internal_readonly_nonces')->count());
        $this->assertSame(2, DB::table('tx_internal_readonly_audits')->count());
    }

    public function test_oversized_guest_response_is_refused_as_a_whole_and_audited_without_body(): void
    {
        $before = $this->businessState();
        HookManager::registerFilter('guest_comm_config', static function (array $data): array {
            $data['app_description'] = str_repeat('fixture-public-body-', 4000);
            return $data;
        });
        $this->send('public.site-config.get')->assertStatus(502)->assertJsonPath('error.code', 'RESPONSE_TOO_LARGE')
            ->assertJsonMissingPath('data');
        $audit = DB::table('tx_internal_readonly_audits')->sole();
        $this->assertSame(0, (int) $audit->actor_id);
        $this->assertSame(502, (int) $audit->status);
        $this->assertSame('RESPONSE_TOO_LARGE', $audit->error_code);
        $this->assertStringNotContainsString('fixture-public-body-', json_encode($audit));
        $this->assertSame($before, $this->businessState());
    }

    public function test_audit_storage_failure_refuses_anonymous_success_without_business_mutation(): void
    {
        $before = $this->businessState();
        DB::unprepared(DB::connection()->getDriverName() === 'sqlite'
            ? "CREATE TRIGGER reject_public_audit BEFORE INSERT ON tx_internal_readonly_audits BEGIN SELECT RAISE(ABORT, 'fixture-audit-failure'); END"
            : "CREATE TRIGGER reject_public_audit BEFORE INSERT ON tx_internal_readonly_audits FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture-audit-failure'");
        try {
            $this->send('public.plan.list')->assertStatus(503)->assertJsonPath('error.code', 'DEPENDENCY_UNAVAILABLE');
        } finally {
            DB::unprepared('DROP TRIGGER reject_public_audit');
        }
        $this->assertSame(1, DB::table('tx_internal_readonly_nonces')->count());
        $this->assertSame(0, DB::table('tx_internal_readonly_audits')->count());
        $this->assertSame($before, $this->businessState());
    }

    public function test_anonymous_gate_does_not_expand_order_ownership_or_write_permissions(): void
    {
        $plan = $this->plan('Owner Plan');
        $mine = Order::create(['user_id' => $this->user->id, 'plan_id' => $plan->id, 'trade_no' => 'public-mine',
            'period' => 'monthly', 'type' => 1, 'status' => 0, 'total_amount' => 1200]);
        Order::create(['user_id' => $this->admin->id, 'plan_id' => $plan->id, 'trade_no' => 'public-other',
            'period' => 'monthly', 'type' => 1, 'status' => 0, 'total_amount' => 1200]);
        $this->send('public.plan.list')->assertOk();
        $this->send('user.order.get', ['trade_no' => $mine->trade_no], '2', $this->userToken)->assertOk();
        $this->send('user.order.get', ['trade_no' => 'public-other'], '2', $this->userToken)->assertNotFound();
        $this->send('user.order.get', ['trade_no' => $mine->trade_no], '2')->assertUnauthorized();
        $this->send('user.order.create', ['plan_id' => (string) $plan->id, 'period' => 'monthly', 'idempotency_key' => 'public-cannot-write'],
            authorization: $this->userToken)->assertNotFound();
        $this->assertSame(2, Order::count());
    }

    private function account(string $email, array $attributes = []): User
    {
        return User::create([...['email' => $email, 'password' => 'fixture-only', 'uuid' => (string) Str::uuid(),
            'token' => 'private-'.bin2hex(random_bytes(12)), 'banned' => false], ...$attributes]);
    }

    private function plan(string $name, array $attributes = []): Plan
    {
        return Plan::create([...['name' => $name, 'group_id' => 1, 'transfer_enable' => 1, 'show' => true,
            'sell' => true, 'renew' => true, 'sort' => 5, 'speed_limit' => null, 'device_limit' => null,
            'capacity_limit' => null, 'reset_traffic_method' => null, 'prices' => ['monthly' => 12]], ...$attributes]);
    }

    private function businessState(): array
    {
        return [DB::table('tx_user')->orderBy('id')->get()->toJson(), PersonalAccessToken::query()->orderBy('id')->get()->toJson(),
            DB::table('tx_plan')->orderBy('id')->get()->toJson(), DB::table('tx_order')->orderBy('id')->get()->toJson()];
    }

    private function send(string $operation, array $input = [], string $version = '1', ?string $authorization = null,
        array $claims = [], ?string $rawInput = null, string $service = 'user-gateway')
    {
        [$path, $body, $server] = $this->requestData($operation, $input, $version, $authorization, $claims, $rawInput, $service);
        return $this->call('POST', $path, [], [], [], $server, $body);
    }

    private function requestData(string $operation, array $input = [], string $version = '1', ?string $authorization = null,
        array $claims = [], ?string $rawInput = null, string $service = 'user-gateway'): array
    {
        $body = json_encode(['contract_version' => $version, 'request_id' => (string) Str::uuid(),
            'deadline_at' => time()+5, 'input' => (object) $input], JSON_THROW_ON_ERROR);
        if ($rawInput !== null) $body = substr($body, 0, strpos($body, '"input":')+8).$rawInput.'}';
        $path = '/txapi/internal/v1/operations/'.$operation;
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_TXBOARD_SERVICE_PROOF' => $this->proof($path, $body, $authorization, $claims, $service)];
        if ($authorization !== null) $server['HTTP_AUTHORIZATION'] = $authorization;
        return [$path, $body, $server];
    }

    private function proof(string $path, string $body, ?string $authorization = null, array $claims = [], string $service = 'user-gateway'): string
    {
        return JWT::encode([...['v' => 'internal-readonly/1', 'epoch' => 1, 'iss' => 'txboard-local-tls-boundary',
            'aud' => 'txboard-core-local', 'service' => $service, 'cert_sha256' => str_repeat('a', 64),
            'method' => 'POST', 'path' => $path, 'body_sha256' => hash('sha256', $body),
            'actor_sha256' => hash('sha256', $authorization ?? ''), 'iat' => time(), 'nbf' => time()-2,
            'exp' => time()+10, 'jti' => bin2hex(random_bytes(16))], ...$claims],
            $this->private, 'RS256', $service === 'user-gateway' ? 'user-1' : 'admin-1');
    }
}
