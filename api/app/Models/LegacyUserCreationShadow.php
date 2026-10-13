<?php

namespace App\Models;

final class LegacyUserCreationShadow extends ImmutableSubscriptionRecord
{
    protected $table = 'tx_legacy_user_creation_shadow';
    public $timestamps = false;
}
