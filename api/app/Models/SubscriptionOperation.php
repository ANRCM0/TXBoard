<?php

namespace App\Models;

/** Immutable native lifecycle and fulfillment idempotency evidence. */
class SubscriptionOperation extends ImmutableSubscriptionRecord
{
    protected $table = 'tx_subscription_operation';

    protected function casts(): array
    {
        return parent::casts() + [
            'user_id' => 'integer', 'subscription_id' => 'integer', 'order_id' => 'integer',
            'request_snapshot' => 'array', 'result_snapshot' => 'array',
        ];
    }
}
