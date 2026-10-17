<?php

namespace App\Domains\Billing;

use App\Domains\Installation\InstallationAuthority;
use App\Domains\Subscription\Services\NativeSubscriptionStore;
use App\Models\Order;
use App\Models\OrderPurchaseTerms;
use Illuminate\Support\Facades\DB;

/** Full-wallet purchases share the same order-payment allocation and durable fulfillment authority. */
final class NativeWalletPayments implements \App\Domains\Billing\Contracts\WalletPayments
{
    public function __construct(
        private readonly NativeBillingTransaction $transactions,
        private readonly NativeSubscriptionStore $subscriptions,
        private readonly NativeWalletReservations $reservations,
        private readonly PurchasedOrderGrant $grants,
    ) {}

    public function balance(int $userId): array
    {
        return DB::transaction(function () use ($userId): array {
            $installation = app(InstallationAuthority::class)->assertNativeRead();
            $this->require($userId > 0 && DB::table('tx_user')->where('id', $userId)->exists(), 'BILLING_ACCOUNT_MISSING');
            $accounts = DB::table('tx_money_account')->where('installation_id', $installation['installation_id'])
                ->where('owner_id', $userId)->where('user_id', $userId)
                ->whereIn('purpose', ['wallet_available', 'wallet_reserved'])->orderBy('currency')->orderBy('purpose')->limit(21)->get();
            $this->require($accounts->count() <= 20, 'WALLET_CURRENCY_LIMIT');
            $balances = [];
            foreach ($accounts as $account) {
                $currency = MoneyValue::currency($account->currency);
                $this->require($account->normal_side === 'credit', 'BILLING_ACCOUNT_CORRUPT');
                $balances[$currency] ??= ['currency' => $currency, 'available_minor' => '0', 'reserved_minor' => '0'];
                $balances[$currency][$account->purpose === 'wallet_available' ? 'available_minor' : 'reserved_minor']
                    = MoneyValue::minor($account->balance_minor);
            }
            return array_values($balances);
        });
    }

    public function pay(int $userId, int $orderId, string $requestKey): array
    {
        $key = MoneyValue::key($requestKey);
        return $this->transactions->run($userId, function (array $installation, object $user) use ($userId, $orderId, $key): array {
            [, , $subscriptions] = $this->subscriptions->lockAccount($userId, time(), $installation['installation_id']);
            foreach ($subscriptions as $subscription) { $this->subscriptions->lockHistory($subscription); }
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();
            $terms = OrderPurchaseTerms::query()->where('order_id', $orderId)->lockForUpdate()->first();
            $this->require($order !== null && $terms !== null && (string) $order->user_id === (string) $userId,
                'WALLET_ORDER_MISSING');
            $purchase = $this->grants->resolve($order, $terms);
            $this->require($purchase !== null, 'WALLET_PURCHASE_REQUIRED');
            app(NativeUpgradeCredits::class)->lockSourcesLocked($installation, $order);
            $reservation = DB::table('tx_wallet_order_reservation')->where('order_id', $orderId)->lockForUpdate()->first();
            $this->reservations->lockMoneyAccountsLocked($installation, $userId, $purchase['currency']);
            // Admission can establish an older RR snapshot before the owning user lock is available.
            $allocation = DB::table('tx_order_payment')->where('order_id', $orderId)->sharedLock()->first();
            if ($allocation !== null) {
                $this->require($reservation !== null && $reservation->request_key === $key, 'WALLET_IDEMPOTENCY_CONFLICT');
                return ['type' => -1, 'data' => true, 'payment' => $this->verifyWinningPaymentLocked($installation, $userId, $order, $terms)];
            }
            $acceptedAt = time();
            app(NativeUpgradeCredits::class)->assertPayableLocked($installation, $order, $acceptedAt);
            $this->require(!(bool) ($user->banned ?? false), 'WALLET_ACCOUNT_UNAVAILABLE');
            $this->reservations->reserveLocked($installation, $userId, $order, $terms,
                MoneyValue::minor($purchase['price_minor'], true), $key);
            $reservation = $this->reservations->captureLocked($installation, $userId, $order, $terms);
            $payload = [
                'installation_id' => $installation['installation_id'], 'user_id' => (string) $userId,
                'order_id' => (string) $orderId, 'settlement_id' => null, 'receipt_id' => null,
                'wallet_reservation_id' => $reservation['reservation_id'], 'amount_minor' => $reservation['wallet_minor'],
                'currency' => $reservation['currency'],
            ];
            DB::table('tx_order_payment')->insert($payload + ['content_hash' => MoneyValue::hash($payload), 'created_at' => time()]);
            $work = $this->workPayload($installation, $userId, $orderId, $reservation['reservation_id']);
            DB::table('tx_billing_work')->insert($work + [
                'content_hash' => MoneyValue::hash($work), 'status' => 'pending', 'attempts' => 0, 'created_at' => time(),
            ]);
            $order->status = Order::STATUS_PROCESSING;
            $order->paid_at = (int) $reservation['captured_at'];
            $order->saveOrFail();
            app(NativeUpgradeCredits::class)->fulfillAcceptedLocked($installation, $order, $terms, $acceptedAt);
            return ['type' => -1, 'data' => true, 'payment' => $this->verifyWinningPaymentLocked($installation, $userId, $order, $terms)];
        });
    }

