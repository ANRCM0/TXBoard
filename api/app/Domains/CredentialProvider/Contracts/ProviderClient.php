<?php

namespace App\Domains\CredentialProvider\Contracts;

/** Current Provider protocol capability; credentials never enter Core responses. */
interface ProviderClient
{
    /** Local operator configuration, including its non-secret identity digest. */
    public function configuration(string $providerId): array;

    public function configDigest(string $providerId): string;

    /** Network methods require no active SQL transaction; preserve original operation identity. */
    public function call(#[\SensitiveParameter] array $registration, #[\SensitiveParameter] array $request): array;

    public function metadata(#[\SensitiveParameter] array $registration, #[\SensitiveParameter] array $request): array;

    public function capabilities(#[\SensitiveParameter] array $registration): array;
}
