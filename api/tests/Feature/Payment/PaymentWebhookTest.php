<?php

namespace Tests\Feature\Payment;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Plugin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Plugin::create([
            'name' => 'EPay',
            'code' => 'epay',
            'type' => 'payment',
            'version' => '1.0.0',
            'is_enabled' => true,
            'config' => '{}',
        ]);
    }

    public function test_missing_order_must_not_be_acknowledged_as_paid(): void
    {
        $payment = $this->payment();
        $response = $this->postCallback($payment, 'missing-order');

        $response->assertStatus(400);
        $this->assertNotSame('success', $response->getContent());
    }

    public function test_signed_underpayment_cannot_settle_order(): void
    {
        $payment = $this->payment();
        $order = $this->order($payment, 1000, 100);

        $this->postCallback($payment, $order->trade_no, ['money' => '10.99'])
            ->assertStatus(400);
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
    }

    public function test_signed_pending_event_cannot_settle_order(): void
    {
        $payment = $this->payment();
        $order = $this->order($payment);

        $this->postCallback($payment, $order->trade_no, ['trade_status' => 'WAIT_BUYER_PAY'])
            ->assertStatus(422);
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
    }

    public function test_signed_callback_for_a_different_merchant_is_rejected(): void
    {
        $payment = $this->payment();
        $order = $this->order($payment);

        $this->postCallback($payment, $order->trade_no, ['pid' => 'other'])
            ->assertStatus(422);
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
    }

    public function test_correctly_signed_callback_cannot_settle_another_gateway_order(): void
    {
        $payment = $this->payment();
        $otherPayment = $this->payment('another_gateway_uuid_value00001');
        $order = $this->order($otherPayment);

        $this->postCallback($payment, $order->trade_no)->assertStatus(400);
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
    }

    public function test_cancelled_order_cannot_be_acknowledged_as_paid(): void
    {
        $payment = $this->payment();
        $order = $this->order($payment);
        $order->update(['status' => Order::STATUS_CANCELLED]);

        $this->postCallback($payment, $order->trade_no)->assertStatus(400);
        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
    }

    public function test_repeated_callback_needs_the_same_provider_transaction(): void
    {
        $payment = $this->payment();
        $order = $this->order($payment);
        $order->update([
            'status' => Order::STATUS_COMPLETED,
            'callback_no' => 'provider-trade-1',
        ]);

        $this->postCallback($payment, $order->trade_no)->assertOk()
            ->assertSeeText('success');
        $this->postCallback($payment, $order->trade_no, ['trade_no' => 'provider-trade-2'])
            ->assertStatus(400);
    }

    public function test_wrong_gateway_endpoint_and_bad_signature_are_rejected(): void
    {
        $payment = $this->payment();
        $order = $this->order($payment);

        $this->post('/api/v1/guest/payment/notify/MGate/' . $payment->uuid,
            $this->signedPayload($order->trade_no))->assertStatus(422);

        $payload = $this->signedPayload($order->trade_no);
        $payload['sign'] = 'not-valid';
        $this->post('/api/v1/guest/payment/notify/EPay/' . $payment->uuid, $payload)
            ->assertStatus(422);
    }

    public function test_valid_payment_settles_order_once(): void
    {
        $payment = $this->payment();
        $order = $this->order($payment);

        $this->postCallback($payment, $order->trade_no)->assertOk()
            ->assertSeeText('success');
        $this->assertSame(Order::STATUS_COMPLETED, $order->fresh()->status);
        $this->assertSame('provider-trade-1', $order->fresh()->callback_no);

        $this->postCallback($payment, $order->trade_no)->assertOk();
        $this->assertSame(Order::STATUS_COMPLETED, $order->fresh()->status);
    }

    private function payment(string $uuid = 'epay_gateway_uuid_value_00000001'): Payment
    {
        return Payment::create([
            'uuid' => $uuid,
            'payment' => 'EPay',
            'name' => 'EPay test',
            'enable' => true,
            'config' => ['pid' => 'merchant-1', 'key' => 'test-secret', 'url' => 'https://example.invalid'],
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function order(Payment $payment, int $total = 1000, int $fee = 0): Order
    {
        $user = User::create([
            'email' => uniqid('payment_', true) . '@example.com',
            'password' => 'password',
            'uuid' => '00000000-0000-0000-0000-000000000001',
            'token' => '0123456789abcdef0123456789abcdef',
            'balance' => 0,
            'commission_balance' => 0,
            'transfer_enable' => 0,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
            'is_admin' => 0,
            'is_staff' => 0,
            'expired_at' => 0,
            'remind_expire' => 1,
            'remind_traffic' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $plan = Plan::create([
            'group_id' => null,
            'transfer_enable' => 1111,
            'name' => 'Payment Test Plan',
            'speed_limit' => null,
            'show' => 1,
            'sort' => 0,
            'renew' => 1,
            'prices' => [Plan::PERIOD_MONTHLY => 10],
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
            'capacity_limit' => null,
            'sell' => 1,
            'device_limit' => null,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        return Order::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'payment_id' => $payment->id,
            'type' => Order::TYPE_NEW_PURCHASE,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => uniqid('payment_order_', true),
            'total_amount' => $total,
            'handling_amount' => $fee,
            'balance_amount' => 0,
            'status' => Order::STATUS_PENDING,
            'commission_status' => 0,
            'commission_balance' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function postCallback(Payment $payment, string $orderNo, array $overrides = [])
    {
        return $this->post('/api/v1/guest/payment/notify/EPay/' . $payment->uuid,
            $this->signedPayload($orderNo, $overrides));
    }

    private function signedPayload(string $orderNo, array $overrides = []): array
    {
        $params = array_merge([
            'pid' => 'merchant-1',
            'out_trade_no' => $orderNo,
            'trade_no' => 'provider-trade-1',
            'money' => '10.00',
            'trade_status' => 'TRADE_SUCCESS',
        ], $overrides);
        ksort($params);
        $params['sign'] = md5(stripslashes(urldecode(http_build_query($params))) . 'test-secret');
        $params['sign_type'] = 'MD5';
        return $params;
    }
}