    public function verifyWinningPaymentLocked(array $installation, int $userId, Order $order, OrderPurchaseTerms $terms): array
    {
        $this->require(DB::transactionLevel() > 0, 'BILLING_TRANSACTION_REQUIRED');
        $purchase = $this->grants->resolve($order, $terms);
        $this->require($purchase !== null, 'WALLET_PURCHASE_REQUIRED');
        $row = DB::table('tx_wallet_order_reservation')->where('order_id', $order->id)->lockForUpdate()->first();
        $this->reservations->lockMoneyAccountsLocked($installation, $userId, $purchase['currency']);
        $allocation = DB::table('tx_order_payment')->where('order_id', $order->id)->sharedLock()->first();
        $this->require($allocation !== null && $row !== null && $allocation->settlement_id === null && $allocation->receipt_id === null,
            'WALLET_PAYMENT_EVIDENCE_INVALID');
        $reservation = $this->reservations->verifyLocked($installation, $userId, $order, $terms, $row);
        $payload = [
            'installation_id' => $installation['installation_id'], 'user_id' => (string) $userId,
            'order_id' => (string) $order->id, 'settlement_id' => null, 'receipt_id' => null,
            'wallet_reservation_id' => $reservation['reservation_id'], 'amount_minor' => $reservation['wallet_minor'],
            'currency' => $reservation['currency'],
        ];
        $this->require($reservation['status'] === 'captured' && $reservation['channel_minor'] === '0'
            && (string) $order->paid_at === $reservation['captured_at']
            && $order->payment_id === null && $order->callback_no === null, 'WALLET_PAYMENT_EVIDENCE_INVALID');
        $this->verifyFields($allocation, $payload);
        $work = DB::table('tx_billing_work')->where('wallet_reservation_id', $row->id)->lockForUpdate()->first();
        $this->require($work !== null, 'WALLET_PAYMENT_EVIDENCE_INVALID');
        $this->verifyWorkLocked($work, $installation);
        return ['order_payment_id' => (string) $allocation->id, 'work_id' => (string) $work->id,
            'journal_id' => $reservation['capture_journal_id'], 'decision' => 'order_payment',
            'occurred_at' => $reservation['captured_at']] + $payload;
    }

    public function verifyWorkLocked(object $work, array $installation): void
    {
        $this->require(DB::transactionLevel() > 0 && $work->wallet_reservation_id !== null
            && $work->settlement_id === null && $work->receipt_id === null
            && in_array($work->status, ['pending', 'completed', 'cancelled'], true)
            && ($work->status !== 'pending') === ($work->completed_at !== null), 'WALLET_WORK_EVIDENCE_INVALID');
        $this->verifyFields($work, $this->workPayload($installation, (int) $work->user_id,
            (int) $work->order_id, (string) $work->wallet_reservation_id));
    }

    private function workPayload(array $installation, int $userId, int $orderId, string $reservationId): array
    {
        $identity = ['kind' => 'order.fulfill', 'installation_id' => $installation['installation_id'],
            'order_id' => (string) $orderId, 'wallet_reservation_id' => $reservationId];
        return ['event_key' => MoneyValue::hash($identity)] + $identity + [
            'user_id' => (string) $userId, 'settlement_id' => null, 'receipt_id' => null,
        ];
    }

    private function verifyFields(object $row, array $payload): void
    {
        foreach ($payload as $field => $value) {
            $actual = $row->$field === null ? null : (string) $row->$field;
            $this->require($actual === $value, 'WALLET_PAYMENT_EVIDENCE_INVALID');
        }
        $this->require(hash_equals(MoneyValue::hash($payload), $row->content_hash), 'WALLET_PAYMENT_EVIDENCE_INVALID');
    }

    private function require(bool $condition, string $error): void
    {
        if (!$condition) { throw new BillingError($error); }
    }
}
