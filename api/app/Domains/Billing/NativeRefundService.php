<?php

namespace App\Domains\Billing;

use App\Domains\Subscription\Services\NativeCredentialSynchronization;
use App\Domains\Subscription\Services\NativeSubscriptionStore;
use App\Models\Order;
use App\Models\OrderPurchaseTerms;
use Illuminate\Support\Facades\DB;

/** Authorized refunds explicitly arrive in the user's native wallet, never pretend to refund a channel. */
final class NativeRefundService
{
    public function __construct(
        private readonly NativeBillingTransaction $transactions,
        private readonly NativeMoneyLedger $ledger,
        private readonly NativeSubscriptionStore $subscriptions,
    ) {}

    public function refundToWallet(int $userId, string $sourceType, int $sourceId, string $amountMinor,
        string $requestKey, int $actorId, string $reason): array
    {
        $this->require(in_array($sourceType, ['receipt', 'wallet_payment'], true) && $sourceId > 0 && $actorId > 0,
            'REFUND_SOURCE_INVALID');
        $this->require(preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}\z/D', $reason) === 1, 'REFUND_REASON_INVALID');
        $request = ['user_id' => (string) $userId, 'source_type' => $sourceType, 'source_id' => (string) $sourceId,
            'amount_minor' => MoneyValue::minor($amountMinor, true), 'request_key' => MoneyValue::key($requestKey),
            'actor_id' => (string) $actorId, 'reason' => $reason, 'destination' => 'wallet'];
        return $this->transactions->run($userId, function (array $installation) use ($userId, $sourceType, $sourceId, $request, $actorId): array {
            $actor = DB::table('tx_user')->where('id', $actorId)->first();
            $this->require($actor !== null && (bool) $actor->is_admin && !(bool) ($actor->banned ?? false), 'REFUND_ACTOR_FORBIDDEN');
            [, $slot, $subscriptions] = $this->subscriptions->lockAccount($userId, time(), $installation['installation_id']);
            $histories = [];
            foreach ($subscriptions as $subscription) {
                $histories[(string) $subscription->id] = $this->subscriptions->lockHistory($subscription);
            }
            $locator = DB::table($sourceType === 'receipt' ? 'tx_payment_receipt' : 'tx_order_payment')->where('id', $sourceId)->first();
            $this->require($locator !== null && $locator->installation_id === $installation['installation_id']
                && (string) $locator->user_id === (string) $userId, 'REFUND_SOURCE_MISSING');
            $order = $locator->order_id === null ? null : Order::query()->whereKey($locator->order_id)->lockForUpdate()->first();
            $terms = $order === null ? null : OrderPurchaseTerms::query()->where('order_id', $order->id)->lockForUpdate()->first();
            $currency = MoneyValue::currency($locator->currency);
            $this->ledger->lockAccountsLocked($installation, $userId, $currency, [
                ['owner_id' => '0', 'purpose' => 'provider_clearing'],
                ['owner_id' => '0', 'purpose' => 'refund_liability'],
                ['owner_id' => '0', 'purpose' => 'sales_revenue'],
                ['owner_id' => (string) $userId, 'purpose' => 'wallet_available'],
                ['owner_id' => (string) $userId, 'purpose' => 'wallet_reserved'],
            ]);
            $source = $this->sourceLocked($installation, $userId, $sourceType, $sourceId, $order, $terms);
            $hash = MoneyValue::hash($request);
            $existing = DB::table('tx_native_refund')->where('installation_id', $installation['installation_id'])
                ->where('user_id', $userId)->where('key_hash', hash('sha256', $request['request_key']))->lockForUpdate()->first();
            if ($existing !== null) {
                $this->require(hash_equals($existing->request_hash, $hash), 'REFUND_IDEMPOTENCY_CONFLICT');
                $this->verifyRefund($existing, $source);
                return $this->result($existing);
            }
            $previous = DB::table('tx_native_refund')->where('installation_id', $installation['installation_id'])
                ->where('user_id', $userId)->where('source_type', $sourceType)->where('source_id', $sourceId)
                ->orderBy('id')->limit(1001)->lockForUpdate()->get();
            $this->require($previous->count() <= 1000, 'REFUND_HISTORY_LIMIT');
            $cumulative = '0';
            foreach ($previous as $refund) {
                $this->verifyRefund($refund, $source);
                $cumulative = MoneyValue::add($cumulative, (string) $refund->amount_minor);
                $this->require($cumulative === (string) $refund->cumulative_minor, 'REFUND_EVIDENCE_INVALID');
            }
            $cumulative = MoneyValue::add($cumulative, $request['amount_minor']);
            $this->require(bccomp($cumulative, $source['original_amount_minor']) <= 0, 'REFUND_AMOUNT_EXCEEDS_SOURCE');
            $entitlement = ['effect' => 'none', 'subscription_id' => null];
            if ($source['order_payment_id'] !== null) {
                $rows = DB::table('tx_native_refund')->where('order_payment_id', $source['order_payment_id'])
                    ->orderBy('id')->limit(2001)->lockForUpdate()->get();
                $this->require($rows->count() <= 2000, 'REFUND_HISTORY_LIMIT');
                $orderTotal = $request['amount_minor'];
                foreach ($rows as $row) {
                    $this->verifyRefundHash($row);
                    $orderTotal = MoneyValue::add($orderTotal, (string) $row->amount_minor);
                }
                $allocation = DB::table('tx_order_payment')->where('id', $source['order_payment_id'])->first();
                $this->require($allocation !== null && bccomp($orderTotal, (string) $allocation->amount_minor) <= 0,
                    'REFUND_AMOUNT_EXCEEDS_ORDER');
                $entitlement = $orderTotal === (string) $allocation->amount_minor
                    ? $this->fullEntitlementLocked($installation, $userId, $order, $slot, $subscriptions, $histories, $request['request_key'])
                    : ['effect' => 'partial_retained', 'subscription_id' => null];
            }
            $now = (string) time();
            $journal = $this->ledger->postLocked($installation, $userId,
                $this->refundJournal($source, $request['amount_minor'], $request['request_key'], $now));
            $payload = [
                'installation_id' => $installation['installation_id'], 'user_id' => (string) $userId,
                'actor_id' => $request['actor_id'], 'source_type' => $sourceType, 'source_id' => (string) $sourceId,
                'receipt_id' => $source['receipt_id'], 'order_payment_id' => $source['order_payment_id'],
                'order_id' => $source['order_id'], 'source_journal_id' => $source['source_journal_id'],
                'journal_id' => $journal['journal_id'], 'request_key' => $request['request_key'], 'request_hash' => $hash,
                'amount_minor' => $request['amount_minor'], 'cumulative_minor' => $cumulative, 'currency' => $source['currency'],
                'destination' => 'wallet', 'reason' => $request['reason'], 'entitlement_effect' => $entitlement['effect'],
                'subscription_id' => $entitlement['subscription_id'], 'created_at' => $now,
            ];
            $id = DB::table('tx_native_refund')->insertGetId($payload + [
                'key_hash' => hash('sha256', $request['request_key']), 'content_hash' => MoneyValue::hash($payload),
            ]);
            return $this->result(DB::table('tx_native_refund')->where('id', $id)->first());
        });
    }

