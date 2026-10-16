<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SubscriptionTerms extends ImmutableSubscriptionRecord
{
    protected $table = 'tx_subscription_terms';

    protected static function booted(): void
    {
        parent::booted();
        static::creating(function (self $terms) {
            if ($terms->schema_version === 'core-subscription-terms/v1') {
                $subscription = \Illuminate\Support\Facades\DB::table('tx_subscription')
                    ->where('id', $terms->subscription_id)->first();
                if ($subscription === null) {
                    throw new LogicException('Native subscription owner is missing.');
                }
                app(\App\Domains\Subscription\Services\NativeSubscriptionStore::class)
                    ->verifyTerms((object) $terms->getAttributes(), $subscription);
            } elseif ($terms->plan_revision_id !== null || $terms->schema_version !== null
                || $terms->source_order_id !== null || $terms->source_snapshot !== null) {
                throw new LogicException('Catalog revision references are unavailable in subscription shadow v1.');
            }
        });
    }

    protected function casts(): array
    {
        return parent::casts() + [
            'subscription_id' => 'integer', 'plan_revision_id' => 'integer', 'version_no' => 'integer',
            'effective_from' => 'integer', 'effective_until' => 'integer', 'quota_bytes' => 'string',
            'group_id' => 'integer', 'speed_limit_mbps' => 'integer', 'device_limit' => 'integer',
            'reset_policy' => 'array', 'legacy_snapshot' => 'array',
            'source_order_id' => 'integer', 'source_snapshot' => 'array',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
