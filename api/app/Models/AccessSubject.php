<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AccessSubject extends SubscriptionRecord
{
    protected $table = 'tx_access_subject';

    protected static function booted(): void
    {
        static::updating(function (self $subject) {
            if ($subject->isDirty(['subscription_id', 'provider_id', 'provider_subject_ref'])) {
                throw new LogicException('Access Subject identity and ownership cannot change.');
            }
        });
        static::deleting(function () {
            throw new LogicException('Access Subject history cannot be deleted; revoke it instead.');
        });
    }

    protected function casts(): array
    {
        return parent::casts() + [
            'subscription_id' => 'integer', 'auth_version' => 'integer',
            'revocation_fact_version' => 'integer', 'revoked_at' => 'integer',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
