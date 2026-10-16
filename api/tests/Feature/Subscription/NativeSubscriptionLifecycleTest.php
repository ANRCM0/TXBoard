<?php

namespace Tests\Feature\Subscription;

use App\Models\Order;
use App\Domains\Subscription\Services\PublishedOrderService;
use App\Domains\Subscription\Services\SubscriptionEntitlementResolver;
use App\Domains\Subscription\Services\SubscriptionFulfillmentService;
use App\Domains\Subscription\Services\SubscriptionLifecycleService;
use App\Domains\Subscription\Services\SubscriptionTermsTimeline;
use Illuminate\Support\Facades\DB;
use Tests\Support\NativeSubscriptionTestCase;
use Throwable;

class NativeSubscriptionLifecycleTest extends NativeSubscriptionTestCase
{
    public function test_suspend_resume_revoke_replay_and_stale_version_preserve_account_and_history(): void
    {
        [$user, $offer, $initial, $at] = $this->opened();
        $before = $user->fresh()->getAttributes();
        $subscription = (int) $initial['subscription_id'];
        $suspended = $this->lifecycle()->suspend($user->id, $subscription, 'suspend', '1', $at + 10, 'review');
        $this->assertSame('suspended', $suspended['status']);
        $this->assertFalse(app(SubscriptionEntitlementResolver::class)->resolve($user->id, $at + 11)['authorized']);
        $this->assertSame($initial['subscription_id'], (string) DB::table('tx_subscription_slot')->value('subscription_id'));
        $this->reject(fn () => $this->lifecycle()->resume($user->id, $subscription, 'stale', '1', $at + 20),
            'NATIVE_SUBSCRIPTION_VERSION_CONFLICT');
        $this->reject(fn () => $this->lifecycle()->suspend($user->id, $subscription, 'suspend', '1', $at + 11),
            'NATIVE_OPERATION_CONFLICT');
        $resumed = $this->lifecycle()->resume($user->id, $subscription, 'resume', '2', $at + 20);
        $this->assertTrue(app(SubscriptionEntitlementResolver::class)->resolve($user->id, $at + 21)['authorized']);
        $this->assertSameResult($suspended, $this->lifecycle()->suspend($user->id, $subscription, 'suspend', '1', $at + 10, 'review'));
        $revoked = $this->lifecycle()->revoke($user->id, $subscription, 'revoke', $resumed['subscription_version'], $at + 30);
        $this->assertSame('revoked', $revoked['status']);
        $this->assertNull(DB::table('tx_subscription_slot')->value('subscription_id'));
        $this->reject(fn () => $this->lifecycle()->resume($user->id, $subscription, 'revive', '4', $at + 40),
            'NATIVE_SUBSCRIPTION_INACTIVE');
        $this->assertSame('closed', DB::table('tx_usage_period')->value('status'));
        $this->assertSame(1, DB::table('tx_subscription_terms')->count());
        $this->assertSame(4, DB::table('tx_subscription_operation')->count());
        $this->assertSame($before, $user->fresh()->getAttributes());
    }

    public function test_reset_closes_old_counters_and_never_rewrites_terms_or_replays_twice(): void
    {
        [$user, , $initial, $at] = $this->opened();
        DB::table('tx_usage_period')->where('id', $initial['usage_period_id'])->update([
            'upload_bytes' => 20, 'download_bytes' => 30, 'billable_bytes' => 50,
        ]);
        $terms = DB::table('tx_subscription_terms')->first();
        $reset = $this->lifecycle()->reset($user->id, (int) $initial['subscription_id'], 'reset', '1', $at + 100);
        $old = DB::table('tx_usage_period')->where('id', $initial['usage_period_id'])->first();
        $new = DB::table('tx_usage_period')->where('id', $reset['usage_period_id'])->first();
        $this->assertSame('closed', $old->status);
        $this->assertSame('50', (string) $old->billable_bytes);
        $this->assertSame('0', (string) $new->billable_bytes);
        $this->assertSame((string) $old->ends_at, (string) $new->starts_at);
        $this->assertEquals($terms, DB::table('tx_subscription_terms')->first());
        $this->assertSameResult($reset, $this->lifecycle()->reset($user->id, (int) $initial['subscription_id'], 'reset', '1', $at + 100));
        $this->assertSame(2, DB::table('tx_usage_period')->count());
    }