    public function refundExternally(): never
    {
        throw new BillingError('REFUND_EXTERNAL_UNSUPPORTED');
    }

    public function assertOrderNotFullyRefundedLocked(int $userId, int $orderId): void
    {
        $this->require(DB::transactionLevel() > 0, 'BILLING_TRANSACTION_REQUIRED');
        $this->require(!DB::table('tx_native_refund')->where('user_id', $userId)->where('order_id', $orderId)
            ->whereIn('entitlement_effect', ['unfulfilled_cancelled', 'subscription_revoked', 'already_ended'])->exists(),
            'REFUND_ORDER_FULLY_REFUNDED');
    }

    private function sourceLocked(array $installation, int $userId, string $type, int $id, ?Order $order, ?OrderPurchaseTerms $terms): array
    {
        if ($type === 'wallet_payment') {
            $allocation = DB::table('tx_order_payment')->where('id', $id)->lockForUpdate()->first();
            $this->require($allocation !== null && $order !== null && $terms !== null, 'REFUND_SOURCE_MISSING');
            $reservation = DB::table('tx_wallet_order_reservation')->where('order_id', $order->id)->first();
            $this->require($reservation !== null, 'REFUND_WALLET_SOURCE_REQUIRED');
            $wallet = app(NativeWalletReservations::class)->verifyLocked($installation, $userId, $order, $terms, $reservation);
            $this->require($wallet['status'] === 'captured'
                && (string) $allocation->wallet_reservation_id === $wallet['reservation_id']
                && $allocation->currency === $wallet['currency'], 'REFUND_WALLET_SOURCE_REQUIRED');
            $this->verifyAllocation($allocation, $installation, $userId, $order);
            return ['source_type' => $type, 'source_id' => (string) $id, 'receipt_id' => null,
                'order_payment_id' => (string) $allocation->id, 'order_id' => (string) $order->id,
                'source_journal_id' => $wallet['capture_journal_id'], 'original_amount_minor' => $wallet['wallet_minor'],
                'currency' => $wallet['currency'], 'debit_purpose' => 'sales_revenue',
                'installation_id' => $installation['installation_id'], 'user_id' => (string) $userId];
        }
        $receipt = DB::table('tx_payment_receipt')->where('id', $id)->first();
        $this->require($receipt !== null && (string) $receipt->user_id === (string) $userId
            && $receipt->installation_id === $installation['installation_id'], 'REFUND_SOURCE_MISSING');
        $fact = [];
        foreach (['attempt_id', 'provider', 'provider_account_ref', 'transaction_ref', 'amount_minor', 'currency', 'occurred_at'] as $field) {
            $fact[$field] = (string) $receipt->$field;
        }
        $verified = app(NativePaymentAttempts::class)->recordVerifiedReceiptLocked($installation, $userId, (int) $receipt->attempt_id, $fact);
        $this->require($verified['receipt_id'] === (string) $id, 'REFUND_SOURCE_EVIDENCE_INVALID');
        $journal = DB::table('tx_money_journal')->where('installation_id', $installation['installation_id'])->where('user_id', $userId)
            ->where('business_type', 'provider_capture')->where('business_ref', (string) $receipt->attempt_id)
            ->where('idempotency_key', $receipt->identity_hash)->first();
        $this->require($journal !== null, 'REFUND_SOURCE_NOT_REFUNDABLE');
        $evidence = $this->ledger->inspectJournal((int) $journal->id);
        $settlement = DB::table('tx_payment_settlement')->where('receipt_id', $id)->first();
        $allocation = null;
        if ($settlement !== null) {
            $payload = [];
            foreach (['installation_id', 'user_id', 'order_id', 'payment_id', 'attempt_id', 'receipt_id',
                'journal_id', 'decision', 'amount_minor', 'currency', 'occurred_at'] as $field) {
                $payload[$field] = (string) $settlement->$field;
            }
            $this->require(hash_equals(MoneyValue::hash($payload), $settlement->content_hash)
                && $payload['installation_id'] === $installation['installation_id'] && $payload['user_id'] === (string) $userId
                && $payload['receipt_id'] === (string) $id && $payload['journal_id'] === (string) $journal->id
                && $payload['attempt_id'] === $verified['attempt_id'] && $payload['order_id'] === $verified['order_id']
                && $payload['payment_id'] === $verified['payment_id'] && $payload['occurred_at'] === $verified['occurred_at']
                && $payload['amount_minor'] === $verified['amount_minor'] && $payload['currency'] === $verified['currency'],
                'REFUND_SOURCE_EVIDENCE_INVALID');
            if ($settlement->decision === 'order_payment') {
                $allocation = DB::table('tx_order_payment')->where('settlement_id', $settlement->id)->first();
                $this->require($allocation !== null && $order !== null, 'REFUND_SOURCE_EVIDENCE_INVALID');
                $this->verifyAllocation($allocation, $installation, $userId, $order);
            } else {
                $this->require($settlement->decision === 'refund_liability', 'REFUND_SOURCE_EVIDENCE_INVALID');
            }
        }
        $purpose = $allocation === null ? 'refund_liability' : 'sales_revenue';
        $this->require($evidence['currency'] === $verified['currency'] && $evidence['occurred_at'] === $verified['occurred_at']
            && $evidence['postings'] === [
                ['owner_id' => '0', 'purpose' => 'provider_clearing', 'side' => 'debit', 'amount_minor' => $verified['amount_minor']],
                ['owner_id' => '0', 'purpose' => $purpose, 'side' => 'credit', 'amount_minor' => $verified['amount_minor']],
            ], 'REFUND_SOURCE_EVIDENCE_INVALID');
        return ['source_type' => $type, 'source_id' => (string) $id, 'receipt_id' => (string) $id,
            'order_payment_id' => $allocation === null ? null : (string) $allocation->id,
            'order_id' => $receipt->order_id === null ? null : (string) $receipt->order_id,
            'source_journal_id' => (string) $journal->id, 'original_amount_minor' => $verified['amount_minor'],
            'currency' => $verified['currency'], 'debit_purpose' => $purpose,
            'installation_id' => $installation['installation_id'], 'user_id' => (string) $userId];
    }

