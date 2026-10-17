<?php

namespace App\Domains\Billing;

use App\Domains\Billing\Internal\WinningPaymentProofs;
use App\Domains\Subscription\Internal\LockedOrderFulfillment;
use App\Domains\Subscription\Services\NativeSubscriptionStore;
use App\Domains\UsageV2\NativeUsageReservations;
use App\Models\Order;
use App\Models\OrderPurchaseTerms;
use Illuminate\Support\Facades\DB;

/** Source-bound, noncash price credits; original financial journals are never rewritten. */
final class NativeUpgradeCredits
{
    public const SCHEMA = 'native-upgrade-credit/1';

    public function quoteLocked(array $installation, object $subscription, array $history, object $offer, int $now): array
    {
        $this->transaction();
        $store = app(NativeSubscriptionStore::class);
        $current = $store->verifyHistory($subscription, $history, currentRead: true);
        $sources = [];
        foreach ($history['terms'] as $terms) {
            $grant = $store->verifyTerms($terms, $subscription, currentRead: true);
            if ($grant['order_type'] === Order::TYPE_UPGRADE) { $sources = []; }
            if ($grant['purchase_kind'] !== 'traffic_reset') {
                $sources[] = ['terms' => $terms, 'grant' => $grant];
            }
        }
        $orders = [];
        foreach (collect($sources)->sortBy(fn ($source) => (int) $source['grant']['order_id']) as $source) {
            $id = (int) $source['grant']['order_id'];
            $orders[$id] = [
                Order::query()->whereKey($id)->lockForUpdate()->firstOrFail(),
                OrderPurchaseTerms::query()->where('order_id', $id)->lockForUpdate()->firstOrFail(),
            ];
        }
        $this->lockSourceMoney($installation, (int) $subscription->user_id, $offer->currency, $orders);
        $components = [];
        $left = MoneyValue::minor($offer->price_minor);
        $previousExpiry = null;
        foreach ($history['terms'] as $terms) {
            $source = collect($sources)->first(fn ($source) => (string) $source['terms']->id === (string) $terms->id);
            if ($source !== null) {
                $grant = $source['grant'];
                [$order, $purchase] = $orders[(int) $grant['order_id']];
                $cash = MoneyValue::minor($grant['price_minor']);
                $carry = MoneyValue::minor($grant['upgrade_credit']['credit_minor'] ?? '0');
                $from = $grant['order_type'] === Order::TYPE_RENEWAL ? $previousExpiry : (int) $terms->effective_from;
                $until = $terms->effective_until === null ? null : (int) $terms->effective_until;
                $this->require($from !== null && ($until === null || $until > $from), 'UPGRADE_SOURCE_COVERAGE_INVALID');
                if ($cash !== '0' || $carry !== '0') {
                    $this->require($grant['currency'] === $offer->currency, 'UPGRADE_SOURCE_CURRENCY_MISMATCH');
                    $operation = DB::table('tx_subscription_operation')->where('order_id', $order->id)->sharedLock()->first();
                    $this->require($operation !== null, 'UPGRADE_SOURCE_NOT_FULFILLED');
                    $store->replay($operation, [
                        'schema' => NativeSubscriptionStore::OPERATION, 'user_id' => (string) $order->user_id,
                        'business_key' => 'order.fulfill/'.$order->id, 'kind' => 'order.fulfill',
                        'order_id' => (string) $order->id, 'purchase_hash' => $purchase->content_hash,
                        'installation_id' => $installation['installation_id'],
                    ], $subscription, currentRead: true);
                    $allocationId = null;
                    $refunded = '0';
                    $used = '0';
                    if ($cash !== '0') {
                        $paid = app(WinningPaymentProofs::class)->verifyWinningPaymentLocked(
                            $installation, (int) $order->user_id, $order, $purchase);
                        $allocationId = $paid['order_payment_id'];
                        $allocation = DB::table('tx_order_payment')->where('id', $allocationId)->sharedLock()->first();
                        $this->require($allocation !== null && (string) $allocation->amount_minor === $cash, 'UPGRADE_SOURCE_PAYMENT_INVALID');
                        $refunded = app(NativeRefundService::class)->refundedForPaymentLocked(
                            $installation, (int) $order->user_id, (int) $allocationId);
                        $used = $this->cashClaimsLocked($installation, (int) $order->user_id, (int) $allocationId);
                    }
                    $available = bcsub(bcsub($cash, $refunded, 0), $used, 0);
                    MoneyValue::minor($available);
                    [$numerator, $denominator] = $this->ratio($history, (int) $from, $until, $grant, $now);
                    $carryEligible = $this->prorate($carry, $numerator, $denominator);
                    $cashEligible = $this->prorate($available, $numerator, $denominator);
                    $carryApplied = $this->minimum($left, $carryEligible);
                    $left = bcsub($left, $carryApplied, 0);
                    $cashApplied = $this->minimum($left, $cashEligible);
                    $left = bcsub($left, $cashApplied, 0);
                    if ($carryApplied !== '0' || $cashApplied !== '0') {
                        $components[] = [
                            'terms_id' => (string) $terms->id, 'order_id' => (string) $order->id,
                            'purchase_hash' => $purchase->content_hash, 'order_payment_id' => $allocationId,
                            'cash_minor' => $cash, 'refunded_minor' => $refunded, 'used_minor' => $used,
                            'carry_minor' => $carry, 'coverage_from' => (string) $from,
                            'coverage_until' => $until === null ? null : (string) $until,
                            'numerator' => $numerator, 'denominator' => $denominator,
                            'cash_applied_minor' => $cashApplied, 'carry_applied_minor' => $carryApplied,
                        ];
                    }
                }
            }
            if ($terms->effective_until !== null) { $previousExpiry = (int) $terms->effective_until; }
        }
        $credit = bcsub((string) $offer->price_minor, $left, 0);
        $expiry = min($now + 7200, $subscription->expires_at === null ? PHP_INT_MAX : (int) $subscription->expires_at,
            $current['period']->ends_at === null ? PHP_INT_MAX : (int) $current['period']->ends_at);
        $this->require($expiry > $now, 'UPGRADE_SOURCE_EXPIRED');
        return [
            'schema' => self::SCHEMA, 'installation_id' => $installation['installation_id'],
            'user_id' => (string) $subscription->user_id, 'subscription_id' => (string) $subscription->id,
            'terms_id' => (string) $current['terms']->id, 'period_id' => (string) $current['period']->id,
            'source_version' => (string) $subscription->row_version,
            'period_starts_at' => (string) $current['period']->starts_at,
            'period_ends_at' => $current['period']->ends_at === null ? null : (string) $current['period']->ends_at,
            'offer_id' => (string) $offer->id, 'currency' => $offer->currency,
            'list_minor' => (string) $offer->price_minor, 'credit_minor' => $credit, 'net_minor' => $left,
            'quoted_at' => (string) $now, 'expires_at' => (string) $expiry, 'sources' => $components,
        ];
    }

