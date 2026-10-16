<?php

namespace App\Domains\Billing;

use App\Domains\Subscription\Services\NativeSubscriptionStore;
use App\Models\Order;
use App\Models\OrderPurchaseTerms;
use Illuminate\Support\Facades\DB;

/** Trusted verified channel facts enter the one atomic money and purchase decision. */
final class NativePaymentSettlement
{
    public function __construct(
        private readonly NativeBillingTransaction $transactions,
        private readonly NativeSubscriptionStore $subscriptions,
        private readonly PurchasedOrderGrant $grants,
        private readonly NativePaymentAttempts $attempts,
        private readonly NativeMoneyLedger $ledger,
    ) {}

    public function settle(int $userId, int $attemptId, array $verifiedFact): array
    {
        $this->require($attemptId > 0, 'PAYMENT_IDENTITY_INVALID');
        $locator = DB::table('tx_payment_attempt')->useWritePdo()->where('id', $attemptId)->first();
        $this->require($locator !== null && (string) $locator->user_id === (string) $userId, 'PAYMENT_ATTEMPT_MISSING');
        return $this->transactions->run($userId, function (array $installation, object $user) use ($userId, $attemptId, $locator, $verifiedFact): array {
            if ($locator->intent_type === 'recharge') {
                return app(NativeRechargeService::class)->settleVerifiedLocked($installation, $userId, $attemptId, $verifiedFact);
            }
            $this->require($locator->intent_type === 'purchase', 'PAYMENT_ATTEMPT_EVIDENCE_INVALID');
            [, , $subscriptions] = $this->subscriptions->lockAccount($userId, time(), $installation['installation_id']);
            foreach ($subscriptions as $subscription) { $this->subscriptions->lockHistory($subscription); }
            $order = Order::query()->whereKey($locator->order_id)->lockForUpdate()->first();
            $this->require($order !== null && (string) $order->user_id === (string) $userId
                && $order->purchase_terms_version === 'tx-core.purchase-terms/v2', 'PAYMENT_ORDER_OWNERSHIP_INVALID');
            $terms = OrderPurchaseTerms::query()->where('order_id', $order->id)->lockForUpdate()->first();
            $purchase = $this->grants->resolve($order, $terms);
            $this->require($purchase !== null && $terms !== null, 'PAYMENT_PURCHASE_REQUIRED');
            $this->require((int) ($order->handling_amount ?? 0) === 0, 'PAYMENT_SETTLEMENT_FEES_UNSUPPORTED');
            $amount = MoneyValue::minor(app(NativeWalletReservations::class)
                ->channelAmountLocked($installation, $userId, $order, $terms), true);
            $currency = MoneyValue::currency($purchase['currency']);
            $reservation = DB::table('tx_wallet_order_reservation')->where('order_id', $order->id)->first();
            // These immutable reads select the journal inputs before money locks; the participant
            // revalidates all attempt/receipt bindings under their locks before anything commits.
            $prior = DB::table('tx_payment_settlement')->where('attempt_id', $attemptId)->first();
            $previousReceipt = DB::table('tx_payment_receipt')->where('attempt_id', $attemptId)->first();
            $allocation = DB::table('tx_order_payment')->where('order_id', $order->id)->first();
            $decision = $prior?->decision ?? (
                (int) $order->status === Order::STATUS_PENDING && !(bool) ($user->banned ?? false)
                && $allocation === null && ($reservation === null || $reservation->status !== 'released')
                    ? 'order_payment' : 'refund_liability'
            );
            $this->require(in_array($decision, ['order_payment', 'refund_liability'], true), 'PAYMENT_SETTLEMENT_EVIDENCE_INVALID');
            $fact = $verifiedFact;
            if (array_key_exists('occurred_at', $fact) && $fact['occurred_at'] === null) {
                $fact['occurred_at'] = $previousReceipt === null ? (string) time() : (string) $previousReceipt->occurred_at;
            }
            $identityHash = MoneyValue::hash([
                'provider' => MoneyValue::key($fact['provider'] ?? null, 32),
                'provider_account_ref' => MoneyValue::key($fact['provider_account_ref'] ?? null),
                'transaction_ref' => MoneyValue::key($fact['transaction_ref'] ?? null),
            ]);
            $occurredAt = MoneyValue::minor($fact['occurred_at'] ?? null, true);
            if ($reservation !== null) {
                $this->ledger->lockAccountsLocked($installation, $userId, $currency, [
                    ['owner_id' => 0, 'purpose' => 'provider_clearing'],
                    ['owner_id' => 0, 'purpose' => 'sales_revenue'],
                    ['owner_id' => 0, 'purpose' => 'refund_liability'],
                    ['owner_id' => $userId, 'purpose' => 'wallet_available'],
                    ['owner_id' => $userId, 'purpose' => 'wallet_reserved'],
                ]);
                if ($decision === 'order_payment') {
                    app(NativeWalletReservations::class)->captureLocked($installation, $userId, $order, $terms);
                } elseif ($reservation->status === 'reserved') {
                    app(NativeWalletReservations::class)->releaseLocked($installation, $userId, $order, $terms);
                }
            }
            $journal = $this->ledger->postLocked($installation, $userId, [
                'business_type' => 'provider_capture', 'business_ref' => (string) $attemptId,
                'idempotency_key' => $identityHash, 'currency' => $currency, 'occurred_at' => $occurredAt,
                'postings' => [
                    ['owner_id' => 0, 'purpose' => 'provider_clearing', 'side' => 'debit', 'amount_minor' => $amount],
                    ['owner_id' => 0, 'purpose' => $decision === 'order_payment' ? 'sales_revenue' : 'refund_liability',
                        'side' => 'credit', 'amount_minor' => $amount],
                ],
            ]);
            $receipt = $this->attempts->recordVerifiedReceiptLocked($installation, $userId, $attemptId, $fact);
            $this->require($receipt['order_id'] === (string) $order->id && $receipt['amount_minor'] === $amount
                && $receipt['currency'] === $currency && $receipt['identity_hash'] === $identityHash,
                'PAYMENT_SETTLEMENT_BINDING_INVALID');
            $payload = [
                'installation_id' => $installation['installation_id'], 'user_id' => (string) $userId,
                'order_id' => (string) $order->id, 'payment_id' => $receipt['payment_id'],
                'attempt_id' => (string) $attemptId, 'receipt_id' => $receipt['receipt_id'],
                'journal_id' => $journal['journal_id'], 'decision' => $decision, 'amount_minor' => $amount,
                'currency' => $currency, 'occurred_at' => $receipt['occurred_at'],
            ];
            $existing = DB::table('tx_payment_settlement')->where('attempt_id', $attemptId)->lockForUpdate()->first();
            if ($existing === null) {
                $id = DB::table('tx_payment_settlement')->insertGetId($payload + [
                    'content_hash' => MoneyValue::hash($payload), 'created_at' => time(),
                ]);
                $existing = DB::table('tx_payment_settlement')->where('id', $id)->first();
                if ($decision === 'order_payment') {
                    $this->allocate($existing, $payload);
                    $order->status = Order::STATUS_PROCESSING;
                    $order->payment_id = (int) $receipt['payment_id'];
                    $order->callback_no = $receipt['transaction_ref'];
                    $order->paid_at = (int) $receipt['occurred_at'];
                    $order->saveOrFail();
                }
            }
            $this->require($this->payload($existing) === $payload
                && hash_equals(MoneyValue::hash($payload), (string) $existing->content_hash),
                'PAYMENT_SETTLEMENT_EVIDENCE_INVALID');
            return $this->result($existing, $payload);
        });
    }

