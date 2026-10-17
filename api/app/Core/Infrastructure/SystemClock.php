<?php

namespace App\Core\Infrastructure;

use App\Core\Kernel\Clock;

final class SystemClock implements Clock
{
    public function now(): int
    {
        return time();
    }
}