    public function test_renewal_and_plan_change_append_terms_and_keep_consumption_until_explicit_reset(): void
    {
        [$user, $offer, $initial, $at] = $this->opened();
        DB::table('tx_usage_period')->where('id', $initial['usage_period_id'])->update(['billable_bytes' => 123]);
        $original = DB::table('tx_subscription_terms')->first();
        $renewed = $this->purchase($user->id, $offer, 'renew', $at + 10);
        $this->assertSame($initial['usage_period_id'], $renewed['usage_period_id']);
        $this->assertGreaterThan($initial['expires_at'], $renewed['expires_at']);
        $this->assertEquals($original, DB::table('tx_subscription_terms')->where('id', $original->id)->first());
        $plan = $this->plan(['transfer_enable' => 3]);
        $changed = $this->purchase($user->id, $this->publishedOffer($plan), 'change', $at + 20);
        $this->assertSame($initial['usage_period_id'], $changed['usage_period_id']);
        $this->assertNotNull($changed['quota_adjustment_id']);
        $period = DB::table('tx_usage_period')->where('id', $initial['usage_period_id'])->first();
        $this->assertSame('123', (string) $period->billable_bytes);
        $this->assertSame('3221225472', (string) $period->quota_bytes);
        $this->assertSame($initial['terms_id'], (string) $period->terms_id);
        $this->assertSame(3, DB::table('tx_subscription_terms')->count());
        $this->assertSame(2, DB::table('tx_subscription_terms_transition')->count());
        $resetOffer = (int) DB::table('tx_plan_offer')->where('plan_id', $plan->id)->where('offer_code', 'reset_traffic')->value('id');
        $reset = $this->purchase($user->id, $resetOffer, 'paid-reset', $at + 30);
        $this->assertNotSame($changed['usage_period_id'], $reset['usage_period_id']);
        $this->assertSame($changed['expires_at'], $reset['expires_at']);
        $this->assertSame('123', (string) DB::table('tx_usage_period')->where('id', $initial['usage_period_id'])->value('billable_bytes'));
        $this->assertSame('0', (string) DB::table('tx_usage_period')->where('id', $reset['usage_period_id'])->value('billable_bytes'));
    }

    public function test_expiration_releases_slot_at_frozen_expiry_and_perpetual_cannot_expire(): void
    {
        [$user, , $initial] = $this->opened();
        $subscription = (int) $initial['subscription_id'];
        $this->reject(fn () => $this->lifecycle()->expire($user->id, $subscription, 'early', '1', $initial['expires_at'] - 1),
            'NATIVE_EXPIRY_NOT_DUE');
        $expired = $this->lifecycle()->expire($user->id, $subscription, 'expire', '1', $initial['expires_at'] + 100);
        $this->assertSame('expired', $expired['status']);
        $this->assertSame($initial['expires_at'], (int) DB::table('tx_subscription')->where('id', $subscription)->value('ended_at'));
        $this->assertNull(DB::table('tx_subscription_slot')->where('user_id', $user->id)->value('subscription_id'));
        [$other, , $perpetual, $at] = $this->opened(period: 'onetime');
        $this->assertNull($perpetual['expires_at']);
        $this->reject(fn () => $this->lifecycle()->expire($other->id, (int) $perpetual['subscription_id'], 'perpetual', '1', $at + 100),
            'NATIVE_EXPIRY_NOT_DUE');
    }

    public function test_tick_replays_each_stored_boundary_with_bounded_catchup_and_expiry(): void
    {
        [$user, , $initial, $at] = $this->opened(['reset_traffic_method' => 0], 'yearly');
        $firstBoundary = (int) DB::table('tx_usage_period')->value('next_reset_at');
        $first = $this->lifecycle()->tick(1, $initial['expires_at'] + 100);
        $this->assertSame(['attempted' => 1, 'completed' => 1, 'failed' => 0, 'errors' => []], $first);
        $periods = DB::table('tx_usage_period')->orderBy('period_no')->get();
        $this->assertSame($firstBoundary, (int) $periods[1]->starts_at);
        $this->assertSame($firstBoundary, (int) $periods[0]->ends_at);
        $next = $this->lifecycle()->tick(100, $initial['expires_at'] + 100);
        $this->assertSame(0, $next['failed']);
        $this->assertGreaterThan(1, $next['completed']);
        $this->assertSame('expired', DB::table('tx_subscription')->value('status'));
        $this->assertNull(DB::table('tx_subscription_slot')->value('subscription_id'));
        $this->assertSame(0, $this->lifecycle()->tick(100, $initial['expires_at'] + 100)['attempted']);
        $periods = DB::table('tx_usage_period')->orderBy('period_no')->get();
        foreach ($periods as $index => $period) {
            $this->assertSame('closed', $period->status);
            if ($index > 0) {
                $this->assertSame((string) $periods[$index - 1]->ends_at, (string) $period->starts_at);
            }
        }
        $this->assertSame($initial['expires_at'], (int) $periods->last()->ends_at);
    }

