<?php

namespace Tests\Feature\InternalReadOnly;

use App\Models\Order;
use App\Models\Plan;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class InternalUserDisplayTest extends TestCase
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

    public function test_display_reads_delegate_native_authority_and_exclude_provider_secrets(): void
    {
        foreach ([[$this->user->id, 0], [$this->user->id, 1], [$this->user->id+100, 0]] as [$owner, $status]) {
            Order::create(['user_id' => $owner, 'plan_id' => $this->plan->id, 'trade_no' => 'display-'.$owner.'-'.$status,
                'period' => Plan::PERIOD_MONTHLY, 'type' => 1, 'status' => $status, 'total_amount' => 1200]);
            Ticket::create(['user_id' => $owner, 'subject' => 'Fixture', 'level' => 1, 'status' => $status]);
        }
        User::create(['email' => 'invited@example.test', 'password' => 'fixture-only', 'uuid' => (string) Str::uuid(),
            'token' => 'private-invited-token', 'invite_user_id' => $this->user->id]);
        $first = Payment::create(['uuid' => str_replace('-', '', (string) Str::uuid()), 'name' => 'First', 'payment' => 'NotAProvider', 'enable' => true, 'sort' => 2,
            'handling_fee_fixed' => 17, 'handling_fee_percent' => 2.5, 'config' => ['secret' => 'private-provider-config']]);
        $second = Payment::create(['uuid' => str_replace('-', '', (string) Str::uuid()), 'config' => [], 'name' => 'Second', 'payment' => 'NotAProvider', 'enable' => true, 'sort' => 1,
            'handling_fee_fixed' => 0, 'handling_fee_percent' => 0, 'icon' => '/fixture.svg']);
        Payment::create(['uuid' => str_replace('-', '', (string) Str::uuid()), 'config' => [], 'name' => 'Disabled', 'payment' => 'NotAProvider', 'enable' => false, 'sort' => 0]);
        $before = DB::table('tx_payment')->orderBy('id')->get()->toJson();
        $this->send('user.dashboard.get')->assertOk()
            ->assertJsonPath('data', ['unpaid_orders' => 1, 'open_tickets' => 1, 'invited_users' => 1])
            ->assertJsonPath('meta.slice', 'internal-readonly/2')->assertHeader('Cache-Control', 'no-store, private');
        $response = $this->send('user.payment-methods.list')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', (string) $second->id)->assertJsonPath('data.1.id', (string) $first->id)
            ->assertJsonPath('data.1.fee_fixed_minor', '17')->assertJsonPath('data.1.fee_percent', 2.5);
        $this->assertSame(['id', 'name', 'provider', 'icon', 'fee_fixed_minor', 'fee_percent'], array_keys($response->json('data.0')));
        foreach (['private-provider-config', 'private-list-token', 'Disabled', 'config', 'trade_no'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->assertSame($before, DB::table('tx_payment')->orderBy('id')->get()->toJson());
        $first->update(['enable' => false]);
        $this->send('user.payment-methods.list')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_empty_input_versions_and_current_actor_gates_apply_to_both_reads(): void
    {
        foreach (['user.dashboard.get', 'user.payment-methods.list'] as $operation) {
            $this->send($operation, ['user_id' => $this->user->id])->assertStatus(400);
            $this->send($operation, version: '1')->assertStatus(400);
            $this->send($operation, rawInput: '[]')->assertStatus(400);
            $this->send($operation, rawInput: '{"user_id":1,"\\u0075ser_id":2}')->assertStatus(400);
            $this->send($operation, token: 'Bearer missing')->assertUnauthorized();
            $this->send($operation, service: 'admin-gateway')->assertUnauthorized()->assertJsonPath('error.code', 'SERVICE_UNAUTHORIZED');
            $this->user->update(['banned' => true]);
            $this->send($operation)->assertForbidden();
            $this->user->update(['banned' => false, 'is_admin' => true]);
            $this->send($operation)->assertForbidden();
            $this->user->update(['is_admin' => false]);
            config(['internal_api_slice.enabled' => false]);
            $this->send($operation)->assertNotFound();
            config(['internal_api_slice.enabled' => true]);
        }
        $this->app->detectEnvironment(static fn () => 'production');
        foreach (['user.dashboard.get', 'user.payment-methods.list'] as $operation) {
            $this->send($operation)->assertNotFound();
        }
    }

    private function send(string $operation, array $input = [], string $version = '2', ?string $token = null, ?string $rawInput = null, string $service = 'user-gateway')
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