    private function verifyAllocation(object $allocation, array $installation, int $userId, Order $order): void
    {
        $payload = [];
        foreach (['installation_id', 'user_id', 'order_id', 'settlement_id', 'receipt_id', 'amount_minor', 'currency'] as $field) {
            $payload[$field] = $allocation->$field === null ? null : (string) $allocation->$field;
        }
        if ($allocation->wallet_reservation_id !== null) {
            $payload['wallet_reservation_id'] = (string) $allocation->wallet_reservation_id;
        }
        $this->require($payload['installation_id'] === $installation['installation_id'] && $payload['user_id'] === (string) $userId
            && $payload['order_id'] === (string) $order->id && $payload['amount_minor'] === (string) $order->total_amount
            && hash_equals(MoneyValue::hash($payload), $allocation->content_hash), 'REFUND_SOURCE_EVIDENCE_INVALID');
    }

    private function fullEntitlementLocked(array $installation, int $userId, ?Order $order, object $slot,
        $subscriptions, array $histories, string $key): array
    {
        $this->require($order !== null, 'REFUND_ORDER_MISSING');
        $now = time();
        $sourceTerms = DB::table('tx_subscription_terms')->where('source_order_id', $order->id)->orderBy('id')->limit(2)->get();
        if ($sourceTerms->isEmpty()) {
            $this->require((int) $order->status === Order::STATUS_PROCESSING, 'REFUND_FULFILLMENT_EVIDENCE_INVALID');
            $this->cancelPendingFulfillmentLocked($installation, $userId, $order, $now, true);
            $order->status = Order::STATUS_CANCELLED;
            $order->saveOrFail();
            return ['effect' => 'unfulfilled_cancelled', 'subscription_id' => null];
        }
        $this->require($sourceTerms->count() === 1, 'REFUND_FULFILLMENT_EVIDENCE_INVALID');
        $this->require((int) $order->type === Order::TYPE_NEW_PURCHASE, 'REFUND_ENTITLEMENT_REVERSAL_UNSUPPORTED');
        $term = $sourceTerms->first();
        $subscription = $subscriptions->firstWhere('id', $term->subscription_id);
        $this->require($subscription !== null, 'REFUND_FULFILLMENT_EVIDENCE_INVALID');
        $history = $histories[(string) $subscription->id];
        $current = $this->subscriptions->verifyHistory($subscription, $history);
        $this->require((string) $subscription->current_terms_id === (string) $term->id, 'REFUND_ENTITLEMENT_SUPERSEDED');
        if (!in_array($subscription->status, ['active', 'suspended'], true)) {
            $this->cancelPendingFulfillmentLocked($installation, $userId, $order, $now);
            return ['effect' => 'already_ended', 'subscription_id' => (string) $subscription->id];
        }
        $this->require((string) $slot->subscription_id === (string) $subscription->id, 'REFUND_FULFILLMENT_EVIDENCE_INVALID');
        $period = $current['period'];
        $this->require($period !== null && $now > (int) $period->starts_at && $now > (int) $subscription->starts_at,
            'REFUND_ENTITLEMENT_BOUNDARY_NOT_READY');
        $this->require($subscription->expires_at === null || $now < (int) $subscription->expires_at, 'REFUND_ENTITLEMENT_EXPIRED');
        $this->subscriptions->protectAcceptedUsage((string) $subscription->id, $now);
        $period = $this->subscriptions->closePeriod($period, $now, $now);
        $request = [
            'schema' => NativeSubscriptionStore::OPERATION, 'kind' => 'revoke', 'user_id' => (string) $userId,
            'subscription_id' => (string) $subscription->id, 'business_key' => 'refund:'.hash('sha256', $key),
            'expected_version' => (string) $subscription->row_version, 'effective_at' => $now, 'parameters' => [],
        ];
        $subscription = $this->subscriptions->updateSubscription($subscription, ['status' => 'revoked', 'ended_at' => $now], $now);
        $this->subscriptions->replaceSlot($slot, null, $now);
        $this->subscriptions->record($request, $this->subscriptions->result($subscription, $period, $now), $now);
        app(NativeCredentialSynchronization::class)->stageLocked($userId, (int) $subscription->id);
        $this->cancelPendingFulfillmentLocked($installation, $userId, $order, $now);
        return ['effect' => 'subscription_revoked', 'subscription_id' => (string) $subscription->id];
    }