    /** Caller holds installation, account, subscription history, order and frozen purchase locks. */
    public function verifyWinningPaymentLocked(array $installation, int $userId, Order $order, OrderPurchaseTerms $terms): array
    {
        $this->require(DB::transactionLevel() > 0 && (string) $order->user_id === (string) $userId
            && (string) $terms->order_id === (string) $order->id, 'PAYMENT_SETTLEMENT_BINDING_INVALID');
        app(NativeRefundService::class)->assertOrderNotFullyRefundedLocked($userId, (int) $order->id);
        $purchase = $this->grants->resolve($order, $terms);
        $this->require($purchase !== null, 'PAYMENT_PURCHASE_REQUIRED');
        $allocation = DB::table('tx_order_payment')->where('order_id', $order->id)->first();
        $this->require($allocation !== null, 'PAYMENT_ORDER_ALLOCATION_REQUIRED');
        if ($allocation->settlement_id === null) {
            return app(NativeWalletPayments::class)->verifyWinningPaymentLocked($installation, $userId, $order, $terms);
        }
        $settlement = DB::table('tx_payment_settlement')->where('id', $allocation->settlement_id)->first();
        $this->require($settlement !== null, 'PAYMENT_SETTLEMENT_EVIDENCE_INVALID');
        $payload = $this->payload($settlement);
        $this->require($payload['decision'] === 'order_payment'
            && $payload['installation_id'] === $installation['installation_id']
            && $payload['user_id'] === (string) $userId && $payload['order_id'] === (string) $order->id
            && $payload['amount_minor'] === MoneyValue::minor(app(NativeWalletReservations::class)
                ->channelAmountLocked($installation, $userId, $order, $terms), true)
            && $payload['currency'] === MoneyValue::currency($purchase['currency'])
            && (int) ($order->handling_amount ?? 0) === 0
            && hash_equals(MoneyValue::hash($payload), (string) $settlement->content_hash),
            'PAYMENT_SETTLEMENT_EVIDENCE_INVALID');
        $reservation = DB::table('tx_wallet_order_reservation')->where('order_id', $order->id)->first();
        $this->require($reservation === null || $reservation->status === 'captured', 'PAYMENT_SETTLEMENT_EVIDENCE_INVALID');
        $receipt = DB::table('tx_payment_receipt')->where('id', $payload['receipt_id'])->first();
        $this->require($receipt !== null && DB::table('tx_money_journal')->where('id', $payload['journal_id'])->exists(),
            'PAYMENT_SETTLEMENT_EVIDENCE_INVALID');
        $journal = $this->ledger->postLocked($installation, $userId, [
            'business_type' => 'provider_capture', 'business_ref' => $payload['attempt_id'],
            'idempotency_key' => (string) $receipt->identity_hash, 'currency' => $payload['currency'],
            'occurred_at' => $payload['occurred_at'],
            'postings' => [
                ['owner_id' => 0, 'purpose' => 'provider_clearing', 'side' => 'debit', 'amount_minor' => $payload['amount_minor']],
                ['owner_id' => 0, 'purpose' => 'sales_revenue', 'side' => 'credit', 'amount_minor' => $payload['amount_minor']],
            ],
        ]);
        $this->require($journal['journal_id'] === $payload['journal_id'], 'PAYMENT_SETTLEMENT_EVIDENCE_INVALID');
        $verified = $this->attempts->recordVerifiedReceiptLocked($installation, $userId, (int) $payload['attempt_id'], [
            'attempt_id' => $payload['attempt_id'], 'provider' => (string) $receipt->provider,
            'provider_account_ref' => (string) $receipt->provider_account_ref, 'transaction_ref' => (string) $receipt->transaction_ref,
            'amount_minor' => $payload['amount_minor'], 'currency' => $payload['currency'], 'occurred_at' => $payload['occurred_at'],
        ]);
        $this->require($verified['receipt_id'] === $payload['receipt_id'] && $verified['payment_id'] === $payload['payment_id']
            && (string) $order->payment_id === $payload['payment_id']
            && (string) $order->callback_no === $verified['transaction_ref']
            && (string) $order->paid_at === $payload['occurred_at'], 'PAYMENT_SETTLEMENT_EVIDENCE_INVALID');
        $locked = DB::table('tx_payment_settlement')->where('id', $settlement->id)->lockForUpdate()->first();
        $this->require($locked !== null && $this->payload($locked) === $payload
            && hash_equals(MoneyValue::hash($payload), (string) $locked->content_hash), 'PAYMENT_SETTLEMENT_EVIDENCE_INVALID');
        return $this->result($locked, $payload);
    }

