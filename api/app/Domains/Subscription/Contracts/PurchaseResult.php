<?php

namespace App\Domains\Subscription\Contracts;

final readonly class PurchaseResult
{
    public function __construct(public string $id, public string $tradeNo, public int $status,
        public string $amountMinor, public string $currency, public string $purchaseTermsVersion) {}

    public function toArray(): array
    {
        return ['id' => $this->id, 'trade_no' => $this->tradeNo, 'status' => $this->status,
            'amount_minor' => $this->amountMinor, 'currency' => $this->currency,
            'purchase_terms_version' => $this->purchaseTermsVersion];
    }
}
