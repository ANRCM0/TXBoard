<?php

namespace App\Domains\Billing;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use App\Domains\Subscription\Services\NativeSubscriptionStore;
use App\Models\Order;
use App\Models\OrderPurchaseTerms;
use Throwable;

/** Frozen dispatch orchestration; only verified adapter facts enter settlement. */
final class NativePaymentCheckout
{
    public function __construct(
        private readonly NativeBillingTransaction $transactions,
        private readonly NativePaymentAttempts $attempts,
        private readonly NativePaymentProviderRegistry $providers,
        private readonly NativePaymentSettlement $settlement,
    ) {}

    public function start(int $userId, int $orderId, int $paymentId, string $requestKey, ?string $walletMinor = null): array
    {
        return $this->dispatch($userId, $orderId, null, $paymentId, $requestKey, $walletMinor);
    }

    public function recharge(int $userId, int $rechargeId, int $paymentId, string $requestKey): array
    {
        return $this->dispatch($userId, null, $rechargeId, $paymentId, $requestKey);
    }

    private function dispatch(int $userId, ?int $orderId, ?int $rechargeId, int $paymentId,
        string $requestKey, ?string $walletMinor = null): array
    {
        $key = MoneyValue::key($requestKey);
        $prepared = $this->transactions->run($userId, function (array $installation, object $user) use (
            $userId, $orderId, $rechargeId, $paymentId, $key, $walletMinor
        ): array {
            if ($orderId !== null && $walletMinor !== null) {
                app(NativeSubscriptionStore::class)->lockAccount($userId, time(), $installation['installation_id']);
                $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();
                $terms = OrderPurchaseTerms::query()->where('order_id', $orderId)->lockForUpdate()->first();
                if ($order === null || $terms === null || (string) $order->user_id !== (string) $userId) {
                    throw new BillingError('PAYMENT_ORDER_OWNERSHIP_INVALID');
                }
                if (DB::table('tx_payment_attempt')->where('order_id', $orderId)->sharedLock()->exists()
                    && !DB::table('tx_wallet_order_reservation')->where('order_id', $orderId)->lockForUpdate()->exists()) {
                    throw new BillingError('PAYMENT_ORDER_ALLOCATION_FROZEN');
                }
                app(NativeWalletReservations::class)->reserveLocked($installation, $userId, $order, $terms,
                    MoneyValue::minor($walletMinor, true), 'checkout:'.$orderId);
            }
            // The user lock serializes these owned locators; a pre-wait RR snapshot may miss a dispatch.
            // An existing request resolves its original credentials before consulting current registration.
            $existing = DB::table('tx_payment_attempt')->where('installation_id', $installation['installation_id'])
                ->where('user_id', $userId)->where('key_hash', hash('sha256', $key))->sharedLock()->first();
            $dispatch = $existing === null ? null : DB::table('tx_payment_dispatch')->where('attempt_id', $existing->id)
                ->sharedLock()->first();
            if ($existing !== null && $dispatch === null) {
                throw new BillingError('NATIVE_PAYMENT_DISPATCH_MISSING');
            }
            if ($dispatch !== null) {
                $snapshot = $this->snapshot($dispatch);
                $attempt = $this->prepare($installation, $userId, $orderId, $rechargeId, $paymentId,
                    $snapshot['provider_account_ref'], $key);
                $this->matchesAttempt($snapshot, $attempt);
            } else {
                if ((bool) ($user->banned ?? false)) {
                    throw new BillingError('PAYMENT_ACCOUNT_UNAVAILABLE');
                }
                $payment = DB::table('tx_payment')->where('id', $paymentId)->first();
                if ($payment === null) {
                    throw new BillingError('PAYMENT_PROVIDER_UNAVAILABLE');
                }
                $provider = $this->providers->forPayment($payment);
                $accountRef = $provider->adapter->accountRef($payment);
                $attempt = $this->prepare($installation, $userId, $orderId, $rechargeId, $paymentId, $accountRef, $key);
                // prepareLocked acquires the account/order locks before the payment registration lock.
                $payment = DB::table('tx_payment')->where('id', $paymentId)->lockForUpdate()->first();
                $provider = $this->providers->forPayment($payment, true);
                if ($provider->adapter->accountRef($payment) !== $accountRef) {
                    throw new BillingError('PAYMENT_PROVIDER_BINDING_INVALID');
                }
                $snapshot = $this->providers->freeze($provider, $payment, $attempt);
                DB::table('tx_payment_dispatch')->insert([
                    'attempt_id' => $attempt['attempt_id'], 'installation_id' => $installation['installation_id'],
                    'user_id' => $userId, 'order_id' => $orderId, 'payment_id' => $paymentId,
                    'intent_type' => $attempt['intent_type'], 'recharge_id' => $rechargeId,
                    'plugin_code' => $provider->pluginCode, 'plugin_version' => $provider->pluginVersion,
                    'contract_version' => $provider->contractVersion, 'provider' => $provider->providerId,
                    'provider_order_ref' => $snapshot['provider_order_ref'], 'callback_uuid' => $snapshot['callback_uuid'],
                    'snapshot_ciphertext' => Crypt::encryptString(json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
                    'content_hash' => MoneyValue::hash($snapshot), 'created_at' => time(),
                ]);
                $attempt = $this->attempts->markPendingLocked($installation, $userId, (int) $attempt['attempt_id']);
            }
            return ['attempt' => $attempt, 'snapshot' => $snapshot];
        });
        // The contract forbids provider I/O under SQL locks, including future checkout implementations.
        $provider = $this->providers->forFrozen($prepared['snapshot'], 'checkout');
        $checkout = $provider->adapter->checkout($prepared['snapshot']);
        if (count($checkout) !== 2 || ($checkout['type'] ?? null) !== 1 || !is_string($checkout['data'] ?? null)
            || strlen($checkout['data']) > 8192 || !str_starts_with($checkout['data'], 'https://')
            || preg_match('/[\x00-\x20\x7f]/', $checkout['data']) === 1) {
            throw new BillingError('PAYMENT_PROVIDER_CHECKOUT_INVALID');
        }
        return ['attempt' => $prepared['attempt'], 'payment' => $checkout];
    }

    public function status(int $userId, int $attemptId): array
    {
        $this->ownedSnapshot($userId, $attemptId);
        $attempt = DB::table('tx_payment_attempt')->useWritePdo()->where('id', $attemptId)->first();
        return ['attempt_id' => (string) $attemptId, 'status' => $attempt->status,
            'intent_type' => $attempt->intent_type, 'amount_minor' => (string) $attempt->amount_minor,
            'currency' => (string) $attempt->currency];
    }

    public function query(int $userId, int $attemptId): array
    {
        $snapshot = $this->ownedSnapshot($userId, $attemptId, true);
        $provider = $this->providers->forFrozen($snapshot, 'query');
        $fact = $provider->adapter->query($snapshot);
        if ($fact === null) {
            $status = $this->unknown($userId, $attemptId);
            return ['attempt_id' => (string) $attemptId, 'status' => $status];
        }
        return ['attempt_id' => (string) $attemptId, 'status' => 'succeeded',
            'settlement' => $this->settlement->settle($userId, $attemptId, $this->providers->verifiedFact($snapshot, $fact))];
    }

    public function recover(int $userId, int $attemptId): array
    {
        return $this->query($userId, $attemptId);
    }

    /** Claim known native references even with a bad callback path, so they cannot fall through. */
    public function recognizes(string $method, string $uuid, array $params): bool
    {
        return $this->dispatchForNotification($params) !== null;
    }

    public function notify(string $method, string $uuid, array $params): ?array
    {
        $dispatch = $this->dispatchForNotification($params);
        if ($dispatch === null) {
            return null;
        }
        $snapshot = $this->snapshot($dispatch);
        if ($method !== $snapshot['provider'] || !hash_equals($snapshot['callback_uuid'], $uuid)) {
            throw new BillingError('NATIVE_PAYMENT_CALLBACK_BINDING_INVALID');
        }
        $provider = $this->providers->forFrozen($snapshot, 'verify_notification');
        $fact = $this->providers->verifiedFact($snapshot, $provider->adapter->verifyNotification($snapshot, $params));
        $ack = $provider->adapter->acknowledgement();
        if ($ack === '' || strlen($ack) > 128 || preg_match('/[^\x20-\x7e]/', $ack) === 1) {
            throw new BillingError('PAYMENT_PROVIDER_ACKNOWLEDGEMENT_INVALID');
        }
        return $this->settlement->settle((int) $snapshot['user_id'], (int) $snapshot['attempt_id'], $fact)
            + ['acknowledgement' => $ack];
    }

    private function ownedSnapshot(int $userId, int $attemptId, bool $queried = false): array
    {
        if ($attemptId <= 0) {
            throw new BillingError('PAYMENT_ATTEMPT_MISSING');
        }
        return $this->transactions->run($userId, function (array $installation) use ($userId, $attemptId, $queried): array {
            $dispatch = DB::table('tx_payment_dispatch')->where('attempt_id', $attemptId)
                ->where('installation_id', $installation['installation_id'])->where('user_id', $userId)->sharedLock()->first();
            if ($dispatch === null) {
                throw new BillingError('PAYMENT_ATTEMPT_MISSING');
            }
            $snapshot = $this->snapshot($dispatch);
            $stored = DB::table('tx_payment_attempt')->where('id', $attemptId)->sharedLock()->first();
            if ($stored === null) {
                throw new BillingError('PAYMENT_ATTEMPT_MISSING');
            }
            $attempt = $this->prepare($installation, $userId,
                $snapshot['order_id'] === null ? null : (int) $snapshot['order_id'],
                $snapshot['recharge_id'] === null ? null : (int) $snapshot['recharge_id'],
                (int) $snapshot['payment_id'], $snapshot['provider_account_ref'], $stored->request_key);
            $this->matchesAttempt($snapshot, $attempt);
            if ($queried) {
                DB::table('tx_payment_dispatch')->where('attempt_id', $attemptId)->update(['last_queried_at' => time()]);
            }
            return $snapshot;
        });
    }

    private function dispatchForNotification(array $params): ?object
    {
        return $this->providers->locateNotification($params);
    }

    private function snapshot(object $dispatch): array
    {
        try {
            $snapshot = json_decode(Crypt::decryptString($dispatch->snapshot_ciphertext), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($snapshot) || ($snapshot['schema'] ?? null) !== NativePaymentProviderRegistry::SNAPSHOT
                || !hash_equals(MoneyValue::hash($snapshot), $dispatch->content_hash)) {
                throw new BillingError('NATIVE_PAYMENT_DISPATCH_EVIDENCE_INVALID');
            }
            foreach (['installation_id', 'user_id', 'intent_type', 'recharge_id', 'order_id', 'payment_id',
                'attempt_id', 'provider_order_ref', 'callback_uuid', 'plugin_code', 'plugin_version',
                'contract_version', 'provider'] as $field) {
                if (!array_key_exists($field, $snapshot)
                    || $snapshot[$field] !== ($dispatch->$field === null ? null : (string) $dispatch->$field)) {
                    throw new BillingError('NATIVE_PAYMENT_DISPATCH_EVIDENCE_INVALID');
                }
            }
            return $snapshot;
        } catch (Throwable) {
            throw new BillingError('NATIVE_PAYMENT_DISPATCH_EVIDENCE_INVALID');
        }
    }

    private function matchesAttempt(array $snapshot, array $attempt): void
    {
        foreach (['installation_id', 'user_id', 'intent_type', 'recharge_id', 'order_id', 'payment_id', 'attempt_id', 'provider', 'provider_account_ref',
            'order_trade_no', 'amount_minor', 'currency'] as $field) {
            if (($snapshot[$field] ?? null) !== ($attempt[$field] ?? null)) {
                throw new BillingError('NATIVE_PAYMENT_DISPATCH_EVIDENCE_INVALID');
            }
        }
    }

    private function prepare(array $installation, int $userId, ?int $orderId, ?int $rechargeId,
        int $paymentId, string $accountRef, string $key): array
    {
        return $rechargeId === null
            ? $this->attempts->prepareLocked($installation, $userId, (int) $orderId, $paymentId, $accountRef, $key)
            : $this->attempts->prepareRechargeLocked($installation, $userId, $rechargeId, $paymentId, $accountRef, $key);
    }

    private function unknown(int $userId, int $attemptId): string
    {
        $state = DB::table('tx_payment_attempt')->useWritePdo()->where('id', $attemptId)->where('user_id', $userId)->value('status');
        if (in_array($state, ['succeeded', 'failed', 'unknown'], true)) {
            return $state;
        }
        try {
            return $this->attempts->markUnknown($userId, $attemptId)['status'];
        } catch (BillingError $error) {
            if ($error->errorCode !== 'PAYMENT_ATTEMPT_TRANSITION_INVALID') {
                throw $error;
            }
            $state = DB::table('tx_payment_attempt')->useWritePdo()->where('id', $attemptId)->where('user_id', $userId)->value('status');
            if (!in_array($state, ['succeeded', 'failed'], true)) {
                throw $error;
            }
            return $state;
        }
    }
}
