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
            if ($terms->plan_revision_id !== null) {
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
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
