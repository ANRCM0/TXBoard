<?php

namespace App\Models;

final class LegacyUserDeletionShadow extends ImmutableSubscriptionRecord
{
    protected $table = 'tx_legacy_user_deletion_shadow';
    public $timestamps = false;
}
