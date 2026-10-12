<?php

namespace App\Models;

class UsageAdjustment extends ImmutableSubscriptionRecord
{
    protected $table = 'tx_usage_adjustment';

    protected function casts(): array
    {
        return parent::casts() + [
            'usage_period_id' => 'integer', 'terms_transition_id' => 'integer',
            'quota_delta_bytes' => 'string',
        ];
    }
}
