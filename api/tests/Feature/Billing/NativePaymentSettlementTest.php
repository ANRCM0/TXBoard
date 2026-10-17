<?php

namespace Tests\Feature\Billing;

use App\Domains\Billing\NativeBillingTransaction;
use App\Domains\Billing\NativePaymentAttempts;
use App\Domains\Billing\NativePaymentSettlement;
use App\Domains\Subscription\Services\NativeSubscriptionStore;
use App\Domains\Subscription\Services\PublishedOrderService;
use App\Models\Order;
use App\Models\OrderPurchaseTerms;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\NativePaymentTestCase;

final class NativePaymentSettlementTest extends NativePaymentTestCase
{
    public function test_verified_payment_atomically_commits_money_allocation_and_durable_work_with_permanent_replay(): void
    {
        [$user, $order, $attempt] = $this->prepared('winner');
        $service = app(NativePaymentSettlement::class);
        $fact = $this->fact($attempt, ['occurred_at' => null]);
        $before = time();
        $settlement = $service->settle($user->id, (int) $attempt['attempt_id'], $fact);
        $this->assertSame('order_payment', $settlement['decision']);
        $this->assertGreaterThanOrEqual($before, (int) $settlement['occurred_at']);
        $this->assertSame($settlement, $service->settle($user->id, (int) $attempt['attempt_id'], $fact));
        $this->assertSame(Order::STATUS_PROCESSING, (int) $order->fresh()->status);
        $this->assertSame($this->payment->id, (int) $order->fresh()->payment_id);
        $this->assertSame($settlement['occurred_at'], (string) $order->fresh()->paid_at);
        $this->assertSame(1, DB::table('tx_payment_receipt')->count());
        $this->assertSame(1, DB::table('tx_payment_settlement')->count());
        $this->assertSame(1, DB::table('tx_money_journal')->count());
        $this->assertSame(2, DB::table('tx_money_posting')->count());
        $this->assertSame(1, DB::table('tx_order_payment')->count());
        $this->assertSame(1, DB::table('tx_billing_work')->where('kind', 'order.fulfill')->where('status', 'pending')->count());
        $this->assertSame('1234', $this->balance('provider_clearing'));
        $this->assertSame('1234', $this->balance('sales_revenue'));
        $this->assertSame(0, DB::table('tx_subscription')->count(), 'The SQL receipt commit does not depend on queue or Provider I/O.');
        $this->assertSame($settlement, $this->verifyWinner($user->id, $order->id));
        app(PublishedOrderService::class)->transitionOrder($order->id,
            static fn (Order $locked) => $locked->update(['status' => Order::STATUS_COMPLETED]));
        $this->assertSame($settlement, $service->settle($user->id, (int) $attempt['attempt_id'], $fact));
    }

    public function test_additional_successes_are_real_refund_liabilities_and_never_win_a_second_allocation(): void
    {
        [$user, $order, $first] = $this->prepared('first');
        $second = app(NativePaymentAttempts::class)->prepare($user->id, $order->id, $this->payment->id, 'merchant-1', 'second');
        $service = app(NativePaymentSettlement::class);
        $winner = $service->settle($user->id, (int) $first['attempt_id'], $this->fact($first));
        $fact = $this->fact($second, ['transaction_ref' => 'second-real-success']);
        $extra = $service->settle($user->id, (int) $second['attempt_id'], $fact);
        $this->assertSame('order_payment', $winner['decision']);
        $this->assertSame('refund_liability', $extra['decision']);
        $this->assertNull($extra['order_payment_id']);
        $this->assertNull($extra['work_id']);
        $this->assertSame($extra, $service->settle($user->id, (int) $second['attempt_id'], $fact));
        $this->assertSame(2, DB::table('tx_payment_receipt')->count());
        $this->assertSame(2, DB::table('tx_money_journal')->count());
        $this->assertSame(1, DB::table('tx_order_payment')->count());
        $this->assertSame(1, DB::table('tx_billing_work')->count());
        $this->assertSame('2468', $this->balance('provider_clearing'));
        $this->assertSame('1234', $this->balance('sales_revenue'));
        $this->assertSame('1234', $this->balance('refund_liability'));
        $this->assertSame('settled-transaction-1', $order->fresh()->callback_no);
    }

