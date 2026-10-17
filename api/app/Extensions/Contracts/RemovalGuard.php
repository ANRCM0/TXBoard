<?php

namespace App\Extensions\Contracts;

interface RemovalGuard
{
    /** Called after new execution is fenced. The owner rejects unresolved external/data references. */
    public function assertDrained(): void;
}
