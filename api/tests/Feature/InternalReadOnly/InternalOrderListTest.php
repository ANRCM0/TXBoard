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

final class InternalOrderListTest extends TestCase
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

    public function test_native_owner_filter_stable_tie_order_and_pages_with_no_private_fields_or_mutations(): void
    {
        $first = $this->order('list-first', 4);
        $second = $this->order('list-second', 0);
        $third = $this->order('list-third', 4);
        $foreign = $this->order('list-foreign', 4, $this->user->id + 100);
        DB::table('tx_order')->whereIn('id', [$first->id, $second->id, $third->id, $foreign->id])->update(['created_at' => 1791763200]);
        $before = DB::table('tx_order')->orderBy('id')->get()->toJson();
        $page = $this->send(['page' => 1, 'per_page' => 2])->assertOk()
            ->assertJsonPath('data.pagination', ['page' => 1, 'per_page' => 2, 'total' => 3, 'last_page' => 2])
            ->assertJsonPath('data.items.0.id', (string) $third->id)->assertJsonPath('data.items.1.id', (string) $second->id)
            ->assertJsonPath('meta.slice', 'internal-readonly/2')->assertHeader('Cache-Control', 'no-store, private');
        $this->send(['page' => 2, 'per_page' => 2])->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', (string) $first->id);
        $this->send(['page' => 3, 'per_page' => 2])->assertOk()->assertJsonPath('data.items', []);
        $this->send(['page' => 1, 'per_page' => 1, 'status' => 4])->assertOk()
            ->assertJsonPath('data.pagination.total', 2)->assertJsonPath('data.items.0.id', (string) $third->id);
        $row = $page->json('data.items.0');
        $this->assertSame('9007199254740993', $row['amount_minor']);
        $this->assertSame('monthly', $row['period']);
        $this->assertSame(['id', 'trade_no', 'plan_id', 'period', 'type', 'status', 'amount_minor', 'plan', 'created_at', 'paid_at'], array_keys($row));
        foreach (['private-list-token', 'private-callback', 'private-surplus', 'list-foreign'] as $secret) {
            $this->assertStringNotContainsString($secret, $page->getContent());
        }
        $this->assertSame($before, DB::table('tx_order')->orderBy('id')->get()->toJson());
        DB::table('tx_plan')->where('id', $this->plan->id)->delete();
        $this->send(['page' => 1, 'per_page' => 2])->assertOk()->assertJsonPath('data.items.0.plan', null);
    }

    public function test_strict_input_versions_and_authenticated_actor_gates(): void
    {
        foreach ([[], ['page' => 1], ['page' => 0, 'per_page' => 1], ['page' => 10000, 'per_page' => 1],
            ['page' => '1', 'per_page' => 1], ['page' => 1.0, 'per_page' => 1], ['page' => 1, 'per_page' => 101],
            ['page' => 1, 'per_page' => 0], ['page' => 1, 'per_page' => 1, 'status' => null],
            ['page' => 1, 'per_page' => 1, 'status' => 5], ['page' => 1, 'per_page' => 1, 'status' => '0'],
            ['page' => 1, 'per_page' => 1, 'user_id' => $this->user->id]] as $input) {
            $this->send($input)->assertStatus(400);
        }
        $this->send(['page' => 1, 'per_page' => 1], '1')->assertStatus(400);
        $this->send(['page' => 9999, 'per_page' => 100, 'status' => 0])->assertOk()->assertJsonPath('data.items', []);
        $this->send(['page' => 1, 'per_page' => 1], rawInput: '{"page":1,"per_page":1,"\\u0070age":2}')->assertStatus(400);
        $this->send(['page' => 1, 'per_page' => 1], token: 'Bearer missing')->assertUnauthorized();
        $this->user->update(['banned' => true]);
        $this->send(['page' => 1, 'per_page' => 1])->assertForbidden();
        $this->user->update(['banned' => false, 'is_admin' => true]);
        $this->send(['page' => 1, 'per_page' => 1])->assertForbidden();
        config(['internal_api_slice.enabled' => false]);
        $this->send(['page' => 1, 'per_page' => 1])->assertNotFound();
        config(['internal_api_slice.enabled' => true]);
        $this->app->detectEnvironment(static fn () => 'production');
        $this->send(['page' => 1, 'per_page' => 1])->assertNotFound();
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
        $path = '/txapi/internal/v1/operations/user.order.list';
        $proof = JWT::encode(['v' => 'internal-readonly/1', 'epoch' => 1, 'iss' => 'txboard-local-tls-boundary',
            'aud' => 'txboard-core-local', 'service' => 'user-gateway', 'cert_sha256' => str_repeat('a', 64),
            'method' => 'POST', 'path' => $path, 'body_sha256' => hash('sha256', $body), 'actor_sha256' => hash('sha256', $token),
            'iat' => time(), 'nbf' => time()-2, 'exp' => time()+10, 'jti' => bin2hex(random_bytes(16))], $this->private, 'RS256', 'user-1');
        return $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $token, 'HTTP_X_TXBOARD_SERVICE_PROOF' => $proof], $body);
    }
}
