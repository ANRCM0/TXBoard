<?php

namespace App\Domains\Billing;

use App\Domains\Subscription\Services\NativeSubscriptionStore;
use App\Models\Order;
use App\Models\OrderPurchaseTerms;
use Illuminate\Support\Facades\DB;

/** Internal payment evidence only. Adapters must verify protocol facts before calling receipt recording. */
final class NativePaymentAttempts
{
    private const STATES = ['prepared', 'pending', 'unknown', 'failed', 'succeeded'];

    public function __construct(
        private readonly NativeBillingTransaction $transactions,
        private readonly NativeSubscriptionStore $subscriptions,
        private readonly PurchasedOrderGrant $grants,
    ) {}

    public function prepare(int $userId, int $orderId, int $paymentId, string $providerAccountRef, string $requestKey): array
    {
        return $this->transactions->run($userId, fn (array $installation): array =>
            $this->prepareLocked($installation, $userId, $orderId, $paymentId, $providerAccountRef, $requestKey));
    }

    /** Caller holds current installation admission and the owning account/aggregate locks. */
    public function prepareLocked(array $installation, int $userId, int $orderId, int $paymentId,
        string $providerAccountRef, string $requestKey): array
    {
        $this->assertLockedContext($installation, $userId);
        $this->positiveId($orderId);
        $this->positiveId($paymentId);
        $account = MoneyValue::key($providerAccountRef);
        $key = MoneyValue::key($requestKey);
        return (function () use ($installation, $userId, $orderId, $paymentId, $account, $key): array {
            [$order, $terms, $purchase] = $this->purchaseLocked($userId, $orderId, $installation['installation_id']);
            $existing = DB::table('tx_payment_attempt')->where('installation_id', $installation['installation_id'])
                ->where('user_id', $userId)->where('key_hash', hash('sha256', $key))->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->intent_type !== 'purchase' || $existing->recharge_id !== null
                    || (string) $existing->order_id !== (string) $orderId
                    || (string) $existing->payment_id !== (string) $paymentId
                    || $existing->provider_account_ref !== $account || $existing->request_key !== $key) {
                    throw new BillingError('PAYMENT_ATTEMPT_IDEMPOTENCY_CONFLICT');
                }
                $this->verifyAttempt($existing, $installation['installation_id'], $userId, $order, $terms, $purchase);
                return $this->attemptResult($existing);
            }
            if ((int) $order->status !== Order::STATUS_PENDING) {
                throw new BillingError('PAYMENT_ORDER_NOT_PAYABLE');
            }
            $amount = MoneyValue::minor(app(NativeWalletReservations::class)
                ->channelAmountLocked($installation, $userId, $order, $terms), true);
            $currency = MoneyValue::currency($purchase['currency']);
            $payment = DB::table('tx_payment')->where('id', $paymentId)->lockForUpdate()->first();
            if ($payment === null || !(bool) $payment->enable) {
                throw new BillingError('PAYMENT_PROVIDER_UNAVAILABLE');
            }
            $provider = MoneyValue::key($payment->payment, 32);
            $payload = [
                'installation_id' => $installation['installation_id'], 'user_id' => (string) $userId,
                'intent_type' => 'purchase', 'recharge_id' => null,
                'order_id' => (string) $orderId, 'purchase_terms_id' => (string) $terms->id,
                'payment_id' => (string) $paymentId, 'provider' => $provider, 'provider_account_ref' => $account,
                'order_trade_no' => (string) $order->trade_no, 'amount_minor' => $amount, 'currency' => $currency,
                'purchase_content_hash' => $purchase['content_hash'], 'request_key' => $key,
            ];
            $now = time();
            $requestHash = MoneyValue::hash($payload);
            $id = DB::table('tx_payment_attempt')->insertGetId($payload + [
                'key_hash' => hash('sha256', $key), 'request_hash' => $requestHash,
                'attempt_hash' => MoneyValue::hash($payload + ['request_hash' => $requestHash, 'created_at' => (string) $now]),
                'status' => 'prepared', 'created_at' => $now, 'updated_at' => $now,
            ]);
            return $this->attemptResult(DB::table('tx_payment_attempt')->where('id', $id)->first());
        })();
    }

    public function prepareRecharge(int $userId, int $rechargeId, int $paymentId,
        string $providerAccountRef, string $requestKey): array
    {
        return $this->transactions->run($userId, fn (array $installation): array =>
            $this->prepareRechargeLocked($installation, $userId, $rechargeId, $paymentId, $providerAccountRef, $requestKey));
    }

    /** Recharge and purchase use the same permanent request and provider receipt identity authority. */
    public function prepareRechargeLocked(array $installation, int $userId, int $rechargeId, int $paymentId,
        string $providerAccountRef, string $requestKey): array
    {
        $this->assertLockedContext($installation, $userId);
        $this->positiveId($rechargeId);
        $this->positiveId($paymentId);
        $account = MoneyValue::key($providerAccountRef);
        $key = MoneyValue::key($requestKey);
        $intent = app(NativeRechargeService::class)->intentLocked($installation, $userId, $rechargeId);
        if (($intent['payment_id'] ?? null) !== (string) $paymentId) {
            throw new BillingError('PAYMENT_ATTEMPT_BINDING_CONFLICT');
        }
        $existing = DB::table('tx_payment_attempt')->where('installation_id', $installation['installation_id'])
            ->where('user_id', $userId)->where('key_hash', hash('sha256', $key))->lockForUpdate()->first();
        if ($existing !== null) {
            if ($existing->intent_type !== 'recharge' || (string) $existing->recharge_id !== (string) $rechargeId
                || (string) $existing->payment_id !== (string) $paymentId
                || $existing->provider_account_ref !== $account || $existing->request_key !== $key) {
                throw new BillingError('PAYMENT_ATTEMPT_IDEMPOTENCY_CONFLICT');
            }
            $this->verifyRechargeAttempt($existing, $installation['installation_id'], $userId, $intent);
            return $this->attemptResult($existing);
        }
        $payment = DB::table('tx_payment')->where('id', $paymentId)->lockForUpdate()->first();
        if ($payment === null || !(bool) $payment->enable) {
            throw new BillingError('PAYMENT_PROVIDER_UNAVAILABLE');
        }
        $payload = [
            'installation_id' => $installation['installation_id'], 'user_id' => (string) $userId,
            'intent_type' => 'recharge', 'recharge_id' => (string) $rechargeId,
            'order_id' => null, 'purchase_terms_id' => null,
            'payment_id' => (string) $paymentId, 'provider' => MoneyValue::key($payment->payment, 32),
            'provider_account_ref' => $account, 'order_trade_no' => MoneyValue::key($intent['trade_no'], 36),
            'amount_minor' => MoneyValue::minor($intent['amount_minor'], true),
            'currency' => MoneyValue::currency($intent['currency']), 'purchase_content_hash' => $intent['content_hash'],
            'request_key' => $key,
        ];
        $now = time();
        $requestHash = MoneyValue::hash($payload);
        $id = DB::table('tx_payment_attempt')->insertGetId($payload + [
            'key_hash' => hash('sha256', $key), 'request_hash' => $requestHash,
            'attempt_hash' => MoneyValue::hash($payload + ['request_hash' => $requestHash, 'created_at' => (string) $now]),
            'status' => 'prepared', 'created_at' => $now, 'updated_at' => $now,
        ]);
        return $this->attemptResult(DB::table('tx_payment_attempt')->where('id', $id)->first());
    }

    public function markPending(int $userId, int $attemptId): array
    {
        return $this->transition($userId, $attemptId, 'pending');
    }

    public function markPendingLocked(array $installation, int $userId, int $attemptId): array
    {
        return $this->withAttemptLocked($installation, $userId, $attemptId,
            fn (object $attempt): array => $this->changeState($attempt, 'pending'));
    }

    /** A timeout cannot prove that a provider did not take money. */
    public function markUnknown(int $userId, int $attemptId): array
    {
        return $this->transition($userId, $attemptId, 'unknown');
    }

    public function markFailed(int $userId, int $attemptId): array
    {
        return $this->transition($userId, $attemptId, 'failed');
    }

    /**
     * Trusted adapter output, never an HTTP request body. No network I/O, fulfillment or ledger credit occurs here.
     * Fact fields: attempt_id, provider, provider_account_ref, transaction_ref, amount_minor, currency, occurred_at.
     */
    public function recordVerifiedReceipt(int $userId, int $attemptId, array $fact): array
    {
        $fact = $this->normalizeFact($attemptId, $fact);
        return $this->withAttempt($userId, $attemptId,
            fn (object $attempt): array => $this->recordReceipt($attempt, $fact));
    }

    /** Money accounts must already be locked by the caller before financial evidence locks. */
    public function recordVerifiedReceiptLocked(array $installation, int $userId, int $attemptId, array $fact): array
    {
        $fact = $this->normalizeFact($attemptId, $fact);
        return $this->withAttemptLocked($installation, $userId, $attemptId,
            fn (object $attempt): array => $this->recordReceipt($attempt, $fact));
    }

    private function recordReceipt(object $attempt, array $fact): array
    {
            foreach (['provider', 'provider_account_ref', 'amount_minor', 'currency'] as $field) {
                if ((string) $attempt->$field !== $fact[$field]) {
                    throw new BillingError('PAYMENT_RECEIPT_BINDING_CONFLICT');
                }
            }
            $identityHash = MoneyValue::hash([
                'provider' => $fact['provider'], 'provider_account_ref' => $fact['provider_account_ref'],
                'transaction_ref' => $fact['transaction_ref'],
            ]);
            $payload = [
                'installation_id' => $attempt->installation_id, 'user_id' => (string) $attempt->user_id,
                'intent_type' => $attempt->intent_type,
                'recharge_id' => $attempt->recharge_id === null ? null : (string) $attempt->recharge_id,
                'order_id' => $attempt->order_id === null ? null : (string) $attempt->order_id,
                'payment_id' => (string) $attempt->payment_id,
                'attempt_id' => (string) $attempt->id, 'provider' => $fact['provider'],
                'provider_account_ref' => $fact['provider_account_ref'], 'transaction_ref' => $fact['transaction_ref'],
                'identity_hash' => $identityHash, 'amount_minor' => $fact['amount_minor'],
                'currency' => $fact['currency'], 'occurred_at' => $fact['occurred_at'],
            ];
            // The unique identity serializes receipts across users, orders and duplicate payment registrations.
            DB::table('tx_payment_receipt')->insertOrIgnore($payload + [
                'content_hash' => MoneyValue::hash($payload), 'created_at' => time(),
            ]);
            $receipt = DB::table('tx_payment_receipt')->where('installation_id', $attempt->installation_id)
                ->where('identity_hash', $identityHash)->lockForUpdate()->first();
            if ($receipt === null || $this->receiptPayload($receipt) !== $payload
                || !hash_equals(MoneyValue::hash($payload), (string) $receipt->content_hash)) {
                throw new BillingError('PAYMENT_RECEIPT_IDENTITY_CONFLICT');
            }
            DB::table('tx_payment_attempt')->where('id', $attempt->id)->update(['status' => 'succeeded', 'updated_at' => time()]);
            return $this->receiptResult($receipt);
    }

    private function transition(int $userId, int $attemptId, string $state): array
    {
        return $this->withAttempt($userId, $attemptId,
            fn (object $attempt): array => $this->changeState($attempt, $state));
    }

    private function changeState(object $attempt, string $state): array
    {
            if ($attempt->status === $state) {
                return $this->attemptResult($attempt);
            }
            $allowed = match ($attempt->status) {
                'prepared' => ['pending', 'unknown', 'failed'],
                'pending' => ['unknown', 'failed'],
                'unknown' => ['pending', 'failed'],
                default => [],
            };
            if (!in_array($state, $allowed, true)) {
                throw new BillingError('PAYMENT_ATTEMPT_TRANSITION_INVALID');
            }
            DB::table('tx_payment_attempt')->where('id', $attempt->id)->update(['status' => $state, 'updated_at' => time()]);
            $attempt->status = $state;
            return $this->attemptResult($attempt);
    }

    private function withAttemptLocked(array $installation, int $userId, int $attemptId, \Closure $operation): array
    {
        $this->assertLockedContext($installation, $userId);
        $this->positiveId($attemptId);
        $locator = DB::table('tx_payment_attempt')->useWritePdo()->where('id', $attemptId)
            ->first(['user_id', 'order_id', 'intent_type', 'recharge_id']);
        if ($locator === null || (string) $locator->user_id !== (string) $userId) {
            throw new BillingError('PAYMENT_ATTEMPT_MISSING');
        }
        if ($locator->intent_type === 'recharge') {
            $intent = app(NativeRechargeService::class)->intentLocked($installation, $userId, (int) $locator->recharge_id);
        } else {
            [$order, $terms, $purchase] = $this->purchaseLocked($userId, (int) $locator->order_id, $installation['installation_id']);
        }
        $attempt = DB::table('tx_payment_attempt')->where('id', $attemptId)->lockForUpdate()->first();
        if ($attempt === null) {
            throw new BillingError('PAYMENT_ATTEMPT_MISSING');
        }
        if ($locator->intent_type === 'recharge') {
            $this->verifyRechargeAttempt($attempt, $installation['installation_id'], $userId, $intent);
        } else {
            $this->verifyAttempt($attempt, $installation['installation_id'], $userId, $order, $terms, $purchase);
        }
        return $operation($attempt);
    }

    private function assertLockedContext(array $installation, int $userId): void
    {
        if (DB::transactionLevel() === 0 || $userId <= 0
            || !is_string($installation['installation_id'] ?? null)
            || preg_match('/\A[0-9a-f-]{36}\z/D', $installation['installation_id']) !== 1) {
            throw new BillingError('PAYMENT_TRANSACTION_REQUIRED');
        }
    }

    private function withAttempt(int $userId, int $attemptId, \Closure $operation): array
    {
        return $this->transactions->run($userId, fn (array $installation): array =>
            $this->withAttemptLocked($installation, $userId, $attemptId, $operation));
    }

    private function purchaseLocked(int $userId, int $orderId, string $installationId): array
    {
        $this->subscriptions->lockAccount($userId, time(), $installationId);
        $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();
        if ($order === null || (string) $order->user_id !== (string) $userId
            || $order->purchase_terms_version !== 'tx-core.purchase-terms/v2') {
            throw new BillingError('PAYMENT_ORDER_OWNERSHIP_INVALID');
        }
        $terms = OrderPurchaseTerms::query()->where('order_id', $orderId)->lockForUpdate()->first();
        $purchase = $this->grants->resolve($order, $terms);
        if ($terms === null || $purchase === null) {
            throw new BillingError('PAYMENT_PURCHASE_REQUIRED');
        }
        return [$order, $terms, $purchase];
    }

    private function verifyAttempt(object $attempt, string $installationId, int $userId, Order $order, OrderPurchaseTerms $terms, array $purchase): void
    {
        $payload = $this->attemptPayload($attempt);
        if ($attempt->intent_type !== 'purchase' || $attempt->recharge_id !== null
            || $attempt->installation_id !== $installationId || (string) $attempt->user_id !== (string) $userId
            || (string) $attempt->order_id !== (string) $order->id || (string) $attempt->purchase_terms_id !== (string) $terms->id
            || $attempt->order_trade_no !== (string) $order->trade_no
            || $attempt->purchase_content_hash !== $purchase['content_hash']
            || (string) $attempt->amount_minor !== MoneyValue::minor(app(NativeWalletReservations::class)
                ->channelAmountLocked(['installation_id' => $installationId], $userId, $order, $terms), true)
            || $attempt->currency !== MoneyValue::currency($purchase['currency'])) {
            throw new BillingError('PAYMENT_ATTEMPT_EVIDENCE_INVALID');
        }
        $this->verifyAttemptIntegrity($attempt, $payload);
    }

    private function verifyRechargeAttempt(object $attempt, string $installationId, int $userId, array $intent): void
    {
        $payload = $this->attemptPayload($attempt);
        if ($attempt->intent_type !== 'recharge' || $attempt->order_id !== null || $attempt->purchase_terms_id !== null
            || $attempt->installation_id !== $installationId || (string) $attempt->user_id !== (string) $userId
            || (string) $attempt->recharge_id !== $intent['recharge_id']
            || (string) $attempt->payment_id !== ($intent['payment_id'] ?? null)
            || $attempt->order_trade_no !== $intent['trade_no'] || $attempt->purchase_content_hash !== $intent['content_hash']
            || (string) $attempt->amount_minor !== MoneyValue::minor($intent['amount_minor'], true)
            || $attempt->currency !== MoneyValue::currency($intent['currency'])) {
            throw new BillingError('PAYMENT_ATTEMPT_EVIDENCE_INVALID');
        }
        $this->verifyAttemptIntegrity($attempt, $payload);
    }

    private function verifyAttemptIntegrity(object $attempt, array $payload): void
    {
        if (!hash_equals(hash('sha256', $payload['request_key']), (string) $attempt->key_hash)
            || !hash_equals(MoneyValue::hash($payload), (string) $attempt->request_hash)
            || !hash_equals(MoneyValue::hash($payload + [
                'request_hash' => (string) $attempt->request_hash, 'created_at' => MoneyValue::minor($attempt->created_at, true),
            ]), (string) $attempt->attempt_hash)
            || !in_array($attempt->status, self::STATES, true)) {
            throw new BillingError('PAYMENT_ATTEMPT_EVIDENCE_INVALID');
        }
        $receipt = DB::table('tx_payment_receipt')->where('attempt_id', $attempt->id)->lockForUpdate()->first();
        if (($attempt->status === 'succeeded') !== ($receipt !== null)) {
            throw new BillingError('PAYMENT_ATTEMPT_EVIDENCE_INVALID');
        }
        if ($receipt !== null) {
            $this->verifyReceipt($receipt, $attempt);
        }
    }

    private function attemptPayload(object $attempt): array
    {
        $payload = [];
        foreach (['user_id', 'payment_id'] as $field) {
            $payload[$field] = MoneyValue::minor($attempt->$field, true);
        }
        return [
            'installation_id' => (string) $attempt->installation_id, 'user_id' => $payload['user_id'],
            'intent_type' => (string) $attempt->intent_type,
            'recharge_id' => $attempt->recharge_id === null ? null : MoneyValue::minor($attempt->recharge_id, true),
            'order_id' => $attempt->order_id === null ? null : MoneyValue::minor($attempt->order_id, true),
            'purchase_terms_id' => $attempt->purchase_terms_id === null ? null : MoneyValue::minor($attempt->purchase_terms_id, true),
            'payment_id' => $payload['payment_id'], 'provider' => MoneyValue::key($attempt->provider, 32),
            'provider_account_ref' => MoneyValue::key($attempt->provider_account_ref),
            'order_trade_no' => MoneyValue::key($attempt->order_trade_no, 36),
            'amount_minor' => MoneyValue::minor($attempt->amount_minor, true),
            'currency' => MoneyValue::currency($attempt->currency),
            'purchase_content_hash' => (string) $attempt->purchase_content_hash,
            'request_key' => MoneyValue::key($attempt->request_key),
        ];
    }

    private function normalizeFact(int $attemptId, array $fact): array
    {
        $keys = array_keys($fact);
        sort($keys, SORT_STRING);
        $expected = ['amount_minor', 'attempt_id', 'currency', 'occurred_at', 'provider', 'provider_account_ref', 'transaction_ref'];
        if ($keys !== $expected || MoneyValue::minor($fact['attempt_id'] ?? null, true) !== (string) $attemptId) {
            throw new BillingError('PAYMENT_RECEIPT_FACT_INVALID');
        }
        return [
            'attempt_id' => (string) $attemptId, 'provider' => MoneyValue::key($fact['provider'], 32),
            'provider_account_ref' => MoneyValue::key($fact['provider_account_ref']),
            'transaction_ref' => MoneyValue::key($fact['transaction_ref']),
            'amount_minor' => MoneyValue::minor($fact['amount_minor'], true),
            'currency' => MoneyValue::currency($fact['currency']),
            'occurred_at' => MoneyValue::minor($fact['occurred_at'], true),
        ];
    }

    private function receiptPayload(object $receipt): array
    {
        return [
            'installation_id' => (string) $receipt->installation_id, 'user_id' => MoneyValue::minor($receipt->user_id, true),
            'intent_type' => (string) $receipt->intent_type,
            'recharge_id' => $receipt->recharge_id === null ? null : MoneyValue::minor($receipt->recharge_id, true),
            'order_id' => $receipt->order_id === null ? null : MoneyValue::minor($receipt->order_id, true),
            'payment_id' => MoneyValue::minor($receipt->payment_id, true),
            'attempt_id' => MoneyValue::minor($receipt->attempt_id, true), 'provider' => MoneyValue::key($receipt->provider, 32),
            'provider_account_ref' => MoneyValue::key($receipt->provider_account_ref),
            'transaction_ref' => MoneyValue::key($receipt->transaction_ref), 'identity_hash' => (string) $receipt->identity_hash,
            'amount_minor' => MoneyValue::minor($receipt->amount_minor, true), 'currency' => MoneyValue::currency($receipt->currency),
            'occurred_at' => MoneyValue::minor($receipt->occurred_at, true),
        ];
    }

    private function verifyReceipt(object $receipt, object $attempt): void
    {
        $payload = $this->receiptPayload($receipt);
        foreach (['installation_id', 'user_id', 'intent_type', 'recharge_id', 'order_id', 'payment_id', 'provider',
            'provider_account_ref', 'amount_minor', 'currency'] as $field) {
            if ($payload[$field] !== ($attempt->$field === null ? null : (string) $attempt->$field)) {
                throw new BillingError('PAYMENT_RECEIPT_EVIDENCE_INVALID');
            }
        }
        if ($payload['attempt_id'] !== (string) $attempt->id
            || !hash_equals(MoneyValue::hash([
                'provider' => $payload['provider'], 'provider_account_ref' => $payload['provider_account_ref'],
                'transaction_ref' => $payload['transaction_ref'],
            ]), $payload['identity_hash'])
            || !hash_equals(MoneyValue::hash($payload), (string) $receipt->content_hash)) {
            throw new BillingError('PAYMENT_RECEIPT_EVIDENCE_INVALID');
        }
    }

    private function attemptResult(object $attempt): array
    {
        return ['attempt_id' => (string) $attempt->id, 'status' => $attempt->status] + $this->attemptPayload($attempt);
    }

    private function receiptResult(object $receipt): array
    {
        return ['receipt_id' => (string) $receipt->id] + $this->receiptPayload($receipt);
    }

    private function positiveId(int $id): void
    {
        if ($id <= 0) {
            throw new BillingError('PAYMENT_IDENTITY_INVALID');
        }
    }
}
