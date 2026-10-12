<?php

namespace App\Models;

class SubscriptionTermsTransition extends ImmutableSubscriptionRecord
{
    protected $table = 'tx_subscription_terms_transition';

    protected function casts(): array
    {
        return parent::casts() + [
            'subscription_id' => 'integer', 'previous_terms_id' => 'integer',
            'next_terms_id' => 'integer', 'effective_at' => 'integer',
        ];
    }
}
