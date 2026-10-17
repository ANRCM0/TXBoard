<?php

namespace App\Domains\Identity\Contracts;

final readonly class PreferenceChange
{
    public function __construct(public ?bool $remindExpire = null, public ?bool $remindTraffic = null)
    {
        if ($remindExpire === null && $remindTraffic === null) throw new \DomainException('PREFERENCES_REQUIRED');
    }
}
