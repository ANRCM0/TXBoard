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

final class InternalOrderStatusTest extends TestCase
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
                'not_before' => time()-60, 'not_after' => time()+3600, 'public_key_path' => $this->directory.'/proof.pub']],
            'services' => ['user-gateway' => ['certificates' => [str_repeat('a', 64) => ['revoked' => false,
                'not_before' => time()-60, 'not_after' => time()+3600]]]],
        ]));
        config(['internal_api_slice.enabled' => true, 'internal_api_slice.trust_file' => $this->directory.'/trust.json']);
        $this->user = User::create(['email' => 'list@example.test', 'password' => 'fixture-only',
            'uuid' => (string) Str::uuid(), 'token' => 'private-list-token']);
        $this->token = 'Bearer '.$this->user->createToken('list', ['*'], now()->addHour())->plainTextToken;
        $this->plan = Plan::create(['name' => 'List Plan', 'group_id' => 1, 'transfer_enable' => 1,
            'show' => true, 'sell' => true, 'renew' => true, 'prices' => [Plan::PERIOD_MONTHLY => 12]]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) unlink($file);
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_current_status_keeps_native_ownership_all_states_and_missing_plan_without_business_writes(): void
    {
        $orders = [];
        for ($status = 0; $status <= 4; $status++) $orders[] = $this->order('status-'.$status, $status);
        $this->order('foreign-status', 1, $this->user->id+100);
        $before = DB::table('tx_order')->orderBy('id')->get()->toJson();
        DB::table('tx_plan')->where('id', $this->plan->id)->delete();
        foreach ($orders as $order) {
            $response = $this->send(['trade_no' => $order->trade_no])->assertOk()
                ->assertJsonPath('data', ['trade_no' => $order->trade_no, 'status' => $order->status])
                ->assertHeader('Cache-Control', 'no-store, private');
            foreach (['private-callback', 'private-surplus', 'private-list-token', 'payment_id', 'amount_minor'] as $secret) {
                $this->assertStringNotContainsString($secret, $response->getContent());
            }
        }
        $this->send(['trade_no' => 'foreign-status'])->assertNotFound();
        $this->send(['trade_no' => 'missing-status'])->assertNotFound();
        $this->assertSame($before, DB::table('tx_order')->orderBy('id')->get()->toJson());
    }

    public function test_exact_trade_input_and_actor_environment_gates(): void
    {
        foreach ([[], ['trade_no' => ''], ['trade_no' => 1], ['trade_no' => str_repeat('a', 129)],
            ['trade_no' => 'a/b'], ['trade_no' => "valid\n"], ['trade_no' => 'valid', 'user_id' => 1]] as $input) {
            $this->send($input)->assertStatus(400);
        }
        $this->send(['trade_no' => 'valid'], '1')->assertStatus(400);
        $this->send(['trade_no' => 'valid'], rawInput: '{"trade_no":"valid","\\u0074rade_no":"other"}')->assertStatus(400);
        $this->send(['trade_no' => 'valid'], token: 'Bearer missing')->assertUnauthorized();
        $this->user->update(['banned' => true]); $this->send(['trade_no' => 'valid'])->assertForbidden();
        $this->user->update(['banned' => false, 'is_admin' => true]); $this->send(['trade_no' => 'valid'])->assertForbidden();
        config(['internal_api_slice.enabled' => false]); $this->send(['trade_no' => 'valid'])->assertNotFound();
        config(['internal_api_slice.enabled' => true]); $this->app->detectEnvironment(static fn () => 'production');
        $this->send(['trade_no' => 'valid'])->assertNotFound();
    }

    private function order(string $tradeNo, int $status, ?int $owner = null): Order
    {
        return Order::create(['user_id' => $owner ?? $this->user->id, 'plan_id' => $this->plan->id, 'trade_no' => $tradeNo,
            'period' => Plan::PERIOD_MONTHLY, 'type' => 1, 'status' => $status, 'total_amount' => 9007199254740993,
            'callback_no' => 'private-callback', 'surplus_order_ids' => ['private-surplus']]);
    }

    private function send(array $input, string $version = '2', ?string $token = null, ?string $rawInput = null)
    {
        $token ??= $this->token;
        $body = json_encode(['contract_version' => $version, 'request_id' => (string) Str::uuid(),
            'deadline_at' => time()+5, 'input' => (object) $input], JSON_PRESERVE_ZERO_FRACTION);
        if ($rawInput !== null) {
            $body = substr($body, 0, strpos($body, '"input":')+8).$rawInput.'}';
        }
        $path = '/txapi/internal/v1/operations/user.order-status.get';
        $proof = JWT::encode(['v' => 'internal-readonly/1', 'epoch' => 1, 'iss' => 'txboard-local-tls-boundary',
            'aud' => 'txboard-core-local', 'service' => 'user-gateway', 'cert_sha256' => str_repeat('a', 64),
            'method' => 'POST', 'path' => $path, 'body_sha256' => hash('sha256', $body), 'actor_sha256' => hash('sha256', $token),
            'iat' => time(), 'nbf' => time()-2, 'exp' => time()+10, 'jti' => bin2hex(random_bytes(16))], $this->private, 'RS256', 'user-1');
        return $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $token, 'HTTP_X_TXBOARD_SERVICE_PROOF' => $proof], $body);
    }
}
