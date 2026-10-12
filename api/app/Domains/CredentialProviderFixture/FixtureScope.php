<?php

namespace App\Domains\CredentialProviderFixture;

use DomainException;

/** Local operator scope only; not a substitute for production authentication. */
final readonly class FixtureScope
{
    public function __construct(public string $providerId, public string $subjectId,
        public string $actorRef = 'local-fixture-operator', public string $authorizationRef = 'local-fixture-grant/v1')
    {
        FixtureCodec::provider($providerId);
        FixtureCodec::decimal($subjectId);
        if ($actorRef !== 'local-fixture-operator' || $authorizationRef !== 'local-fixture-grant/v1') {
            throw new DomainException('UNAUTHORIZED_SCOPE');
        }
    }
}
