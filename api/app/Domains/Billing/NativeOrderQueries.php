<?php

namespace App\Domains\Billing;

use App\Domains\Billing\Contracts\OrderListQuery;
use App\Domains\Billing\Contracts\OrderPage;
use App\Domains\Billing\Contracts\OrderQueries;
use App\Models\Order;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

final class NativeOrderQueries implements OrderQueries
{
    public function listing(int $userId, OrderListQuery $query): OrderPage
    {
        $this->requireAccount($userId);
        $orders = Order::query()->with('plan:id,name')->where('user_id', $userId);
        if ($query->status !== null) {
            $orders->where('status', $query->status);
        }
        $result = $orders->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($query->perPage, ['id', 'trade_no', 'plan_id', 'period', 'type',
                'status', 'total_amount', 'paid_at', 'created_at'], 'page', $query->page);

        return new OrderPage(
            $result->getCollection()->map(static fn (Order $order): array => self::summary($order))->all(),
            ['page' => $result->currentPage(), 'per_page' => $result->perPage(),
                'total' => $result->total(), 'last_page' => $result->lastPage()],
        );
    }

    public function find(int $userId, string $tradeNo): ?array
    {
        $this->requireAccount($userId);
        $order = Order::query()->with('plan:id,name')->where('user_id', $userId)
            ->where('trade_no', $tradeNo)->first();
        return $order === null ? null : self::summary($order);
    }

    public function detail(int $userId, string $tradeNo): ?array
    {
        $this->requireAccount($userId);
        $order = Order::query()->with('plan:id,name,transfer_enable')->where('user_id', $userId)
            ->where('trade_no', $tradeNo)->first();
        if ($order === null || $order->plan === null) return null;

        // Detail-only financial display fields must not expand the summary projection.
        $data = self::summary($order);
        $data['plan'] = ['id' => (int) $order->plan->id, 'name' => (string) $order->plan->name,
            'traffic_limit_bytes' => (int) $order->plan->transfer_enable * 1073741824];
        $data['payment_id'] = $order->payment_id === null ? null : (int) $order->payment_id;
        $data['balance_amount_minor'] = (int) ($order->balance_amount ?? 0);
        $data['discount_amount_minor'] = (int) ($order->discount_amount ?? 0);
        return $data;
    }

    private function requireAccount(int $userId): void
    {
        if ($userId <= 0) throw new InvalidArgumentException('ORDER_READ_ACCOUNT_INVALID');
    }

    private static function summary(Order $order): array
    {
        return [
            'id' => (int) $order->id, 'trade_no' => (string) $order->trade_no,
            'plan_id' => (int) $order->plan_id, 'period' => (string) $order->period,
            'type' => (int) $order->type, 'status' => (int) $order->status,
            'amount_minor' => (int) $order->total_amount,
            'plan' => $order->plan ? ['id' => (int) $order->plan->id, 'name' => (string) $order->plan->name] : null,
            'paid_at' => $order->paid_at ? Carbon::createFromTimestampUTC((int) $order->paid_at)->toIso8601String() : null,
            'created_at' => Carbon::createFromTimestampUTC((int) $order->created_at)->toIso8601String(),
        ];
    }
}