    public function persistLocked(Order $order, array $quote): void
    {
        $this->transaction();
        $quote['order_id'] = (string) $order->id;
        $row = array_intersect_key($quote, array_flip(['installation_id', 'user_id', 'order_id', 'subscription_id',
            'terms_id', 'period_id', 'offer_id', 'currency', 'list_minor', 'credit_minor', 'net_minor', 'quoted_at', 'expires_at']));
        $id = DB::table('tx_order_upgrade_credit')->insertGetId($row + [
            'quote_json' => json_encode($quote, JSON_THROW_ON_ERROR), 'content_hash' => MoneyValue::hash($quote),
        ]);
        foreach ($quote['sources'] as $source) {
            $this->require((int) $source['order_id'] < (int) $order->id, 'UPGRADE_SOURCE_ORDER_INVALID');
            if ($source['cash_applied_minor'] !== '0') {
                DB::table('tx_upgrade_credit_source')->insert([
                    'quote_id' => $id, 'order_payment_id' => $source['order_payment_id'],
                    'amount_minor' => $source['cash_applied_minor'],
                ]);
            }
        }
    }

    /** Local immutable verification only: do not recurse into the source's current financial state. */
    public function binding(Order $order, object $offer): array
    {
        $row = DB::table('tx_order_upgrade_credit')->useWritePdo()->where('order_id', $order->id)->sharedLock()->first();
        $this->require($row !== null, 'UPGRADE_QUOTE_REQUIRED');
        $quote = $this->verify($row);
        $this->require((int) $order->type === Order::TYPE_UPGRADE && $quote['user_id'] === (string) $order->user_id
            && $quote['offer_id'] === (string) $offer->id && $quote['currency'] === $offer->currency
            && $quote['list_minor'] === (string) $offer->price_minor
            && $quote['net_minor'] === (string) $order->total_amount, 'UPGRADE_QUOTE_BINDING_INVALID');
        return ['quote_id' => (string) $row->id, 'content_hash' => $row->content_hash, 'credit_minor' => $quote['credit_minor']];
    }

