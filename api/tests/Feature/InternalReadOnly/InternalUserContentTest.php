<?php

namespace Tests\Feature\InternalReadOnly;

use App\Models\Order;
use App\Models\Plan;
use App\Models\Notice;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class InternalUserContentTest extends TestCase
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

    public function test_subscription_summary_preserves_current_terms_but_excludes_credentials_and_missing_plan(): void
    {
        $this->user->update(['plan_id' => $this->plan->id, 'u' => 100, 'd' => 200, 'transfer_enable' => 1073741824,
            'device_limit' => 0, 'speed_limit' => 2.5, 'expired_at' => 1791849600]);
        $before = DB::table('tx_user')->where('id', $this->user->id)->first();
        $response = $this->send('user.subscription-summary.get')->assertOk()
            ->assertJsonPath('data.plan.id', (string) $this->plan->id)->assertJsonPath('data.plan.traffic_limit_bytes', '1073741824')
            ->assertJsonPath('data.upload_bytes', '100')->assertJsonPath('data.download_bytes', '200')
            ->assertJsonPath('data.device_limit', '0')->assertJsonPath('data.speed_limit_mbps', 2.5)
            ->assertJsonPath('data.expired_at', 1791849600)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(['plan', 'reset_day', 'upload_bytes', 'download_bytes', 'traffic_limit_bytes', 'device_limit',
            'speed_limit_mbps', 'expired_at', 'next_reset_at'], array_keys($response->json('data')));
        foreach (['private-list-token', $this->user->uuid, $this->user->email, 'subscribe_url', 'password'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->assertEquals($before, DB::table('tx_user')->where('id', $this->user->id)->first());
        DB::table('tx_plan')->where('id', $this->plan->id)->delete();
        $this->send('user.subscription-summary.get')->assertStatus(409)->assertJsonPath('error.code', 'PLAN_UNAVAILABLE');
        DB::table('tx_user')->where('id', $this->user->id)->update(['plan_id' => null, 'expired_at' => null]);
        $this->send('user.subscription-summary.get')->assertOk()->assertJsonPath('data.plan', null)->assertJsonPath('data.expired_at', null);
    }

    public function test_visible_notices_use_native_order_and_bounded_pages_without_mutation(): void
    {
        $first = $this->notice('first', 1);
        $second = $this->notice('second', 1);
        $third = $this->notice('third', 2);
        $this->notice('private-hidden', 0, false);
        $before = DB::table('tx_notice')->orderBy('id')->get()->toJson();
        $response = $this->send('user.notice.list', ['page' => 1, 'per_page' => 2])->assertOk()
            ->assertJsonPath('data.pagination', ['page' => 1, 'per_page' => 2, 'total' => 3, 'last_page' => 2])
            ->assertJsonPath('data.items.0.id', (string) $second->id)->assertJsonPath('data.items.1.id', (string) $first->id)
            ->assertJsonPath('data.items.0.tags', ['fixture'])->assertJsonPath('data.items.0.created_at', 1791763200)
            ->assertJsonPath('data.items.0.content', '<p>Fixture {{subscribeUrl}}</p>');
        $this->assertSame(['id', 'title', 'content', 'img_url', 'tags', 'created_at'], array_keys($response->json('data.items.0')));
        $this->assertStringNotContainsString('private-hidden', $response->getContent());
        $this->send('user.notice.list', ['page' => 2, 'per_page' => 2])->assertOk()->assertJsonPath('data.items.0.id', (string) $third->id);
        $this->send('user.notice.list', ['page' => 3, 'per_page' => 2])->assertOk()->assertJsonPath('data.items', []);
        $this->assertSame($before, DB::table('tx_notice')->orderBy('id')->get()->toJson());
    }

    public function test_exact_input_and_current_actor_or_service_gates(): void
    {
        foreach (['user.subscription-summary.get' => [], 'user.notice.list' => ['page' => 1, 'per_page' => 2]] as $op => $input) {
            $this->send($op, [...$input, 'user_id' => 1])->assertStatus(400);
            $this->send($op, $input, version: '1')->assertStatus(400);
            $this->send($op, $input, token: 'Bearer missing')->assertUnauthorized();
            $this->send($op, $input, service: 'admin-gateway')->assertUnauthorized();
            $this->user->update(['banned' => true]); $this->send($op, $input)->assertForbidden();
            $this->user->update(['banned' => false, 'is_admin' => true]); $this->send($op, $input)->assertForbidden();
            $this->user->update(['is_admin' => false]);
            config(['internal_api_slice.enabled' => false]); $this->send($op, $input)->assertNotFound();
            config(['internal_api_slice.enabled' => true]);
        }
        foreach ([[], ['page' => 0, 'per_page' => 2], ['page' => '1', 'per_page' => 2], ['page' => 1.0, 'per_page' => 2],
            ['page' => 10000, 'per_page' => 2], ['page' => 1, 'per_page' => 101], ['page' => 1, 'per_page' => 2, 'status' => 0]] as $input) {
            $this->send('user.notice.list', $input)->assertStatus(400);
        }
        $this->send('user.notice.list', ['page' => 1, 'per_page' => 2], rawInput: '{"page":1,"per_page":2,"\\u0070age":2}')->assertStatus(400);
        $this->app->detectEnvironment(static fn () => 'production');
        $this->send('user.subscription-summary.get')->assertNotFound();
        $this->send('user.notice.list', ['page' => 1, 'per_page' => 2])->assertNotFound();
    }

    private function notice(string $name, int $sort, bool $show = true): Notice
    {
        $n = Notice::create(['title' => $name, 'content' => '<p>Fixture {{subscribeUrl}}</p>', 'tags' => ['fixture'], 'sort' => $sort, 'show' => $show]);
        DB::table('tx_notice')->where('id', $n->id)->update(['created_at' => 1791763200]);
        return $n;
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
