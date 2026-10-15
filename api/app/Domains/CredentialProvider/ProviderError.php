<?php

namespace App\Domains\CredentialProvider;

use RuntimeException;

final class ProviderError extends RuntimeException
{
    public function __construct(
        public readonly string $codeName,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($codeName);
    }
}
