<?php

namespace Tests\Feature\Journey;

use App\Jobs\OrderHandleJob;
use App\Jobs\TrafficBatchJob;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Plugin;
use App\Models\Server;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\Plugin\PluginManager;
use App\Services\Plugin\HookManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * P0-B synthetic in-process journey using exclusively native TXAPI HTTP routes.
 * All HTTP and persistence paths are real, but the provider and workers are
 * simulated; this is NOT staging E2E or external TX-Node interoperability.
 */
class CorePurchaseTrafficJourneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_purchase_signed_payment_fulfilment_node_and_traffic_are_consistent(): void
    {
        Bus::fake();
        Redis::shouldReceive('sadd')->zeroOrMoreTimes()->andReturn(1);
        admin_setting([
            'captcha_enable' => 0,
            'server_token' => 'p0b-node-secret',
        ]);

        Plugin::create([
            'name' => 'EPay', 'code' => 'epay', 'type' => 'payment',
            'version' => '1.0.0', 'is_enabled' => true, 'config' => '{}',
        ]);
        $payment = Payment::create([
            'uuid' => 'p0b_epay_gateway_00000000000001',
            'payment' => 'EPay', 'name' => 'Synthetic EPay',
            'enable' => true,
            'config' => ['pid' => 'p0b-merchant', 'key' => 'p0b-test-only-secret',
                'url' => 'https://payment.invalid'],
            'created_at' => time(), 'updated_at' => time(),
        ]);
        $user = User::create([
            'email' => 'p0b-journey@example.test',
            'password' => password_hash('sample-password-2026', PASSWORD_DEFAULT),
            'uuid' => '00000000-0000-0000-0000-000000000123',
            'token' => '1234567890abcdef1234567890abcdef',
            'balance' => 0, 'commission_balance' => 0,
            'transfer_enable' => 0, 'u' => 0, 'd' => 0, 'banned' => 0,
            'is_admin' => 0, 'is_staff' => 0, 'expired_at' => 0,
            'created_at' => time(), 'updated_at' => time(),
        ]);
        $plan = Plan::create([
            'group_id' => 1, 'transfer_enable' => 2,
            'name' => 'P0-B Synthetic Plan', 'show' => 1, 'sort' => 0,
            'renew' => 1, 'sell' => 1, 'capacity_limit' => null,
            'prices' => [Plan::PERIOD_MONTHLY => 10],
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
            'created_at' => time(), 'updated_at' => time(),
        ]);

        // Plugin fixtures are inserted after Laravel boot. Simulate the next
        // request boundary so stale pre-fixture scoped plugin state is discarded.
        $this->app->forgetInstance(PluginManager::class);
        HookManager::reset();

        // A seeded provider must be discoverable before HTTP bootstraps plugins.
        $this->assertArrayHasKey('epay', app(PluginManager::class)->getEnabledPaymentPlugins(),
            'EPay was not discoverable immediately after the synthetic fixture');

        // Use the real password/login endpoint and its Sanctum bearer.
        $login = $this->postJson('/txapi/auth/login', [
            'email' => $user->email, 'password' => 'sample-password-2026',
        ]);
        $login->assertOk()->assertJsonStructure(['data' => ['auth_data'], 'request_id']);
        $bearer = $login->json('data.auth_data');
        $this->assertIsString($bearer);
        $this->assertStringStartsWith('Bearer ', $bearer);
        $headers = ['Authorization' => $bearer];

        $create = $this->postJson('/txapi/orders', [
            'plan_id' => $plan->id, 'period' => Plan::PERIOD_MONTHLY,
        ], $headers);
        $create->assertStatus(201)->assertJsonStructure(['data' => ['trade_no'], 'request_id']);
        $tradeNo = $create->json('data.trade_no');
        $this->assertIsString($tradeNo);
        $order = Order::where('trade_no', $tradeNo)->firstOrFail();
        $this->assertSame(1000, (int) $order->total_amount);
        $this->assertSame(Order::STATUS_PENDING, (int) $order->status);
        $this->assertNull($user->fresh()->plan_id);

        // Verify that the payment provider remains enabled and hook-registered
        // on both SQLite and MySQL before the checkout invokes it.
        $this->assertTrue(Plugin::query()->where('code', 'epay')->where('is_enabled', true)
            ->where('type', 'payment')->exists(), 'Payment plugin record is inactive');
        $this->assertArrayHasKey('epay', app(PluginManager::class)->getEnabledPaymentPlugins(),
            'Enabled payment plugin was not loaded');
        $this->assertArrayHasKey('EPay', (new PaymentService('temp'))->getAvailablePaymentMethods(),
            'EPay payment hook was not registered');

        // EPay pay() only creates an external redirect URL; nothing is sent.
        $checkout = $this->postJson('/txapi/orders/' . $tradeNo . '/checkout', [
            'method' => $payment->id,
        ], $headers);
        $checkout->assertOk()->assertJsonPath('data.type', 1);
        $this->assertStringContainsString('payment.invalid/submit.php?', (string) $checkout->json('data.data'));
        $this->assertSame((int) $payment->id, (int) $order->fresh()->payment_id);

        $payload = [
            'pid' => 'p0b-merchant', 'out_trade_no' => $tradeNo,
            'trade_no' => 'p0b-provider-transaction-1',
            'money' => '10.00', 'trade_status' => 'TRADE_SUCCESS',
        ];
        ksort($payload);
        $payload['sign'] = md5(stripslashes(urldecode(http_build_query($payload))) . 'p0b-test-only-secret');
        $payload['sign_type'] = 'MD5';
        $endpoint = '/txapi/payment/webhook/EPay/' . $payment->uuid;

        $this->post($endpoint, $payload)->assertOk()->assertSeeText('success');
        $this->assertSame(Order::STATUS_PROCESSING, (int) $order->fresh()->status);
        Bus::assertDispatchedTimes(OrderHandleJob::class, 1);

        // A repeated valid event cannot dispatch a second fulfilment.
        $this->post($endpoint, $payload)->assertOk()->assertSeeText('success');
        Bus::assertDispatchedTimes(OrderHandleJob::class, 1);

        // Explicitly run the queued worker to verify persisted entitlement.
        (new OrderHandleJob($tradeNo))->handle();
        (new OrderHandleJob($tradeNo))->handle();
        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->fresh()->status);
        $this->assertSame((int) $plan->id, (int) $user->fresh()->plan_id);
        $this->assertSame(2 * 1073741824, (int) $user->fresh()->transfer_enable);
        $this->assertGreaterThan(time(), (int) $user->fresh()->expired_at);

        $server = Server::create([
            'name' => 'p0b-node', 'type' => Server::TYPE_VMESS,
            'host' => '127.0.0.1', 'port' => '443', 'server_port' => 443,
            'rate' => 2, 'group_ids' => ['1'], 'show' => true, 'enabled' => true,
        ]);
        $this->getJson('/txapi/me/nodes', $headers)
            ->assertOk()->assertJsonPath('data.0.id', $server->id);
        $nodeHeaders = [
            'Authorization' => 'Bearer p0b-node-secret',
            'X-TX-Node-ID' => (string) $server->id,
        ];
        $this->postJson('/txapi/node/v1/handshake', [], $nodeHeaders)
            ->assertOk()->assertJsonPath('data.websocket.enabled', false);

        $report = [
            'protocol_version' => 1, 'traffic_batch_id' => 'p0b-traffic-0001',
            'traffic' => [$user->id => [100, 300]],
        ];
        $this->postJson('/txapi/node/v1/report', $report, $nodeHeaders)
            ->assertStatus(202)->assertJsonPath('data.settlement', 'queued');
        $jobs = Bus::dispatched(TrafficBatchJob::class);
        $this->assertCount(1, $jobs);
        $jobs->first()->handle();
        $jobs->first()->handle();

        $this->assertSame(200, (int) $user->fresh()->u);
        $this->assertSame(600, (int) $user->fresh()->d);
        $this->assertSame(1, DB::table('tx_traffic_batch')->count());
        $this->assertSame(800, (int) DB::table('tx_stat_user')
            ->where('user_id', $user->id)->sum(DB::raw('u + d')));
        $this->assertSame(400, (int) ($server->fresh()->u + $server->fresh()->d));
        $this->assertSame(1, DB::table('tx_stat_server')->where('server_id', $server->id)->count());

        $this->getJson('/txapi/orders/' . rawurlencode($tradeNo), $headers)
            ->assertOk()->assertJsonPath('data.status', Order::STATUS_COMPLETED);
    }
}
