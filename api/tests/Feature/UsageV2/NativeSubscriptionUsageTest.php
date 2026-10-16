<?php

namespace Tests\Feature\UsageV2;

use App\Domains\UsageV2\UsagePayload;
use App\Domains\UsageV2\UsagePolicy;
use App\Domains\UsageV2\UsageReceipts;
use App\Domains\UsageV2\UsageSettlement;
use App\Models\Order;
use App\Domains\Subscription\Services\PublishedOrderService;
use App\Domains\Subscription\Services\SubscriptionEntitlementResolver;
use App\Domains\Subscription\Services\SubscriptionFulfillmentService;
use App\Domains\Subscription\Services\SubscriptionLifecycleService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Tests\Support\NativeRuntimeTestCase;
use Tests\Support\UsageV2Fixture;

final class NativeSubscriptionUsageTest extends NativeRuntimeTestCase
{
    use UsageV2Fixture;

    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $f = $this->usageFixture();
        $offer = (int) collect($this->offers)->firstWhere('offer_code', 'monthly')['offer_id'];
        $opened = ['subscription_id' => $f['sub'], 'terms_id' => $f['terms'],
            'usage_period_id' => $f['period'], 'subscription_version' =>
                (string) DB::table('tx_subscription')->where('id', $f['sub'])->value('row_version')];
        $this->fixture = $f + compact('opened', 'offer');
    }

    public function test_accepted_traffic_after_reset_settles_original_period_exactly_once(): void
    {
        $f = $this->fixture;
        $payload = $this->payload();
        $receipts = app(UsageReceipts::class);
        $first = $receipts->submit(1, $payload, $f['now'] + 30);
        $this->awaitHistoricalInterval();
        $reset = app(SubscriptionLifecycleService::class)->reset($f['user']->id,
            (int) $f['opened']['subscription_id'], 'manual-reset', $f['opened']['subscription_version'], $f['now'] + 40);
        $this->assertNotSame($f['opened']['usage_period_id'], $reset['usage_period_id']);
        $this->assertSame($first, $receipts->submit(1, $payload, $f['now'] + 50));
        $this->assertSame(['settled'], array_values(app(UsageSettlement::class)->scan(10, $f['now'] + 50)));
        $this->assertSame([], app(UsageSettlement::class)->scan(10, $f['now'] + 60));
        $this->assertSame('11', (string) DB::table('tx_usage_period')->where('id', $f['opened']['usage_period_id'])->value('billable_bytes'));
        $this->assertSame('0', (string) DB::table('tx_usage_period')->where('id', $reset['usage_period_id'])->value('billable_bytes'));
        $this->assertSame(1, DB::table('tx_usage_entry')->count());
        $this->assertSame(0, (int) $f['user']->fresh()->u);
        $state = app(SubscriptionEntitlementResolver::class)->resolve($f['user']->id, $f['now'] + 60);
        $this->assertTrue($state['authorized']);
        $this->assertSame('0', $state['consumed_bytes']);
    }

    public function test_renewal_retains_original_period_and_late_settlement_consumes_current_quota(): void
    {
        $f = $this->fixture;
        app(UsageReceipts::class)->submit(1, $this->payload(), $f['now'] + 30);
        $this->awaitHistoricalInterval();
        $order = app(PublishedOrderService::class)->create($f['user']->id, $f['offer'], 'usage-renew');
        $order->update(['status' => Order::STATUS_PROCESSING]);
        $renewed = app(SubscriptionFulfillmentService::class)->fulfill($order->id, $f['now'] + 40);
        $this->assertSame($f['opened']['usage_period_id'], $renewed['usage_period_id']);
        $this->assertNotSame($f['opened']['terms_id'], $renewed['terms_id']);
        $this->assertSame(['settled'], array_values(app(UsageSettlement::class)->scan(10, $f['now'] + 50)));
        $state = app(SubscriptionEntitlementResolver::class)->resolve($f['user']->id, $f['now'] + 60);
        $this->assertTrue($state['authorized']);
        $this->assertSame('11', $state['consumed_bytes']);
        $this->assertSame($f['opened']['terms_id'], $state['period_terms_id']);
        $this->assertSame($renewed['terms_id'], $state['terms_id']);
    }

    public function test_reset_and_renewal_cannot_reassign_an_accepted_interval_in_any_receipt_state(): void
    {
        $f = $this->fixture;
        app(UsageReceipts::class)->submit(1, $this->payload(80), $f['now'] + 80);
        $order = app(PublishedOrderService::class)->create($f['user']->id, $f['offer'], 'unsafe-renewal');
        $order->update(['status' => Order::STATUS_PROCESSING]);
        $subscription = DB::table('tx_subscription')->where('id', $f['opened']['subscription_id'])->first();
        $period = DB::table('tx_usage_period')->where('id', $f['opened']['usage_period_id'])->first();
        $operations = DB::table('tx_subscription_operation')->count();

        foreach (['pending', 'processing', 'retry_wait', 'quarantined', 'settled'] as $state) {
            DB::table('tx_usage_batch')->update(['status' => $state]);
            foreach ([
                fn () => app(SubscriptionLifecycleService::class)->reset($f['user']->id,
                    (int) $f['opened']['subscription_id'], 'unsafe-reset',
                    $f['opened']['subscription_version'], $f['now'] + 40),
                fn () => app(SubscriptionFulfillmentService::class)->fulfill($order->id, $f['now'] + 40),
            ] as $mutate) {
                try {
                    $mutate();
                    $this->fail('Accepted usage was reassigned in receipt state '.$state);
                } catch (DomainException $error) {
                    $this->assertSame('NATIVE_ACCEPTED_USAGE_WOULD_BE_REASSIGNED', $error->getMessage());
                }
                $this->assertEquals($subscription, DB::table('tx_subscription')->where('id', $subscription->id)->first());
                $this->assertEquals($period, DB::table('tx_usage_period')->where('id', $period->id)->first());
                $this->assertSame(1, DB::table('tx_subscription_terms')->count());
                $this->assertSame(0, DB::table('tx_subscription_terms_transition')->count());
                $this->assertSame($operations, DB::table('tx_subscription_operation')->count());
                $this->assertSame(Order::STATUS_PROCESSING, (int) $order->fresh()->status);
            }
        }
    }

    public function test_settlement_failure_rolls_back_period_and_ledger_then_recovers(): void
    {
        $f = $this->fixture;
        app(UsageReceipts::class)->submit(1, $this->payload(), $f['now'] + 30);
        $worker = new UsageSettlement(app(UsagePolicy::class), static function (string $phase): void {
            if ($phase === 'after_period') throw new \RuntimeException('injected-native-rollback');
        });
        $this->assertSame(['retry_wait'], array_values($worker->scan(10, $f['now'] + 40)));
        $this->assertSame('0', (string) DB::table('tx_usage_period')->value('billable_bytes'));
        $this->assertSame(0, DB::table('tx_usage_entry')->count());
        $this->assertSame(['settled'], array_values(app(UsageSettlement::class)->scan(10, $f['now'] + 100)));
        $this->assertSame('11', (string) DB::table('tx_usage_period')->value('billable_bytes'));
    }

    private function awaitHistoricalInterval(): void
    {
        // Credential synchronization records real whole-second cutoffs, including after a reset or renewal.
        $deadline = microtime(true) + 3;
        while (time() <= $this->fixture['now'] && microtime(true) < $deadline) { usleep(20000); }
        $this->assertGreaterThan($this->fixture['now'], time());
    }

    private function payload(int $end = 1): array
    {
        $f = $this->fixture;
        $payload = ['protocol_version' => 2, 'node_id' => '1', 'source_epoch' => (string) $f['source']->source_epoch,
            'batch_id' => 'native_usage_batch_0001', 'started_at' => $f['now'], 'ended_at' => $f['now'] + $end,
            'entries' => [['access_subject_id' => (string) $f['subject'], 'auth_snapshot_id' => (string) $f['grant']->id,
                'tariff_revision_id' => (string) $f['tariff']->id, 'terms_id' => $f['opened']['terms_id'],
                'usage_period_id' => $f['opened']['usage_period_id'], 'raw_up_bytes' => '3', 'raw_down_bytes' => '5']]];
        ksort($payload['entries'][0]);
        ksort($payload);
        $canonical = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        return app(UsagePayload::class)->submission(json_encode(['payload' => $payload,
            'payload_digest' => 'sha256:'.hash('sha256', $canonical)], JSON_THROW_ON_ERROR));
    }
}
