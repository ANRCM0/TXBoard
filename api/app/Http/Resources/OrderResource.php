<?php

namespace App\Http\Resources;

use App\Models\Order;
use App\Services\PlanService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'period' => PlanService::getLegacyPeriod((string)$this->period),
            'plan' => $this->whenLoaded('plan', fn() => PlanResource::make($this->plan)),
            'purchase_terms' => $this->whenLoaded('purchaseTerms', fn() => $this->purchaseTerms ? [
                'version' => 'tx-core.purchase-terms/v1',
                'period' => (string) $this->purchaseTerms->period,
                'order_type' => (int) $this->purchaseTerms->order_type,
                'price_minor' => (string) $this->purchaseTerms->price_minor,
                'currency' => (string) $this->purchaseTerms->currency,
                'content_hash' => (string) $this->purchaseTerms->content_hash,
            ] : null),
            'payment' => $this->whenLoaded('payment', fn() => $this->payment ? [
                'id' => $this->payment->id,
                'name' => $this->payment->name,
                'payment' => $this->payment->payment,
                'icon' => $this->payment->icon,
            ] : null),
        ];
    }
}
