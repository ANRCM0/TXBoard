<?php

namespace Tests\Feature\Subscription;

use App\Jobs\OrderHandleJob;
use App\Models\Order;
use App\Services\Auth\NativeIdentityService;
use App\Domains\Subscription\Services\NativePlanCatalog;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\NativeRuntimeTestCase;

final class NativeRuntimeCommerceTest extends NativeRuntimeTestCase
{
    protected array $planPrices = ['monthly' => '0.00', 'yearly' => '12.34'];

    public function test_activated_catalog_and_direct_purchase_allow_only_free_offers_without_reserving_paid_capacity(): void
    {
        $this->activate();
        [, $user] = app(NativeIdentityService::class)->create('commerce@example.test', 'runtime-password');
        Sanctum::actingAs($user);
        $free = collect($this->offers)->firstWhere('offer_code', 'monthly');
        $paid = collect($this->offers)->firstWhere('offer_code', 'yearly');
        $catalog = app(NativePlanCatalog::class)->available();
        $this->assertSame([(string) $free['offer_id']], array_column($catalog, 'offer_id'));
        $this->postJson('/txapi/orders', ['offer_id' => $paid['offer_id']], ['Idempotency-Key' => 'paid-denied'])
            ->assertStatus(409)->assertJsonPath('error.code', 'ORDER_REJECTED');
        foreach (['tx_order', 'tx_order_purchase_terms', 'tx_subscription_slot', 'idempotency_keys'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table.' must not retain a rejected paid purchase');
        }
        $this->postJson('/txapi/orders', ['offer_id' => $free['offer_id']], ['Idempotency-Key' => 'free-admitted'])
            ->assertCreated();
        $order = Order::query()->firstOrFail();
        $this->postJson('/txapi/orders/'.$order->trade_no.'/checkout', ['method' => 1])
            ->assertOk()->assertJsonPath('data.type', -1)->assertJsonPath('data.data', true);
        $this->assertSame(Order::STATUS_PROCESSING, (int) $order->fresh()->status);
        Bus::assertDispatchedTimes(OrderHandleJob::class, 1);
        $this->postJson('/txapi/orders/'.$order->trade_no.'/checkout')->assertStatus(409);
        Bus::assertDispatchedTimes(OrderHandleJob::class, 1);
    }
}