    private function allocate(object $settlement, array $payload): void
    {
        $allocation = $this->allocationPayload($settlement, $payload);
        DB::table('tx_order_payment')->insert($allocation + [
            'content_hash' => MoneyValue::hash($allocation), 'created_at' => time(),
        ]);
        $work = $this->workPayload($settlement, $payload);
        DB::table('tx_billing_work')->insert($work + [
            'content_hash' => MoneyValue::hash($work), 'status' => 'pending', 'attempts' => 0,
            'error_code' => null, 'created_at' => time(), 'completed_at' => null,
        ]);
    }

    private function result(object $settlement, array $payload): array
    {
        $allocation = DB::table('tx_order_payment')->where('settlement_id', $settlement->id)->lockForUpdate()->first();
        $work = DB::table('tx_billing_work')->where('settlement_id', $settlement->id)->lockForUpdate()->first();
        if ($payload['decision'] === 'order_payment') {
            $this->require($allocation !== null && $work !== null, 'PAYMENT_SETTLEMENT_EVIDENCE_INVALID');
            $this->verifyFields($allocation, $this->allocationPayload($settlement, $payload));
            $this->verifyFields($work, $this->workPayload($settlement, $payload));
            $this->require(in_array($work->status, ['pending', 'completed', 'cancelled'], true)
                && ($work->status !== 'pending') === ($work->completed_at !== null), 'PAYMENT_SETTLEMENT_EVIDENCE_INVALID');
        } else {
            $this->require($allocation === null && $work === null, 'PAYMENT_SETTLEMENT_EVIDENCE_INVALID');
        }
        return ['settlement_id' => (string) $settlement->id] + $payload + [
            'content_hash' => (string) $settlement->content_hash,
            'order_payment_id' => $allocation === null ? null : (string) $allocation->id,
            'work_id' => $work === null ? null : (string) $work->id,
        ];
    }

