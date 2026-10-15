<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Immutable terms actually purchased by an order. */
class OrderPurchaseTerms extends Model
{
    protected $table = 'tx_order_purchase_terms';
    public const UPDATED_AT = null;
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    protected $casts = [
        'order_id' => 'integer', 'user_id' => 'integer', 'plan_id' => 'integer',
        'order_type' => 'integer', 'price_minor' => 'string', 'currency_minor_exponent' => 'integer',
        'quota_bytes' => 'string', 'group_id' => 'integer', 'speed_limit_mbps' => 'integer',
        'device_limit' => 'integer', 'reset_policy' => 'array', 'duration_months' => 'integer',
        'source_snapshot' => 'array', 'created_at' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(static function (self $terms): void {
            $snapshot = is_array($terms->source_snapshot) ? $terms->source_snapshot : [];
            $hash = $snapshot['content_hash'] ?? null;
            unset($snapshot['content_hash']);
            if (!is_string($hash) || !hash_equals($hash, app(\App\Services\Subscription\PlanCatalogShadowService::class)->hash($snapshot))
                || !hash_equals($hash, (string) $terms->content_hash)
                || (string) ($snapshot['price_minor'] ?? '') !== (string) $terms->price_minor
                || (string) ($snapshot['currency'] ?? '') !== (string) $terms->currency
                || (string) ($snapshot['period'] ?? '') !== (string) $terms->period
                || (string) ($snapshot['plan_id'] ?? '') !== (string) $terms->plan_id
                || (string) ($snapshot['order_type'] ?? '') !== (string) $terms->order_type) {
                throw new LogicException('Order purchase terms content hash mismatch.');
            }
        });
        static::updating(static function (): void { throw new LogicException('Order purchase terms are immutable.'); });
        static::deleting(static function (): void { throw new LogicException('Order purchase terms cannot be deleted.'); });
    }

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
}
