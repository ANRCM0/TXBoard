<?php

namespace Tests\Feature\Billing;

use App\Jobs\OrderHandleJob;
use App\Models\Order;
use App\Models\OrderPurchaseTerms;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderService;
use App\Services\Plugin\HookManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

/** Exercises the actual transaction/fulfillment entry points, not a copied builder. */
class PurchasedTermsFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        HookManager::reset();
        config(['app.timezone' => 'UTC', 'cache.stores.redis' => ['driver' => 'array']]);
        admin_setting(['currency' => 'USD', 'reset_traffic_method' => Plan::RESET_TRAFFIC_FIRST_DAY_MONTH]);
        Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        HookManager::reset();
        parent::tearDown();
    }

    public function test_actual_creation_and_fulfillment_keep_purchased_grants_after_plan_edit(): void
    {
        $user = $this->user();
        $plan = $this->plan();
        $order = $this->create($user, $plan);
        $terms = $this->terms($order);
        $this->assertSame('tx-core.purchase-terms/v1', $order->fresh()->purchase_terms_version);
        $this->assertSame('2147483648', $terms->quota_bytes);
        $this->assertSame('USD', $terms->currency);
        $this->assertSame((string) $order->id, (string) $terms->source_snapshot['order_id']);
        $this->assertSame((string) $user->id, (string) $terms->source_snapshot['user_id']);
        $plan->update(['transfer_enable' => 99, 'group_id' => 99, 'speed_limit' => 1,
            'device_limit' => 1, 'prices' => ['monthly' => 999]]);

        $this->payAndOpen($order);

        $this->assertGrant($user, $plan->id);
        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->fresh()->status);
        $this->assertSame(1100, (int) $order->fresh()->total_amount);
        $this->assertSame($terms->content_hash, $this->terms($order)->content_hash);
        Bus::assertDispatchedTimes(OrderHandleJob::class, 1);
        $before = $this->state();
        (new OrderService($order->fresh()))->open();
        $this->assertSame($before, $this->state(), 'A completed order must not grant twice.');
    }

    public function test_purchased_order_can_fulfill_after_current_plan_is_deleted(): void
    {
        $user = $this->user();
        $plan = $this->plan();
        $order = $this->create($user, $plan);
        DB::table('tx_plan')->where('id', $plan->id)->delete();

        $this->payAndOpen($order);

        $this->assertGrant($user, $plan->id);
        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->fresh()->status);
        $this->assertSame(1, OrderPurchaseTerms::where('order_id', $order->id)->count());
    }

    public function test_expected_missing_snapshot_refuses_before_grant_or_order_transition(): void
    {
        $order = $this->create($this->user(), $this->plan());
        $this->markPaid($order);
        DB::table('tx_order_purchase_terms')->where('order_id', $order->id)->delete();
        $this->assertOpenRefusedWithoutMutation($order);
    }

    public function test_corrupt_snapshot_payload_refuses_before_grant_or_order_transition(): void
    {
        $order = $this->create($this->user(), $this->plan());
        $this->markPaid($order);
        $snapshot = $this->terms($order)->source_snapshot;
        $snapshot['quota_bytes'] = '999999999';
        DB::table('tx_order_purchase_terms')->where('order_id', $order->id)
            ->update(['source_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);
        $this->assertOpenRefusedWithoutMutation($order);
    }

    public function test_corrupt_snapshot_scalar_projection_refuses(): void
    {
        $order = $this->create($this->user(), $this->plan());
        $this->markPaid($order);
        DB::table('tx_order_purchase_terms')->where('order_id', $order->id)->update(['group_id' => 999]);
        $this->assertOpenRefusedWithoutMutation($order);
    }

    public function test_changed_order_period_cannot_reinterpret_purchased_terms(): void
    {
        $order = $this->create($this->user(), $this->plan());
        $this->markPaid($order);
        DB::table('tx_order')->where('id', $order->id)->update(['period' => Plan::PERIOD_YEARLY]);
        $this->assertOpenRefusedWithoutMutation($order);
    }

    public function test_changed_order_owner_cannot_receive_someone_elses_purchased_grant(): void
    {
        $order = $this->create($this->user(), $this->plan());
        $other = $this->user();
        $this->markPaid($order);
        DB::table('tx_order')->where('id', $order->id)->update(['user_id' => $other->id]);
        $this->assertOpenRefusedWithoutMutation($order);
    }

    public function test_legacy_order_without_version_marker_uses_current_plan(): void
    {
        $user = $this->user();
        $plan = $this->plan(['transfer_enable' => 3]);
        $order = Order::create(['user_id' => $user->id, 'plan_id' => $plan->id,
            'period' => Plan::PERIOD_MONTHLY, 'type' => Order::TYPE_NEW_PURCHASE,
            'status' => Order::STATUS_PROCESSING, 'trade_no' => 'legacy-purchased-'.(++$this->sequence),
            'total_amount' => 1100, 'balance_amount' => 0, 'purchase_terms_version' => null]);
        (new OrderService($order))->open();
        $this->assertSame(3 * 1073741824, (int) $user->fresh()->transfer_enable);
        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->fresh()->status);
        $this->assertSame(0, OrderPurchaseTerms::where('order_id', $order->id)->count());
    }

    public function test_idempotent_creation_keeps_one_order_and_one_snapshot(): void
    {
        $user = $this->user();
        $plan = $this->plan();
        $first = OrderService::createFromRequest($user, $plan, Plan::PERIOD_MONTHLY, null, 'purchased-terms-repeat');
        $second = OrderService::createFromRequest($user, $plan, Plan::PERIOD_MONTHLY, null, 'purchased-terms-repeat');
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Order::where('user_id', $user->id)->count());
        $this->assertSame(1, OrderPurchaseTerms::where('order_id', $first->id)->count());
    }

    public function test_cancellation_refunds_wallet_once_and_retains_purchased_evidence(): void
    {
        $user = $this->user(['balance' => 400]);
        $order = $this->create($user, $this->plan());
        $hash = $this->terms($order)->content_hash;
        $this->assertSame(0, (int) $user->fresh()->balance);
        $this->assertTrue((new OrderService($order->fresh()))->cancel());
        $this->assertFalse((new OrderService($order->fresh()))->cancel());
        $this->assertSame(400, (int) $user->fresh()->balance);
        $this->assertSame(Order::STATUS_CANCELLED, (int) $order->fresh()->status);
        $this->assertSame($hash, $this->terms($order)->content_hash);
    }

    public function test_published_snapshot_model_rejects_updates(): void
    {
        $terms = $this->terms($this->create($this->user(), $this->plan()));
        $this->expectException(\LogicException::class);
        $terms->update(['group_id' => 88]);
    }

    public function test_published_snapshot_model_rejects_deletion(): void
    {
        $terms = $this->terms($this->create($this->user(), $this->plan()));
        $this->expectException(\LogicException::class);
        $terms->delete();
    }

    public function test_reset_policy_is_saved_as_evidence_while_native_scheduling_remains_current(): void
    {
        $plan = $this->plan(['reset_traffic_method' => null]);
        $user = $this->user(['plan_id' => $plan->id, 'group_id' => 7,
            'transfer_enable' => 2 * 1073741824,
            'expired_at' => Carbon::parse('2026-11-20 12:00:00', 'UTC')->timestamp, 'u' => 100, 'd' => 200]);
        $order = OrderService::createFromRequest($user, $plan, Plan::PERIOD_RESET_TRAFFIC);
        $this->assertSame(Plan::RESET_TRAFFIC_FIRST_DAY_MONTH, $this->terms($order)->reset_policy['method']);
        admin_setting(['reset_traffic_method' => Plan::RESET_TRAFFIC_NEVER]);
        $plan->update(['reset_traffic_method' => Plan::RESET_TRAFFIC_NEVER]);
        $this->payAndOpen($order);
        $user->refresh();
        $this->assertSame(0, (int) $user->u);
        $this->assertSame(0, (int) $user->d);
        $this->assertNull($user->next_reset_at);
        $this->assertSame(Plan::RESET_TRAFFIC_FIRST_DAY_MONTH, $this->terms($order)->reset_policy['method']);
        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->fresh()->status);
    }

    public function test_manual_admin_order_also_requires_and_consumes_purchased_evidence(): void
    {
        $admin = $this->user(['is_admin' => true]);
        $owner = $this->user();
        $plan = $this->plan();
        admin_setting(['secure_path' => 'purchase_admin']);
        \Laravel\Sanctum\Sanctum::actingAs($admin);
        $response = $this->postJson('/txapi/admin/purchase_admin/orders/assign', [
            'email' => $owner->email, 'plan_id' => $plan->id,
            'period' => 'year_price', 'total_amount' => 300,
        ])->assertCreated();
        $order = Order::where('trade_no', $response->json('data.trade_no'))->firstOrFail();
        $this->assertSame('tx-core.purchase-terms/v1', $order->purchase_terms_version);
        $this->assertSame('300', $this->terms($order)->price_minor);
        $this->assertSame('manual_assignment', $this->terms($order)->source_snapshot['quote_source']);
        $this->assertNull($this->terms($order)->source_snapshot['list_price_minor']);
        $plan->update(['transfer_enable' => 99]);
        $this->payAndOpen($order);
        $this->assertGrant($owner, (int) $plan->id);
    }

    public function test_order_open_event_is_frozen_and_applied_once_inside_fulfillment(): void
    {
        $plan = $this->plan();
        $user = $this->user(['plan_id' => $plan->id, 'group_id' => 7,
            'transfer_enable' => 2 * 1073741824, 'expired_at' => time() + 86400, 'u' => 17, 'd' => 23]);
        admin_setting(['renew_order_event_id' => 1]);
        $order = $this->create($user, $plan);
        admin_setting(['renew_order_event_id' => 0]);
        $this->assertSame(1, $this->terms($order)->source_snapshot['open_event_id']);
        $this->payAndOpen($order);
        $this->assertSame(0, (int) $user->fresh()->u);
        $this->assertSame(0, (int) $user->fresh()->d);
        $before = $this->state();
        (new OrderService($order->fresh()))->open();
        $this->assertSame($before, $this->state());
    }

    public function test_expiry_uses_purchased_month_overflow_after_runtime_setting_changes(): void
    {
        $plan = $this->plan();
        $anchor = Carbon::parse('2027-01-31 10:00:00', 'UTC')->timestamp;
        $user = $this->user(['plan_id' => $plan->id, 'group_id' => 7,
            'transfer_enable' => 2 * 1073741824, 'expired_at' => $anchor]);
        $prior = Carbon::shouldOverflowMonths();
        try {
            Carbon::useMonthsOverflow(false);
            $order = $this->create($user, $plan);
            Carbon::useMonthsOverflow(true);
            $this->payAndOpen($order);
            $this->assertSame(Carbon::parse('2027-02-28 10:00:00', 'UTC')->timestamp, (int) $user->fresh()->expired_at);
        } finally {
            Carbon::useMonthsOverflow($prior);
        }
    }

    public function test_failed_snapshot_capture_rolls_back_order_wallet_and_idempotency(): void
    {
        $user = $this->user(['balance' => 400]);
        $plan = $this->plan();
        $before = $this->state();
        new OrderPurchaseTerms();
        $dispatcher = OrderPurchaseTerms::getEventDispatcher();
        OrderPurchaseTerms::setEventDispatcher(clone $dispatcher);
        OrderPurchaseTerms::created(static function (): void {
            throw new \RuntimeException('snapshot capture failed after insert');
        });
        $caught = null;
        try {
            OrderService::createFromRequest($user, $plan, Plan::PERIOD_MONTHLY, null, 'failed-purchase-snapshot');
        } catch (\App\Exceptions\ApiException $exception) {
            $caught = $exception;
        } finally {
            OrderPurchaseTerms::setEventDispatcher($dispatcher);
        }
        $this->assertNotNull($caught);
        $this->assertSame($before, $this->state());
        $this->assertSame(0, DB::table('idempotency_keys')->where('user_id', $user->id)->count());
    }

    public function test_failed_frozen_open_event_rolls_back_and_retry_applies_once(): void
    {
        $plan = $this->plan();
        $user = $this->user(['plan_id' => $plan->id, 'group_id' => 7,
            'transfer_enable' => 2 * 1073741824, 'expired_at' => time() + 86400, 'u' => 17, 'd' => 23]);
        admin_setting(['renew_order_event_id' => 1]);
        $order = $this->create($user, $plan);
        $this->markPaid($order);
        $this->mock(\App\Services\TrafficResetService::class, function ($mock): void {
            $mock->shouldReceive('performReset')->once()->andReturn(false);
        });

        $this->assertOpenRefusedWithoutMutation($order);

        $this->app->forgetInstance(\App\Services\TrafficResetService::class);
        (new OrderService($order->fresh()))->open();
        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->fresh()->status);
        $this->assertSame(0, (int) $user->fresh()->u);
        $this->assertSame(0, (int) $user->fresh()->d);
        $this->assertSame(1, (int) $user->fresh()->reset_count);
        $before = $this->state();
        (new OrderService($order->fresh()))->open();
        $this->assertSame($before, $this->state());
    }

    public function test_one_time_purchase_uses_frozen_grant_and_remains_perpetual(): void
    {
        $user = $this->user(['u' => 17, 'd' => 23]);
        $plan = $this->plan(['prices' => ['onetime' => 11]]);
        $order = OrderService::createFromRequest($user, $plan, Plan::PERIOD_ONETIME);
        $this->assertSame('perpetual', $this->terms($order)->purchase_kind);
        $plan->update(['transfer_enable' => 99, 'group_id' => 99, 'speed_limit' => 1, 'device_limit' => 1]);

        $this->payAndOpen($order);

        $this->assertGrant($user, (int) $plan->id);
        $this->assertNull($user->fresh()->expired_at);
        $this->assertSame(0, (int) $user->fresh()->u);
        $this->assertSame(0, (int) $user->fresh()->d);
        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->fresh()->status);
    }

    public function test_upgrade_uses_frozen_target_grant_and_restarts_its_duration(): void
    {
        admin_setting(['surplus_enable' => 0]);
        $previous = $this->plan();
        $oldExpiry = time() + 365 * 86400;
        $user = $this->user(['plan_id' => $previous->id, 'group_id' => 3,
            'transfer_enable' => 1073741824, 'expired_at' => $oldExpiry, 'u' => 17, 'd' => 23]);
        $target = $this->plan();
        $order = $this->create($user, $target);
        $this->assertSame(Order::TYPE_UPGRADE, (int) $order->type);
        $target->update(['transfer_enable' => 99, 'group_id' => 99, 'speed_limit' => 1, 'device_limit' => 1]);
        $before = time();

        $this->payAndOpen($order);

        $this->assertGrant($user, (int) $target->id);
        $this->assertGreaterThanOrEqual($before + 28 * 86400, (int) $user->fresh()->expired_at);
        $this->assertLessThan($oldExpiry, (int) $user->fresh()->expired_at);
        $this->assertSame(17, (int) $user->fresh()->u);
        $this->assertSame(23, (int) $user->fresh()->d);
        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->fresh()->status);
    }

    private function assertOpenRefusedWithoutMutation(Order $order): void
    {
        $before = $this->state();
        $caught = null;
        try { (new OrderService($order->fresh()))->open(); }
        catch (Throwable $exception) { $caught = $exception; }
        $this->assertNotNull($caught, 'Invalid purchased evidence must not fall back to the mutable Plan.');
        $this->assertSame($before, $this->state(), 'Refusal must leave user, order and reset records untouched.');
    }

    private function state(): array
    {
        $state = [];
        foreach (['tx_user', 'tx_order', 'tx_order_purchase_terms', 'tx_traffic_reset_logs'] as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        return $state;
    }

    private function payAndOpen(Order $order): void
    {
        $this->markPaid($order);
        (new OrderService($order->fresh()))->open();
    }

    private function markPaid(Order $order): void
    {
        $this->assertTrue((new OrderService($order->fresh()))->paid('purchased-callback-'.$order->id));
        $this->assertSame(Order::STATUS_PROCESSING, (int) $order->fresh()->status);
    }

    private function assertGrant(User $user, int $planId): void
    {
        $user->refresh();
        $this->assertSame($planId, (int) $user->plan_id);
        $this->assertSame(2 * 1073741824, (int) $user->transfer_enable);
        $this->assertSame(7, (int) $user->group_id);
        $this->assertSame(30, (int) $user->speed_limit);
        $this->assertSame(4, (int) $user->device_limit);
    }

    private function terms(Order $order): OrderPurchaseTerms
    {
        return OrderPurchaseTerms::where('order_id', $order->id)->firstOrFail();
    }

    private function create(User $user, Plan $plan): Order
    {
        return OrderService::createFromRequest($user, $plan, Plan::PERIOD_MONTHLY);
    }

    private function user(array $extra = []): User
    {
        $number = ++$this->sequence;
        return User::create(array_replace(['email' => 'purchase-terms-'.$number.'@example.test',
            'password' => 'purchased-terms-fixture', 'uuid' => sprintf('b1000000-0000-4000-8000-%012d', $number),
            'token' => sprintf('%032d', $number), 'balance' => 0, 'commission_balance' => 0,
            'transfer_enable' => 0, 'u' => 0, 'd' => 0, 'expired_at' => 0,
            'banned' => false, 'is_admin' => false, 'is_staff' => false], $extra));
    }

    private function plan(array $extra = []): Plan
    {
        return Plan::create(array_replace(['name' => 'Purchased terms fixture', 'group_id' => 7,
            'transfer_enable' => 2, 'speed_limit' => 30, 'device_limit' => 4, 'show' => true,
            'sell' => true, 'renew' => true, 'prices' => ['monthly' => 11, 'reset_traffic' => 2],
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY, 'capacity_limit' => null], $extra));
    }
}