    /** Caller holds the owning account. Normal usage counters are deliberately not a fingerprint. */
    public function payableLocked(array $installation, Order $order, int $now): bool
    {
        $this->transaction();
        $row = DB::table('tx_order_upgrade_credit')->where('order_id', $order->id)->sharedLock()->first();
        if ($row === null) {
            $this->require((int) $order->type !== Order::TYPE_UPGRADE, 'UPGRADE_QUOTE_REQUIRED');
            return true;
        }
        $quote = $this->verify($row);
        $this->require($quote['installation_id'] === $installation['installation_id']
            && $quote['user_id'] === (string) $order->user_id && $quote['net_minor'] === (string) $order->total_amount,
            'UPGRADE_QUOTE_BINDING_INVALID');
        $subscription = DB::table('tx_subscription')->where('id', $quote['subscription_id'])->sharedLock()->first();
        $period = DB::table('tx_usage_period')->where('id', $quote['period_id'])->sharedLock()->first();
        $slot = DB::table('tx_subscription_slot')->where('user_id', $order->user_id)->where('slot_no', 1)->sharedLock()->first();
        if ($now < (int) $quote['quoted_at'] || $now >= (int) $quote['expires_at'] || !$subscription || !$period || !$slot
            || (string) $slot->subscription_id !== $quote['subscription_id']
            || (string) $subscription->current_terms_id !== $quote['terms_id']
            || (string) $subscription->row_version !== $quote['source_version']
            || !in_array($subscription->status, ['active', 'suspended'], true) || $subscription->ended_at !== null
            || (string) $period->starts_at !== $quote['period_starts_at']
            || ($period->ends_at === null ? null : (string) $period->ends_at) !== $quote['period_ends_at']
            || $period->status !== 'open') { return false; }
        try {
            app(NativeSubscriptionStore::class)->protectAcceptedUsage($quote['subscription_id'], $now);
        } catch (\DomainException $error) {
            if ($error->getMessage() !== 'NATIVE_ACCEPTED_USAGE_WOULD_BE_REASSIGNED') { throw $error; }
            return false;
        }
        // Current usage is rechecked for volume-priced perpetual sources; no money locks are acquired here.
        foreach ($quote['sources'] as $source) {
            if ($source['coverage_until'] !== null) { continue; }
            $terms = DB::table('tx_subscription_terms')->where('id', $source['terms_id'])->sharedLock()->first();
            $this->require($terms !== null && (string) $terms->subscription_id === $quote['subscription_id'], 'UPGRADE_SOURCE_INVALID');
            $periods = DB::table('tx_usage_period')->where('subscription_id', $subscription->id)
                ->orderBy('id')->limit(1001)->sharedLock()->get();
            $this->require($periods->count() <= 1000, 'UPGRADE_SOURCE_HISTORY_LIMIT');
            [$numerator, $denominator] = $this->volumeRatio($periods, (int) $source['coverage_from'], (string) $terms->quota_bytes);
            $available = bcsub(bcsub($source['cash_minor'], $source['refunded_minor'], 0), $source['used_minor'], 0);
            if (bccomp($source['cash_applied_minor'], $this->prorate($available, $numerator, $denominator), 0) > 0
                || bccomp($source['carry_applied_minor'], $this->prorate($source['carry_minor'], $numerator, $denominator), 0) > 0) {
                return false;
            }
        }
        return true;
    }

