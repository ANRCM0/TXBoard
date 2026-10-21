<?php

namespace App\Domains\CredentialProvider\Contracts;

/** Core-only authenticated template parser; it never returns credential data. */
interface TemplateValidationClient
{
    public function validateTemplate(array $registration, string $format, string $source): void;
}
