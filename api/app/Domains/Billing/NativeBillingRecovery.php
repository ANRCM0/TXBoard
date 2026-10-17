<?php

namespace App\Domains\Billing;

use App\Domains\Billing\Internal\WinningPaymentProofs;
use App\Domains\Installation\InstallationAuthority;
use App\Domains\Subscription\Contracts\OrderFulfillment;
use App\Domains\Subscription\Services\NativeSubscriptionStore;
use App\Models\Order;
use App\Models\OrderPurchaseTerms;
use Illuminate\Support\Facades\DB;
use Throwable;

/** SQL work is the durable wakeup; the subscription operation owns fulfillment idempotency. */
final class NativeBillingRecovery
{
    public function __construct(
        private readonly NativeSubscriptionStore $subscriptions,
        private readonly WinningPaymentProofs $settlements,
        private readonly OrderFulfillment $fulfillment,
    ) {}

    /** Query original frozen attempts; the transport and later settlement own separate transactions. */
    public function recoverPayments(int $limit = 10): array
    {
        if (DB::transactionLevel() !== 0) { throw new BillingError('BILLING_OUTER_TRANSACTION_UNSUPPORTED'); }
        if ($limit < 1 || $limit > 1000) { throw new BillingError('BILLING_WORK_LIMIT_INVALID'); }
        app(InstallationAuthority::class)->assertNativeRuntime();
        $attempts = DB::table('tx_payment_dispatch as dispatch')->useWritePdo()
            ->join('tx_payment_attempt as attempt', 'attempt.id', '=', 'dispatch.attempt_id')
            ->whereIn('attempt.status', ['pending', 'unknown'])
            ->where(fn ($query) => $query->whereNull('dispatch.last_queried_at')
                ->orWhere('dispatch.last_queried_at', '<=', time() - 60))
            ->orderBy('dispatch.last_queried_at')->orderBy('dispatch.attempt_id')->limit(min($limit, 10))
            ->get(['dispatch.user_id', 'dispatch.attempt_id']);
        $results = [];
        foreach ($attempts as $attempt) {
            try {
                $results[(string) $attempt->attempt_id] = app(NativePaymentCheckout::class)
                    ->recover((int) $attempt->user_id, (int) $attempt->attempt_id);
            } catch (Throwable $error) {
                $results[(string) $attempt->attempt_id] = ['error_code' => $this->errorCode($error)];
            }
        }
        return $results;
    }