    private function cancelPendingFulfillmentLocked(array $installation, int $userId, Order $order,
        int $now, bool $unfulfilled = false): void
    {
        $rows = DB::table('tx_billing_work')->where('order_id', $order->id)->orderBy('id')->limit(2)->lockForUpdate()->get();
        $this->require($rows->count() === 1, 'REFUND_FULFILLMENT_EVIDENCE_INVALID');
        $work = $rows->first();
        $this->require($work->installation_id === $installation['installation_id']
            && (string) $work->user_id === (string) $userId && $work->kind === 'order.fulfill'
            && in_array($work->status, ['pending', 'completed'], true)
            && ($work->status === 'pending') === ($work->completed_at === null)
            && (!$unfulfilled || $work->status === 'pending'), 'REFUND_FULFILLMENT_EVIDENCE_INVALID');
        // Direct fulfillment may commit before the worker acknowledges its durable item.
        // A full refund must stop that pending retry without rewriting completed history.
        if ($work->status === 'pending') {
            DB::table('tx_billing_work')->where('id', $work->id)->where('status', 'pending')->update([
                'status' => 'cancelled', 'completed_at' => $now, 'error_code' => null,
            ]);
        }
    }

    private function verifyRefund(object $row, array $source): void
    {
        $this->verifyRefundHash($row);
        foreach (['installation_id', 'user_id', 'source_type', 'source_id', 'receipt_id', 'order_payment_id', 'order_id', 'source_journal_id', 'currency'] as $field) {
            $actual = $row->$field === null ? null : (string) $row->$field;
            $this->require($actual === $source[$field], 'REFUND_EVIDENCE_INVALID');
        }
        $this->require(bccomp((string) $row->cumulative_minor, $source['original_amount_minor']) <= 0, 'REFUND_EVIDENCE_INVALID');
        $journal = $this->ledger->inspectJournal((int) $row->journal_id);
        $this->require($journal['installation_id'] === $source['installation_id']
            && $journal['user_id'] === $source['user_id'], 'REFUND_EVIDENCE_INVALID');
        $expected = $this->refundJournal($source, (string) $row->amount_minor, $row->request_key, (string) $row->created_at);
        foreach ($expected as $field => $value) {
            $this->require($journal[$field] === $value, 'REFUND_EVIDENCE_INVALID');
        }
    }

