<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsagePeriod extends SubscriptionRecord
{
    protected $table = 'tx_usage_period';

    protected function casts(): array
    {
        return parent::casts() + [
            'subscription_id' => 'integer', 'terms_id' => 'integer', 'period_no' => 'integer',
            'starts_at' => 'integer', 'ends_at' => 'integer', 'quota_bytes' => 'string',
            'upload_bytes' => 'string', 'download_bytes' => 'string', 'billable_bytes' => 'string',
            'last_reset_at' => 'integer', 'next_reset_at' => 'integer', 'legacy_reset_count' => 'integer',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function terms(): BelongsTo
    {
        return $this->belongsTo(SubscriptionTerms::class, 'terms_id');
    }
}
