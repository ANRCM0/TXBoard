<?php

namespace App\Domains\Subscription\Services;

use App\Domains\Billing\OrderIdempotency;
use App\Domains\Billing\OrderPurchaseTermsSnapshot;
use App\Domains\Billing\PurchasedOrderGrant;
use App\Domains\Billing\NativeUpgradeCredits;
use App\Domains\Billing\NativeCustomerPolicies;
use App\Domains\Billing\Internal\LockedCouponDiscounts;
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
        $couponCode = null;
        if ($adjustments !== [] && (array_keys($adjustments) !== ['coupon_code']
            || !is_string($adjustments['coupon_code'])
            || preg_match('/\A[A-Z0-9][A-Z0-9_-]{7,63}\z/Di', $adjustments['coupon_code']) !== 1)) {
            throw new RuntimeException('PUBLISHED_ORDER_FINANCIAL_ADJUSTMENT_UNSUPPORTED');
        }
        if ($adjustments !== []) $couponCode = strtoupper($adjustments['coupon_code']);
        // Locate immutable ownership before opening the RR transaction. Replays do not
        // need a current Plan; fresh purchases recheck this locator under its Plan lock.
        $locator = DB::table('tx_plan_offer')->useWritePdo()->where('id', $offerId)->first(['plan_id']);
        $referralLocator = DB::table('tx_native_referral')->useWritePdo()->where('user_id', $userId)->first(['sponsor_id']);
        return DB::transaction(function () use ($userId, $offerId, $key, $locator, $couponCode, $referralLocator): Order {
            $installation = app(InstallationAuthority::class)->assertNativeMutation();
            $now = time();
            $accountIds = [$userId]; if ($referralLocator !== null) $accountIds[] = (int) $referralLocator->sponsor_id;
            $accountIds = array_unique($accountIds); sort($accountIds, SORT_NUMERIC);
            foreach ($accountIds as $id) if (!DB::table('tx_user')->where('id', $id)->lockForUpdate()->exists()) throw new RuntimeException('PUBLISHED_ORDER_ACCOUNT_UNAVAILABLE');
            $referral = app(\App\Domains\Identity\Internal\ReferralSnapshot::class)->forAccountLocked($installation['installation_id'], $userId);
            if (($referral['sponsor_id'] ?? null) !== ($referralLocator === null ? null : (string) $referralLocator->sponsor_id)) throw new RuntimeException('PUBLISHED_ORDER_REFERRAL_CHANGED');
            [$user, $slot, $subscriptions] = $this->subscriptions->lockAccount($userId, $now, $installation['installation_id']);
            if ($couponCode !== null) app(\App\Domains\Identity\Internal\CurrentSessionAuthorization::class)->assertCurrentLocked($userId);
            if ((bool) $user->banned) {
                throw new RuntimeException('PUBLISHED_ORDER_ACCOUNT_UNAVAILABLE');
            }
            $body = ['schema' => self::VERSION, 'offer_id' => (string) $offerId,
                'installation_id' => $installation['installation_id']];
            if ($couponCode !== null) $body['coupon_code'] = $couponCode;
            $order = OrderIdempotency::run($userId, $key, $body, function () use (
                $userId, $offerId, $slot, $subscriptions, $installation, $now, $locator, $couponCode,
            ): Order {
                try {
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
                    $history = null;
                    if ($current !== null) {
                        if (!in_array($current->status, ['active', 'suspended'], true)
                            || ($current->expires_at !== null && (int) $current->expires_at <= $now)) {
                            throw new RuntimeException('PUBLISHED_ORDER_SLOT_RELEASE_REQUIRED');
                        }
                        $history = $this->subscriptions->lockHistory($current);
                        $this->subscriptions->verifyHistory($current, $history, currentRead: true);
                    }
                    $published = $this->catalog->verifiedOffer($offerId, true, true);
                    $offer = $published['offer'];
                    if (($installation['runtime_version'] ?? null) === NativeRuntimeAdmission::VERSION
                        && (string) $offer->price_minor !== '0' && $offer->currency !== 'CNY') {
                        throw new RuntimeException('PUBLISHED_ORDER_CURRENCY_UNSUPPORTED');
                    }
                    $type = $this->orderType($current, $offer);
                    if ($couponCode !== null && $type === Order::TYPE_UPGRADE) {
                        throw new RuntimeException('PUBLISHED_ORDER_COUPON_UPGRADE_UNSUPPORTED');
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
                    $personal = app(NativeCustomerPolicies::class)->quotePriceLocked($installation, $userId, $offer, $now);
                    $discountedOffer = clone $offer; $discountedOffer->price_minor = $personal['discounted_minor'];
                    $credit = ($installation['runtime_version'] ?? null) === NativeRuntimeAdmission::VERSION
                        && $type === Order::TYPE_UPGRADE
                        ? app(NativeUpgradeCredits::class)->quoteLocked($installation, $current, $history, $discountedOffer, $now) : null;
                    $coupon = $couponCode === null ? null : app(LockedCouponDiscounts::class)->quoteLocked(
                        $userId, $offerId, $offer->currency, (string) $offer->price_minor, $couponCode, $now, $installation['installation_id']);
                    $net = $credit['net_minor'] ?? bcsub($personal['discounted_minor'], $coupon['discount_minor'] ?? '0', 0);
                    if (bccomp($net, '0', 0) < 0) $net = '0';
                    $created = Order::query()->create([
                        'user_id' => $userId, 'plan_id' => (int) $offer->plan_id, 'period' => $offer->offer_code,
                        'type' => $type, 'status' => Order::STATUS_PENDING, 'trade_no' => Helper::generateOrderNo(),
                        'total_amount' => (int) $net, 'purchase_terms_version' => self::VERSION,
                        'balance_amount' => 0, 'discount_amount' => 0, 'surplus_amount' => 0, 'surplus_credit' => 0,
                        'commission_balance' => 0, 'coupon_id' => null, 'invite_user_id' => null,
                        'surplus_order_ids' => null,
                    ]);
                    if ($credit !== null) { app(NativeUpgradeCredits::class)->persistLocked($created, $credit); }
                    if ($coupon !== null) app(LockedCouponDiscounts::class)->persistLocked((int) $created->id, $coupon);
                    app(NativeCustomerPolicies::class)->persistPriceLocked($created, $personal, $coupon, $credit);
                    $purchase = $this->snapshots->capturePublished($created, $published);
                    app(\App\Domains\Billing\Internal\CommissionTerms::class)->captureLocked($installation, $created, $purchase);
                    app(\App\Domains\Billing\NativeOrderEventJournal::class)->recordLocked($created, 'created', (int) $created->created_at);
                    return $created;
                } catch (RuntimeException $error) {
                    // Only a failure inside a fresh creation callback proves no prior purchase committed.
                    if (in_array($error->getMessage(), ['PUBLICATION_OFFER_MISSING', 'PUBLICATION_PLAN_MISSING',
                        'PUBLICATION_OFFER_NOT_PUBLISHED', 'PUBLISHED_ORDER_PENDING_ORDER_EXISTS',
                        'PUBLISHED_ORDER_SLOT_RELEASE_REQUIRED', 'PUBLISHED_ORDER_CURRENCY_UNSUPPORTED',
                        'PUBLISHED_ORDER_OFFER_UNAVAILABLE', 'PUBLISHED_ORDER_PRICE_OUT_OF_RANGE',
                        'PUBLISHED_ORDER_CAPACITY_EXHAUSTED'], true)) {
                        throw new \App\Domains\Subscription\Contracts\PurchaseRejected($error->getMessage());
                    }
                    throw $error;
                }
            }, permanent: true);
            // A successful idempotency claim is not permission to return damaged evidence.
            $purchase = OrderPurchaseTerms::query()->where('order_id', $order->id)->sharedLock()->first();
            $grant = $this->grants->resolve($order, $purchase);
            if ($order->purchase_terms_version !== self::VERSION || $grant === null
                || $grant['catalog']['offer_id'] !== (string) $offerId) {
                throw new RuntimeException('PUBLISHED_ORDER_REPLAY_INVALID');
            }
            return $order->setRelation('purchaseTerms', $purchase);
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
            [, , $subscriptions] = $this->subscriptions->lockAccount((int) $locator->user_id, time(), $installation['installation_id']);
            foreach ($subscriptions as $subscription) { $this->subscriptions->lockHistory($subscription); }
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