    public function recover(int $limit = 100): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new BillingError('BILLING_OUTER_TRANSACTION_UNSUPPORTED');
        }
        if ($limit < 1 || $limit > 1000) {
            throw new BillingError('BILLING_WORK_LIMIT_INVALID');
        }
        app(InstallationAuthority::class)->assertNativeRuntime();
        $items = DB::table('tx_billing_work')->useWritePdo()->where('status', 'pending')
            ->orderBy('id')->limit($limit)->get();
        $results = [];
        foreach ($items as $locator) {
            $started = false;
            try {
                $terminal = $this->withWork($locator, function (object $work): ?string {
                    if (in_array($work->status, ['completed', 'cancelled'], true)) { return $work->status; }
                    DB::table('tx_billing_work')->where('id', $work->id)->update([
                        'attempts' => $this->nextAttempt($work), 'error_code' => null,
                    ]);
                    return null;
                });
                if ($terminal !== null) {
                    $results[(string) $locator->id] = ['status' => $terminal];
                    continue;
                }
                $started = true;
                // This owns a separate transaction and may perform Provider I/O after committing.
                $result = $this->fulfillment->fulfill((int) $locator->order_id);
                $this->withWork($locator, function (object $work, Order $order, OrderPurchaseTerms $purchase,
                    array $installation, $subscriptions) use ($result): void {
                    if (in_array($work->status, ['completed', 'cancelled'], true)) { return; }
                    $subscription = $subscriptions->firstWhere('id', $result['subscription_id'] ?? null);
                    if (!$subscription || (int) $order->status !== Order::STATUS_COMPLETED) {
                        throw new BillingError('BILLING_WORK_FULFILLMENT_INVALID');
                    }
                    $operations = DB::table('tx_subscription_operation')->where(function ($query) use ($order): void {
                        $query->where('order_id', $order->id)->orWhere(fn ($q) => $q->where('user_id', $order->user_id)
                            ->where('business_key', 'order.fulfill/'.$order->id));
                    })->orderBy('id')->limit(2)->lockForUpdate()->get();
                    if ($operations->count() !== 1) {
                        throw new BillingError('BILLING_WORK_FULFILLMENT_INVALID');
                    }
                    $verified = $this->subscriptions->replay($operations->first(), [
                        'schema' => NativeSubscriptionStore::OPERATION, 'user_id' => (string) $order->user_id,
                        'business_key' => 'order.fulfill/'.$order->id, 'kind' => 'order.fulfill',
                        'order_id' => (string) $order->id, 'purchase_hash' => $purchase->content_hash,
                        'installation_id' => $installation['installation_id'],
                    ], $subscription);
                    if (!hash_equals(MoneyValue::hash($verified), MoneyValue::hash($result))) {
                        throw new BillingError('BILLING_WORK_FULFILLMENT_INVALID');
                    }
                    DB::table('tx_billing_work')->where('id', $work->id)->where('status', 'pending')->update([
                        'status' => 'completed', 'error_code' => null, 'completed_at' => time(),
                    ]);
                }, $result);
                $results[(string) $locator->id] = ['status' => 'completed'];
            } catch (Throwable $error) {
                $code = $this->errorCode($error);
                $results[(string) $locator->id] = $this->recordFailure($locator, $code, !$started);
            }
        }
        return $results + app(NativeFreeCheckout::class)->recover($limit);
    }

    private function withWork(object $locator, \Closure $operation, ?array $result = null): mixed
    {
        return DB::transaction(function () use ($locator, $operation, $result): mixed {
            $installation = app(InstallationAuthority::class)->assertNativeMutation();
            [, , $subscriptions] = $this->subscriptions->lockAccount(
                (int) $locator->user_id, time(), $installation['installation_id']);
            $history = null;
            if ($result !== null) {
                $subscription = $subscriptions->firstWhere('id', $result['subscription_id'] ?? null);
                if (!$subscription) { throw new BillingError('BILLING_WORK_FULFILLMENT_INVALID'); }
                $history = $this->subscriptions->lockHistory($subscription);
            }
            $order = Order::query()->whereKey($locator->order_id)->lockForUpdate()->first();
            $purchase = OrderPurchaseTerms::query()->where('order_id', $locator->order_id)->lockForUpdate()->first();
            if (!$order || !$purchase || (string) $order->user_id !== (string) $locator->user_id) {
                throw new BillingError('BILLING_WORK_OWNERSHIP_INVALID');
            }
            if ($history !== null) { $this->subscriptions->verifyHistory($subscription, $history); }
            $currentWork = DB::table('tx_billing_work')->where('id', $locator->id)->first();
            $this->verifyWork($currentWork, $locator, $installation['installation_id']);
            if ($currentWork->status === 'cancelled') {
                $work = DB::table('tx_billing_work')->where('id', $locator->id)->lockForUpdate()->first();
                $this->verifyWork($work, $locator, $installation['installation_id']);
                return $operation($work, $order, $purchase, $installation, $subscriptions);
            }
            $payment = $this->settlements->verifyWinningPaymentLocked(
                $installation, (int) $locator->user_id, $order, $purchase);
            $work = DB::table('tx_billing_work')->where('id', $locator->id)->lockForUpdate()->first();
            $this->verifyWork($work, $locator, $installation['installation_id']);
            if (($work->settlement_id === null ? null : (string) $work->settlement_id) !== $payment['settlement_id']
                || ($work->receipt_id === null ? null : (string) $work->receipt_id) !== $payment['receipt_id']
                || ($work->settlement_id === null
                    && (string) $work->wallet_reservation_id !== ($payment['wallet_reservation_id'] ?? null))) {
                throw new BillingError('BILLING_WORK_EVIDENCE_INVALID');
            }
            return $operation($work, $order, $purchase, $installation, $subscriptions);
        }, 3);
    }

    private function recordFailure(object $locator, string $code, bool $increment): array
    {
        try {
            return DB::transaction(function () use ($locator, $code, $increment): array {
                $installation = app(InstallationAuthority::class)->assertNativeMutation();
                $this->subscriptions->lockAccount((int) $locator->user_id, time(), $installation['installation_id']);
                Order::query()->whereKey($locator->order_id)->lockForUpdate()->first();
                $work = DB::table('tx_billing_work')->where('id', $locator->id)->lockForUpdate()->first();
                $this->verifyWork($work, $locator, $installation['installation_id']);
                if (in_array($work->status, ['completed', 'cancelled'], true)) { return ['status' => $work->status]; }
                DB::table('tx_billing_work')->where('id', $work->id)->where('status', 'pending')->update([
                    'attempts' => $increment ? $this->nextAttempt($work) : $work->attempts, 'error_code' => $code,
                ]);
                return ['status' => 'pending', 'error_code' => $code];
            }, 3);
        } catch (Throwable $error) {
            return ['status' => 'pending', 'error_code' => $this->errorCode($error)];
        }
    }

    private function verifyWork(?object $work, object $locator, string $installationId): void
    {
        if (!$work || $work->installation_id !== $installationId || $work->kind !== 'order.fulfill'
            || !in_array($work->status, ['pending', 'completed', 'cancelled'], true)
            || ($work->status !== 'pending') !== ($work->completed_at !== null)) {
            throw new BillingError('BILLING_WORK_EVIDENCE_INVALID');
        }
        foreach (['id', 'installation_id', 'user_id', 'order_id', 'receipt_id', 'settlement_id',
            'wallet_reservation_id', 'event_key', 'kind'] as $field) {
            if ((string) $work->$field !== (string) $locator->$field) {
                throw new BillingError('BILLING_WORK_OWNERSHIP_INVALID');
            }
        }
        if ($work->wallet_reservation_id !== null) {
            app(NativeWalletPayments::class)->verifyWorkLocked($work, ['installation_id' => $installationId]);
            return;
        }
        $key = MoneyValue::hash(['kind' => 'order.fulfill', 'installation_id' => $installationId,
            'order_id' => (string) $work->order_id, 'receipt_id' => (string) $work->receipt_id]);
        $payload = ['event_key' => $key, 'kind' => 'order.fulfill', 'installation_id' => $installationId,
            'order_id' => (string) $work->order_id, 'receipt_id' => (string) $work->receipt_id,
            'user_id' => (string) $work->user_id, 'settlement_id' => (string) $work->settlement_id];
        if (!hash_equals($key, $work->event_key)
            || !hash_equals(MoneyValue::hash($payload), (string) $work->content_hash)) {
            throw new BillingError('BILLING_WORK_EVIDENCE_INVALID');
        }
    }

    private function nextAttempt(object $work): int
    {
        return min(4294967295, (int) $work->attempts + 1);
    }

    private function errorCode(Throwable $error): string
    {
        return $error instanceof \DomainException
            && preg_match('/\A(?:BILLING|PAYMENT|NATIVE|INSTALLATION)_[A-Z0-9_]{1,50}\z/D', $error->getMessage()) === 1
                ? $error->getMessage() : 'BILLING_WORK_FAILED';
    }
}
