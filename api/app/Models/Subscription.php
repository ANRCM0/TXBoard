<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends SubscriptionShadowModel
{
    protected $table = 'tx_subscription';

    protected function casts(): array
    {
        return parent::casts() + [
            'user_id' => 'integer', 'plan_id' => 'integer', 'current_terms_id' => 'integer',
            'starts_at' => 'integer', 'expires_at' => 'integer', 'ended_at' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function terms(): HasMany
    {
        return $this->hasMany(SubscriptionTerms::class);
    }

    public function currentTerms(): BelongsTo
    {
        return $this->belongsTo(SubscriptionTerms::class, 'current_terms_id');
    }

    public function usagePeriods(): HasMany
    {
        return $this->hasMany(UsagePeriod::class);
    }

    public function accessSubjects(): HasMany
    {
        return $this->hasMany(AccessSubject::class);
    }

    public function reservesSlotAt(int $now): bool
    {
        return in_array($this->status, ['active', 'suspended'], true)
            && ($this->expires_at === null || $this->expires_at > $now);
    }
}
