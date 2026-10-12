<?php

namespace Tests\Feature\InternalReadOnly;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class InternalOrderWriteTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;
    private string $private = '';
    private User $user;
    private string $token;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/txboard-internal-list-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        openssl_pkey_export($key, $this->private);
        file_put_contents($this->directory.'/proof.pub', openssl_pkey_get_details($key)['key']);
        file_put_contents($this->directory.'/trust.json', json_encode([
            'proof_keys' => ['user-1' => ['epoch' => 1, 'service' => 'user-gateway', 'revoked' => false,
                'not_before' => time()-60, 'not_after' => time()+3600, 'public_key_path' => $this->directory.'/proof.pub'],
                'admin-1' => ['epoch' => 1, 'service' => 'admin-gateway', 'revoked' => false,
                    'not_before' => time()-60, 'not_after' => time()+3600, 'public_key_path' => $this->directory.'/proof.pub']],
            'services' => ['user-gateway' => ['certificates' => [str_repeat('a', 64) => ['revoked' => false,
                'not_before' => time()-60, 'not_after' => time()+3600]]],
                'admin-gateway' => ['certificates' => [str_repeat('a', 64) => ['revoked' => false,
                    'not_before' => time()-60, 'not_after' => time()+3600]]]],
        ]));
        config(['core_shadow.isolated_copy' => true, 'internal_api_slice.order_write_enabled' => true, 'internal_api_slice.enabled' => true, 'internal_api_slice.trust_file' => $this->directory.'/trust.json']);
        $this->user = User::create(['email' => 'list@example.test', 'password' => 'fixture-only',
            'uuid' => (string) Str::uuid(), 'token' => 'private-list-token']);
        $this->token = 'Bearer '.$this->user->createToken('list', ['*'], now()->addHour())->plainTextToken;
        $this->plan = Plan::create(['name' => 'List Plan', 'group_id' => 1, 'transfer_enable' => 1,
            'show' => true, 'sell' => true, 'renew' => true, 'capacity_limit' => null, 'prices' => [Plan::PERIOD_MONTHLY => 12]]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) unlink($file);
        rmdir($this->directory);
        parent::tearDown();
    }


    public function test_native_wallet_reservation_and_same_key_replay_remain_core_atomic(): void
    {
        $this->user->update(['balance' => 300]);
        $first = $this->send('user.order.create', $this->input())->assertStatus(201)
            ->assertJsonPath('meta.slice', 'internal-order-write/1');
        $trade = $first->json('data.trade_no');
        $order = Order::where('trade_no', $trade)->firstOrFail();
        $this->assertSame((int) $this->user->id, (int) $order->user_id);
        $this->assertSame(300, (int) $order->balance_amount);
        $this->assertSame(900, (int) $order->total_amount);
        $this->assertSame(0, (int) $this->user->fresh()->balance);
        $this->assertSame(['trade_no'], array_keys($first->json('data')));
        $this->send('user.order.create', $this->input())->assertStatus(201)->assertJsonPath('data.trade_no', $trade);
        $this->assertSame(1, Order::count());
        $this->assertSame(1, DB::table('idempotency_keys')->count());
        $this->send('user.order.create', [...$this->input(), 'coupon_code' => 'different-private-coupon'])
            ->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        $this->assertSame(0, (int) $this->user->fresh()->balance);
        foreach ([$this->token, $this->user->uuid, 'private-list-token', 'idempotency_key'] as $secret) {
            $this->assertStringNotContainsString($secret, $first->getContent());
        }
    }

    public function test_disabled_isolated_actor_and_service_gates_prevent_business_mutation(): void
    {
        config(['internal_api_slice.order_write_enabled' => false]);
        $this->send('user.order.create', $this->input())->assertNotFound();
        config(['internal_api_slice.order_write_enabled' => 'true']);
        $this->send('user.order.create', $this->input())->assertNotFound();
        config(['internal_api_slice.order_write_enabled' => true, 'core_shadow.isolated_copy' => false]);
        $this->send('user.order.create', $this->input())->assertNotFound();
        config(['core_shadow.isolated_copy' => true]);
        $this->send('user.order.create', $this->input(), service: 'admin-gateway')->assertUnauthorized();
        $this->send('user.order.create', $this->input(), token: 'Bearer missing')->assertUnauthorized();
        $this->user->update(['banned' => true]);
        $this->send('user.order.create', $this->input())->assertForbidden();
        $this->user->update(['banned' => false, 'is_admin' => true]);
        $this->send('user.order.create', $this->input())->assertForbidden();
        $this->app->detectEnvironment(static fn () => 'production');
        $this->send('user.order.create', $this->input())->assertNotFound();
        $this->assertSame(0, Order::count());
        $this->assertSame(0, DB::table('idempotency_keys')->count());
    }

    public function test_exact_input_validation_and_native_rejections_do_not_create_claims(): void
    {
        foreach ([['plan_id' => 1], ['plan_id' => '01'], ['plan_id' => '2147483648'], ['period' => 'month_price'],
            ['idempotency_key' => ''], ['idempotency_key' => "key\n"], ['coupon_code' => null],
            ['coupon_code' => str_repeat('x', 65)], ['owner_id' => '1']] as $patch) {
            $this->send('user.order.create', [...$this->input(), ...$patch])->assertStatus(400);
        }
        $this->send('user.order.create', $this->input(), '2')->assertStatus(400);
        $raw = json_encode($this->input());
        $raw = substr($raw, 0, -1).',"\\u0069dempotency_key":"other"}';
        $this->send('user.order.create', $this->input(), rawInput: $raw)->assertStatus(400);
        $this->send('user.order.create', [...$this->input(), 'plan_id' => '2147483647'])->assertNotFound();
        $this->send('user.order.create', [...$this->input(), 'coupon_code' => 'private-invalid-coupon'])
            ->assertStatus(409)->assertJsonPath('error.code', 'ORDER_REJECTED');
        $this->assertSame(0, Order::count());
        $this->assertSame(0, DB::table('idempotency_keys')->count());
    }

    public function test_audit_failure_after_commit_is_recovered_only_with_same_business_key(): void
    {
        DB::unprepared(DB::connection()->getDriverName() === 'sqlite'
            ? "CREATE TRIGGER reject_internal_audit BEFORE INSERT ON tx_internal_readonly_audits BEGIN SELECT RAISE(ABORT, 'fixture-audit-failure'); END"
            : "CREATE TRIGGER reject_internal_audit BEFORE INSERT ON tx_internal_readonly_audits FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture-audit-failure'");
        try {
            $this->send('user.order.create', $this->input())->assertStatus(503)
                ->assertJsonPath('error.code', 'DEPENDENCY_UNAVAILABLE');
        } finally {
            DB::unprepared('DROP TRIGGER reject_internal_audit');
        }
        $this->assertSame(1, Order::count());
        $trade = Order::firstOrFail()->trade_no;
        $this->send('user.order.create', $this->input())->assertStatus(201)->assertJsonPath('data.trade_no', $trade);
        $this->assertSame(1, Order::count());
        $this->assertSame(1, DB::table('idempotency_keys')->count());
    }

    public function test_plan_deletion_before_native_replay_retains_not_found_and_original_committed_order(): void
    {
        $trade = $this->send('user.order.create', $this->input())->assertStatus(201)->json('data.trade_no');
        DB::table('tx_plan')->where('id', $this->plan->id)->delete();
        $this->send('user.order.create', $this->input())->assertNotFound();
        $this->assertSame($trade, Order::firstOrFail()->trade_no);
        $this->assertSame(1, DB::table('idempotency_keys')->count());
    }

    private function input(): array
    {
        return ['plan_id' => (string) $this->plan->id, 'period' => 'monthly', 'idempotency_key' => 'original-business-key'];
    }

    private function send(string $operation, array $input = [], string $version = '1', ?string $token = null, ?string $rawInput = null, string $service = 'user-gateway')
    {
        $token ??= $this->token;
        $body = json_encode(['contract_version' => $version, 'request_id' => (string) Str::uuid(),
            'deadline_at' => time()+5, 'input' => (object) $input], JSON_PRESERVE_ZERO_FRACTION);
        if ($rawInput !== null) {
            $body = substr($body, 0, strpos($body, '"input":')+8).$rawInput.'}';
        }
        $path = '/txapi/internal/v1/operations/'.$operation;
        $proof = JWT::encode(['v' => 'internal-readonly/1', 'epoch' => 1, 'iss' => 'txboard-local-tls-boundary',
            'aud' => 'txboard-core-local', 'service' => $service, 'cert_sha256' => str_repeat('a', 64),
            'method' => 'POST', 'path' => $path, 'body_sha256' => hash('sha256', $body), 'actor_sha256' => hash('sha256', $token),
            'iat' => time(), 'nbf' => time()-2, 'exp' => time()+10, 'jti' => bin2hex(random_bytes(16))], $this->private, 'RS256', $service === 'user-gateway' ? 'user-1' : 'admin-1');
        return $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $token, 'HTTP_X_TXBOARD_SERVICE_PROOF' => $proof], $body);
    }
}
