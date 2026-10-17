<?php

namespace App\Domains\Identity\Contracts;

final readonly class Preferences
{
    public function __construct(public bool $remindExpire, public bool $remindTraffic) {}
    public function toArray(): array { return ['remind_expire' => $this->remindExpire, 'remind_traffic' => $this->remindTraffic]; }
}
