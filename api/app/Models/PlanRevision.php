<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class PlanRevision extends ImmutableSubscriptionRecord
{
    protected $table = 'tx_plan_revision';

    protected function casts(): array
    {
        return parent::casts() + [
            'plan_id' => 'integer', 'revision_no' => 'string', 'group_id' => 'integer', 'quota_bytes' => 'string',
            'speed_limit_mbps' => 'integer', 'device_limit' => 'integer', 'reset_policy' => 'array',
            'source_snapshot' => 'array', 'published_at' => 'integer',
        ];
    }

    public function offers(): HasMany
    {
        return $this->hasMany(PlanOffer::class, 'plan_revision_id');
    }
}
