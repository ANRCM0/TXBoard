<?php

namespace App\Domains\Subscription\Services;

use App\Domains\Billing\PurchasedOrderGrant;
use App\Domains\Billing\Internal\WinningPaymentProofs;
use App\Domains\Installation\InstallationAuthority;
use App\Domains\Installation\NativeRuntimeAdmission;
use App\Domains\Subscription\Contracts\OrderFulfillment;
use App\Domains\Subscription\Internal\LockedOrderFulfillment;
use App\Models\Order;
use App\Models\OrderPurchaseTerms;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Native fulfillment never calls legacy User, wallet, reset or commission writers. */
final class SubscriptionFulfillmentService implements OrderFulfillment, LockedOrderFulfillment
{
    public function __construct(
        private NativeSubscriptionStore $store,
        private PurchasedOrderGrant $grants,
        private NativeSubscriptionCalendar $calendar,
    ) {}

    public function fulfill(int $orderId, ?int $now = null): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new DomainException('NATIVE_OUTER_TRANSACTION_UNSUPPORTED');
        }
        SubscriptionTermsTimeline::decimal($orderId, true);
        $now = SubscriptionTermsTimeline::timestamp($now ?? time());
        // The locator is outside the fresh transaction so it cannot freeze a stale RR view.
        // Its identity is untrusted until rechecked under the account-first locks below.
        $locator = DB::table('tx_order')->useWritePdo()->where('id', $orderId)->first(['id', 'user_id']);
        if (!$locator) {
            throw new DomainException('NATIVE_ORDER_MISSING');
        }
        $result = DB::transaction(function () use ($orderId, $locator, $now): array {
            $installation = app(InstallationAuthority::class)->assertNativeMutation();
            [$user, $slot, $subscriptions] = $this->store->lockAccount((int) $locator->user_id, $now, $installation['installation_id']);
            $histories = [];
            foreach ($subscriptions as $existing) {
                $histories[(string) $existing->id] = $this->store->lockHistory($existing);
            }
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();
            if (!$order || (string) $order->user_id !== (string) $locator->user_id) {
                throw new DomainException('NATIVE_ORDER_IDENTITY_CHANGED');
            }
            $purchase = OrderPurchaseTerms::query()->where('order_id', $orderId)->lockForUpdate()->first();
            return $this->applyLocked($installation, $user, $slot, $subscriptions, $histories, $order, $purchase, $now);
        }, 3);
        app(NativeCredentialSynchronization::class)->synchronize((int) $locator->user_id, (int) $result['subscription_id']);
        return $result;
    }

    /** SQL-only application. The caller owns admission/account/history/order and the complete financial lock union. */
    public function applyLocked(array $installation, object $user, object $slot, \Illuminate\Support\Collection $subscriptions,
        array $histories, Order $order, ?OrderPurchaseTerms $purchase, int $now): array
    {
        if (DB::transactionLevel() < 1 || (string) $order->user_id !== (string) $user->id) {
            throw new DomainException('NATIVE_TRANSACTION_CONTEXT_REQUIRED');
        }
        $orderId = (int) $order->id;
        if ($order->purchase_terms_version !== 'tx-core.purchase-terms/v2' || !$purchase) {
            throw new DomainException('NATIVE_ORDER_PURCHASE_REQUIRED');
        }
        $this->assertNoFinancialAdjustments($order);
        $key = 'order.fulfill/'.$orderId;
        $request = [
            'schema' => NativeSubscriptionStore::OPERATION, 'user_id' => (string) $user->id,
            'business_key' => $key, 'kind' => 'order.fulfill', 'order_id' => (string) $orderId,
            'purchase_hash' => $purchase->content_hash, 'installation_id' => $installation['installation_id'],
        ];
        $receipts = DB::table('tx_subscription_operation')->where(function ($query) use ($user, $key, $orderId): void {
            $query->where('order_id', $orderId)->orWhere(fn ($q) => $q->where('user_id', $user->id)->where('business_key', $key));
        })->orderBy('id')->lockForUpdate()->get();
        if ($receipts->count() > 1) {
            throw new DomainException('NATIVE_OPERATION_DAMAGED');
        }
        $operation = $receipts->first();
        if ($operation) {
            $subscription = $subscriptions->firstWhere('id', $operation->subscription_id);
            if (!$subscription || (int) $order->status !== Order::STATUS_COMPLETED) {
                throw new DomainException('NATIVE_ORDER_RECEIPT_INVALID');
            }
            $history = $histories[(string) $subscription->id];
            $this->nativeGrant($order, $purchase);
            $this->verifyPayment($installation, (int) $user->id, $order, $purchase);
            $this->store->verifyHistory($subscription, $history, currentRead: true);
            return $this->store->replay($operation, $request, $subscription, currentRead: true);
        }
        if ((int) $order->status !== Order::STATUS_PROCESSING) {
            throw new DomainException('NATIVE_ORDER_NOT_PROCESSING');
        }
        if ((bool) ($user->banned ?? false)) {
            throw new DomainException('NATIVE_ACCOUNT_BANNED');
        }
        $subscription = $slot->subscription_id === null ? null : $subscriptions->firstWhere('id', $slot->subscription_id);
        $history = $subscription === null ? null : $histories[(string) $subscription->id];
        if ($subscription !== null) {
            // Must precede grant/catalog/source verification, which performs ordinary reads.
            $this->store->protectAcceptedUsage((string) $subscription->id, $now);
        }
        $grant = $this->nativeGrant($order, $purchase);
        $this->verifyPayment($installation, (int) $user->id, $order, $purchase);
        if ($subscription === null) {
            if ((int) $order->type !== Order::TYPE_NEW_PURCHASE || $grant['purchase_kind'] === 'traffic_reset') {
                throw new DomainException('NATIVE_ORDER_TARGET_MISSING');
            }
            $result = $this->create($user, $slot, $grant, $installation['installation_id'], $now);
        } else {
            if ((int) $order->type === Order::TYPE_NEW_PURCHASE) {
                throw new DomainException($subscription->expires_at !== null && (int) $subscription->expires_at <= $now
                    ? 'NATIVE_SLOT_REQUIRES_EXPIRY' : 'NATIVE_SLOT_OCCUPIED');
            }
            $current = $this->store->verifyHistory($subscription, $history, currentRead: true);
            $result = $this->advance($subscription, $current['terms'], $current['period'], $grant, $key, $now);
        }
        if (DB::table('tx_order')->where('id', $orderId)->where('status', Order::STATUS_PROCESSING)
            ->update(['status' => Order::STATUS_COMPLETED, 'updated_at' => $now]) !== 1) {
            throw new DomainException('NATIVE_ORDER_STATUS_CONFLICT');
        }
        $this->store->record($request, $result, $now);
        app(NativeCredentialSynchronization::class)->stageLocked((int) $user->id, (int) $result['subscription_id']);
        return $result;
    }

    private function create(object $user, object $slot, array $grant, string $installationId, int $now): array
    {
        $row = [
            'user_id' => $user->id, 'plan_id' => (int) $grant['plan_id'], 'status' => 'active',
            'current_terms_id' => null, 'starts_at' => $now, 'expires_at' => $this->calendar->expiry($grant, $now),
            'ended_at' => null, 'suspended_reason' => null, 'authority_version' => NativeSubscriptionStore::AUTHORITY,
            'installation_id' => $installationId, 'row_version' => '1', 'created_at' => $now, 'updated_at' => $now,
        ];
        $row['id'] = DB::table('tx_subscription')->insertGetId($row);
        $subscription = (object) $row;
        $terms = $this->store->appendTerms($subscription, $grant, $now, $subscription->expires_at);
        DB::table('tx_subscription')->where('id', $subscription->id)->whereNull('current_terms_id')->update(['current_terms_id' => $terms->id]);
        $subscription->current_terms_id = $terms->id;
        $period = $this->store->openPeriod($subscription, $terms, $now);
        $this->store->replaceSlot($slot, (string) $subscription->id, $now);
        return $this->store->result($subscription, $period, $now);
    }

    private function advance(object $subscription, object $previous, ?object $period, array $grant, string $key, int $now): array
    {
        if (!in_array($subscription->status, ['active', 'suspended'], true) || $subscription->ended_at !== null
            || (int) $subscription->starts_at > $now
            || ($subscription->expires_at !== null && (int) $subscription->expires_at <= $now)) {
            throw new DomainException('NATIVE_SUBSCRIPTION_INACTIVE');
        }
        if (!$period || $period->status !== 'open' || $now <= (int) $period->starts_at
            || ($period->ends_at !== null && (int) $period->ends_at < $now)) {
            throw new DomainException('NATIVE_PERIOD_BOUNDARY_INVALID');
        }
        $type = $grant['order_type'];
        $samePlan = (string) $subscription->plan_id === $grant['plan_id'];
        if ((in_array($type, [Order::TYPE_RENEWAL, Order::TYPE_RESET_TRAFFIC], true) && !$samePlan)
            || ($type === Order::TYPE_UPGRADE && $samePlan)) {
            throw new DomainException('NATIVE_ORDER_TARGET_CHANGED');
        }
        $reset = $grant['purchase_kind'] === 'traffic_reset' || $grant['purchase_kind'] === 'perpetual' || $grant['open_event_id'] === 1;
        if (!$reset && $period->ends_at !== null && (int) $period->ends_at === $now) {
            throw new DomainException('NATIVE_PERIOD_RESET_REQUIRED');
        }
        $expiry = $grant['purchase_kind'] === 'traffic_reset'
            ? ($subscription->expires_at === null ? null : (int) $subscription->expires_at)
            : $this->calendar->expiry($grant, $type === Order::TYPE_RENEWAL && $subscription->expires_at !== null
                ? (int) $subscription->expires_at : $now);
        $terms = $this->store->appendTerms($subscription, $grant, $now, $expiry, $previous);
        $transition = $this->store->appendTransition($subscription, $previous, $terms, $key, $now);
        $subscription = $this->store->updateSubscription($subscription, [
            'current_terms_id' => $terms->id, 'plan_id' => (int) $grant['plan_id'], 'expires_at' => $expiry,
        ], $now);
        $previousPeriod = null;
        $adjustment = null;
        if ($reset) {
            $previousPeriod = (string) $period->id;
            $this->store->closePeriod($period, $now, $now);
            $period = $this->store->openPeriod($subscription, $terms, $now, $period);
        } else {
            $adjustment = $this->store->adjustQuota($period, $terms, $transition, $key, $now);
            $period = $this->store->updatePeriod($period, [
                'quota_bytes' => $terms->quota_bytes,
                'ends_at' => $this->calendar->periodEnd($period->next_reset_at === null ? null : (int) $period->next_reset_at, $expiry),
            ], $now);
        }
        return $this->store->result($subscription, $period, $now, [
            'transition_id' => $transition, 'previous_period_id' => $previousPeriod, 'quota_adjustment_id' => $adjustment,
        ]);
    }

    private function nativeGrant(Order $order, OrderPurchaseTerms $purchase): array
    {
        $grant = $this->grants->resolve($order, $purchase);
        if (($grant['schema'] ?? null) !== 'tx-core.purchase-terms/v2' || ($grant['quota_bytes'] ?? null) === null) {
            throw new DomainException('NATIVE_ORDER_PURCHASE_REQUIRED');
        }
        return $grant;
    }

    private function verifyPayment(array $installation, int $userId, Order $order, OrderPurchaseTerms $purchase): void
    {
        if (($installation['runtime_version'] ?? null) === NativeRuntimeAdmission::VERSION
            && (string) $order->total_amount !== '0') {
            app(WinningPaymentProofs::class)->verifyWinningPaymentLocked($installation, $userId, $order, $purchase);
        }
    }

    private function assertNoFinancialAdjustments(Order $order): void
    {
        foreach (['balance_amount', 'surplus_amount', 'surplus_credit', 'discount_amount', 'commission_balance',
            'actual_commission_balance', 'commission_rate', 'commission_status', 'commission_auto_check',
            'coupon_id', 'invite_user_id'] as $field) {
            $value = $order->getRawOriginal($field);
            if ($value !== null && $value !== 0 && $value !== '0') {
                throw new DomainException('NATIVE_ORDER_FINANCIAL_ADJUSTMENT_UNSUPPORTED');
            }
        }
        if ($order->surplus_order_ids !== null && $order->surplus_order_ids !== []) {
            throw new DomainException('NATIVE_ORDER_FINANCIAL_ADJUSTMENT_UNSUPPORTED');
        }
    }
}
