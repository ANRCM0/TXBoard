<?php

namespace App\Models;

use LogicException;

abstract class ImmutableSubscriptionRecord extends SubscriptionShadowModel
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Subscription history is immutable; append a successor or adjustment.');
        });
        static::deleting(function () {
            throw new LogicException('Subscription history cannot be deleted.');
        });
    }
}