    public function test_tick_isolates_failed_aggregate_and_retries_after_repair_without_partial_writes(): void
    {
        [, , $damaged] = $this->opened();
        [, , $healthy] = $this->opened();
        $hash = DB::table('tx_subscription_terms')->where('id', $damaged['terms_id'])->value('content_hash');
        DB::table('tx_subscription_terms')->where('id', $damaged['terms_id'])->update(['content_hash' => str_repeat('0', 64)]);
        $now = max($damaged['expires_at'], $healthy['expires_at']) + 100;
        $result = $this->lifecycle()->tick(100, $now);
        $this->assertSame(2, $result['attempted']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, $result['completed']);
        $this->assertSame('active', DB::table('tx_subscription')->where('id', $damaged['subscription_id'])->value('status'));
        $this->assertSame('expired', DB::table('tx_subscription')->where('id', $healthy['subscription_id'])->value('status'));
        $this->assertSame(3, DB::table('tx_subscription_operation')->count());
        DB::table('tx_subscription_terms')->where('id', $damaged['terms_id'])->update(['content_hash' => $hash]);
        $this->assertSame(1, $this->lifecycle()->tick(100, $now)['completed']);
        $this->assertSame(4, DB::table('tx_subscription_operation')->count());
    }

    public function test_banned_suspended_account_can_advance_scheduled_boundaries_without_resuming_access(): void
    {
        [$user, , $initial, $at] = $this->opened(['reset_traffic_method' => 0], 'yearly');
        $id = (int) $initial['subscription_id'];
        $this->lifecycle()->suspend($user->id, $id, 'suspend', '1', $at + 10);
        DB::table('tx_user')->where('id', $user->id)->update(['banned' => true]);
        $this->reject(fn () => $this->lifecycle()->reset($user->id, $id, 'manual', '2', $at + 20),
            'NATIVE_RESET_BOUNDARY_INVALID');
        $boundary = (int) DB::table('tx_usage_period')->value('next_reset_at');
        $this->assertSame(1, $this->lifecycle()->tick(1, $boundary)['completed']);
        $this->assertSame('suspended', DB::table('tx_subscription')->value('status'));
        $this->assertFalse(app(SubscriptionEntitlementResolver::class)->resolve($user->id, $boundary + 1)['authorized']);
        $this->assertSame(0, $this->lifecycle()->tick(100, $initial['expires_at'] + 100)['failed']);
        $this->assertSame('expired', DB::table('tx_subscription')->value('status'));
    }

    public function test_ban_ownership_and_native_admission_fail_closed(): void
    {
        [$user, , $initial, $at] = $this->opened();
        $id = (int) $initial['subscription_id'];
        $other = $this->user();
        $this->reject(fn () => $this->lifecycle()->revoke($other->id, $id, 'wrong-owner', '1', $at + 10),
            'NATIVE_SUBSCRIPTION_MISSING');
        $this->lifecycle()->suspend($user->id, $id, 'suspend', '1', $at + 10);
        DB::table('tx_user')->where('id', $user->id)->update(['banned' => true]);
        $this->reject(fn () => $this->lifecycle()->resume($user->id, $id, 'banned', '2', $at + 20),
            'NATIVE_LIFECYCLE_TRANSITION_INVALID');
        $this->denyNativeAdmission = true;
        $this->reject(fn () => $this->lifecycle()->revoke($user->id, $id, 'denied', '2', $at + 30),
            'INSTALLATION_NATIVE_NOT_READY');
        $this->reject(fn () => $this->lifecycle()->tick(), 'INSTALLATION_NATIVE_NOT_READY');
        $this->assertSame(2, DB::table('tx_subscription_operation')->count());
        $this->assertSame('suspended', DB::table('tx_subscription')->value('status'));
    }

    private function opened(array $plan = [], string $period = 'monthly'): array
    {
        $user = $this->user();
        $offer = $this->publishedOffer($this->plan($plan), $period);
        $at = time() - 3600;
        return [$user, $offer, $this->purchase($user->id, $offer, 'initial', $at), $at];
    }

    private function purchase(int $user, int $offer, string $key, int $at): array
    {
        $order = app(PublishedOrderService::class)->create($user, $offer, $key);
        DB::table('tx_order')->where('id', $order->id)->update(['status' => Order::STATUS_PROCESSING, 'paid_at' => $at]);
        $result = app(SubscriptionFulfillmentService::class)->fulfill($order->id, $at);
        $this->assertSameResult($result, app(SubscriptionFulfillmentService::class)->fulfill($order->id, $at + 1));
        return $result;
    }

    private function assertSameResult(array $expected, array $actual): void
    {
        $this->assertSame(SubscriptionTermsTimeline::json($expected), SubscriptionTermsTimeline::json($actual));
    }

    private function lifecycle(): SubscriptionLifecycleService
    {
        return app(SubscriptionLifecycleService::class);
    }

    private function reject(callable $operation, string $code): void
    {
        try {
            $operation();
            $this->fail('Expected native lifecycle rejection.');
        } catch (Throwable $error) {
            if ($error instanceof \PHPUnit\Framework\AssertionFailedError) {
                throw $error;
            }
            $this->assertSame($code, $error->getMessage());
        }
    }
}
