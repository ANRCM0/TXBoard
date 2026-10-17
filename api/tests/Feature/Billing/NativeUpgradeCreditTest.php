<?php

namespace Tests\Feature\Billing;

use App\Domains\Billing\MoneyValue;
use App\Domains\Billing\NativeBillingRecovery;
use App\Domains\Billing\NativeBillingTransaction;
use App\Domains\Billing\NativeOrderLifecycle;
use App\Domains\Billing\NativePaymentAttempts;
use App\Domains\Billing\NativePaymentSettlement;
use App\Domains\Billing\NativeRechargeService;
use App\Domains\Billing\NativeRefundService;
use App\Domains\Billing\NativeUpgradeCredits;
use App\Domains\Billing\NativeWalletReservations;
use App\Domains\Billing\OrderCheckout;
use App\Domains\CredentialProvider\ProviderCodec;
use App\Domains\Subscription\Services\NativeSubscriptionStore;
use App\Domains\Subscription\Services\PlanPublicationService;
use App\Domains\Subscription\Services\PublishedOrderService;
use App\Domains\Subscription\Services\SubscriptionFulfillmentService;
use App\Domains\Subscription\Services\SubscriptionLifecycleService;
use App\Domains\UsageV2\UsagePayload;
use App\Domains\UsageV2\UsageReceipts;
use App\Domains\UsageV2\UsageSettlement;
use App\Models\Order;
use App\Models\OrderPurchaseTerms;
use App\Models\Plan;
use App\Models\User;
use App\Services\Auth\NativeIdentityService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\NativePaymentTestCase;

