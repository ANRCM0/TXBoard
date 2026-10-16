<?php

namespace App\Models;

class SubscriptionSlot extends SubscriptionRecord
{
    protected $table = 'tx_subscription_slot';
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'user_id' => 'integer', 'slot_no' => 'integer', 'subscription_id' => 'integer',
            'reserved_at' => 'integer',
        ];
    }

    protected function setKeysForSaveQuery($query)
    {
        return parent::setKeysForSaveQuery($query)->where('slot_no', $this->getOriginal('slot_no', $this->slot_no));
    }
}