    private function verifyRefundHash(object $row): void
    {
        $payload = $this->payload($row);
        $request = [];
        foreach (['user_id', 'source_type', 'source_id', 'amount_minor', 'request_key', 'actor_id', 'reason', 'destination'] as $field) {
            $request[$field] = $payload[$field];
        }
        $this->require($row->destination === 'wallet' && hash_equals(MoneyValue::hash($payload), $row->content_hash)
            && hash_equals(MoneyValue::hash($request), $row->request_hash)
            && hash_equals(hash('sha256', $row->request_key), $row->key_hash), 'REFUND_EVIDENCE_INVALID');
        MoneyValue::minor($row->amount_minor, true);
        MoneyValue::minor($row->cumulative_minor, true);
    }

    private function refundJournal(array $source, string $amount, string $key, string $at): array
    {
        return ['business_type' => 'refund', 'business_ref' => $source['source_type'].':'.$source['source_id'],
            'idempotency_key' => $key, 'currency' => $source['currency'], 'occurred_at' => $at, 'postings' => [
                ['owner_id' => '0', 'purpose' => $source['debit_purpose'], 'side' => 'debit', 'amount_minor' => $amount],
                ['owner_id' => $source['user_id'], 'purpose' => 'wallet_available', 'side' => 'credit', 'amount_minor' => $amount],
            ]];
    }

    private function payload(object $row): array
    {
        $payload = [];
        foreach (['installation_id', 'user_id', 'actor_id', 'source_type', 'source_id', 'receipt_id', 'order_payment_id',
            'order_id', 'source_journal_id', 'journal_id', 'request_key', 'request_hash', 'amount_minor',
            'cumulative_minor', 'currency', 'destination', 'reason', 'entitlement_effect', 'subscription_id', 'created_at'] as $field) {
            $payload[$field] = $row->$field === null ? null : (string) $row->$field;
        }
        return $payload;
    }

    private function result(object $row): array
    {
        return ['refund_id' => (string) $row->id, 'status' => 'wallet_credited', 'external_refund' => false] + $this->payload($row);
    }

    private function require(bool $condition, string $error): void
    {
        if (!$condition) { throw new BillingError($error); }
    }
}
