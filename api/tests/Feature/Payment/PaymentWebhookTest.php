<?php

namespace Tests\Feature\Payment;

use App\Models\Order;
use App\Models\Payment;
use App\Domains\Billing\NativePaymentCheckout;
use App\Domains\Billing\NativeBillingRecovery;
use App\Domains\Subscription\Services\PublishedOrderService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\NativePaymentTestCase;

class PaymentWebhookTest extends NativePaymentTestCase
{
    protected string $publicationCurrency = 'CNY';
    protected array $planPrices = ['monthly' => '10.00', 'onetime' => '0.00'];
    private array $references = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://core.example.test']);
        $this->payment->update(['payment' => 'EPay', 'config' => [
            'pid' => 'merchant-1', 'key' => 'test-secret', 'url' => 'https://payment.example.test',
        ]]);
        Http::preventStrayRequests();
    }

    public function test_missing_order_must_not_be_acknowledged_as_paid(): void
    {
        $payment = $this->payment();
        $response = $this->postCallback($payment, 'missing-order');

        $response->assertStatus(422);
        $this->assertNotSame('success', $response->getContent());
    }

    public function test_signed_underpayment_cannot_settle_order(): void
    {
        $payment = $this->payment();
        $order = $this->order($payment);

        $this->postCallback($payment, $order->trade_no, ['money' => '9.99'])
            ->assertStatus(500);
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
    }

    public function test_signed_pending_event_cannot_settle_order(): void
    {
        $payment = $this->payment();
        $order = $this->order($payment);

        $this->postCallback($payment, $order->trade_no, ['trade_status' => 'WAIT_BUYER_PAY'])
            ->assertStatus(500);
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
    }

    public function test_signed_callback_for_a_different_merchant_is_rejected(): void
    {
        $payment = $this->payment();
        $order = $this->order($payment);

        $this->postCallback($payment, $order->trade_no, ['pid' => 'other'])
            ->assertStatus(500);
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
    }

    public function test_correctly_signed_callback_cannot_settle_another_gateway_order(): void
    {
        $payment = $this->payment();
        $otherPayment = $this->payment('another_gateway_uuid_value00001');
        $order = $this->order($otherPayment);

        $this->postCallback($payment, $order->trade_no)->assertStatus(500);
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
    }

    public function test_cancelled_order_records_late_payment_as_liability_without_fulfillment(): void
    {
        $payment = $this->payment();
        $order = $this->order($payment);
        app(PublishedOrderService::class)->transitionOrder($order->id,
            static fn (Order $locked) => $locked->update(['status' => Order::STATUS_CANCELLED]));

        $this->postCallback($payment, $order->trade_no)->assertOk()->assertSeeText('success');
        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertSame(1, DB::table('tx_payment_receipt')->count());
        $this->assertSame('1000', (string) DB::table('tx_money_account')->where('purpose', 'refund_liability')->value('balance_minor'));
        $this->assertSame(0, DB::table('tx_order_payment')->count());
        $this->assertSame(0, DB::table('tx_billing_work')->count());
    }

    public function test_repeated_callback_needs_the_same_provider_transaction(): void
    {
        $payment = $this->payment();
        $order = $this->order($payment);
        $this->postCallback($payment, $order->trade_no)->assertOk();
        app(NativeBillingRecovery::class)->recover();
        $this->assertSame(Order::STATUS_COMPLETED, $order->fresh()->status);

        $this->postCallback($payment, $order->trade_no)->assertOk()
            ->assertSeeText('success');
        $this->postCallback($payment, $order->trade_no, ['trade_no' => 'provider-trade-2'])
            ->assertStatus(500);
    }

    public function test_wrong_gateway_endpoint_and_bad_signature_are_rejected(): void
    {
        $payment = $this->payment();
        $order = $this->order($payment);

        $this->post('/txapi/payment/webhook/MGate/' . $payment->uuid,
            $this->signedPayload($order->trade_no))->assertStatus(500);

        $payload = $this->signedPayload($order->trade_no);
        $payload['sign'] = 'not-valid';
        $this->post('/txapi/payment/webhook/EPay/' . $payment->uuid, $payload)
            ->assertStatus(500);
    }

    public function test_valid_payment_settles_order_once(): void
    {
        $payment = $this->payment();
        $order = $this->order($payment);

        $this->postCallback($payment, $order->trade_no)->assertOk()
            ->assertSeeText('success');
        $this->assertSame(Order::STATUS_PROCESSING, $order->fresh()->status);
        $this->assertSame('provider-trade-1', $order->fresh()->callback_no);

        $this->postCallback($payment, $order->trade_no)->assertOk();
        $this->assertSame(Order::STATUS_PROCESSING, $order->fresh()->status);
    }

    public function test_repeated_native_callback_has_exactly_one_settlement(): void
    {
        \Illuminate\Support\Facades\Bus::fake();
        $payment = $this->payment();
        $order = $this->order($payment);
        $uri = '/txapi/payment/webhook/EPay/' . $payment->uuid;
        $signed = $this->signedPayload($order->trade_no);

        $this->post($uri, $signed)->assertOk()->assertSeeText('success');
        $this->postCallback($payment, $order->trade_no)
            ->assertOk()->assertSeeText('success');
        $this->assertSame(Order::STATUS_PROCESSING, (int) $order->fresh()->status);
        $this->assertSame('provider-trade-1', $order->fresh()->callback_no);
        $this->assertSame(1, DB::table('tx_payment_receipt')->count());
        $this->assertSame(1, DB::table('tx_order_payment')->count());
        $this->assertSame(1, DB::table('tx_billing_work')->where('kind', 'order.fulfill')->count());
        \Illuminate\Support\Facades\Bus::assertNotDispatched(\App\Jobs\OrderHandleJob::class);

        $otherTransaction = $this->signedPayload($order->trade_no,
            ['trade_no' => 'another-provider-transaction']);
        $this->post($uri, $otherTransaction)->assertStatus(500);
        $this->assertSame('provider-trade-1', $order->fresh()->callback_no);
    }

    public function test_native_callback_rejects_invalid_signature_wrong_provider_and_amount(): void
    {
        $payment = $this->payment();
        $different = $this->payment('native-other-provider-uuid');
        $order = $this->order($payment);
        $uri = '/txapi/payment/webhook/EPay/' . $payment->uuid;

        $invalid = $this->signedPayload($order->trade_no);
        $invalid['sign'] = 'forged';
        $this->post($uri, $invalid)->assertStatus(500)->assertSeeText('fail');
        $this->post($uri, $this->signedPayload($order->trade_no,
            ['money' => '9.99']))->assertStatus(500)->assertSeeText('fail');
        $this->post('/txapi/payment/webhook/EPay/' . $different->uuid,
            $this->signedPayload($order->trade_no))->assertStatus(500);
        $this->assertSame(Order::STATUS_PENDING, (int) $order->fresh()->status);
    }

    public function test_native_get_callback_supports_provider_query_parameters(): void
    {
        \Illuminate\Support\Facades\Bus::fake();
        $payment = $this->payment();
        $order = $this->order($payment);
        $url = '/txapi/payment/webhook/EPay/' . $payment->uuid . '?'
            . http_build_query($this->signedPayload($order->trade_no));
        $this->get($url)->assertOk()->assertSeeText('success');
        $this->assertSame(Order::STATUS_PROCESSING, (int) $order->fresh()->status);
    }

    public function test_legacy_webhook_route_is_not_registered(): void
    {
        $this->assertNotContains('api/v1/guest/payment/notify/{method}/{uuid}', array_map(
            static fn ($route) => $route->uri(), \Illuminate\Support\Facades\Route::getRoutes()->getRoutes()
        ));
    }

    private function payment(string $uuid = 'default'): Payment
    {
        if ($uuid === 'default') { return $this->payment; }
        return Payment::create([
            'uuid' => $uuid, 'payment' => 'EPay', 'name' => 'EPay test', 'enable' => true,
            'config' => $this->payment->config,
        ]);
    }

    private function order(Payment $payment): Order
    {
        [$user, $order] = $this->purchase();
        $started = app(NativePaymentCheckout::class)->start($user->id, $order->id, $payment->id,
            'webhook-'.bin2hex(random_bytes(8)));
        $snapshot = json_decode(Crypt::decryptString(DB::table('tx_payment_dispatch')
            ->where('attempt_id', $started['attempt']['attempt_id'])->value('snapshot_ciphertext')), true, 16, JSON_THROW_ON_ERROR);
        $this->references[$order->trade_no] = $snapshot['provider_order_ref'];
        return $order;
    }

    private function postCallback(Payment $payment, string $orderNo, array $overrides = [])
    {
        return $this->post('/txapi/payment/webhook/EPay/' . $payment->uuid,
            $this->signedPayload($orderNo, $overrides));
    }

    private function signedPayload(string $orderNo, array $overrides = []): array
    {
        $params = array_merge([
            'pid' => 'merchant-1',
            'out_trade_no' => $this->references[$orderNo] ?? $orderNo,
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
