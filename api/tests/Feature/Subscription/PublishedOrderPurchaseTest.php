<?php

namespace Tests\Feature\Subscription;

use App\Domains\Billing\OrderPurchaseTermsSnapshot;
use App\Domains\Billing\OrderCheckout;
use App\Domains\Billing\PurchasedOrderGrant;
use App\Domains\Subscription\CatalogValue;
use App\Models\Order;
use App\Models\OrderPurchaseTerms;
use App\Models\Plan;
use App\Domains\Billing\NativeOrderLifecycle;
use App\Services\Plugin\HookManager;
use App\Jobs\OrderHandleJob;
use App\Domains\Subscription\Services\PlanPublicationService;
use App\Domains\Subscription\Services\PublishedOrderService;
use App\Domains\Subscription\Services\SubscriptionFulfillmentService;
use App\Domains\Subscription\Services\SubscriptionLifecycleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use Tests\Support\NativeSubscriptionTestCase;
use Throwable;

class PublishedOrderPurchaseTest extends NativeSubscriptionTestCase
{
    public function test_purchase_uses_immutable_publication_after_plan_edit_and_never_legacy_entitlements_or_funds(): void
    {
        $plan = $this->plan();
        $this->settings['new_order_event_id'] = 1;
        $offer = $this->publishedOffer($plan);
        $user = $this->user([
            'plan_id' => $plan->id, 'expired_at' => time() + 86400, 'balance' => 20000,
            'discount' => 90, 'commission_balance' => 5000, 'transfer_enable' => 999, 'u' => 77, 'd' => 88,
        ]);
        $before = $user->fresh()->getAttributes();
        $plan->update(['name' => 'Changed later', 'transfer_enable' => 99, 'group_id' => 99,
            'speed_limit' => 1, 'device_limit' => 1, 'prices' => ['monthly' => 999],
            'reset_traffic_method' => 0, 'sell' => false]);
        $this->settings['new_order_event_id'] = 0;

        $order = $this->service()->create($user->id, $offer, 'frozen');
        $grant = $this->grant($order);

        $this->assertSame('tx-core.purchase-terms/v2', $order->purchase_terms_version);
        $this->assertSame(Order::TYPE_NEW_PURCHASE, (int) $order->type);
        $this->assertSame('1234', $grant['price_minor']);
        $this->assertSame('1234', $grant['list_price_minor']);
        $this->assertSame('2147483648', $grant['quota_bytes']);
        $this->assertSame(7, $grant['group_id']);
        $this->assertSame(30, $grant['speed_limit_mbps']);
        $this->assertSame(4, $grant['device_limit']);
        $this->assertSame(2, $grant['reset_policy']['method']);
        $this->assertSame(1, $grant['open_event_id']);
        $this->assertSame((string) $offer, $grant['catalog']['offer_id']);
        $this->assertSame($before, $user->fresh()->getAttributes());
        foreach (['balance_amount', 'discount_amount', 'surplus_amount', 'surplus_credit', 'commission_balance'] as $field) {
            $this->assertSame(0, (int) $order->$field);
        }
        $this->assertNull($order->coupon_id);
        $this->assertNull($order->invite_user_id);
        $this->assertSame(0, DB::table('tx_subscription')->count());
    }

