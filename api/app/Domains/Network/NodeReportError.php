<?php

namespace App\Domains\Network;

use RuntimeException;

final class NodeReportError extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message
    ) {
        parent::__construct($message);
    }
}
