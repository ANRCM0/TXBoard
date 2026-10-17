<?php

namespace App\Core\Kernel;

interface Clock
{
    /** Current UTC Unix timestamp in seconds. */
    public function now(): int;
}
