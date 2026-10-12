<?php

namespace Tests\Feature\InternalReadOnly;

use App\Domains\InternalReadOnly\ServiceProofVerifier;
use App\Domains\InternalReadOnly\ServiceState;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class InternalReadOnlySliceTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;
    private array $trust;
    private array $privateKeys;
    private User $user;
    private User $admin;
    private Order $order;
    private string $userToken;
    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/txboard-internal-unit-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $now = time();
        $this->trust = ['proof_keys' => [], 'services' => []];
        foreach (['user', 'admin'] as $role) {
            $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
            openssl_pkey_export($key, $this->privateKeys[$role]);
            $publicPath = $this->directory.'/'.$role.'.pub';
            file_put_contents($publicPath, openssl_pkey_get_details($key)['key']);
            $this->trust['proof_keys'][$role.'-1'] = [
                'epoch' => 1, 'service' => $role.'-gateway', 'revoked' => false,
                'not_before' => $now - 60, 'not_after' => $now + 3600, 'public_key_path' => $publicPath,
            ];
            $this->trust['services'][$role.'-gateway']['certificates'][str_repeat($role === 'user' ? 'a' : 'b', 64)] = [
                'revoked' => false, 'not_before' => $now - 60, 'not_after' => $now + 3600,
            ];
        }
        $this->saveTrust();
        config(['internal_api_slice.enabled' => true, 'internal_api_slice.trust_file' => $this->directory.'/trust.json']);
        $this->user = $this->actor('slice-user@example.test');
        $this->admin = $this->actor('slice-admin@example.test', true);
        $this->userToken = 'Bearer '.$this->user->createToken('slice-user', ['*'], now()->addHour())->plainTextToken;
        $this->adminToken = 'Bearer '.$this->admin->createToken('slice-admin', ['*'], now()->addHour())->plainTextToken;
        $plan = Plan::create(['name' => 'Internal Plan', 'group_id' => 1, 'transfer_enable' => 3,
            'show' => true, 'sell' => true, 'renew' => true, 'prices' => [Plan::PERIOD_MONTHLY => 12]]);
        $this->order = Order::create(['user_id' => $this->user->id, 'plan_id' => $plan->id, 'trade_no' => 'internal-trade-1',
            'period' => Plan::PERIOD_MONTHLY, 'type' => Order::TYPE_NEW_PURCHASE, 'status' => Order::STATUS_DISCOUNTED,
            'total_amount' => 1234, 'callback_no' => 'must-never-return-callback']);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_default_disabled_and_production_cannot_be_enabled(): void
    {
        config(['internal_api_slice.enabled' => false]);
        $this->send('user.profile.get')->assertNotFound();
        config(['internal_api_slice.enabled' => true]);
        $this->app->detectEnvironment(static fn () => 'production');
        $this->send('user.profile.get')->assertNotFound();
        $this->assertDatabaseCount('tx_internal_readonly_nonces', 0);
    }

    public function test_profile_projection_excludes_private_fields_and_uses_decimal_strings(): void
    {
        $response = $this->send('user.profile.get')->assertOk()->assertJsonPath('data.id', (string) $this->user->id)
            ->assertJsonPath('data.balance_minor', '0')->assertJsonPath('data.traffic.upload_bytes', '0')
            ->assertJsonPath('meta.current_only', true)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertNotEquals('00000000-0000-4000-8000-000000000000', $response->json('request_id'));
        foreach (['uuid', 'token', 'is_admin', 'password', 'telegram_id', 'subscribe_url'] as $field) {
            $this->assertArrayNotHasKey($field, $response->json('data'));
        }
        $this->assertDatabaseHas('tx_internal_readonly_audits', ['operation' => 'user.profile.get', 'status' => 200]);
    }

    public function test_order_owner_and_admin_reuse_current_core_authorization(): void
    {
        $input = ['trade_no' => $this->order->trade_no];
        $this->send('user.order.get', $input)->assertOk()->assertJsonPath('data.status', 4)
            ->assertJsonPath('data.amount_minor', '1234')->assertJsonPath('data.period', Plan::PERIOD_MONTHLY);
        $other = $this->actor('slice-other@example.test');
        $token = 'Bearer '.$other->createToken('other', ['*'], now()->addHour())->plainTextToken;
        $this->send('user.order.get', $input, 'user', $token)->assertNotFound();
        $adminInput = ['id' => (string) $this->order->id, 'admin_path' => hash('crc32b', config('app.key'))];
        $response = $this->send('admin.order.get', $adminInput, 'admin')->assertOk()
            ->assertJsonPath('data.user_id', (string) $this->user->id)->assertJsonPath('data.amount_minor', '1234');
        $this->assertStringNotContainsString('must-never-return-callback', $response->getContent());
        $this->send('admin.order.get', array_replace($adminInput, ['admin_path' => 'wrong']), 'admin')->assertNotFound();
        $this->send('admin.order.get', $adminInput, 'admin', $this->userToken)->assertForbidden();
        $this->send('user.profile.get', [], 'user', $this->adminToken)->assertForbidden();
        $this->send('admin.order.get', $adminInput, 'user')->assertUnauthorized();
        $this->send('user.profile.get', [], 'admin')->assertUnauthorized();
    }

    public function test_ban_admin_role_and_session_revocation_are_checked_on_each_request(): void
    {
        $this->send('user.profile.get')->assertOk();
        $this->user->update(['banned' => true]);
        $this->send('user.profile.get')->assertForbidden();
        $this->user->update(['banned' => false]);
        $this->send('user.profile.get')->assertOk();
        $this->user->tokens()->delete();
        $this->send('user.profile.get')->assertUnauthorized()->assertJsonPath('error.code', 'ACTOR_UNAUTHORIZED');
        $input = ['id' => (string) $this->order->id, 'admin_path' => hash('crc32b', config('app.key'))];
        $this->send('admin.order.get', $input, 'admin')->assertOk();
        $this->admin->update(['is_admin' => false]);
        $this->send('admin.order.get', $input, 'admin')->assertForbidden();
    }

    public function test_expired_session_and_fake_identity_headers_cannot_authorize(): void
    {
        $expired = 'Bearer '.$this->user->createToken('expired', ['*'], now()->subMinute())->plainTextToken;
        $this->send('user.profile.get', [], 'user', $expired)->assertUnauthorized();
        $raw = $this->body([]);
        $this->raw('user.profile.get', $raw, '', $this->userToken, [
            'HTTP_X_CLIENT_CERT' => 'trusted', 'HTTP_X_ACTOR_ID' => (string) $this->admin->id,
            'HTTP_X_SERVICE_IDENTITY' => 'admin-gateway',
        ])->assertUnauthorized();
        $this->assertDatabaseCount('tx_internal_readonly_nonces', 0);
    }

    public function test_unsigned_alg_confusion_header_extensions_and_duplicates_are_rejected(): void
    {
        $raw = $this->body([]);
        $claims = $this->claims('user.profile.get', $raw, 'user', $this->userToken);
        foreach ([
            '{"alg":"none","typ":"JWT","kid":"user-1"}',
            '{"alg":"HS256","typ":"JWT","kid":"user-1"}',
            '{"alg":"RS256","typ":"JWT","kid":"user-1","jku":"https://example.test"}',
            '{"alg":"RS256","typ":"JWT","kid":"user-1","kid":"user-1"}',
            '{"alg":"RS256","typ":"JWT","kid":"missing"}',
        ] as $header) {
            $this->raw('user.profile.get', $raw, $this->signRaw($header, json_encode($claims)), $this->userToken)->assertUnauthorized();
        }
        $header = '{"alg":"RS256","typ":"JWT","kid":"user-1"}';
        $json = json_encode($claims);
        $duplicate = substr($json, 0, -1).',"service":"user-gateway"}';
        $extra = substr($json, 0, -1).',"actor_id":"1"}';
        foreach ([$duplicate, $extra] as $payload) {
            $this->raw('user.profile.get', $raw, $this->signRaw($header, $payload), $this->userToken)->assertUnauthorized();
        }
        $wrongKey = JWT::encode($claims, $this->privateKeys['admin'], 'RS256', 'user-1');
        $this->raw('user.profile.get', $raw, $wrongKey, $this->userToken)->assertUnauthorized();
        $good = JWT::encode($claims, $this->privateKeys['user'], 'RS256', 'user-1');
        $this->raw('user.profile.get', $raw, $good.'=', $this->userToken)->assertUnauthorized();
        $this->assertDatabaseCount('tx_internal_readonly_nonces', 0);
    }

    public function test_proof_binding_version_clock_certificate_and_key_fail_closed(): void
    {
        $now = time();
        foreach ([
            ['v' => '2'], ['epoch' => 2], ['iss' => 'visitor'], ['aud' => 'wrong'], ['service' => 'admin-gateway'],
            ['cert_sha256' => str_repeat('c', 64)], ['method' => 'GET'], ['path' => '/txapi/me'],
            ['body_sha256' => str_repeat('0', 64)], ['actor_sha256' => str_repeat('0', 64)],
            ['iat' => $now + 30], ['nbf' => $now + 30], ['exp' => $now], ['exp' => $now + 100],
            ['jti' => 'short'], ['iat' => (string) $now],
        ] as $patch) {
            $raw = $this->body([]);
            $proof = JWT::encode(array_replace($this->claims('user.profile.get', $raw, 'user', $this->userToken), $patch),
                $this->privateKeys['user'], 'RS256', 'user-1');
            $this->raw('user.profile.get', $raw, $proof, $this->userToken)->assertUnauthorized();
        }
        $this->trust['proof_keys']['user-1']['revoked'] = true;
        $this->saveTrust();
        $this->send('user.profile.get')->assertUnauthorized();
        $this->trust['proof_keys']['user-1']['revoked'] = false;
        $this->trust['services']['user-gateway']['certificates'][str_repeat('a', 64)]['revoked'] = true;
        $this->saveTrust();
        $this->send('user.profile.get')->assertUnauthorized();
        $this->assertDatabaseCount('tx_internal_readonly_nonces', 0);
    }

    public function test_valid_rotation_epoch_and_old_key_window_are_explicit(): void
    {
        $this->trust['proof_keys']['user-2'] = array_replace($this->trust['proof_keys']['user-1'], ['epoch' => 2]);
        $this->saveTrust();
        $raw = $this->body([]);
        $claims = array_replace($this->claims('user.profile.get', $raw, 'user', $this->userToken), ['epoch' => 2]);
        $proof = JWT::encode($claims, $this->privateKeys['user'], 'RS256', 'user-2');
        $this->raw('user.profile.get', $raw, $proof, $this->userToken)->assertOk();
        $this->trust['proof_keys']['user-1']['not_after'] = time();
        $this->saveTrust();
        $this->send('user.profile.get')->assertUnauthorized();
    }

    public function test_failed_proof_does_not_consume_nonce_but_verified_replay_is_durable(): void
    {
        $raw = $this->body([]);
        $claims = $this->claims('user.profile.get', $raw, 'user', $this->userToken);
        $good = JWT::encode($claims, $this->privateKeys['user'], 'RS256', 'user-1');
        $bad = JWT::encode(array_replace($claims, ['actor_sha256' => str_repeat('0', 64)]), $this->privateKeys['user'], 'RS256', 'user-1');
        $this->raw('user.profile.get', $raw, $bad, $this->userToken)->assertUnauthorized();
        $this->raw('user.profile.get', $raw, $good, $this->userToken)->assertOk();
        $this->app->forgetInstance(ServiceProofVerifier::class);
        $this->app->forgetInstance(ServiceState::class);
        $this->raw('user.profile.get', $raw, $good, $this->userToken)->assertStatus(409)->assertJsonPath('error.code', 'SERVICE_REPLAY');
        $this->assertDatabaseCount('tx_internal_readonly_nonces', 1);
    }

    public function test_request_unknown_fields_duplicate_keys_sizes_and_deadline_are_bounded(): void
    {
        foreach ([
            ['contract_version' => '3'], ['deadline_at' => time() + 100], ['request_id' => 'not-uuid'],
            ['input' => ['actor_id' => '1']], ['input' => []], ['actor' => 'admin'],
        ] as $patch) {
            $raw = $this->body([], $patch);
            $this->raw('user.profile.get', $raw, '', $this->userToken)->assertStatus(400);
        }
        $raw = $this->body([]);
        $duplicate = substr($raw, 0, -1).',"contract_version":"1"}';
        $this->raw('user.profile.get', $duplicate, '', $this->userToken)->assertStatus(400);
        $this->raw('user.profile.get', str_repeat(' ', 16385), '', $this->userToken)->assertStatus(413);
        $this->raw('user.profile.get', $this->body([], ['deadline_at' => time()]), '', $this->userToken)->assertStatus(504);
        $this->raw('unknown', $raw, '', $this->userToken)->assertStatus(400)->assertJsonPath('error.code', 'UNKNOWN_OPERATION');
        $this->raw('user.profile.get?admin=1', $raw, '', $this->userToken)->assertStatus(400);
    }

    public function test_rate_limit_retention_and_storage_failure_do_not_open_the_endpoint(): void
    {
        config(['internal_api_slice.requests_per_minute' => 1]);
        $now = time();
        DB::table('tx_internal_readonly_nonces')->insert([
            ['kid' => 'old', 'jti' => str_repeat('0', 32), 'expires_at' => $now - 1],
            ['kid' => 'live', 'jti' => str_repeat('1', 32), 'expires_at' => $now + 10],
        ]);
        $this->send('user.profile.get')->assertOk();
        $this->assertDatabaseMissing('tx_internal_readonly_nonces', ['kid' => 'old']);
        $this->assertDatabaseHas('tx_internal_readonly_nonces', ['kid' => 'live']);
        $limited = $this->send('user.profile.get')->assertStatus(429);
        $this->assertGreaterThanOrEqual(1, (int) $limited->headers->get('Retry-After'));
        $this->assertLessThanOrEqual(60, (int) $limited->headers->get('Retry-After'));
        Schema::drop('tx_internal_readonly_nonces');
        $response = $this->send('user.profile.get')->assertStatus(503)->assertJsonPath('error.code', 'DEPENDENCY_UNAVAILABLE');
        $this->assertStringNotContainsString('SQL', $response->getContent());
        $this->assertStringNotContainsString($this->userToken, $response->getContent());
    }

    private function actor(string $email, bool $admin = false): User
    {
        return User::create(['email' => $email, 'password' => 'test-hash', 'uuid' => (string) Str::uuid(),
            'token' => bin2hex(random_bytes(16)), 'is_admin' => $admin, 'banned' => false]);
    }

    private function body(array $input, array $patch = []): string
    {
        return json_encode(array_replace(['contract_version' => '1', 'request_id' => '00000000-0000-4000-8000-000000000000',
            'deadline_at' => time() + 5, 'input' => (object) $input], $patch), JSON_THROW_ON_ERROR);
    }

    private function claims(string $operation, string $raw, string $role, string $token): array
    {
        $now = time();
        return ['v' => 'internal-readonly/1', 'epoch' => 1, 'iss' => 'txboard-local-tls-boundary', 'aud' => 'txboard-core-local',
            'service' => $role.'-gateway', 'cert_sha256' => str_repeat($role === 'user' ? 'a' : 'b', 64), 'method' => 'POST',
            'path' => '/txapi/internal/v1/operations/'.$operation, 'body_sha256' => hash('sha256', $raw),
            'actor_sha256' => hash('sha256', $token), 'iat' => $now, 'nbf' => $now - 2, 'exp' => $now + 10,
            'jti' => bin2hex(random_bytes(16))];
    }

    private function send(string $operation, array $input = [], string $role = 'user', ?string $token = null)
    {
        $token ??= $role === 'user' ? $this->userToken : $this->adminToken;
        $raw = $this->body($input);
        $proof = JWT::encode($this->claims($operation, $raw, $role, $token), $this->privateKeys[$role], 'RS256', $role.'-1');
        return $this->raw($operation, $raw, $proof, $token);
    }

    private function raw(string $operation, string $raw, string $proof, string $token, array $headers = [])
    {
        return $this->call('POST', '/txapi/internal/v1/operations/'.$operation, [], [], [], array_replace([
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => $token,
            'HTTP_X_TXBOARD_SERVICE_PROOF' => $proof, 'HTTP_X_REQUEST_ID' => 'visitor-selected',
        ], $headers), $raw);
    }

    private function signRaw(string $header, string $payload): string
    {
        $encode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $signed = $encode($header).'.'.$encode($payload);
        openssl_sign($signed, $signature, $this->privateKeys['user'], OPENSSL_ALGO_SHA256);
        return $signed.'.'.$encode($signature);
    }

    private function saveTrust(): void
    {
        file_put_contents($this->directory.'/trust.json', json_encode($this->trust, JSON_THROW_ON_ERROR));
    }
}