    public function assertPayableLocked(array $installation, Order $order, ?int $now = null): void
    {
        $this->require($this->payableLocked($installation, $order, $now ?? time()), 'UPGRADE_QUOTE_STALE');
    }

    /** Acquire source locators before the complete money union, not after target postings. */
    public function lockSourcesLocked(array $installation, Order $order): void
    {
        $this->transaction();
        $row = DB::table('tx_order_upgrade_credit')->where('order_id', $order->id)->sharedLock()->first();
        if ($row === null) { return; }
        $quote = $this->verify($row);
        $orders = [];
        foreach (collect($quote['sources'])->sortBy(fn ($source) => (int) $source['order_id']) as $source) {
            $id = (int) $source['order_id'];
            $original = Order::query()->whereKey($id)->lockForUpdate()->first();
            $purchase = OrderPurchaseTerms::query()->where('order_id', $id)->lockForUpdate()->first();
            $this->require($original !== null && $purchase !== null && (string) $original->user_id === (string) $order->user_id,
                'UPGRADE_SOURCE_INVALID');
            $orders[$id] = [$original, $purchase];
        }
        $this->lockSourceMoney($installation, (int) $order->user_id, $quote['currency'], $orders);
    }

    /** All upgrades commit SQL rights with acceptance; durable work performs postcommit provider I/O. */
    public function fulfillAcceptedLocked(array $installation, Order $order, OrderPurchaseTerms $purchase, ?int $now = null): void
    {
        if ((int) $order->type !== Order::TYPE_UPGRADE) { return; }
        $now ??= time();
        $this->assertPayableLocked($installation, $order, $now);
        $row = DB::table('tx_order_upgrade_credit')->where('order_id', $order->id)->sharedLock()->first();
        if ($row !== null) {
            $quote = $this->verify($row);
            foreach ($quote['sources'] as $source) {
                $original = Order::query()->whereKey($source['order_id'])->sharedLock()->first();
                $originalTerms = OrderPurchaseTerms::query()->where('order_id', $source['order_id'])->sharedLock()->first();
                $this->require($original !== null && $originalTerms !== null
                    && (string) $original->user_id === (string) $order->user_id
                    && (int) $original->status === Order::STATUS_COMPLETED
                    && $source['purchase_hash'] === $originalTerms->content_hash, 'UPGRADE_SOURCE_INVALID');
                $grant = app(PurchasedOrderGrant::class)->resolve($original, $originalTerms);
                $this->require($grant !== null && $grant['price_minor'] === $source['cash_minor']
                    && ($grant['upgrade_credit']['credit_minor'] ?? '0') === $source['carry_minor'], 'UPGRADE_SOURCE_INVALID');
                $terms = DB::table('tx_subscription_terms')->where('id', $source['terms_id'])->sharedLock()->first();
                $this->require($terms !== null && (string) $terms->subscription_id === $quote['subscription_id']
                    && (string) $terms->source_order_id === $source['order_id']
                    && ($terms->effective_until === null ? null : (string) $terms->effective_until) === $source['coverage_until'],
                    'UPGRADE_SOURCE_INVALID');
                $from = (string) $terms->effective_from;
                if ($grant['order_type'] === Order::TYPE_RENEWAL) {
                    $previous = DB::table('tx_subscription_terms')->where('subscription_id', $terms->subscription_id)
                        ->where('version_no', (int) $terms->version_no - 1)->sharedLock()->first();
                    $this->require($previous !== null && $previous->effective_until !== null, 'UPGRADE_SOURCE_INVALID');
                    $from = (string) $previous->effective_until;
                }
                $this->require($from === $source['coverage_from'], 'UPGRADE_SOURCE_INVALID');
                if ($source['cash_minor'] !== '0') {
                    $paid = app(WinningPaymentProofs::class)->verifyWinningPaymentLocked(
                        $installation, (int) $order->user_id, $original, $originalTerms);
                    $this->require($paid['order_payment_id'] === $source['order_payment_id'], 'UPGRADE_SOURCE_INVALID');
                    $refunded = app(NativeRefundService::class)->refundedForPaymentLocked(
                        $installation, (int) $order->user_id, (int) $source['order_payment_id']);
                    $this->assertRefundBudgetLocked($installation, (int) $order->user_id, (int) $source['order_payment_id'], $refunded);
                }
            }
        }
        $store = app(NativeSubscriptionStore::class);
        [$user, $slot, $subscriptions] = $store->lockAccount((int) $order->user_id, $now, $installation['installation_id']);
        $histories = [];
        foreach ($subscriptions as $subscription) { $histories[(string) $subscription->id] = $store->lockHistory($subscription); }
        app(LockedOrderFulfillment::class)->applyLocked(
            $installation, $user, $slot, $subscriptions, $histories, $order, $purchase, $now);
        $order->refresh();
    }