    private function allocationPayload(object $settlement, array $payload): array
    {
        $allocation = [
            'installation_id' => $payload['installation_id'], 'user_id' => $payload['user_id'],
            'order_id' => $payload['order_id'], 'settlement_id' => (string) $settlement->id,
            'receipt_id' => $payload['receipt_id'],
            'amount_minor' => MoneyValue::minor(DB::table('tx_order')->where('id', $payload['order_id'])->value('total_amount'), true),
            'currency' => $payload['currency'],
        ];
        $reservation = DB::table('tx_wallet_order_reservation')->where('order_id', $payload['order_id'])->first();
        if ($reservation !== null) { $allocation['wallet_reservation_id'] = (string) $reservation->id; }
        return $allocation;
    }

    private function workPayload(object $settlement, array $payload): array
    {
        $identity = ['kind' => 'order.fulfill', 'installation_id' => $payload['installation_id'],
            'order_id' => $payload['order_id'], 'receipt_id' => $payload['receipt_id']];
        return ['event_key' => MoneyValue::hash($identity)] + $identity + [
            'user_id' => $payload['user_id'], 'settlement_id' => (string) $settlement->id,
        ];
    }

    private function payload(object $row): array
    {
        $payload = [];
        foreach (['installation_id', 'user_id', 'order_id', 'payment_id', 'attempt_id', 'receipt_id',
            'journal_id', 'decision', 'amount_minor', 'currency', 'occurred_at'] as $field) {
            $payload[$field] = (string) $row->$field;
        }
        return $payload;
    }

    private function verifyFields(object $row, array $payload): void
    {
        foreach ($payload as $field => $value) {
            $this->require((string) $row->$field === $value, 'PAYMENT_SETTLEMENT_EVIDENCE_INVALID');
        }
        $this->require(hash_equals(MoneyValue::hash($payload), (string) $row->content_hash), 'PAYMENT_SETTLEMENT_EVIDENCE_INVALID');
    }

    private function require(bool $condition, string $code): void
    {
        if (!$condition) { throw new BillingError($code); }
    }
}
