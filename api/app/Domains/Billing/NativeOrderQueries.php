<?php

namespace App\Domains\Billing;

use App\Domains\Billing\Contracts\OrderListQuery;
use App\Domains\Billing\Contracts\OrderPage;
use App\Domains\Billing\Contracts\OrderQueries;
use App\Models\Order;
use App\Models\OrderPurchaseTerms;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use App\Domains\Billing\Contracts\AdminOrderQueries;
use App\Domains\Billing\Contracts\AdminOrderListQuery;
use App\Domains\Subscription\Services\PublishedOrderService;

final class NativeOrderQueries implements OrderQueries, AdminOrderQueries
{
    private readonly NativeOrderEventJournal $eventsJournal;

    public function __construct(?NativeOrderEventJournal $eventsJournal = null)
    {
        $this->eventsJournal = $eventsJournal ?? new NativeOrderEventJournal();
    }

    public function listing(int $userId, OrderListQuery $query): OrderPage
    {
        $this->requireAccount($userId);
        $orders = Order::query()->useWritePdo()->with(['plan:id,name', 'purchaseTerms' => static fn ($query) => $query->useWritePdo()])->where('user_id', $userId);
        if ($query->status !== null) {
            $orders->where('status', $query->status);
        }
        $result = $orders->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($query->perPage, ['id', 'user_id', 'trade_no', 'plan_id', 'period', 'type',
                'status', 'total_amount', 'paid_at', 'created_at', 'purchase_terms_version'], 'page', $query->page);

        return new OrderPage(
            $result->getCollection()->map(static fn (Order $order): array => self::summary($order))->all(),
            ['page' => $result->currentPage(), 'per_page' => $result->perPage(),
                'total' => $result->total(), 'last_page' => $result->lastPage()],
        );
    }

    public function find(int $userId, string $tradeNo): ?array
    {
        $this->requireAccount($userId);
        $order = Order::query()->useWritePdo()->with(['plan:id,name', 'purchaseTerms' => static fn ($query) => $query->useWritePdo()])->where('user_id', $userId)
            ->where('trade_no', $tradeNo)->first();
        return $order === null ? null : self::summary($order);
    }

    public function detail(int $userId, string $tradeNo): ?array
    {
        $this->requireAccount($userId);
        $order = Order::query()->useWritePdo()->with(['plan:id,name,transfer_enable', 'purchaseTerms' => static fn ($query) => $query->useWritePdo()])->where('user_id', $userId)
            ->where('trade_no', $tradeNo)->first();
        if ($order === null || ($order->plan === null && $order->purchase_terms_version !== PublishedOrderService::VERSION)) return null;

        // Detail-only financial display fields must not expand the summary projection.
        $data = self::summary($order);
        $purchase = self::frozenPurchase($order);
        $data['plan'] = ['id' => (int) $order->plan_id, 'name' => $purchase['plan_name'] ?? (string) $order->plan->name,
            'traffic_limit_bytes' => $purchase !== null ? (int) ($purchase['quota_bytes'] ?? 0) : (int) $order->plan->transfer_enable * 1073741824];
        $data['payment_id'] = $order->payment_id === null ? null : (int) $order->payment_id;
        $data['balance_amount_minor'] = (int) ($order->balance_amount ?? 0);
        $data['discount_amount_minor'] = (int) ($order->discount_amount ?? 0);
        return $data;
    }

    public function events(int $userId, string $tradeNo, string $afterSequence, int $limit): ?OrderPage
    {
        $this->requireAccount($userId);
        $order = Order::query()->useWritePdo()->where('user_id', $userId)->where('trade_no', $tradeNo)->first();
        if ($order === null || $order->purchase_terms_version !== PublishedOrderService::VERSION) {
            return null;
        }
        return $this->eventsJournal->page($order, $afterSequence, $limit);
    }

    private function requireAccount(int $userId): void
    {
        if ($userId <= 0) throw new InvalidArgumentException('ORDER_READ_ACCOUNT_INVALID');
    }

