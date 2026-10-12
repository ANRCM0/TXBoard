<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanOffer extends ImmutableSubscriptionRecord
{
    protected $table = 'tx_plan_offer';

    protected function casts(): array
    {
        return parent::casts() + [
            'plan_id' => 'integer', 'plan_revision_id' => 'integer', 'version_no' => 'string',
            'price_minor' => 'string', 'currency_minor_exponent' => 'integer', 'calendar_months' => 'integer',
            'duration_seconds' => 'integer', 'calendar_policy' => 'array',
        ];
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(PlanRevision::class, 'plan_revision_id');
    }
}