    public function test_late_success_after_failure_and_cancel_or_ban_retains_money_without_fulfillment(): void
    {
        [$user, $order, $attempt] = $this->prepared('late');
        app(NativePaymentAttempts::class)->markFailed($user->id, (int) $attempt['attempt_id']);
        app(PublishedOrderService::class)->transitionOrder($order->id,
            static fn (Order $locked) => $locked->update(['status' => Order::STATUS_CANCELLED]));
        $settlement = app(NativePaymentSettlement::class)->settle($user->id, (int) $attempt['attempt_id'], $this->fact($attempt));
        $this->assertSame('refund_liability', $settlement['decision']);
        $this->assertSame(Order::STATUS_CANCELLED, (int) $order->fresh()->status);
        $this->assertNull($order->fresh()->paid_at);
        [$banned, $pending, $bannedAttempt] = $this->prepared('banned');
        DB::table('tx_user')->where('id', $banned->id)->update(['banned' => 1]);
        $bannedResult = app(NativePaymentSettlement::class)->settle($banned->id, (int) $bannedAttempt['attempt_id'],
            $this->fact($bannedAttempt, ['transaction_ref' => 'banned-real-success']));
        $this->assertSame('refund_liability', $bannedResult['decision']);
        $this->assertSame(Order::STATUS_PENDING, (int) $pending->fresh()->status);
        $this->assertSame(0, DB::table('tx_order_payment')->count());
        $this->assertSame(0, DB::table('tx_billing_work')->count());
        $this->assertSame('2468', $this->balance('refund_liability'));
    }