    public function assertRefundBudgetLocked(array $installation, int $userId, int $orderPaymentId, string $refundedTotal): void
    {
        $allocation = DB::table('tx_order_payment')->where('id', $orderPaymentId)->sharedLock()->first();
        $this->require($allocation !== null && $allocation->installation_id === $installation['installation_id']
            && (string) $allocation->user_id === (string) $userId, 'UPGRADE_SOURCE_PAYMENT_INVALID');
        $used = $this->cashClaimsLocked($installation, $userId, $orderPaymentId);
        $this->require(bccomp(MoneyValue::add($used, MoneyValue::minor($refundedTotal)), (string) $allocation->amount_minor, 0) <= 0,
            'REFUND_AMOUNT_RESERVED_FOR_UPGRADE');
    }

    private function cashClaimsLocked(array $installation, int $userId, int $paymentId): string
    {
        $this->transaction();
        // Read the owning quote inventory too: deleting a source-index row must not reopen its cash.
        $quotes = DB::table('tx_order_upgrade_credit')->where('installation_id', $installation['installation_id'])
            ->where('user_id', $userId)->orderBy('id')
            ->limit(1001)->sharedLock()->get();
        $this->require($quotes->count() <= 1000, 'UPGRADE_SOURCE_HISTORY_LIMIT');
        $total = '0';
        foreach ($quotes as $row) {
            $quote = $this->verify($row);
            $this->require($quote['installation_id'] === $installation['installation_id']
                && $quote['user_id'] === (string) $userId, 'UPGRADE_SOURCE_INVALID');
            $source = collect($quote['sources'])->firstWhere('order_payment_id', (string) $paymentId);
            if ($source === null) { continue; }
            $target = Order::query()->whereKey($row->order_id)->sharedLock()->first();
            $this->require($target !== null && (string) $target->user_id === (string) $userId
                && (string) $target->total_amount === $quote['net_minor']
                && (int) $target->type === Order::TYPE_UPGRADE && $target->purchase_terms_version === 'tx-core.purchase-terms/v2'
                && in_array((int) $target->status, [Order::STATUS_PENDING, Order::STATUS_PROCESSING,
                    Order::STATUS_COMPLETED, Order::STATUS_CANCELLED], true), 'UPGRADE_SOURCE_INVALID');
            $purchase = OrderPurchaseTerms::query()->where('order_id', $target->id)->sharedLock()->first();
            $grant = app(PurchasedOrderGrant::class)->resolve($target, $purchase);
            $this->require(($grant['upgrade_credit']['quote_id'] ?? null) === (string) $row->id
                && ($grant['upgrade_credit']['content_hash'] ?? null) === $row->content_hash, 'UPGRADE_SOURCE_INVALID');
            if ((int) $target->status === Order::STATUS_CANCELLED) {
                $this->require(!DB::table('tx_subscription_operation')->where('order_id', $target->id)->sharedLock()->exists(),
                    'UPGRADE_SOURCE_INVALID');
            }
            if ((int) $target->status !== Order::STATUS_CANCELLED) {
                $total = MoneyValue::add($total, $source['cash_applied_minor']);
            }
        }
        return $total;
    }

