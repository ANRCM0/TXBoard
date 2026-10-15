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
            $order = Order::query()->findOrFail($terms->order_id);
            app(\App\Domains\Billing\PurchasedOrderGrant::class)->resolve($order, $terms);
        });
        static::updating(static function (): void { throw new LogicException('Order purchase terms are immutable.'); });
        static::deleting(static function (): void { throw new LogicException('Order purchase terms cannot be deleted.'); });
    }

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
}
