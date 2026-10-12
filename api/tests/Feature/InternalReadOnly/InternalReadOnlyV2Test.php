<?php

namespace Tests\Feature\InternalReadOnly;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class InternalReadOnlyV2Test extends TestCase
{
    use RefreshDatabase;

    public function test_full_version_two_user_dtos_reuse_native_authority_without_changing_version_one_or_admin(): void
    {
        $directory = sys_get_temp_dir().'/txboard-internal-v2-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
            openssl_pkey_export($key, $private);
            file_put_contents($directory.'/proof.pub', openssl_pkey_get_details($key)['key']);
            file_put_contents($directory.'/trust.json', json_encode([
                'proof_keys' => ['user-1' => ['epoch' => 1, 'service' => 'user-gateway', 'revoked' => false,
                    'not_before' => time()-60, 'not_after' => time()+3600, 'public_key_path' => $directory.'/proof.pub']],
                'services' => ['user-gateway' => ['certificates' => [str_repeat('a', 64) => ['revoked' => false,
                    'not_before' => time()-60, 'not_after' => time()+3600]]]],
            ]));
            config(['internal_api_slice.enabled' => true, 'internal_api_slice.trust_file' => $directory.'/trust.json']);
            $user = User::create(['email' => 'internal-v2@example.test', 'password' => 'test-hash', 'uuid' => (string) Str::uuid(),
                'token' => 'private-v2-token', 'telegram_id' => -12345, 'banned' => false]);
            $token = 'Bearer '.$user->createToken('v2', ['*'], now()->addHour())->plainTextToken;
            $plan = Plan::create(['name' => 'V2 detail', 'group_id' => 1, 'transfer_enable' => 3,
                'show' => true, 'sell' => true, 'renew' => true, 'prices' => [Plan::PERIOD_MONTHLY => 12]]);
            $order = Order::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'trade_no' => 'v2-order',
                'period' => Plan::PERIOD_MONTHLY, 'type' => 1, 'status' => 4, 'total_amount' => 650,
                'balance_amount' => 350, 'discount_amount' => 200, 'callback_no' => 'private-v2-callback']);
            $call = function (string $operation, string $version, array $input = []) use ($private, $token) {
                $body = json_encode(['contract_version' => $version, 'request_id' => (string) Str::uuid(),
                    'deadline_at' => time()+5, 'input' => (object) $input]);
                $path = '/txapi/internal/v1/operations/'.$operation;
                $proof = JWT::encode(['v' => 'internal-readonly/1', 'epoch' => 1, 'iss' => 'txboard-local-tls-boundary',
                    'aud' => 'txboard-core-local', 'service' => 'user-gateway', 'cert_sha256' => str_repeat('a', 64),
                    'method' => 'POST', 'path' => $path, 'body_sha256' => hash('sha256', $body), 'actor_sha256' => hash('sha256', $token),
                    'iat' => time(), 'nbf' => time()-2, 'exp' => time()+10, 'jti' => bin2hex(random_bytes(16))], $private, 'RS256', 'user-1');
                return $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
                    'HTTP_AUTHORIZATION' => $token, 'HTTP_X_TXBOARD_SERVICE_PROOF' => $proof], $body);
            };
            $v1 = $call('user.profile.get', '1')->assertOk()->assertJsonPath('meta.slice', 'internal-readonly/1');
            $this->assertArrayNotHasKey('telegram_id', $v1->json('data'));
            $call('user.profile.get', '2')->assertOk()->assertJsonPath('data.telegram_id', '-12345')->assertJsonPath('meta.slice', 'internal-readonly/2');
            $light = $call('user.order.get', '1', ['trade_no' => $order->trade_no])->assertOk();
            $this->assertArrayNotHasKey('payment_id', $light->json('data'));
            $full = $call('user.order.get', '2', ['trade_no' => $order->trade_no])->assertOk()
                ->assertJsonPath('data.balance_amount_minor', '350')->assertJsonPath('data.discount_amount_minor', '200')
                ->assertJsonPath('data.plan.traffic_limit_bytes', '3221225472')->assertJsonPath('data.payment_id', null)->assertJsonPath('data.status', 4);
            $this->assertStringNotContainsString('private-v2-callback', $full->getContent());
            $call('admin.order.get', '2', ['id' => (string) $order->id, 'admin_path' => 'test'])->assertStatus(400);
            $order->update(['trade_no' => str_repeat('A', 100)]);
            $call('user.order.get', '1', ['trade_no' => $order->trade_no])->assertStatus(400);
            $call('user.order.get', '2', ['trade_no' => $order->trade_no])->assertOk();
            $order->update(['user_id' => $user->id + 100]);
            $call('user.order.get', '2', ['trade_no' => $order->trade_no])->assertNotFound();
            $order->update(['user_id' => $user->id, 'plan_id' => $plan->id + 100]);
            $call('user.order.get', '1', ['trade_no' => 'missing'])->assertNotFound();
            $call('user.order.get', '2', ['trade_no' => $order->trade_no])->assertNotFound();
            $user->update(['banned' => true]);
            $call('user.profile.get', '2')->assertForbidden();
            $user->update(['banned' => false, 'is_admin' => true]);
            $call('user.profile.get', '2')->assertForbidden();
            config(['internal_api_slice.enabled' => false]);
            $call('user.profile.get', '2')->assertNotFound();
            config(['internal_api_slice.enabled' => true]);
            $this->app->detectEnvironment(static fn () => 'production');
            $call('user.profile.get', '2')->assertNotFound();
        } finally {
            foreach (glob($directory.'/*') ?: [] as $file) unlink($file);
            rmdir($directory);
        }
    }
}