    private function verify(object $row): array
    {
        try { $quote = json_decode($row->quote_json, true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new BillingError('UPGRADE_QUOTE_INVALID'); }
        $this->require(is_array($quote) && !array_is_list($quote)
            && ($quote['schema'] ?? null) === self::SCHEMA
            && hash_equals(MoneyValue::hash($quote), (string) $row->content_hash), 'UPGRADE_QUOTE_INVALID');
        $this->keys($quote, ['schema', 'installation_id', 'user_id', 'order_id', 'subscription_id', 'terms_id',
            'period_id', 'source_version', 'period_starts_at', 'period_ends_at', 'offer_id', 'currency',
            'list_minor', 'credit_minor', 'net_minor', 'quoted_at', 'expires_at', 'sources']);
        foreach (['installation_id', 'user_id', 'order_id', 'subscription_id', 'terms_id', 'period_id', 'offer_id',
            'currency', 'list_minor', 'credit_minor', 'net_minor', 'quoted_at', 'expires_at'] as $field) {
            $this->require(isset($quote[$field]) && is_string($quote[$field]) && $quote[$field] === (string) $row->$field,
                'UPGRADE_QUOTE_INVALID');
        }
        $this->require(MoneyValue::add(MoneyValue::minor($quote['credit_minor']), MoneyValue::minor($quote['net_minor']))
            === MoneyValue::minor($quote['list_minor']) && (int) $quote['expires_at'] > (int) $quote['quoted_at']
            && (int) $quote['expires_at'] <= (int) $quote['quoted_at'] + 7200
            && is_array($quote['sources'] ?? null) && array_is_list($quote['sources'])
            && count($quote['sources']) <= 1000
            && ($quote['credit_minor'] !== '0' || $quote['sources'] === []), 'UPGRADE_QUOTE_INVALID');
        $total = '0';
        $seen = [];
        $claims = [];
        foreach ($quote['sources'] as $source) {
            $this->require(is_array($source), 'UPGRADE_QUOTE_INVALID');
            $this->keys($source, ['terms_id', 'order_id', 'purchase_hash', 'order_payment_id', 'cash_minor',
                'refunded_minor', 'used_minor', 'carry_minor', 'coverage_from', 'coverage_until',
                'numerator', 'denominator', 'cash_applied_minor', 'carry_applied_minor']);
            $this->require(is_array($source) && isset($source['order_id'], $source['terms_id'], $source['purchase_hash'])
                && is_string($source['order_id']) && is_string($source['terms_id'])
                && !isset($seen[$source['order_id']]) && (int) $source['order_id'] < (int) $quote['order_id'],
                'UPGRADE_QUOTE_INVALID');
            $seen[$source['order_id']] = true;
            $this->require(is_string($source['purchase_hash']) && preg_match('/\A[a-f0-9]{64}\z/D', $source['purchase_hash']) === 1
                && ($source['order_payment_id'] === null || is_string($source['order_payment_id']))
                && ($source['coverage_until'] === null || is_string($source['coverage_until'])), 'UPGRADE_QUOTE_INVALID');
            foreach (['cash_minor', 'refunded_minor', 'used_minor', 'carry_minor', 'numerator', 'denominator',
                'cash_applied_minor', 'carry_applied_minor', 'coverage_from'] as $field) {
                $this->require(is_string($source[$field] ?? null), 'UPGRADE_QUOTE_INVALID');
                MoneyValue::minor($source[$field], in_array($field, ['denominator', 'coverage_from'], true));
            }
            $available = bcsub(bcsub($source['cash_minor'], $source['refunded_minor'], 0), $source['used_minor'], 0);
            $this->require(bccomp($source['numerator'], $source['denominator'], 0) <= 0
                && bccomp($source['cash_applied_minor'], $this->prorate(MoneyValue::minor($available),
                    $source['numerator'], $source['denominator']), 0) <= 0
                && bccomp($source['carry_applied_minor'], $this->prorate($source['carry_minor'],
                    $source['numerator'], $source['denominator']), 0) <= 0, 'UPGRADE_QUOTE_INVALID');
            $total = MoneyValue::add($total, MoneyValue::add($source['cash_applied_minor'], $source['carry_applied_minor']));
            if ($source['cash_applied_minor'] !== '0') {
                $id = MoneyValue::minor($source['order_payment_id'], true);
                $this->require(!isset($claims[$id]), 'UPGRADE_QUOTE_INVALID');
                $claims[$id] = $source['cash_applied_minor'];
            }
        }
        $this->require($total === $quote['credit_minor'], 'UPGRADE_QUOTE_INVALID');
        $stored = DB::table('tx_upgrade_credit_source')->where('quote_id', $row->id)->orderBy('id')->sharedLock()->get();
        $this->require($stored->count() === count($claims), 'UPGRADE_SOURCE_INVALID');
        foreach ($stored as $claim) {
            $this->require(($claims[(string) $claim->order_payment_id] ?? null) === (string) $claim->amount_minor,
                'UPGRADE_SOURCE_INVALID');
        }
        return $quote;
    }

    private function lockSourceMoney(array $installation, int $userId, string $currency, array $orders): void
    {
        $accounts = [['owner_id' => 0, 'purpose' => 'provider_clearing'], ['owner_id' => 0, 'purpose' => 'sales_revenue'],
            ['owner_id' => 0, 'purpose' => 'refund_liability'],
            ['owner_id' => $userId, 'purpose' => 'wallet_available'], ['owner_id' => $userId, 'purpose' => 'wallet_reserved']];
        $hasMoney = false;
        foreach ($orders as [$order, $terms]) {
            if ((string) $order->total_amount === '0') { continue; }
            $this->require($terms->currency === $currency, 'UPGRADE_SOURCE_CURRENCY_MISMATCH');
            $hasMoney = true;
            if (DB::table('tx_wallet_order_reservation')->where('order_id', $order->id)->lockForUpdate()->exists()) {
                $accounts[] = ['owner_id' => $userId, 'purpose' => 'wallet_available'];
                $accounts[] = ['owner_id' => $userId, 'purpose' => 'wallet_reserved'];
            }
        }
        if ($hasMoney) { app(NativeMoneyLedger::class)->lockAccountsLocked($installation, $userId, $currency, $accounts); }
    }

    private function ratio(array $history, int $from, ?int $until, array $grant, int $now): array
    {
        if ($until === null) { return $this->volumeRatio($history['periods'], $from, $grant['quota_bytes']); }
        return [(string) max(0, $until - max($from, $now)), (string) ($until - $from)];
    }

    private function volumeRatio($periods, int $from, string $quota): array
    {
        $quota = MoneyValue::minor($quota);
        if ($quota === '0') { return ['0', '1']; }
        $used = '0';
        foreach ($periods as $period) {
            if ($period->ends_at === null || (int) $period->ends_at > $from) {
                $used = bcadd($used, NativeUsageReservations::consumed($period), 0);
            }
        }
        return [bccomp($used, $quota, 0) >= 0 ? '0' : bcsub($quota, $used, 0), $quota];
    }

    private function prorate(string $amount, string $numerator, string $denominator): string
    {
        return MoneyValue::minor(bcdiv(bcmul($amount, $numerator, 0), MoneyValue::minor($denominator, true), 0));
    }

    private function minimum(string $left, string $right): string { return bccomp($left, $right, 0) <= 0 ? $left : $right; }
    private function keys(array $value, array $expected): void
    {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        $this->require($keys === $expected, 'UPGRADE_QUOTE_INVALID');
    }
    private function transaction(): void { $this->require(DB::transactionLevel() > 0, 'BILLING_TRANSACTION_REQUIRED'); }
    private function require(bool $condition, string $code): void { if (!$condition) { throw new BillingError($code); } }
}
