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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * P0-B synthetic in-process journey. All HTTP and persistence paths are real,
 * but the payment provider and workers are simulated; this is NOT staging E2E.
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
            'server_ws_enable' => 0,
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

        // Use the real password/login endpoint and its Sanctum bearer.
        $login = $this->postJson('/api/v1/passport/auth/login', [
            'email' => $user->email, 'password' => 'sample-password-2026',
        ]);
        $login->assertOk()->assertJsonPath('status', 'success');
        $bearer = $login->json('data.auth_data');
        $this->assertIsString($bearer);
        $this->assertStringStartsWith('Bearer ', $bearer);
        $headers = ['Authorization' => $bearer];

        $create = $this->postJson('/api/v1/user/order/save', [
            'plan_id' => $plan->id, 'period' => 'month_price',
        ], $headers);
        $this->assertSame(200, $create->status(), 'Order create rejected: ' . (string) $create->json('message'));
        $create->assertJsonPath('status', 'success');
        $tradeNo = $create->json('data');
        $this->assertIsString($tradeNo);
        $order = Order::where('trade_no', $tradeNo)->firstOrFail();
        $this->assertSame(1000, (int) $order->total_amount);
        $this->assertSame(Order::STATUS_PENDING, (int) $order->status);
        $this->assertNull($user->fresh()->plan_id);

        // EPay pay() only creates an external redirect URL; nothing is sent.
        $checkout = $this->postJson('/api/v1/user/order/checkout', [
            'trade_no' => $tradeNo, 'method' => $payment->id,
        ], $headers);
        $checkout->assertOk()->assertJsonPath('type', 1);
        $this->assertStringContainsString('payment.invalid/submit.php?', (string) $checkout->json('data'));
        $this->assertSame((int) $payment->id, (int) $order->fresh()->payment_id);

        $payload = [
            'pid' => 'p0b-merchant', 'out_trade_no' => $tradeNo,
            'trade_no' => 'p0b-provider-transaction-1',
            'money' => '10.00', 'trade_status' => 'TRADE_SUCCESS',
        ];
        ksort($payload);
        $payload['sign'] = md5(stripslashes(urldecode(http_build_query($payload))) . 'p0b-test-only-secret');
        $payload['sign_type'] = 'MD5';
        $endpoint = '/api/v1/guest/payment/notify/EPay/' . $payment->uuid;

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
        $this->getJson('/api/v1/user/server/fetch', $headers)
            ->assertOk()->assertJsonPath('data.0.id', $server->id);
        $this->postJson('/api/v2/server/handshake', [
            'token' => 'p0b-node-secret', 'node_id' => $server->id,
        ])->assertOk()->assertJsonPath('websocket.enabled', false);

        $report = [
            'token' => 'p0b-node-secret', 'node_id' => $server->id,
            'traffic_batch_id' => 'p0b-traffic-0001',
            'traffic' => [$user->id => [100, 300]],
        ];
        $this->postJson('/api/v2/server/report', $report)
            ->assertOk()->assertJsonPath('data', true);
        $jobs = Bus::dispatched(TrafficBatchJob::class);
        $this->assertCount(1, $jobs);
        $jobs->first()->handle();
        $jobs->first()->handle();

        $this->assertSame(200, (int) $user->fresh()->u);
        $this->assertSame(600, (int) $user->fresh()->d);
        $this->assertSame(1, DB::table('v2_traffic_batch')->count());
        $this->assertSame(800, (int) DB::table('v2_stat_user')
            ->where('user_id', $user->id)->sum(DB::raw('u + d')));
        $this->assertSame(400, (int) ($server->fresh()->u + $server->fresh()->d));
        $this->assertSame(1, DB::table('v2_stat_server')->where('server_id', $server->id)->count());

        $this->getJson('/api/v1/user/order/check?trade_no=' . rawurlencode($tradeNo), $headers)
            ->assertOk()->assertJsonPath('data', Order::STATUS_COMPLETED);
    }
}