    public function test_idempotent_replay_survives_successor_and_unavailable_original_without_repricing(): void
    {
        $user = $this->user();
        $plan = $this->plan();
        $offer = $this->publishedOffer($plan);
        $order = $this->service()->create($user->id, $offer, 'repeat');
        $snapshot = $this->grant($order);
        $plan->update(['prices' => ['monthly' => 99]]);
        $next = $this->publishedOffer($plan, 'monthly', 'successor');
        $this->assertNotSame($offer, $next);
        $this->assertSame('superseded', DB::table('tx_plan_offer_availability')->where('offer_id', $offer)->value('status'));
        $replayed = $this->service()->create($user->id, $offer, 'repeat');
        $this->assertSame($order->id, $replayed->id);
        $this->assertSame($snapshot, $this->grant($replayed));
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, OrderPurchaseTerms::query()->count());
        $this->reject(fn () => $this->service()->create($this->user()->id, $offer, 'new-buyer'),
            'PUBLICATION_OFFER_NOT_PUBLISHED');
    }

    public function test_native_creation_key_does_not_expire_or_get_pruned_after_cancellation(): void
    {
        $user = $this->user();
        $plan = $this->plan();
        $offer = $this->publishedOffer($plan);
        $order = $this->service()->create($user->id, $offer, 'permanent-order');
        $this->assertTrue((new NativeOrderLifecycle($order))->cancel());
        DB::table('idempotency_keys')->where('user_id', $user->id)->update(['expires_at' => time() - 86400]);
        $command = new \App\Console\Commands\PruneIdempotencyKeys();
        $command->setOutput(new \Illuminate\Console\OutputStyle(
            new \Symfony\Component\Console\Input\ArrayInput([]),
            new \Symfony\Component\Console\Output\BufferedOutput()
        ));
        $this->assertSame(0, $command->handle());
        $this->assertSame(1, DB::table('idempotency_keys')->count());
        $this->assertSame($order->id, $this->service()->create($user->id, $offer, 'permanent-order')->id);
        $other = (int) DB::table('tx_plan_offer')->where('plan_id', $plan->id)->where('offer_code', 'yearly')->value('id');
        $this->reject(fn () => $this->service()->create($user->id, $other, 'permanent-order'));
        $this->assertSame(1, Order::query()->count());
    }

    public function test_frozen_grant_and_replay_do_not_require_current_plan_or_availability_rows(): void
    {
        $user = $this->user();
        $plan = $this->plan();
        $offer = $this->publishedOffer($plan);
        $order = $this->service()->create($user->id, $offer, 'deleted-plan');
        $snapshot = $this->grant($order);
        DB::table('tx_plan_offer_availability')->where('offer_id', $offer)->delete();
        DB::table('tx_plan')->where('id', $plan->id)->delete();
        $this->assertSame($snapshot, $this->grant($order));
        $this->assertSame($order->id, $this->service()->create($user->id, $offer, 'deleted-plan')->id);
    }

    public function test_different_offer_cannot_reuse_key_and_pending_order_blocks_another_key(): void
    {
        $user = $this->user();
        $plan = $this->plan();
        $offer = $this->publishedOffer($plan);
        $other = (int) DB::table('tx_plan_offer')->where('plan_id', $plan->id)->where('offer_code', 'yearly')->value('id');
        $this->service()->create($user->id, $offer, 'one');
        $this->reject(fn () => $this->service()->create($user->id, $other, 'one'));
        $this->reject(fn () => $this->service()->create($user->id, $other, 'two'), 'PUBLISHED_ORDER_PENDING_ORDER_EXISTS');
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, DB::table('idempotency_keys')->count());
    }

    public function test_pending_orders_reserve_capacity_and_cancellation_releases_reservation(): void
    {
        $plan = $this->plan(['capacity_limit' => 1]);
        $offer = $this->publishedOffer($plan);
        $first = $this->service()->create($this->user()->id, $offer, 'first');
        $other = $this->user();
        $this->reject(fn () => $this->service()->create($other->id, $offer, 'second'), 'PUBLISHED_ORDER_CAPACITY_EXHAUSTED');
        $this->assertTrue((new NativeOrderLifecycle($first))->cancel());
        $this->assertFalse((new NativeOrderLifecycle($first))->cancel());
        $this->assertFalse((new NativeOrderLifecycle($first))->paid('late-callback'));
        $second = $this->service()->create($other->id, $offer, 'second');
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, OrderPurchaseTerms::query()->count());
    }

    public function test_availability_and_finite_zero_capacity_are_checked_only_for_fresh_purchases(): void
    {
        $user = $this->user();
        $offer = $this->publishedOffer($this->plan());
        $order = $this->service()->create($user->id, $offer, 'one');
        app(PlanPublicationService::class)->setAvailability($offer, [
            'show' => false, 'sell' => false, 'renew' => false, 'capacity_limit' => 0,
        ], 1);
        $this->assertSame($order->id, $this->service()->create($user->id, $offer, 'one')->id);
        $other = $this->user();
        $this->reject(fn () => $this->service()->create($other->id, $offer, 'new'), 'PUBLISHED_ORDER_OFFER_UNAVAILABLE');
        app(PlanPublicationService::class)->setAvailability($offer, [
            'show' => true, 'sell' => true, 'renew' => true, 'capacity_limit' => 0,
        ], 2);
        $this->reject(fn () => $this->service()->create($other->id, $offer, 'new'), 'PUBLISHED_ORDER_CAPACITY_EXHAUSTED');
    }

    public function test_explicit_financial_adjustments_are_rejected_without_consuming_balance_or_creating_claims(): void
    {
        $user = $this->user(['balance' => 10000]);
        $offer = $this->publishedOffer($this->plan());
        $before = $user->fresh()->getAttributes();
        foreach (['coupon_code', 'balance_amount', 'discount_amount', 'surplus_credit', 'commission_balance', 'unknown'] as $field) {
            $this->reject(fn () => $this->service()->create($user->id, $offer, 'adjust-'.$field, [$field => 1]),
                'PUBLISHED_ORDER_FINANCIAL_ADJUSTMENT_UNSUPPORTED');
        }
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, DB::table('idempotency_keys')->count());
    }

    public function test_exact_minor_units_zero_and_order_integer_limit(): void
    {
        foreach (['0.29' => 29, '0' => 0, '21474836.47' => 2147483647] as $price => $minor) {
            $offer = $this->publishedOffer($this->plan(['prices' => ['monthly' => (string) $price]]));
            $order = $this->service()->create($this->user()->id, $offer, 'price-'.$minor);
            $this->assertSame($minor, (int) $order->total_amount);
            $this->assertSame((string) $minor, $this->grant($order)['price_minor']);
        }
        $offer = $this->publishedOffer($this->plan(['prices' => ['monthly' => '21474836.48']]));
        $this->reject(fn () => $this->service()->create($this->user()->id, $offer, 'overflow'),
            'PUBLISHED_ORDER_PRICE_OUT_OF_RANGE');
        $this->assertSame(3, Order::query()->count());
    }

    public function test_rehashed_purchase_cannot_change_published_entitlement_or_binding(): void
    {
        $offer = $this->publishedOffer($this->plan());
        $order = $this->service()->create($this->user()->id, $offer, 'tamper');
        $terms = $this->terms($order);
        $original = $terms->source_snapshot;
        foreach (['quota_bytes' => '4294967296', 'plan_name' => 'Unpublished name', 'open_event_id' => 1] as $field => $value) {
            $snapshot = $original;
            $snapshot[$field] = $value;
            $this->replaceSnapshot($order, $snapshot,
                $field === 'quota_bytes' ? ['quota_bytes' => $value] : []);
            $this->reject(fn () => $this->grant($order), 'ORDER_PURCHASE_TERMS_INVALID');
            $this->replaceSnapshot($order, $original, ['quota_bytes' => $original['quota_bytes']]);
        }
        $snapshot = $original;
        $snapshot['catalog']['publication_hash'] = str_repeat('a', 64);
        $this->replaceSnapshot($order, $snapshot);
        $this->reject(fn () => $this->grant($order), 'ORDER_PURCHASE_TERMS_INVALID');
        $snapshot = $original;
        $snapshot['catalog']['extra'] = true;
        $this->replaceSnapshot($order, $snapshot);
        $this->reject(fn () => $this->grant($order), 'ORDER_PURCHASE_TERMS_INVALID');
    }

    public function test_offer_and_revision_projection_damage_prevents_replay_and_grant(): void
    {
        $user = $this->user();
        $offer = $this->publishedOffer($this->plan());
        $order = $this->service()->create($user->id, $offer, 'tamper');
        DB::table('tx_plan_offer')->where('id', $offer)->update(['price_minor' => 999]);
        $this->reject(fn () => $this->grant($order), 'ORDER_PURCHASE_TERMS_INVALID');
        $this->reject(fn () => $this->service()->create($user->id, $offer, 'tamper'), 'ORDER_PURCHASE_TERMS_INVALID');
        DB::table('tx_plan_offer')->where('id', $offer)->update(['price_minor' => 1234]);
        $revision = $this->terms($order)->source_snapshot['catalog']['revision_id'];
        DB::table('tx_plan_revision')->where('id', $revision)->update(['group_id' => 999]);
        $this->reject(fn () => $this->grant($order), 'ORDER_PURCHASE_TERMS_INVALID');
        $this->assertSame(1, Order::query()->count());
    }

    public function test_missing_sibling_offer_and_shadow_marker_are_not_valid_publication_evidence(): void
    {
        $offer = $this->publishedOffer($this->plan());
        $order = $this->service()->create($this->user()->id, $offer, 'complete-set');
        $revision = $this->grant($order)['catalog']['revision_id'];
        $sibling = DB::table('tx_plan_offer')->where('plan_revision_id', $revision)->where('id', '<>', $offer)->first();
        DB::table('tx_plan_offer_availability')->where('offer_id', $sibling->id)->delete();
        DB::table('tx_plan_offer')->where('id', $sibling->id)->delete();
        $this->reject(fn () => $this->grant($order), 'ORDER_PURCHASE_TERMS_INVALID');

        $shadowOffer = $this->publishedOffer($this->plan());
        $shadowRevision = DB::table('tx_plan_offer')->where('id', $shadowOffer)->value('plan_revision_id');
        DB::table('tx_plan_revision')->where('id', $shadowRevision)->update([
            'schema_version' => 'plan-catalog-shadow/v1', 'publication_key' => null, 'publication_request_hash' => null,
        ]);
        $this->reject(fn () => $this->service()->create($this->user()->id, $shadowOffer, 'shadow'),
            'PUBLICATION_OFFER_NOT_PUBLISHED');
    }

    public function test_native_admission_ban_and_caller_transaction_refuse_before_business_writes(): void
    {
        $user = $this->user();
        $offer = $this->publishedOffer($this->plan());
        $this->denyNativeAdmission = true;
        $this->reject(fn () => $this->service()->create($user->id, $offer, 'denied'));
        $this->denyNativeAdmission = false;
        $user->update(['banned' => 1]);
        $this->reject(fn () => $this->service()->create($user->id, $offer, 'banned'), 'PUBLISHED_ORDER_ACCOUNT_UNAVAILABLE');
        DB::transaction(function () use ($user, $offer): void {
            $this->reject(fn () => $this->service()->create($user->id, $offer, 'nested'),
                'PUBLISHED_ORDER_TRANSACTION_OWNERSHIP_REQUIRED');
        });
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, DB::table('idempotency_keys')->count());
        $this->assertSame(0, DB::table('tx_subscription_slot')->count());
    }

    public function test_order_types_follow_canonical_aggregate_and_renewal_does_not_consume_another_capacity_slot(): void
    {
        $user = $this->user();
        $plan = $this->plan(['capacity_limit' => 1]);
        $offer = $this->publishedOffer($plan);
        $initial = $this->service()->create($user->id, $offer, 'initial');
        $this->fulfill($initial, time() - 3600);
        $user->update(['plan_id' => null, 'expired_at' => 0, 'transfer_enable' => 0]);
        app(PlanPublicationService::class)->setAvailability($offer, [
            'show' => true, 'sell' => false, 'renew' => true, 'capacity_limit' => 1,
        ], 1);
        $renewal = $this->service()->create($user->id, $offer, 'renew');
        $this->assertSame(Order::TYPE_RENEWAL, (int) $renewal->type);
        DB::table('tx_order')->where('id', $renewal->id)->update(['status' => Order::STATUS_CANCELLED]);

        $resetOffer = (int) DB::table('tx_plan_offer')->where('plan_id', $plan->id)
            ->where('offer_code', 'reset_traffic')->value('id');
        $reset = $this->service()->create($user->id, $resetOffer, 'reset');
        $this->assertSame(Order::TYPE_RESET_TRAFFIC, (int) $reset->type);
        DB::table('tx_order')->where('id', $reset->id)->update(['status' => Order::STATUS_CANCELLED]);

        $newPlan = $this->plan();
        $upgrade = $this->service()->create($user->id, $this->publishedOffer($newPlan), 'upgrade');
        $this->assertSame(Order::TYPE_UPGRADE, (int) $upgrade->type);
        $this->assertSame(0, (int) $upgrade->surplus_amount);
        $this->assertSame('1234', $this->grant($upgrade)['price_minor']);
    }

    public function test_suspension_keeps_plan_capacity_reserved_and_does_not_change_native_order_type(): void
    {
        $user = $this->user();
        $offer = $this->publishedOffer($this->plan(['capacity_limit' => 1]));
        $initial = $this->service()->create($user->id, $offer, 'initial');
        $result = $this->fulfill($initial, time() - 3600);
        app(SubscriptionLifecycleService::class)->suspend($user->id, (int) $result['subscription_id'],
            'suspend-for-purchase', $result['subscription_version'], time() - 1800);
        $this->reject(fn () => $this->service()->create($this->user()->id, $offer, 'other'),
            'PUBLISHED_ORDER_CAPACITY_EXHAUSTED');
        $renewal = $this->service()->create($user->id, $offer, 'renew-suspended');
        $this->assertSame(Order::TYPE_RENEWAL, (int) $renewal->type);
    }

    public function test_shadow_aggregate_marker_is_never_promoted_to_native_purchase_authority(): void
    {
        $user = $this->user();
        $offer = $this->publishedOffer($this->plan());
        $initial = $this->service()->create($user->id, $offer, 'initial');
        $result = $this->fulfill($initial, time() - 3600);
        DB::table('tx_subscription')->where('id', $result['subscription_id'])->update([
            'authority_version' => null, 'installation_id' => null,
        ]);
        $this->reject(fn () => $this->service()->create($user->id, $offer, 'shadow'),
            'NATIVE_SUBSCRIPTION_OWNERSHIP_INVALID');
        $this->assertSame(1, Order::query()->count());
    }

    public function test_expired_but_occupied_slot_requires_explicit_lifecycle_release_before_new_purchase(): void
    {
        $user = $this->user();
        $offer = $this->publishedOffer($this->plan());
        $initial = $this->service()->create($user->id, $offer, 'initial');
        $this->fulfill($initial, time() - 86400 * 62);
        $this->reject(fn () => $this->service()->create($user->id, $offer, 'new'),
            'PUBLISHED_ORDER_SLOT_RELEASE_REQUIRED');
        $this->assertSame($initial->id, $this->service()->create($user->id, $offer, 'initial')->id);
        $this->assertSame(1, Order::query()->count());
    }

    public function test_replay_rejects_unexpected_financial_adjustments_added_after_capture(): void
    {
        $user = $this->user();
        $offer = $this->publishedOffer($this->plan());
        $order = $this->service()->create($user->id, $offer, 'unadjusted');
        DB::table('tx_order')->where('id', $order->id)->update(['commission_balance' => 50]);
        $this->reject(fn () => $this->service()->create($user->id, $offer, 'unadjusted'),
            'ORDER_PURCHASE_TERMS_INVALID');
    }

    public function test_legacy_v1_capture_and_resolution_still_do_not_require_publication(): void
    {
        $user = $this->user();
        $plan = $this->plan();
        $order = DB::transaction(function () use ($user, $plan): Order {
            $order = Order::query()->create([
                'user_id' => $user->id, 'plan_id' => $plan->id, 'period' => 'monthly',
                'type' => Order::TYPE_NEW_PURCHASE, 'status' => Order::STATUS_PENDING,
                'trade_no' => 'legacy-v1', 'total_amount' => 1000, 'purchase_terms_version' => 'tx-core.purchase-terms/v1',
            ]);
            app(OrderPurchaseTermsSnapshot::class)->capture($order, $plan);
            return $order;
        });
        $plan->update(['transfer_enable' => 99]);
        $grant = $this->grant($order);
        $this->assertSame('tx-core.purchase-terms/v1', $grant['schema']);
        $this->assertArrayNotHasKey('catalog', $grant);
        $this->assertSame('1000', $grant['price_minor']);
        $this->assertSame('2147483648', $grant['quota_bytes']);
        $this->assertSame(0, DB::table('tx_plan_revision')->count());
    }

    public function test_zero_checkout_retains_one_durable_native_fulfillment_and_preserves_legacy_user_state(): void
    {
        $user = $this->user(['balance' => 5000, 'plan_id' => 999, 'transfer_enable' => 999, 'u' => 40, 'd' => 80]);
        $offer = $this->publishedOffer($this->plan(['prices' => ['monthly' => '0']]));
        $order = $this->service()->create($user->id, $offer, 'free-checkout');
        $before = $user->fresh()->getAttributes();
        $outsider = $this->user();
        $this->reject(fn () => app(OrderCheckout::class)->start($outsider->id, $order->trade_no), 'Order does not exist');
        $this->assertSame(['type' => -1, 'data' => true],
            app(OrderCheckout::class)->start($user->id, $order->trade_no, requestKey: 'free-checkout-key'));
        $this->assertSame(Order::STATUS_PROCESSING, (int) $order->fresh()->status);
        Bus::assertNotDispatched(OrderHandleJob::class);
        $this->assertSame('pending', DB::table('tx_native_free_checkout')->value('status'));
        (new OrderHandleJob($order->trade_no))->handle();
        (new OrderHandleJob($order->trade_no))->handle();
        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->fresh()->status);
        $this->assertSame(1, DB::table('tx_subscription')->count());
        $this->assertSame(1, DB::table('tx_subscription_operation')->count());
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertTrue((new NativeOrderLifecycle($order))->paid($order->trade_no));
        Bus::assertNotDispatched(OrderHandleJob::class);
    }

    public function test_native_callbacks_keep_provider_and_verified_amount_checks_under_the_order_transition(): void
    {
        $user = $this->user(['balance' => 5000]);
        $order = $this->service()->create($user->id, $this->publishedOffer($this->plan()), 'verified-payment');
        DB::table('tx_order')->where('id', $order->id)->update(['payment_id' => 11, 'handling_amount' => 100]);
        $before = $user->fresh()->getAttributes();
        $this->assertFalse((new NativeOrderLifecycle($order))->paid('provider-receipt', 12, 1334));
        $this->assertFalse((new NativeOrderLifecycle($order))->paid('provider-receipt', 11, 1234));
        $this->assertSame(Order::STATUS_PENDING, (int) $order->fresh()->status);
        $this->assertTrue((new NativeOrderLifecycle($order))->paid('provider-receipt', 11, 1334));
        $this->assertTrue((new NativeOrderLifecycle($order))->paid('provider-receipt', 11, 1334));
        $this->assertFalse((new NativeOrderLifecycle($order))->paid('different-receipt', 11, 1334));
        $this->assertFalse((new NativeOrderLifecycle($order))->cancel());
        Bus::assertDispatchedTimes(OrderHandleJob::class, 1);
        $this->assertSame(Order::STATUS_PROCESSING, (int) $order->fresh()->status);
        $this->assertSame($before, $user->fresh()->getAttributes());
    }

    public function test_fulfillment_dispatch_uses_stored_marker_instead_of_stale_queued_order(): void
    {
        $user = $this->user(['balance' => 5000, 'transfer_enable' => 999, 'u' => 40]);
        $order = $this->service()->create($user->id, $this->publishedOffer($this->plan()), 'stale-job');
        $before = $user->fresh()->getAttributes();
        $stale = clone $order;
        $stale->purchase_terms_version = null;
        DB::table('tx_order')->where('id', $order->id)->update([
            'status' => Order::STATUS_PROCESSING, 'paid_at' => time(),
        ]);

        (new NativeOrderLifecycle($stale))->open();

        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->fresh()->status);
        $this->assertSame(1, DB::table('tx_subscription')->count());
        $this->assertSame(1, DB::table('tx_subscription_operation')->count());
        $this->assertSame($before, $user->fresh()->getAttributes());
    }

    public function test_legacy_lifecycle_cannot_forward_a_native_order_with_a_stale_model_marker(): void
    {
        $user = $this->user(['balance' => 5000, 'transfer_enable' => 999, 'u' => 40]);
        $order = $this->service()->create($user->id, $this->publishedOffer($this->plan()), 'legacy-owner-refused');
        $stale = clone $order;
        $stale->purchase_terms_version = null;
        $before = $user->fresh()->getAttributes();
        app(\App\Domains\Installation\InstallationAuthority::class)
            ->shouldReceive('assertLegacyWriter')->andReturnNull();

        $legacy = new \App\Services\OrderService($stale);
        $this->assertFalse($legacy->paid('legacy-callback'));
        $this->assertFalse($legacy->cancel());
        $this->assertSame(Order::STATUS_PENDING, (int) $order->fresh()->status);
        DB::table('tx_order')->where('id', $order->id)->update(['status' => Order::STATUS_PROCESSING]);
        $this->reject(fn () => $legacy->open(), 'Native order requires native fulfillment admission');

        $this->assertSame(Order::STATUS_PROCESSING, (int) $order->fresh()->status);
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertSame(0, DB::table('tx_subscription')->count());
        Bus::assertNotDispatched(OrderHandleJob::class);
    }

    public function test_native_cancellation_avoids_legacy_hooks_wallets_and_coupon_writers(): void
    {
        $user = $this->user(['balance' => 5000, 'commission_balance' => 1000]);
        $order = $this->service()->create($user->id, $this->publishedOffer($this->plan()), 'cancel');
        $before = $user->fresh()->getAttributes();
        foreach (['order.cancel.before', 'order.cancel.after'] as $hook) {
            HookManager::register($hook, static function (): void { throw new \RuntimeException('Legacy cancellation hook reached'); });
        }
        try {
            $this->assertTrue((new NativeOrderLifecycle($order))->cancel());
        } finally {
            HookManager::reset();
        }
        $this->assertSame(Order::STATUS_CANCELLED, (int) $order->fresh()->status);
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertSame(1, OrderPurchaseTerms::query()->count());
        $this->assertSame(0, DB::table('tx_subscription_operation')->count());
    }

    public function test_native_admission_and_damaged_purchase_refuse_all_order_transitions(): void
    {
        $user = $this->user();
        $order = $this->service()->create($user->id,
            $this->publishedOffer($this->plan(['prices' => ['monthly' => '0']])), 'refused');
        $this->denyNativeAdmission = true;
        $this->assertFalse((new NativeOrderLifecycle($order))->paid('receipt'));
        $this->assertFalse((new NativeOrderLifecycle($order))->cancel());
        $this->reject(fn () => app(OrderCheckout::class)->start($user->id, $order->trade_no, requestKey: 'refused'),
            'INSTALLATION_NATIVE_NOT_READY');
        $this->denyNativeAdmission = false;
        DB::table('tx_order')->where('id', $order->id)->update(['surplus_credit' => 50]);
        $this->assertFalse((new NativeOrderLifecycle($order))->paid('receipt'));
        $this->assertFalse((new NativeOrderLifecycle($order))->cancel());
        $this->reject(fn () => app(OrderCheckout::class)->start($user->id, $order->trade_no, requestKey: 'refused'),
            'ORDER_PURCHASE_TERMS_INVALID');
        $this->assertSame(Order::STATUS_PENDING, (int) $order->fresh()->status);
        $this->assertSame(0, DB::table('tx_subscription')->count());
        Bus::assertNotDispatched(OrderHandleJob::class);
    }

    private function service(): PublishedOrderService
    {
        return app(PublishedOrderService::class);
    }

    private function fulfill(Order $order, int $now): array
    {
        DB::table('tx_order')->where('id', $order->id)->update([
            'status' => Order::STATUS_PROCESSING, 'paid_at' => $now,
        ]);
        return app(SubscriptionFulfillmentService::class)->fulfill($order->id, $now);
    }

    private function terms(Order $order): OrderPurchaseTerms
    {
        return OrderPurchaseTerms::query()->where('order_id', $order->id)->firstOrFail();
    }

    private function grant(Order $order): array
    {
        return app(PurchasedOrderGrant::class)->resolve($order->fresh(), $this->terms($order));
    }

    private function replaceSnapshot(Order $order, array $snapshot, array $columns = []): void
    {
        unset($snapshot['content_hash']);
        $snapshot['content_hash'] = app(CatalogValue::class)->hash($snapshot);
        DB::table('tx_order_purchase_terms')->where('order_id', $order->id)->update($columns + [
            'source_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'content_hash' => $snapshot['content_hash'],
        ]);
    }

    private function reject(callable $operation, ?string $code = null): void
    {
        try {
            $operation();
            $this->fail('Expected the native purchase boundary to reject the operation.');
        } catch (Throwable $error) {
            if ($error instanceof \PHPUnit\Framework\AssertionFailedError) {
                throw $error;
            }
            if ($code !== null) {
                $this->assertSame($code, $error->getMessage());
            } else {
                $this->assertNotSame('', $error->getMessage());
            }
        }
    }
}
