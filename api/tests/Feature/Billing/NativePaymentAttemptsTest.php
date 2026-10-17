<?php

namespace Tests\Feature\Billing;

use App\Domains\Billing\NativePaymentAttempts;
use App\Domains\Subscription\Services\PublishedOrderService;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\NativePaymentTestCase;

final class NativePaymentAttemptsTest extends NativePaymentTestCase
{
    public function test_attempt_replay_retains_frozen_quote_when_order_or_payment_configuration_changes(): void
    {
        [$user, $order] = $this->purchase();
        $service = app(NativePaymentAttempts::class);
        $attempt = $service->prepare($user->id, $order->id, $this->payment->id, 'merchant-1', 'purchase-1');
        $this->assertSame('1234', $attempt['amount_minor']);
        $this->assertSame('USD', $attempt['currency']);
        $this->assertSame('prepared', $attempt['status']);
        [, $otherOrder] = $this->purchase($user);
        $this->rejects('PAYMENT_ATTEMPT_IDEMPOTENCY_CONFLICT',
            fn () => $service->prepare($user->id, $otherOrder->id, $this->payment->id, 'merchant-1', 'purchase-1'));
        $this->payment->update(['payment' => 'changed-provider', 'enable' => false]);
        app(PublishedOrderService::class)->transitionOrder($order->id,
            static fn (Order $locked) => $locked->update(['status' => Order::STATUS_CANCELLED]));
        $this->assertSame($attempt, $service->prepare($user->id, $order->id, $this->payment->id, 'merchant-1', 'purchase-1'));
        $this->rejects('PAYMENT_ATTEMPT_IDEMPOTENCY_CONFLICT',
            fn () => $service->prepare($user->id, $order->id, $this->payment->id, 'merchant-2', 'purchase-1'));
        $this->assertSame(1, DB::table('tx_payment_attempt')->count());
        $this->assertSame(0, DB::table('tx_payment_receipt')->count());
    }

    public function test_unknown_failed_and_canceled_order_still_accept_a_real_late_success_without_fulfillment(): void
    {
        [$user, $order] = $this->purchase();
        $service = app(NativePaymentAttempts::class);
        $attempt = $service->prepare($user->id, $order->id, $this->payment->id, 'merchant-1', 'late-success');
        $id = (int) $attempt['attempt_id'];
        $this->assertSame('unknown', $service->markUnknown($user->id, $id)['status']);
        $this->assertSame('pending', $service->markPending($user->id, $id)['status']);
        $this->assertSame('failed', $service->markFailed($user->id, $id)['status']);
        app(PublishedOrderService::class)->transitionOrder($order->id,
            static fn (Order $locked) => $locked->update(['status' => Order::STATUS_CANCELLED]));
        $fact = $this->fact($attempt);
        $receipt = $service->recordVerifiedReceipt($user->id, $id, $fact);
        $this->assertSame($receipt, $service->recordVerifiedReceipt($user->id, $id, $fact));
        $this->assertSame('succeeded', DB::table('tx_payment_attempt')->where('id', $id)->value('status'));
        $this->rejects('PAYMENT_ATTEMPT_TRANSITION_INVALID', fn () => $service->markUnknown($user->id, $id));
        $this->rejects('PAYMENT_RECEIPT_IDENTITY_CONFLICT', fn () => $service->recordVerifiedReceipt(
            $user->id, $id, $this->fact($attempt, ['occurred_at' => '1792108801'])));
        $this->rejects('PAYMENT_RECEIPT_IDENTITY_CONFLICT', fn () => $service->recordVerifiedReceipt(
            $user->id, $id, $this->fact($attempt, ['transaction_ref' => 'different-transaction'])));
        $this->assertSame(Order::STATUS_CANCELLED, (int) $order->fresh()->status);
        $this->assertNull($order->fresh()->paid_at);
        $this->assertSame(0, (int) $user->fresh()->balance);
        $this->assertSame(0, DB::table('tx_subscription')->count());
        $this->assertSame(0, DB::table('tx_money_journal')->count());
        $this->assertSame(1, DB::table('tx_payment_receipt')->count());
    }

    public function test_receipt_amount_currency_provider_and_request_binding_are_exact_and_atomic(): void
    {
        [$user, $order] = $this->purchase();
        $service = app(NativePaymentAttempts::class);
        $attempt = $service->prepare($user->id, $order->id, $this->payment->id, 'merchant-1', 'bindings');
        $id = (int) $attempt['attempt_id'];
        foreach ([
            ['amount_minor' => '1235'], ['currency' => 'CNY'], ['provider_account_ref' => 'merchant-2'],
            ['provider' => 'different-provider'],
        ] as $override) {
            $this->rejects('PAYMENT_RECEIPT_BINDING_CONFLICT',
                fn () => $service->recordVerifiedReceipt($user->id, $id, $this->fact($attempt, $override)));
        }
        $this->rejects('PAYMENT_RECEIPT_FACT_INVALID',
            fn () => $service->recordVerifiedReceipt($user->id, $id, $this->fact($attempt, ['attempt_id' => '99999'])));
        $this->rejects('PAYMENT_RECEIPT_FACT_INVALID',
            fn () => $service->recordVerifiedReceipt($user->id, $id, $this->fact($attempt, ['verified' => true])));
        $this->assertSame('prepared', DB::table('tx_payment_attempt')->where('id', $id)->value('status'));
        $this->assertSame(0, DB::table('tx_payment_receipt')->count());
    }

