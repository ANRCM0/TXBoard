<?php

namespace App\Domains\Subscription;

use App\Domains\Subscription\Contracts\Purchases;
use App\Domains\Subscription\Contracts\PurchaseResult;
use App\Domains\Subscription\Services\PublishedOrderService;

final class NativePurchases implements Purchases
{
    public function __construct(private readonly PublishedOrderService $orders) {}

    public function purchase(int $userId, int $offerId, string $idempotencyKey): PurchaseResult
    {
        try { $order = $this->orders->create($userId, $offerId, $idempotencyKey); }
        catch (\RuntimeException $error) {
            if (in_array($error->getMessage(), ['PUBLICATION_OFFER_MISSING',
                'PUBLICATION_PLAN_MISSING', 'PUBLICATION_OFFER_NOT_PUBLISHED', 'PUBLISHED_ORDER_PENDING_ORDER_EXISTS',
                'PUBLISHED_ORDER_SLOT_RELEASE_REQUIRED', 'PUBLISHED_ORDER_CURRENCY_UNSUPPORTED', 'PUBLISHED_ORDER_OFFER_UNAVAILABLE',
                'PUBLISHED_ORDER_PRICE_OUT_OF_RANGE', 'PUBLISHED_ORDER_CAPACITY_EXHAUSTED'], true)) {
                throw new \App\Domains\Subscription\Contracts\PurchaseRejected($error->getMessage());
            }
            throw $error;
        }
        return new PurchaseResult((string) $order->id, (string) $order->trade_no, (int) $order->status,
            (string) $order->total_amount, (string) $order->purchaseTerms->currency, (string) $order->purchase_terms_version);
    }
}
