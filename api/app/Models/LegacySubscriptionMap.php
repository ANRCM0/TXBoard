<?php

namespace App\Models;

class LegacySubscriptionMap extends SubscriptionShadowModel
{
    protected $table = 'tx_legacy_subscription_map';

    protected function casts(): array
    {
        return parent::casts() + [
            'legacy_user_id' => 'integer', 'subscription_id' => 'integer', 'terms_id' => 'integer',
            'usage_period_id' => 'integer', 'access_subject_id' => 'integer',
            'source_snapshot' => 'array', 'last_verified_at' => 'integer',
        ];
    }
}