    public function adminListing(AdminOrderListQuery $query): OrderPage
    {
        AdminOrderFilterFields::validate($query);
        $orders = Order::query()->useWritePdo()
            ->where('purchase_terms_version', PublishedOrderService::VERSION);
        if ($query->userId !== null) $orders->where('user_id', $query->userId);
        if ($query->tradeNo !== null) $orders->where('trade_no', $query->tradeNo);
        if ($query->status !== null) $orders->where('status', $query->status);
        foreach ($query->filters as $filter) {
            if (in_array($filter['field'], ['amount_minor', 'currency'], true)) {
                $orders->whereHas('purchaseTerms', static function ($terms) use ($filter): void {
                    \App\Core\Query\BoundedListFilters::apply($terms, $filter['field'] === 'amount_minor' ? 'price_minor' : 'currency', $filter);
                });
            } else \App\Core\Query\BoundedListFilters::apply($orders, $filter['field'], $filter);
        }
        $orders->orderBy($query->sortBy === 'amount_minor'
            ? OrderPurchaseTerms::query()->select('price_minor')->whereColumn('order_id', 'tx_order.id')->limit(1)
            : $query->sortBy, $query->sortDirection);
        if ($query->sortBy !== 'id') $orders->orderBy('id', $query->sortDirection);
        $page = $orders->paginate($query->perPage,
            ['id', 'trade_no', 'user_id', 'plan_id', 'period', 'type', 'status', 'total_amount',
                'paid_at', 'created_at', 'purchase_terms_version'], 'page', $query->page);
        $terms = OrderPurchaseTerms::query()->useWritePdo()->whereIn('order_id', $page->getCollection()->pluck('id'))
            ->get()->keyBy('order_id');
        return new OrderPage($page->getCollection()->map(
            static fn (Order $order): array => self::adminSummary($order, $terms->get($order->id)))->all(),
            ['page' => $page->currentPage(), 'per_page' => $page->perPage(),
                'total' => $page->total(), 'last_page' => $page->lastPage()]);
    }

    public function adminFind(string $tradeNo): ?array
    {
        if (!preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $tradeNo)) {
            throw new InvalidArgumentException('ADMIN_ORDER_QUERY_INVALID');
        }
        $order = Order::query()->useWritePdo()
            ->where('purchase_terms_version', PublishedOrderService::VERSION)->where('trade_no', $tradeNo)->first();
        return $order === null ? null : self::adminSummary($order,
            OrderPurchaseTerms::query()->useWritePdo()->where('order_id', $order->id)->first());
    }

    public function adminEvents(string $tradeNo, string $afterSequence, int $limit): ?OrderPage
    {
        $order = Order::query()->useWritePdo()->where('purchase_terms_version', PublishedOrderService::VERSION)
            ->where('trade_no', $tradeNo)->first();
        if ($order === null) return null;
        return $this->eventsJournal->page($order, $afterSequence, $limit);
    }

    private static function adminSummary(Order $order, ?OrderPurchaseTerms $terms): array
    {
        $purchase = app(PurchasedOrderGrant::class)->resolve($order, $terms);
        if ($purchase === null) throw new \LogicException('NATIVE_ORDER_PURCHASE_MISSING');
        return ['id' => (int) $order->id, 'trade_no' => (string) $order->trade_no,
            'user_id' => (int) $order->user_id, 'plan_id' => (int) $order->plan_id,
            'period' => (string) $order->period, 'type' => (int) $order->type, 'status' => (int) $order->status,
            'amount_minor' => $purchase['price_minor'], 'currency' => $purchase['currency'],
            'plan' => ['id' => (int) $order->plan_id, 'name' => $purchase['plan_name']],
            'paid_at' => $order->paid_at ? Carbon::createFromTimestampUTC((int) $order->paid_at)->toIso8601String() : null,
            'created_at' => Carbon::createFromTimestampUTC((int) $order->created_at)->toIso8601String(),
            'purchase_terms_version' => PublishedOrderService::VERSION,
            'list_amount_minor' => (string) $purchase['list_price_minor'],
            'coupon_discount_minor' => (string) ($purchase['coupon_discount']['discount_minor'] ?? '0')];
    }

    private static function frozenPurchase(Order $order): ?array
    {
        if ($order->purchase_terms_version !== PublishedOrderService::VERSION) return null;
        $purchase = app(PurchasedOrderGrant::class)->resolve($order, $order->purchaseTerms);
        if ($purchase === null) throw new \LogicException('NATIVE_ORDER_PURCHASE_MISSING');
        return $purchase;
    }

    private static function summary(Order $order): array
    {
        $purchase = self::frozenPurchase($order);
        return [
            'id' => (int) $order->id, 'trade_no' => (string) $order->trade_no,
            'plan_id' => (int) $order->plan_id, 'period' => (string) $order->period,
            'type' => (int) $order->type, 'status' => (int) $order->status,
            'amount_minor' => (int) $order->total_amount,
            'plan' => $purchase !== null ? ['id' => (int) $order->plan_id, 'name' => $purchase['plan_name']]
                : ($order->plan ? ['id' => (int) $order->plan->id, 'name' => (string) $order->plan->name] : null),
            'paid_at' => $order->paid_at ? Carbon::createFromTimestampUTC((int) $order->paid_at)->toIso8601String() : null,
            'created_at' => Carbon::createFromTimestampUTC((int) $order->created_at)->toIso8601String(),
        ] + ($purchase === null ? [] : ['currency' => $purchase['currency'],
            'purchase_terms_version' => PublishedOrderService::VERSION,
            'list_amount_minor' => (string) $purchase['list_price_minor'],
            'coupon_discount_minor' => (string) ($purchase['coupon_discount']['discount_minor'] ?? '0')]);
    }
}
