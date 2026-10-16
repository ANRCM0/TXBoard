<?php

namespace App\Domains\Subscription\Services;

use App\Domains\Billing\OrderIdempotency;
use App\Domains\Billing\OrderPurchaseTermsSnapshot;
use App\Domains\Billing\PurchasedOrderGrant;
use App\Domains\Installation\InstallationAuthority;
use App\Domains\Installation\NativeRuntimeAdmission;
use App\Models\Order;
use App\Models\OrderPurchaseTerms;
use App\Models\Plan;
use App\Utils\Helper;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Explicit published-offer checkout; not a legacy Plan/period compatibility route. */
final class PublishedOrderService
{
    public const VERSION = 'tx-core.purchase-terms/v2';
    private const AUTHORITY = 'core-subscription/v1';

    public function __construct(
        private readonly PlanPublicationService $catalog,
        private readonly OrderPurchaseTermsSnapshot $snapshots,
        private readonly PurchasedOrderGrant $grants,
        private readonly NativeSubscriptionStore $subscriptions,
    ) {}

    public function create(int $userId, int $offerId, string $idempotencyKey, array $adjustments = []): Order
    {
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('PUBLISHED_ORDER_TRANSACTION_OWNERSHIP_REQUIRED');
        }
        if ($userId <= 0 || $offerId <= 0) {
            throw new RuntimeException('PUBLISHED_ORDER_IDENTITY_INVALID');
        }
        $key = OrderIdempotency::clientKey($idempotencyKey);
        if ($adjustments !== []) {
            throw new RuntimeException('PUBLISHED_ORDER_FINANCIAL_ADJUSTMENT_UNSUPPORTED');
        }
        // Locate immutable ownership before opening the RR transaction. Replays do not
        // need a current Plan; fresh purchases recheck this locator under its Plan lock.
        $locator = DB::table('tx_plan_offer')->useWritePdo()->where('id', $offerId)->first(['plan_id']);
        return DB::transaction(function () use ($userId, $offerId, $key, $locator): Order {
            $installation = app(InstallationAuthority::class)->assertNativeMutation();
            $now = time();
            [$user, $slot, $subscriptions] = $this->subscriptions->lockAccount($userId, $now, $installation['installation_id']);
            if ((bool) $user->banned) {
                throw new RuntimeException('PUBLISHED_ORDER_ACCOUNT_UNAVAILABLE');
            }
            $body = ['schema' => self::VERSION, 'offer_id' => (string) $offerId,
                'installation_id' => $installation['installation_id']];
            $order = OrderIdempotency::run($userId, $key, $body, function () use (
                $userId, $offerId, $slot, $subscriptions, $installation, $now, $locator,
            ): Order {
                if ($locator === null) throw new RuntimeException('PUBLICATION_OFFER_MISSING');
                if (!DB::table('tx_plan')->where('id', $locator->plan_id)->lockForUpdate()->first()) {
                    throw new RuntimeException('PUBLICATION_PLAN_MISSING');
                }
                // The first consistent business read must follow the Plan lock, so
                // capacity sees reservations committed by the preceding Plan owner.
                $owner = DB::table('tx_plan_offer')->where('id', $offerId)->first(['plan_id']);
                if ($owner === null || (string) $owner->plan_id !== (string) $locator->plan_id) {
                    throw new RuntimeException('PUBLICATION_OFFER_NOT_PUBLISHED');
                }
                if (Order::query()->where('user_id', $userId)
                    ->whereIn('status', [Order::STATUS_PENDING, Order::STATUS_PROCESSING])->lockForUpdate()->exists()) {
                    throw new RuntimeException('PUBLISHED_ORDER_PENDING_ORDER_EXISTS');
                }
                $current = $slot->subscription_id === null ? null : $subscriptions->firstWhere('id', $slot->subscription_id);
                $hasPaidHistory = false;
                if ($current !== null) {
                    if (!in_array($current->status, ['active', 'suspended'], true)
                        || ($current->expires_at !== null && (int) $current->expires_at <= $now)) {
                        throw new RuntimeException('PUBLISHED_ORDER_SLOT_RELEASE_REQUIRED');
                    }
                    $history = $this->subscriptions->lockHistory($current);
                    $this->subscriptions->verifyHistory($current, $history);
                    foreach ($history['terms'] as $terms) {
                        $source = $this->subscriptions->verifyTerms($terms, $current);
                        $hasPaidHistory = $hasPaidHistory || (string) $source['price_minor'] !== '0';
                    }
                }
                $published = $this->catalog->verifiedOffer($offerId, true, true);
                $offer = $published['offer'];
                if (($installation['runtime_version'] ?? null) === NativeRuntimeAdmission::VERSION
                    && (string) $offer->price_minor !== '0' && $offer->currency !== 'CNY') {
                    throw new RuntimeException('PUBLISHED_ORDER_CURRENCY_UNSUPPORTED');
                }
                $type = $this->orderType($current, $offer);
                if (($installation['runtime_version'] ?? null) === NativeRuntimeAdmission::VERSION
                    && $type === Order::TYPE_UPGRADE && ($hasPaidHistory || (string) $offer->price_minor !== '0')) {
                    // A full-price replacement must not silently discard unimplemented credit.
                    throw new RuntimeException('PUBLISHED_ORDER_UPGRADE_CREDIT_UNAVAILABLE');
                }
                $availability = $published['availability'];
                $flag = in_array($type, [Order::TYPE_RENEWAL, Order::TYPE_RESET_TRAFFIC], true) ? 'renew' : 'sell';
                if (!(bool) $availability->$flag) {
                    throw new RuntimeException('PUBLISHED_ORDER_OFFER_UNAVAILABLE');
                }
                if (in_array($type, [Order::TYPE_NEW_PURCHASE, Order::TYPE_UPGRADE], true)) {
                    $this->assertCapacity((int) $offer->plan_id, $availability->capacity_limit,
                        $installation['installation_id'], $now);
                }
                if ((int) $offer->price_minor > 2147483647) {
                    throw new RuntimeException('PUBLISHED_ORDER_PRICE_OUT_OF_RANGE');
                }
                $created = Order::query()->create([
                    'user_id' => $userId, 'plan_id' => (int) $offer->plan_id, 'period' => $offer->offer_code,
                    'type' => $type, 'status' => Order::STATUS_PENDING, 'trade_no' => Helper::generateOrderNo(),
                    'total_amount' => (int) $offer->price_minor, 'purchase_terms_version' => self::VERSION,
                    'balance_amount' => 0, 'discount_amount' => 0, 'surplus_amount' => 0, 'surplus_credit' => 0,
                    'commission_balance' => 0, 'coupon_id' => null, 'invite_user_id' => null,
                    'surplus_order_ids' => null,
                ]);
                $this->snapshots->capturePublished($created, $published);
                return $created;
            }, permanent: true);
            // A successful idempotency claim is not permission to return damaged evidence.
            $grant = $this->grants->resolve($order,
                OrderPurchaseTerms::query()->where('order_id', $order->id)->first());
            if ($order->purchase_terms_version !== self::VERSION || $grant === null
                || $grant['catalog']['offer_id'] !== (string) $offerId) {
                throw new RuntimeException('PUBLISHED_ORDER_REPLAY_INVALID');
            }
            return $order;
        }, 3);
    }

    /** Payment/cancellation transitions share the aggregate lock order with fulfillment. */
    public function transitionOrder(int $orderId, Closure $transition): mixed
    {
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('PUBLISHED_ORDER_TRANSACTION_OWNERSHIP_REQUIRED');
        }
        $locator = Order::query()->useWritePdo()->find($orderId);
        if ($locator === null) throw new RuntimeException('PUBLISHED_ORDER_MISSING');
        return DB::transaction(function () use ($orderId, $locator, $transition): mixed {
            $installation = app(InstallationAuthority::class)->assertNativeMutation();
            $this->subscriptions->lockAccount((int) $locator->user_id, time(), $installation['installation_id']);
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();
            if ($order === null || (string) $order->user_id !== (string) $locator->user_id
                || $order->purchase_terms_version !== self::VERSION) {
                throw new RuntimeException('PUBLISHED_ORDER_IDENTITY_INVALID');
            }
            $purchase = OrderPurchaseTerms::query()->where('order_id', $orderId)->lockForUpdate()->first();
            if ($this->grants->resolve($order, $purchase) === null) {
                throw new RuntimeException('PUBLISHED_ORDER_PURCHASE_REQUIRED');
            }
            return $transition($order, $purchase, $installation);
        }, 3);
    }

    private function orderType(?object $current, object $offer): int
    {
        if ($offer->offer_code === Plan::PERIOD_RESET_TRAFFIC) {
            if (!$current || (string) $current->plan_id !== (string) $offer->plan_id) {
                throw new RuntimeException('PUBLISHED_ORDER_RESET_TARGET_INVALID');
            }
            return Order::TYPE_RESET_TRAFFIC;
        }
        if ($current === null) {
            return Order::TYPE_NEW_PURCHASE;
        }
        return (string) $current->plan_id === (string) $offer->plan_id
            ? Order::TYPE_RENEWAL : Order::TYPE_UPGRADE;
    }

    private function assertCapacity(int $planId, mixed $limit, string $installationId, int $now): void
    {
        if ($limit === null) {
            return;
        }
        $occupied = DB::table('tx_subscription')->where('plan_id', $planId)
            ->where('authority_version', self::AUTHORITY)->where('installation_id', $installationId)
            ->whereIn('status', ['active', 'suspended'])
            ->where(static fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', $now))->count();
        // The Plan lock also serializes pending native reservations across different buyers.
        $reserved = Order::query()->where('plan_id', $planId)->where('purchase_terms_version', self::VERSION)
            ->whereIn('status', [Order::STATUS_PENDING, Order::STATUS_PROCESSING])
            ->whereIn('type', [Order::TYPE_NEW_PURCHASE, Order::TYPE_UPGRADE])->count();
        if ($occupied + $reserved >= (int) $limit) {
            throw new RuntimeException('PUBLISHED_ORDER_CAPACITY_EXHAUSTED');
        }
    }
}
