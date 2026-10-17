<?php

namespace App\Domains\Billing;

use App\Domains\Billing\Contracts\OrderCancellation;
use App\Domains\Subscription\Services\PublishedOrderService;
use App\Models\Order;
use App\Services\OrderService;

final class NativeOrderCancellation implements OrderCancellation
{
    public function cancel(int $userId, string $tradeNo): ?bool
    {
        if ($userId <= 0) return null;
        $order = Order::query()->useWritePdo()->where('user_id', $userId)->where('trade_no', $tradeNo)->first();
        if ($order === null) return null;
        $lifecycle = $order->purchase_terms_version === PublishedOrderService::VERSION
            ? new NativeOrderLifecycle($order) : new OrderService($order);
        return $lifecycle->cancel();
    }
}