    public function test_receipt_identity_cannot_be_reassigned_via_another_order_owner_or_duplicate_payment_config(): void
    {
        [$firstUser, $firstOrder] = $this->purchase();
        [$otherUser, $otherOrder] = $this->purchase();
        $duplicate = Payment::query()->create([
            'uuid' => bin2hex(random_bytes(16)), 'payment' => 'native-test', 'name' => 'Duplicate account registration',
            'config' => [], 'enable' => true,
        ]);
        $service = app(NativePaymentAttempts::class);
        $first = $service->prepare($firstUser->id, $firstOrder->id, $this->payment->id, 'merchant-1', 'first');
        $other = $service->prepare($otherUser->id, $otherOrder->id, $duplicate->id, 'merchant-1', 'other');
        $service->recordVerifiedReceipt($firstUser->id, (int) $first['attempt_id'], $this->fact($first));
        $this->rejects('PAYMENT_RECEIPT_IDENTITY_CONFLICT',
            fn () => $service->recordVerifiedReceipt($otherUser->id, (int) $other['attempt_id'], $this->fact($other)));
        $this->rejects('PAYMENT_ATTEMPT_MISSING',
            fn () => $service->markFailed($otherUser->id, (int) $first['attempt_id']));
        $this->assertSame('prepared', DB::table('tx_payment_attempt')->where('id', $other['attempt_id'])->value('status'));
        // External identities are byte-exact on SQLite and MySQL despite default SQL collations.
        $distinct = $service->recordVerifiedReceipt($otherUser->id, (int) $other['attempt_id'],
            $this->fact($other, ['transaction_ref' => 'Settled-transaction-1']));
        $this->assertSame('Settled-transaction-1', $distinct['transaction_ref']);
        $this->assertSame(2, DB::table('tx_payment_receipt')->count());
    }

    public function test_free_purchase_ownership_and_outer_transactions_cannot_create_attempts(): void
    {
        [$user, $free] = $this->purchase(period: 'onetime');
        [$other] = $this->purchase();
        $service = app(NativePaymentAttempts::class);
        $this->rejects('BILLING_AMOUNT_INVALID',
            fn () => $service->prepare($user->id, $free->id, $this->payment->id, 'merchant-1', 'free'));
        $this->rejects('PAYMENT_ORDER_OWNERSHIP_INVALID',
            fn () => $service->prepare($other->id, $free->id, $this->payment->id, 'merchant-1', 'owner'));
        DB::transaction(function () use ($user, $free, $service): void {
            $this->rejects('BILLING_OUTER_TRANSACTION_UNSUPPORTED',
                fn () => $service->prepare($user->id, $free->id, $this->payment->id, 'merchant-1', 'nested'));
        });
        $this->assertSame(0, DB::table('tx_payment_attempt')->count());
    }

    public function test_mutated_persistent_evidence_and_cross_owner_sql_are_rejected_and_rollback_is_refused(): void
    {
        [$user, $order] = $this->purchase();
        [$other] = $this->purchase();
        $service = app(NativePaymentAttempts::class);
        $attempt = $service->prepare($user->id, $order->id, $this->payment->id, 'merchant-1', 'evidence');
        $id = (int) $attempt['attempt_id'];
        try {
            DB::transaction(fn () => DB::table('tx_payment_attempt')->where('id', $id)->update(['user_id' => $other->id]));
            $this->fail('Cross-owner SQL must fail the composite ownership foreign key.');
        } catch (QueryException) {
            $this->assertSame($user->id, (int) DB::table('tx_payment_attempt')->where('id', $id)->value('user_id'));
        }
        DB::table('tx_payment_attempt')->where('id', $id)->update(['request_hash' => str_repeat('0', 64)]);
        $this->rejects('PAYMENT_ATTEMPT_EVIDENCE_INVALID', fn () => $service->markPending($user->id, $id));
        $this->assertSame('prepared', DB::table('tx_payment_attempt')->where('id', $id)->value('status'));
        $migration = require database_path('migrations/2026_10_16_000005_create_native_payment_attempts.php');
        try {
            $migration->down();
            $this->fail('Populated payment evidence must survive rollback.');
        } catch (\RuntimeException $error) {
            $this->assertSame('NATIVE_PAYMENT_POPULATED_ROLLBACK_REFUSED', $error->getMessage());
        }
        $this->assertSame(1, DB::table('tx_payment_attempt')->count());
    }
}
