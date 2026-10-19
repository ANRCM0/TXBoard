<?php

namespace Tests\Feature\Billing;

use App\Domains\Billing\BillingError;
use App\Domains\Billing\NativeBillingRecovery;
use App\Domains\Billing\OrderCheckout;
use App\Domains\Subscription\Services\PublishedOrderService;
use App\Domains\Subscription\Services\SubscriptionFulfillmentService;
use App\Models\Order;
use App\Services\Auth\NativeIdentityService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\NativeRuntimeTestCase;

final class NativeFreeCheckoutTest extends NativeRuntimeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->activate();
    }

    public function test_http_free_checkout_replays_original_success_after_response_loss_and_durable_recovery(): void
    {
        [$user, $order] = $this->order();
        Sanctum::actingAs($user);
        $path = '/txapi/orders/'.$order->trade_no.'/checkout';
        $this->postJson($path)->assertStatus(409)->assertJsonPath('error.code', 'PAYMENT_IDEMPOTENCY_KEY_REQUIRED');
        $first = $this->postJson($path, [], ['Idempotency-Key' => 'free-original'])
            ->assertOk()->assertJsonPath('data.type', -1)->assertJsonPath('data.data', true)->json('data');
        $this->assertSame(Order::STATUS_PROCESSING, (int) $order->fresh()->status);
        $this->assertSame($first, $this->postJson($path, [], ['Idempotency-Key' => 'free-original'])->assertOk()->json('data'));
        $this->postJson($path, [], ['Idempotency-Key' => 'free-other-key'])
            ->assertStatus(409)->assertJsonPath('error.code', 'FREE_CHECKOUT_IDEMPOTENCY_CONFLICT');
        $this->postJson($path, ['method' => 1], ['Idempotency-Key' => 'free-original'])
            ->assertStatus(409)->assertJsonPath('error.code', 'FREE_CHECKOUT_IDEMPOTENCY_CONFLICT');
        Bus::assertNothingDispatched();
        $this->assertSame('pending', DB::table('tx_native_free_checkout')->value('status'));
        $work = app(NativeBillingRecovery::class)->recover();
        $this->assertSame('completed', array_values($work)[0]['status']);
        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->fresh()->status);
        $this->assertSame($first, $this->postJson($path, [], ['Idempotency-Key' => 'free-original'])->assertOk()->json('data'));
        $this->assertSame([], app(NativeBillingRecovery::class)->recover());
        $this->assertSame(['created', 'payment_accepted', 'fulfilled'],
            DB::table('tx_native_order_event')->where('order_id', $order->id)->orderBy('sequence')->pluck('type')->all());
        $this->assertSame(1, DB::table('tx_subscription_operation')->where('order_id', $order->id)->count());
        foreach (['tx_payment_attempt', 'tx_payment_receipt', 'tx_money_journal', 'tx_order_payment'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
    }

    public function test_free_key_is_permanently_bound_to_the_original_order_and_evidence(): void
    {
        [$user, $first] = $this->order();
        $checkout = app(OrderCheckout::class);
        $checkout->start($user->id, $first->trade_no, requestKey: 'free-key');
        app(NativeBillingRecovery::class)->recover();
        $offer = collect($this->offers)->firstWhere('offer_code', 'monthly');
        $second = app(PublishedOrderService::class)->create($user->id, (int) $offer['offer_id'], 'second-order');
        try {
            $checkout->start($user->id, $second->trade_no, requestKey: 'free-key');
            $this->fail('A permanent free key was rebound.');
        } catch (BillingError $error) {
            $this->assertSame('FREE_CHECKOUT_IDEMPOTENCY_CONFLICT', $error->errorCode);
        }
        $this->assertSame(Order::STATUS_PENDING, (int) $second->fresh()->status);
        DB::table('tx_native_free_checkout')->update(['result_json' => '{"type":1,"data":false}']);
        try {
            $checkout->start($user->id, $first->trade_no, requestKey: 'free-key');
            $this->fail('Damaged free result was replayed.');
        } catch (BillingError $error) {
            $this->assertSame('FREE_CHECKOUT_EVIDENCE_INVALID', $error->errorCode);
        }
    }

    public function test_committed_fulfillment_with_lost_response_recovers_free_work_without_a_second_grant(): void
    {
        [$user, $order] = $this->order();
        app(OrderCheckout::class)->start($user->id, $order->trade_no, requestKey: 'lost-free-grant');
        // Simulate process death after fulfillment commit but before the work owner records completion.
        app(SubscriptionFulfillmentService::class)->fulfill($order->id);
        $this->assertSame('pending', DB::table('tx_native_free_checkout')->value('status'));
        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->fresh()->status);
        $this->assertSame(1, DB::table('tx_subscription_operation')->where('order_id', $order->id)->count());
        $this->assertSame('completed', array_values(app(NativeBillingRecovery::class)->recover())[0]['status']);
        $this->assertSame(1, DB::table('tx_subscription_operation')->where('order_id', $order->id)->count());
    }

    public function test_fulfillment_event_failure_rolls_back_grant_and_recovery_records_one_completion(): void
    {
        [$user, $order] = $this->order();
        app(OrderCheckout::class)->start($user->id, $order->trade_no, requestKey: 'event-grant-atomic');
        $inject = true;
        DB::connection()->beforeExecuting(function (string $sql) use (&$inject): void {
            if ($inject && preg_match('/insert into ["`]tx_native_order_event["`]/i', $sql)) {
                $inject = false;
                throw new \RuntimeException('INJECTED_FULFILLMENT_EVENT_FAILURE');
            }
        });
        try {
            app(SubscriptionFulfillmentService::class)->fulfill($order->id);
            $this->fail('Fulfillment committed without its event.');
        } catch (\RuntimeException $error) {
            $this->assertSame('INJECTED_FULFILLMENT_EVENT_FAILURE', $error->getMessage());
        }
        $this->assertSame(Order::STATUS_PROCESSING, (int) $order->fresh()->status);
        $this->assertSame(0, DB::table('tx_subscription')->count());
        $this->assertSame(0, DB::table('tx_subscription_operation')->count());
        $this->assertSame(['created', 'payment_accepted'], DB::table('tx_native_order_event')
            ->where('order_id', $order->id)->orderBy('sequence')->pluck('type')->all());
        $this->assertSame('pending', DB::table('tx_native_free_checkout')->value('status'));
        $this->assertSame('completed', array_values(app(NativeBillingRecovery::class)->recover())[0]['status']);
        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->fresh()->status);
        $this->assertSame(['created', 'payment_accepted', 'fulfilled'], DB::table('tx_native_order_event')
            ->where('order_id', $order->id)->orderBy('sequence')->pluck('type')->all());
        $this->assertSame([], app(NativeBillingRecovery::class)->recover());
    }

    private function order(): array
    {
        [, $user] = app(NativeIdentityService::class)->create(bin2hex(random_bytes(8)).'@example.test', 'runtime-password');
        $offer = collect($this->offers)->firstWhere('offer_code', 'monthly');
        return [$user, app(PublishedOrderService::class)->create($user->id, (int) $offer['offer_id'], 'free-order')];
    }
}