    public function test_failure_after_receipt_and_journal_rolls_back_all_financial_and_order_changes(): void
    {
        [$user, $order, $attempt] = $this->prepared('atomic');
        $inject = true;
        DB::connection()->beforeExecuting(function (string $sql) use (&$inject): void {
            if ($inject && (str_contains(strtolower($sql), 'insert into "tx_billing_work"')
                || str_contains(strtolower($sql), 'insert into `tx_billing_work`'))) {
                $inject = false;
                throw new \RuntimeException('INJECTED_BILLING_WORK_FAILURE');
            }
        });
        try {
            app(NativePaymentSettlement::class)->settle($user->id, (int) $attempt['attempt_id'], $this->fact($attempt));
            $this->fail('The injected final SQL write must fail.');
        } catch (\RuntimeException $error) {
            $this->assertSame('INJECTED_BILLING_WORK_FAILURE', $error->getMessage());
        }
        foreach (['tx_money_account', 'tx_money_journal', 'tx_money_posting', 'tx_payment_receipt',
            'tx_payment_settlement', 'tx_order_payment', 'tx_billing_work'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
        $this->assertSame('prepared', DB::table('tx_payment_attempt')->where('id', $attempt['attempt_id'])->value('status'));
        $this->assertSame(Order::STATUS_PENDING, (int) $order->fresh()->status);
        $retry = app(NativePaymentSettlement::class)->settle($user->id, (int) $attempt['attempt_id'], $this->fact($attempt));
        $this->assertSame('order_payment', $retry['decision']);
    }

    public function test_invalid_verified_binding_and_cross_owner_transaction_reuse_leave_no_partial_journals(): void
    {
        [$user, $order, $attempt] = $this->prepared('binding');
        $service = app(NativePaymentSettlement::class);
        foreach ([['amount_minor' => '1235'], ['currency' => 'CNY'], ['provider_account_ref' => 'other-account']] as $override) {
            $this->rejects('PAYMENT_RECEIPT_BINDING_CONFLICT',
                fn () => $service->settle($user->id, (int) $attempt['attempt_id'], $this->fact($attempt, $override)));
        }
        $this->assertSame(0, DB::table('tx_money_journal')->count());
        $this->assertSame(Order::STATUS_PENDING, (int) $order->fresh()->status);
        $service->settle($user->id, (int) $attempt['attempt_id'], $this->fact($attempt));
        [$other, $otherOrder, $otherAttempt] = $this->prepared('other-owner');
        $this->rejects('PAYMENT_ATTEMPT_MISSING',
            fn () => $service->settle($other->id, (int) $attempt['attempt_id'], $this->fact($attempt)));
        $this->rejects('PAYMENT_RECEIPT_IDENTITY_CONFLICT',
            fn () => $service->settle($other->id, (int) $otherAttempt['attempt_id'], $this->fact($otherAttempt)));
        $this->assertSame(1, DB::table('tx_money_journal')->count());
        $this->assertSame('1234', $this->balance('provider_clearing'));
        $this->assertSame(Order::STATUS_PENDING, (int) $otherOrder->fresh()->status);
    }

    public function test_fulfillment_requires_valid_money_and_work_evidence_and_financial_ownership_is_restrictive(): void
    {
        [$user, $order, $attempt] = $this->prepared('integrity');
        $this->rejects('PAYMENT_ORDER_ALLOCATION_REQUIRED', fn () => $this->verifyWinner($user->id, $order->id));
        $settlement = app(NativePaymentSettlement::class)->settle($user->id, (int) $attempt['attempt_id'], $this->fact($attempt));
        [$other] = $this->purchase();
        try {
            DB::transaction(fn () => DB::table('tx_billing_work')->where('id', $settlement['work_id'])->update(['user_id' => $other->id]));
            $this->fail('The work must retain its settlement owner.');
        } catch (QueryException) {
            $this->assertSame($user->id, (int) DB::table('tx_billing_work')->where('id', $settlement['work_id'])->value('user_id'));
        }
        DB::table('tx_money_posting')->where('journal_id', $settlement['journal_id'])->where('position', 1)->update(['amount_minor' => 1235]);
        $this->rejects('BILLING_JOURNAL_UNBALANCED', fn () => $this->verifyWinner($user->id, $order->id));
        $migration = require database_path('migrations/2026_10_16_000007_create_native_payment_settlement.php');
        try {
            $migration->down();
            $this->fail('Populated settlements cannot be rolled back.');
        } catch (\RuntimeException $error) {
            $this->assertSame('NATIVE_PAYMENT_SETTLEMENT_POPULATED_ROLLBACK_REFUSED', $error->getMessage());
        }
        $this->assertSame(1, DB::table('tx_payment_settlement')->count());
    }

    public function test_receipt_timestamp_is_exact_above_the_old_signed_integer_limit(): void
    {
        [$user, $order, $attempt] = $this->prepared('wide-time');
        $settlement = app(NativePaymentSettlement::class)->settle($user->id, (int) $attempt['attempt_id'],
            $this->fact($attempt, ['occurred_at' => '2147483648']));
        $this->assertSame('2147483648', $settlement['occurred_at']);
        $this->assertSame('2147483648', (string) $order->fresh()->paid_at);
        $this->assertSame($settlement, $this->verifyWinner($user->id, $order->id));
    }

    private function prepared(string $key): array
    {
        [$user, $order] = $this->purchase();
        $attempt = app(NativePaymentAttempts::class)->prepare($user->id, $order->id, $this->payment->id, 'merchant-1', $key);
        return [$user, $order, $attempt];
    }

    private function balance(string $purpose): string
    {
        return (string) DB::table('tx_money_account')->where('owner_id', 0)->where('purpose', $purpose)->value('balance_minor');
    }

    private function verifyWinner(int $userId, int $orderId): array
    {
        return app(NativeBillingTransaction::class)->run($userId, function (array $installation) use ($userId, $orderId): array {
            app(NativeSubscriptionStore::class)->lockAccount($userId, time(), $installation['installation_id']);
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
            $terms = OrderPurchaseTerms::query()->where('order_id', $orderId)->lockForUpdate()->firstOrFail();
            return app(NativePaymentSettlement::class)->verifyWinningPaymentLocked($installation, $userId, $order, $terms);
        });
    }
}
