<?php

namespace App\Core\Kernel\Execution;

interface CurrentInvocation
{
    public function current(): ?InvocationContext;
}