final class NativeUpgradeCreditTest extends NativePaymentTestCase
{
    protected string $publicationCurrency = 'CNY';
    protected array $planPrices = ['monthly' => '12.34', 'onetime' => '99.00', 'reset_traffic' => '2.00'];
    private int $fixtureSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->payment->update(['payment' => 'EPay', 'config' => [
            'url' => 'https://epay.example.test', 'pid' => '42', 'key' => 'test-secret', 'type' => 'alipay',
        ]]);
    }

    public function test_half_used_purchase_quotes_actual_calendar_seconds_and_preserves_permanent_order_replay(): void
    {
        [$user, $source, , $subscription] = $this->paidSource();
        $offer = $this->targetOffer('25.00');
        $target = app(PublishedOrderService::class)->create($user->id, $offer, 'half-used-upgrade');
        $quote = $this->quote($target);
        $this->assertCount(1, $quote['sources']);
        $component = $quote['sources'][0];
        $this->assertSame((string) $source->id, $component['order_id']);
        $this->assertSame((string) $subscription['applied_at'], $component['coverage_from']);
        $this->assertSame((string) $subscription['expires_at'], $component['coverage_until']);
        $expected = $this->timeCredit('1234', $subscription['applied_at'], $subscription['expires_at'], (int) $quote['quoted_at']);
        $this->assertSame($expected, $quote['credit_minor']);
        $this->assertSame(bcsub('2500', $expected, 0), (string) $target->total_amount);
        $this->assertGreaterThan(0, (int) $quote['credit_minor']);
        $this->assertLessThan(1234, (int) $quote['credit_minor']);
        $before = DB::table('tx_order_upgrade_credit')->where('order_id', $target->id)->first();
        $replayed = app(PublishedOrderService::class)->create($user->id, $offer, 'half-used-upgrade');
        $this->assertSame($target->id, $replayed->id);
        $this->assertEquals($before, DB::table('tx_order_upgrade_credit')->where('order_id', $target->id)->first());
        $this->assertSame(1, DB::table('tx_upgrade_credit_source')->count());
    }

    public function test_early_renewal_credits_future_purchased_coverage_without_clipping_the_original_purchase(): void
    {
        [$user, $source, , $initial] = $this->paidSource();
        $monthly = (int) collect($this->offers)->firstWhere('offer_code', 'monthly')['offer_id'];
        $renewal = app(PublishedOrderService::class)->create($user->id, $monthly, 'advance-renewal');
        $this->payChannel($user, $renewal);
        $renewed = app(SubscriptionFulfillmentService::class)->fulfill($renewal->id, time() - 10);
        $target = app(PublishedOrderService::class)->create($user->id, $this->targetOffer('50.00'), 'renewed-upgrade');
        $quote = $this->quote($target);
        $this->assertCount(2, $quote['sources']);
        $first = collect($quote['sources'])->firstWhere('order_id', (string) $source->id);
        $future = collect($quote['sources'])->firstWhere('order_id', (string) $renewal->id);
        $this->assertSame((string) $initial['applied_at'], $first['coverage_from']);
        $this->assertSame((string) $initial['expires_at'], $first['coverage_until']);
        $this->assertSame((string) $initial['expires_at'], $future['coverage_from']);
        $this->assertSame((string) $renewed['expires_at'], $future['coverage_until']);
        $this->assertSame('1234', $future['cash_applied_minor']);
        $expected = bcadd('1234', $this->timeCredit('1234', $initial['applied_at'],
            $initial['expires_at'], (int) $quote['quoted_at']), 0);
        $this->assertSame($expected, $quote['credit_minor']);
    }

    public function test_wallet_refund_reduces_credit_and_pending_claim_blocks_excess_refund_until_cancellation(): void
    {
        [$user, $source, $paid, $subscription] = $this->paidSource();
        $actor = $this->admin();
        $refunds = app(NativeRefundService::class);
        $refunds->refundToWallet($user->id, 'receipt', (int) $paid['receipt_id'], '200',
            'before-upgrade-refund', $actor->id, 'customer_request');
        $target = app(PublishedOrderService::class)->create($user->id, $this->targetOffer('25.00'), 'refund-aware-upgrade');
        $quote = $this->quote($target);
        $this->assertSame('200', $quote['sources'][0]['refunded_minor']);
        $this->assertSame($this->timeCredit('1034', $subscription['applied_at'], $subscription['expires_at'],
            (int) $quote['quoted_at']), $quote['credit_minor']);
        $this->rejects('REFUND_AMOUNT_RESERVED_FOR_UPGRADE', fn () => $refunds->refundToWallet($user->id,
            'receipt', (int) $paid['receipt_id'], '1034', 'reserved-upgrade-refund', $actor->id, 'customer_request'));
        $this->assertSame('200', $this->walletAvailable($user));
        $this->assertTrue((new NativeOrderLifecycle($target))->cancel());
        $refunds->refundToWallet($user->id, 'receipt', (int) $paid['receipt_id'], '1034',
            'released-upgrade-refund', $actor->id, 'customer_request');
        $this->assertSame('1234', $this->walletAvailable($user));
        $this->assertSame(Order::STATUS_COMPLETED, (int) $source->fresh()->status);
        $this->assertSame('active', DB::table('tx_subscription')->where('id', $subscription['subscription_id'])->value('status'));
    }

    public function test_fully_refunded_source_keeps_entitlement_but_cannot_mint_an_upgrade_credit(): void
    {
        [$user, , $paid, $subscription] = $this->paidSource();
        $actor = $this->admin();
        app(NativeRefundService::class)->refundToWallet($user->id, 'receipt', (int) $paid['receipt_id'],
            '1234', 'full-source-refund', $actor->id, 'customer_request');
        $target = app(PublishedOrderService::class)->create($user->id, $this->targetOffer('25.00'), 'refunded-source-upgrade');
        $this->assertSame('2500', (string) $target->total_amount);
        $this->assertSame('0', $this->quote($target)['credit_minor']);
        $this->assertSame([], $this->quote($target)['sources']);
        $this->assertSame('1234', $this->walletAvailable($user));
        $this->assertSame('active', DB::table('tx_subscription')->where('id', $subscription['subscription_id'])->value('status'));
    }

    public function test_zero_credit_upgrade_still_binds_its_source_and_rejects_late_money_after_source_change(): void
    {
        [$user, , $paid, $subscription] = $this->paidSource();
        $actor = $this->admin();
        app(NativeRefundService::class)->refundToWallet($user->id, 'receipt', (int) $paid['receipt_id'],
            '1234', 'zero-credit-source-refund', $actor->id, 'customer_request');
        $target = app(PublishedOrderService::class)->create($user->id, $this->targetOffer('25.00'), 'zero-credit-source-change');
        $this->assertSame('0', $this->quote($target)['credit_minor']);
        $this->assertSame('2500', (string) $target->total_amount);
        $attempt = $this->prepare($user, $target);
        app(SubscriptionLifecycleService::class)->suspend($user->id, (int) $subscription['subscription_id'],
            'zero-credit-source-suspended', $subscription['subscription_version']);
        $settled = app(NativePaymentSettlement::class)->settle($user->id, (int) $attempt['attempt_id'],
            $this->fact($attempt, ['transaction_ref' => 'zero-credit-late-payment']));
        $this->assertSame('refund_liability', $settled['decision']);
        $this->assertSame(Order::STATUS_CANCELLED, (int) $target->fresh()->status);
        $this->assertSame(0, DB::table('tx_subscription_terms')->where('source_order_id', $target->id)->count());
        $this->assertSame(0, DB::table('tx_order_payment')->where('order_id', $target->id)->count());
        $this->assertSame('1234', $this->walletAvailable($user));
    }

    public function test_traffic_reset_payment_is_not_reused_as_time_prorated_principal(): void
    {
        [$user, $original, , $subscription] = $this->paidSource();
        $offer = (int) collect($this->offers)->firstWhere('offer_code', 'reset_traffic')['offer_id'];
        $reset = app(PublishedOrderService::class)->create($user->id, $offer, 'paid-traffic-reset');
        $this->payChannel($user, $reset);
        app(SubscriptionFulfillmentService::class)->fulfill($reset->id, time() - 10);
        $target = app(PublishedOrderService::class)->create($user->id, $this->targetOffer('25.00'), 'reset-aware-upgrade');
        $quote = $this->quote($target);
        $this->assertCount(1, $quote['sources']);
        $this->assertSame((string) $original->id, $quote['sources'][0]['order_id']);
        $this->assertSame($this->timeCredit('1234', $subscription['applied_at'], $subscription['expires_at'],
            (int) $quote['quoted_at']), $quote['credit_minor']);
        $this->assertSame(0, DB::table('tx_upgrade_credit_source')->where('order_payment_id',
            DB::table('tx_order_payment')->where('order_id', $reset->id)->value('id'))->count());
    }

    public function test_consecutive_upgrades_preserve_non_cash_carry_without_increasing_the_cash_refund_limit(): void
    {
        [$user] = $this->paidSource();
        $second = app(PublishedOrderService::class)->create($user->id, $this->targetOffer('25.00'), 'upgrade-to-b');
        $secondQuote = $this->quote($second);
        $secondPaid = $this->payChannel($user, $second);
        $this->assertSame(Order::STATUS_COMPLETED, (int) $second->fresh()->status);
        $secondResult = app(SubscriptionFulfillmentService::class)->fulfill($second->id);
        // Terms transitions use strictly increasing, integral-second boundaries.
        while (time() <= $secondResult['applied_at']) { usleep(100000); }
        $third = app(PublishedOrderService::class)->create($user->id, $this->targetOffer('50.00'), 'upgrade-to-c');
        $thirdQuote = $this->quote($third);
        $this->assertCount(1, $thirdQuote['sources']);
        $source = $thirdQuote['sources'][0];
        $this->assertSame((string) $second->id, $source['order_id']);
        $this->assertSame($secondQuote['credit_minor'], $source['carry_minor']);
        $this->assertSame((string) $second->total_amount, $source['cash_minor']);
        $this->assertGreaterThan(0, (int) $source['carry_applied_minor']);
        $this->assertSame(MoneyValue::add($source['cash_applied_minor'], $source['carry_applied_minor']),
            $thirdQuote['credit_minor']);
        $this->assertGreaterThan((int) $second->total_amount, (int) $thirdQuote['credit_minor']);
        $this->assertTrue((new NativeOrderLifecycle($third))->cancel());
        $actor = $this->admin();
        $this->rejects('REFUND_AMOUNT_EXCEEDS_SOURCE', fn () => app(NativeRefundService::class)->refundToWallet(
            $user->id, 'receipt', (int) $secondPaid['receipt_id'], '2500', 'cannot-refund-carry',
            $actor->id, 'customer_request'));
        $this->assertSame('0', $this->walletAvailable($user));
    }

    public function test_credit_capped_to_the_target_price_uses_free_checkout_without_a_fake_financial_fact(): void
    {
        [$user] = $this->paidSource();
        $target = app(PublishedOrderService::class)->create($user->id, $this->targetOffer('1.00'), 'fully-credited-upgrade');
        $quote = $this->quote($target);
        $this->assertSame('100', $quote['credit_minor']);
        $this->assertSame('0', (string) $target->total_amount);
        $before = $this->snapshots(['tx_payment_attempt', 'tx_payment_receipt', 'tx_money_journal', 'tx_order_payment']);
        $first = app(OrderCheckout::class)->start($user->id, $target->trade_no, requestKey: 'zero-upgrade-checkout');
        $this->assertSame(['type' => -1, 'data' => true], $first);
        $this->assertSame(Order::STATUS_COMPLETED, (int) $target->fresh()->status);
        $this->assertSame($first, app(OrderCheckout::class)->start($user->id, $target->trade_no,
            requestKey: 'zero-upgrade-checkout'));
        app(NativeBillingRecovery::class)->recover();
        $this->assertSame($before, $this->snapshots(array_keys($before)));
        $this->assertSame(1, DB::table('tx_subscription_operation')->where('order_id', $target->id)->count());
        $this->assertSame(1, DB::table('tx_native_free_checkout')->where('order_id', $target->id)->count());
        $this->assertSame('completed', DB::table('tx_native_free_checkout')->where('order_id', $target->id)->value('status'));
    }

    public function test_admin_manual_zero_net_upgrade_rejects_stale_quote_and_applies_valid_rights_before_worker(): void
    {
        admin_setting(['secure_path' => 'upgrade_credit_admin']);
        [$owner] = $this->paidSource();
        $valid = app(PublishedOrderService::class)->create($owner->id, $this->targetOffer('1.00'), 'manual-valid-upgrade');
        [$staleOwner, , , $subscription] = $this->paidSource();
        $stale = app(PublishedOrderService::class)->create($staleOwner->id, $this->targetOffer('1.00'), 'manual-stale-upgrade');
        $this->assertSame('0', (string) $valid->total_amount);
        $this->assertSame('0', (string) $stale->total_amount);
        app(SubscriptionLifecycleService::class)->suspend($staleOwner->id, (int) $subscription['subscription_id'],
            'manual-upgrade-source-suspended', $subscription['subscription_version']);
        Sanctum::actingAs($this->admin()->fresh());
        $url = '/txapi/admin/upgrade_credit_admin/orders/';
        $tables = ['tx_order', 'tx_subscription', 'tx_subscription_terms', 'tx_subscription_operation',
            'tx_payment_attempt', 'tx_payment_receipt', 'tx_money_journal', 'tx_order_payment', 'tx_native_runtime_outbox'];
        $before = $this->snapshots($tables);
        $this->postJson($url.$stale->trade_no.'/paid')->assertStatus(409)->assertJsonPath('error.code', 'ORDER_CONFLICT');
        $this->assertSame($before, $this->snapshots($tables));
        $this->assertSame(Order::STATUS_PENDING, (int) $stale->fresh()->status);
        $financial = $this->snapshots(['tx_payment_attempt', 'tx_payment_receipt', 'tx_money_journal', 'tx_order_payment']);
        $this->postJson($url.$valid->trade_no.'/paid')->assertOk()->assertJsonPath('data.ok', true);
        // NativeRuntimeTestCase fakes the queue, so these rights must already be committed in the request.
        $this->assertSame(Order::STATUS_COMPLETED, (int) $valid->fresh()->status);
        $terms = DB::table('tx_subscription_terms')->where('source_order_id', $valid->id)->first();
        $this->assertNotNull($terms);
        $this->assertSame((string) $terms->id,
            (string) DB::table('tx_subscription')->where('id', $terms->subscription_id)->value('current_terms_id'));
        $this->assertSame(1, DB::table('tx_subscription_operation')->where('order_id', $valid->id)->count());
        $this->assertSame($financial, $this->snapshots(array_keys($financial)));
        $accepted = $this->snapshots($tables);
        $this->postJson($url.$valid->trade_no.'/paid')->assertStatus(409)->assertJsonPath('error.code', 'ORDER_CONFLICT');
        $this->assertSame($accepted, $this->snapshots($tables));
    }

    public function test_quote_has_a_half_open_expiration_boundary_and_does_not_reprice_on_replay(): void
    {
        [$user] = $this->paidSource();
        $offer = $this->targetOffer('25.00');
        $target = app(PublishedOrderService::class)->create($user->id, $offer, 'ttl-upgrade');
        $quote = $this->quote($target);
        $expires = (int) $quote['expires_at'];
        $this->assertLessThanOrEqual(7200, $expires - (int) $quote['quoted_at']);
        $check = fn (int $at): bool => app(NativeBillingTransaction::class)->run($user->id,
            function (array $installation) use ($user, $target, $at): bool {
                app(NativeSubscriptionStore::class)->lockAccount($user->id, $at, $installation['installation_id']);
                return app(NativeUpgradeCredits::class)->payableLocked($installation, $target->fresh(), $at);
            });
        $this->assertTrue($check($expires - 1));
        $this->assertFalse($check($expires));
        $this->assertFalse($check((int) $quote['quoted_at'] - 1));
        $again = app(PublishedOrderService::class)->create($user->id, $offer, 'ttl-upgrade');
        $this->assertSame($target->id, $again->id);
        $this->assertSame($quote, $this->quote($again));
    }

    public function test_late_mixed_payment_after_source_change_becomes_liability_and_releases_reserved_wallet(): void
    {
        [$user, , , $subscription] = $this->paidSource();
        $this->fund($user, '5000');
        $target = app(PublishedOrderService::class)->create($user->id, $this->targetOffer('25.00'), 'mixed-source-change');
        $this->reserve($user, $target, '100', 'upgrade-mixed-reservation');
        $attempt = $this->prepare($user, $target);
        app(SubscriptionLifecycleService::class)->suspend($user->id, (int) $subscription['subscription_id'],
            'change-upgrade-source', $subscription['subscription_version']);
        $fact = $this->fact($attempt, ['transaction_ref' => 'late-upgrade-channel']);
        $settled = app(NativePaymentSettlement::class)->settle($user->id, (int) $attempt['attempt_id'], $fact);
        $this->assertSame('refund_liability', $settled['decision']);
        $this->assertNull($settled['order_payment_id']);
        $this->assertSame('released', DB::table('tx_wallet_order_reservation')->where('order_id', $target->id)->value('status'));
        $this->assertSame('5000', $this->walletAvailable($user));
        $this->assertSame('0', (string) DB::table('tx_money_account')->where('owner_id', $user->id)
            ->where('purpose', 'wallet_reserved')->value('balance_minor'));
        $this->assertSame(0, DB::table('tx_subscription_operation')->where('order_id', $target->id)->count());
        $this->assertSame(Order::STATUS_CANCELLED, (int) $target->fresh()->status);
        $before = $this->snapshots(['tx_money_journal', 'tx_payment_receipt', 'tx_payment_settlement']);
        $this->assertSame($settled, app(NativePaymentSettlement::class)->settle($user->id, (int) $attempt['attempt_id'], $fact));
        $this->assertSame($before, $this->snapshots(array_keys($before)));
    }

    public function test_cancelled_quote_releases_source_claim_and_late_receipt_cannot_fulfill_it(): void
    {
        [$user, , $sourcePaid] = $this->paidSource();
        $target = app(PublishedOrderService::class)->create($user->id, $this->targetOffer('25.00'), 'cancelled-upgrade');
        $attempt = $this->prepare($user, $target);
        $this->assertTrue((new NativeOrderLifecycle($target))->cancel());
        $actor = $this->admin();
        app(NativeRefundService::class)->refundToWallet($user->id, 'receipt', (int) $sourcePaid['receipt_id'],
            '1234', 'refund-after-upgrade-cancel', $actor->id, 'customer_request');
        $settled = app(NativePaymentSettlement::class)->settle($user->id, (int) $attempt['attempt_id'],
            $this->fact($attempt, ['transaction_ref' => 'cancelled-upgrade-arrival']));
        $this->assertSame('refund_liability', $settled['decision']);
        $this->assertSame(Order::STATUS_CANCELLED, (int) $target->fresh()->status);
        $this->assertSame(0, DB::table('tx_order_payment')->where('order_id', $target->id)->count());
        $this->assertSame(0, DB::table('tx_subscription_terms')->where('source_order_id', $target->id)->count());
        $this->assertSame('1234', $this->walletAvailable($user));
    }

    public function test_atomic_upgrade_persistence_failure_rolls_back_money_and_rights_then_retries_once(): void
    {
        [$user] = $this->paidSource();
        $target = app(PublishedOrderService::class)->create($user->id, $this->targetOffer('25.00'), 'atomic-upgrade');
        $attempt = $this->prepare($user, $target);
        $tables = ['tx_order', 'tx_subscription', 'tx_subscription_slot', 'tx_subscription_terms',
            'tx_subscription_terms_transition', 'tx_usage_period', 'tx_subscription_operation', 'tx_money_account',
            'tx_money_journal', 'tx_money_posting', 'tx_payment_receipt', 'tx_payment_settlement', 'tx_order_payment',
            'tx_billing_work', 'tx_native_runtime_outbox', 'tx_order_upgrade_credit', 'tx_upgrade_credit_source'];
        $before = $this->snapshots($tables);
        $fail = true;
        DB::listen(static function ($query) use (&$fail): void {
            if ($fail && preg_match('/\binsert\s+into\b/i', $query->sql)
                && str_contains($query->sql, 'tx_subscription_operation')) {
                $fail = false;
                throw new \RuntimeException('INJECTED_UPGRADE_OPERATION_FAILURE');
            }
        });
        $fact = $this->fact($attempt, ['transaction_ref' => 'atomic-upgrade-transaction']);
        try {
            app(NativePaymentSettlement::class)->settle($user->id, (int) $attempt['attempt_id'], $fact);
            $this->fail('A failed upgrade operation must roll back its financial acceptance.');
        } catch (\RuntimeException $error) {
            $this->assertSame('INJECTED_UPGRADE_OPERATION_FAILURE', $error->getMessage());
        }
        $this->assertFalse($fail);
        $this->assertSame($before, $this->snapshots($tables));
        $settled = app(NativePaymentSettlement::class)->settle($user->id, (int) $attempt['attempt_id'], $fact);
        $this->assertSame('order_payment', $settled['decision']);
        $this->assertSame(Order::STATUS_COMPLETED, (int) $target->fresh()->status);
        $this->assertSame($settled, app(NativePaymentSettlement::class)->settle($user->id, (int) $attempt['attempt_id'], $fact));
        app(NativeBillingRecovery::class)->recover();
        $this->assertSame(1, DB::table('tx_payment_receipt')->where('attempt_id', $attempt['attempt_id'])->count());
        $this->assertSame(1, DB::table('tx_payment_settlement')->where('attempt_id', $attempt['attempt_id'])->count());
        $this->assertSame(1, DB::table('tx_subscription_terms')->where('source_order_id', $target->id)->count());
        $this->assertSame(1, DB::table('tx_subscription_operation')->where('order_id', $target->id)->count());
        $this->assertSame('completed', DB::table('tx_billing_work')->where('order_id', $target->id)->value('status'));
    }

    public function test_wallet_upgrade_charges_only_the_net_cash_price_and_applies_rights_atomically(): void
    {
        [$user] = $this->paidSource();
        $this->fund($user, '5000');
        $target = app(PublishedOrderService::class)->create($user->id, $this->targetOffer('25.00'), 'wallet-upgrade');
        $beforeReceipts = DB::table('tx_payment_receipt')->count();
        $first = app(OrderCheckout::class)->start($user->id, $target->trade_no, requestKey: 'wallet-upgrade-checkout', wallet: true);
        $this->assertSame(Order::STATUS_COMPLETED, (int) $target->fresh()->status);
        $this->assertSame(bcsub('5000', (string) $target->total_amount, 0), $this->walletAvailable($user));
        $this->assertSame($beforeReceipts, DB::table('tx_payment_receipt')->count());
        $this->assertSame(1, DB::table('tx_order_payment')->where('order_id', $target->id)->count());
        $this->assertSame(1, DB::table('tx_subscription_operation')->where('order_id', $target->id)->count());
        $this->assertSame($first, app(OrderCheckout::class)->start($user->id, $target->trade_no,
            requestKey: 'wallet-upgrade-checkout', wallet: true));
    }

    public function test_perpetual_credit_counts_reserved_and_settled_usage_and_reset_does_not_recreate_spent_principal(): void
    {
        [$user, , , $subscription] = $this->paidSource(period: 'onetime');
        $target = app(PublishedOrderService::class)->create($user->id, $this->targetOffer('150.00'), 'perpetual-upgrade');
        $this->assertSame('9900', $this->quote($target)['credit_minor']);
        $attempt = $this->prepare($user, $target);
        $snapshot = $this->acceptUsage('715827883');
        $this->assertSame('1073741824', (string) DB::table('tx_native_usage_reservation')->value('reserved_bytes'));
        $this->assertSame('0', (string) DB::table('tx_usage_period')->where('id', $subscription['usage_period_id'])->value('billable_bytes'));
        $this->awaitUsageInterval($snapshot);
        $settled = app(NativePaymentSettlement::class)->settle($user->id, (int) $attempt['attempt_id'],
            $this->fact($attempt, ['transaction_ref' => 'perpetual-consumed-after-quote']));
        $this->assertSame('refund_liability', $settled['decision']);
        $this->assertSame(0, DB::table('tx_subscription_terms')->where('source_order_id', $target->id)->count());
        $this->assertSame(Order::STATUS_CANCELLED, (int) $target->fresh()->status);
        $this->assertFalse((new NativeOrderLifecycle($target))->cancel());
        $reserved = app(PublishedOrderService::class)->create($user->id, $this->targetOffer('150.00'), 'reserved-volume-upgrade');
        $this->assertSame('4950', $this->quote($reserved)['credit_minor']);
        $this->assertTrue((new NativeOrderLifecycle($reserved))->cancel());
        $this->assertSame(['settled'], array_values(app(UsageSettlement::class)->scan(10, $snapshot['issued_at'] + 3)));
        $this->assertSame(0, DB::table('tx_native_usage_reservation')->count());
        $this->assertSame('1073741824', (string) DB::table('tx_usage_period')->where('id', $subscription['usage_period_id'])->value('billable_bytes'));
        app(SubscriptionLifecycleService::class)->reset($user->id, (int) $subscription['subscription_id'],
            'perpetual-manual-reset', $subscription['subscription_version']);
        $reset = app(PublishedOrderService::class)->create($user->id, $this->targetOffer('150.00'), 'reset-volume-upgrade');
        $this->assertSame('4950', $this->quote($reset)['credit_minor']);
    }

    public function test_normal_finite_usage_counter_changes_do_not_invalidate_a_frozen_time_credit(): void
    {
        [$user, , , $subscription] = $this->paidSource();
        $target = app(PublishedOrderService::class)->create($user->id, $this->targetOffer('25.00'), 'time-credit-with-usage');
        $quote = $this->quote($target);
        $attempt = $this->prepare($user, $target);
        $beforeVersion = DB::table('tx_usage_period')->where('id', $subscription['usage_period_id'])->value('row_version');
        $snapshot = $this->acceptUsage('3', '5');
        $this->awaitUsageInterval($snapshot);
        $this->assertSame(['settled'], array_values(app(UsageSettlement::class)->scan(10, $snapshot['issued_at'] + 3)));
        $this->assertSame('11', (string) DB::table('tx_usage_period')->where('id', $subscription['usage_period_id'])->value('billable_bytes'));
        $this->assertGreaterThan((int) $beforeVersion,
            (int) DB::table('tx_usage_period')->where('id', $subscription['usage_period_id'])->value('row_version'));
        $settled = app(NativePaymentSettlement::class)->settle($user->id, (int) $attempt['attempt_id'],
            $this->fact($attempt, ['transaction_ref' => 'finite-usage-after-quote']));
        $this->assertSame('order_payment', $settled['decision']);
        $this->assertSame(Order::STATUS_COMPLETED, (int) $target->fresh()->status);
        $this->assertSame($quote, $this->quote($target));
        $this->assertSame(1, DB::table('tx_subscription_operation')->where('order_id', $target->id)->count());
    }

    private function paidSource(int $ageSeconds = 1296000, string $period = 'monthly'): array
    {
        [, $user] = app(NativeIdentityService::class)->create('credit-'.bin2hex(random_bytes(8)).'@example.test', 'runtime-password');
        $offer = (int) collect($this->offers)->firstWhere('offer_code', $period)['offer_id'];
        $order = app(PublishedOrderService::class)->create($user->id, $offer, 'source-order');
        $paid = $this->payChannel($user, $order);
        $subscription = app(SubscriptionFulfillmentService::class)->fulfill($order->id, time() - $ageSeconds);
        return [$user, $order, $paid, $subscription];
    }

    private function targetOffer(string $monthly): int
    {
        $sourceOffer = DB::table('tx_plan_offer')->where('id', $this->offers[0]['offer_id'])->first();
        $source = Plan::query()->findOrFail($sourceOffer->plan_id);
        $sequence = ++$this->fixtureSequence;
        $plan = Plan::withoutEvents(fn () => Plan::query()->create([
            'name' => 'Upgrade target '.$sequence, 'group_id' => $source->group_id,
            'transfer_enable' => 4, 'speed_limit' => 60, 'device_limit' => 4,
            'reset_traffic_method' => 2, 'capacity_limit' => null,
            'prices' => ['monthly' => $monthly], 'show' => true, 'sell' => true, 'renew' => true,
        ]));
        $published = app(PlanPublicationService::class)->publish($plan->id, 'upgrade-target-'.$sequence, 'CNY', 'UTC');
        return (int) collect($published['offers'])->firstWhere('offer_code', 'monthly')['offer_id'];
    }

    private function quote(Order $order): array
    {
        $json = DB::table('tx_order_upgrade_credit')->where('order_id', $order->id)->value('quote_json');
        $this->assertIsString($json);
        return json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    }

    private function timeCredit(string $amount, int $from, int $until, int $at): string
    {
        return bcdiv(bcmul($amount, (string) max(0, $until - max($from, $at)), 0), (string) ($until - $from), 0);
    }

    private function prepare(User $user, Order $order): array
    {
        return app(NativePaymentAttempts::class)->prepare($user->id, $order->id, $this->payment->id,
            'merchant-1', 'credit-channel:'.$order->id);
    }

    private function payChannel(User $user, Order $order): array
    {
        $attempt = $this->prepare($user, $order);
        return app(NativePaymentSettlement::class)->settle($user->id, (int) $attempt['attempt_id'],
            $this->fact($attempt, ['transaction_ref' => 'credit-paid:'.$order->id]));
    }

    private function fund(User $user, string $amount): void
    {
        $key = 'upgrade-recharge:'.(++$this->fixtureSequence);
        $recharge = app(NativeRechargeService::class)->create($user->id, $amount, $this->payment->id, $key);
        $attempt = app(NativePaymentAttempts::class)->prepareRecharge($user->id, (int) $recharge['recharge_id'],
            $this->payment->id, 'merchant-1', 'channel:'.$key);
        app(NativePaymentSettlement::class)->settle($user->id, (int) $attempt['attempt_id'],
            $this->fact($attempt, ['transaction_ref' => 'real:'.$key]));
    }

    private function reserve(User $user, Order $order, string $amount, string $key): void
    {
        app(NativeBillingTransaction::class)->run($user->id,
            function (array $installation) use ($user, $order, $amount, $key): void {
                app(NativeSubscriptionStore::class)->lockAccount($user->id, time(), $installation['installation_id']);
                $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
                $terms = OrderPurchaseTerms::query()->where('order_id', $order->id)->lockForUpdate()->firstOrFail();
                app(NativeWalletReservations::class)->reserveLocked($installation, $user->id, $locked, $terms, $amount, $key);
            });
    }

    private function admin(): User
    {
        [, $user] = app(NativeIdentityService::class)->create('credit-admin-'.bin2hex(random_bytes(8)).'@example.test', 'runtime-password');
        DB::table('tx_user')->where('id', $user->id)->update(['is_admin' => true]);
        return $user;
    }

    private function walletAvailable(User $user): string
    {
        return (string) (DB::table('tx_money_account')->where('owner_id', $user->id)
            ->where('purpose', 'wallet_available')->where('currency', 'CNY')->value('balance_minor') ?? '0');
    }

    private function acceptUsage(string $up, string $down = '0'): array
    {
        $snapshot = $this->snapshot();
        $this->assertCount(1, $snapshot['usage_v2']['grants']);
        $grant = $snapshot['usage_v2']['grants'][0];
        $entry = array_intersect_key($grant, array_flip([
            'access_subject_id', 'auth_snapshot_id', 'tariff_revision_id', 'terms_id', 'usage_period_id',
        ])) + ['raw_up_bytes' => $up, 'raw_down_bytes' => $down];
        $payload = [
            'protocol_version' => 2, 'node_id' => (string) $this->node->id,
            'source_epoch' => $snapshot['usage_v2']['source_epoch'], 'batch_id' => 'upgrade-credit-usage',
            'started_at' => $snapshot['issued_at'], 'ended_at' => $snapshot['issued_at'] + 1, 'entries' => [$entry],
        ];
        $parsed = app(UsagePayload::class)->submission(json_encode([
            'payload' => $payload, 'payload_digest' => 'sha256:'.hash('sha256', ProviderCodec::canonical($payload)),
        ], JSON_THROW_ON_ERROR));
        $receipt = app(UsageReceipts::class)->submit($this->node->id, $parsed, $snapshot['issued_at'] + 2);
        $this->assertSame('pending', $receipt['settlement_status']);
        return $snapshot;
    }

    private function awaitUsageInterval(array $snapshot): void
    {
        $deadline = microtime(true) + 3;
        while (time() < $snapshot['issued_at'] + 1 && microtime(true) < $deadline) { usleep(20000); }
        $this->assertGreaterThanOrEqual($snapshot['issued_at'] + 1, time());
    }

    private function snapshots(array $tables): array
    {
        $snapshots = [];
        foreach ($tables as $table) {
            $query = DB::table($table);
            if ($table === 'tx_subscription_slot') { $query->orderBy('user_id')->orderBy('slot_no'); }
            else { $query->orderBy('id'); }
            $snapshots[$table] = $query->get()->toJson();
        }
        return $snapshots;
    }
}
