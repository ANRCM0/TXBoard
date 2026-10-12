<?php

namespace App\Models;

final class LegacyCaptureShadowEvent extends ImmutableSubscriptionRecord
{
    protected $table = 'tx_legacy_capture_shadow_event';
    public $timestamps = false;
}
