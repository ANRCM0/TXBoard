<?php

namespace Tests\Unit\Domains\Billing;

use App\Domains\Billing\OrderPurchaseTermsSnapshot;
use App\Models\Order;
use App\Models\Plan;
use Tests\TestCase;
use InvalidArgumentException;

class OrderPurchaseTermsSnapshotTest extends TestCase
{
    public function test_snapshot_freezes_entitlement_price_and_hash(): void
    {
        $plan = new Plan(['name' => 'Gold', 'group_id' => 7, 'transfer_enable' => 2, 'speed_limit' => 30, 'device_limit' => 4, 'prices' => ['monthly' => '10.00'], 'reset_traffic_method' => 1]);
        $plan->id = 9;
        $order = new Order(['user_id' => 3, 'plan_id' => 9, 'period' => 'monthly', 'type' => Order::TYPE_NEW_PURCHASE, 'total_amount' => 875]);
        $order->id = 44;
        $snapshot = app(OrderPurchaseTermsSnapshot::class)->build($order, $plan, 'CNY', 1, 'Asia/Shanghai');
        self::assertSame('875', $snapshot['price_minor']);
        self::assertSame('2147483648', $snapshot['quota_bytes']);
        self::assertSame(7, $snapshot['group_id']);
        self::assertSame(30, $snapshot['speed_limit_mbps']);
        self::assertSame(4, $snapshot['device_limit']);
        self::assertSame(1, $snapshot['reset_policy']['method']);
        self::assertSame(1, $snapshot['duration_months']);
        self::assertSame('subscription', $snapshot['purchase_kind']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $snapshot['content_hash']);
    }

    public function test_snapshot_rejects_unsupported_currency(): void
    {
        $plan = new Plan(['transfer_enable' => 1, 'prices' => ['monthly' => '10.001']]);
        $plan->id = 1;
        $order = new Order(['user_id' => 1, 'plan_id' => 1, 'period' => 'monthly', 'type' => Order::TYPE_NEW_PURCHASE, 'total_amount' => 1000]);
        $order->id = 1;
        $this->expectException(InvalidArgumentException::class);
        app(OrderPurchaseTermsSnapshot::class)->build($order, $plan, 'JPY', 1);
    }

    public function test_snapshot_refuses_a_catalog_price_that_cannot_be_expressed_in_minor_units(): void
    {
        $plan = new Plan(['transfer_enable' => 1, 'prices' => ['monthly' => '10.001']]);
        $plan->id = 1;
        $order = new Order(['user_id' => 1, 'plan_id' => 1, 'period' => 'monthly', 'type' => Order::TYPE_NEW_PURCHASE, 'total_amount' => 1000]);
        $order->id = 1;
        $this->expectException(\RuntimeException::class);
        app(OrderPurchaseTermsSnapshot::class)->build($order, $plan, 'CNY', 1);
    }

    public function test_snapshot_rejects_plan_identity_mismatch(): void
    {
        $plan = new Plan(['transfer_enable' => 1, 'prices' => ['monthly' => 10]]);
        $plan->id = 2;
        $order = new Order(['user_id' => 1, 'plan_id' => 3, 'period' => 'monthly', 'type' => Order::TYPE_NEW_PURCHASE, 'total_amount' => 1000]);
        $order->id = 1;
        $this->expectException(InvalidArgumentException::class);
        app(OrderPurchaseTermsSnapshot::class)->build($order, $plan, 'CNY', 1);
    }
}
