<?php

namespace App\Models;

abstract class CommerceShadowRecord extends ImmutableSubscriptionRecord
{
    protected function casts(): array
    {
        return parent::casts() + ['source_snapshot' => 'array', 'amount_evidence' => 'array', 'captured_at' => 'integer',
            'posted_at' => 'integer', 'occurred_at' => 'integer', 'amount_minor' => 'string'];
    }
}
