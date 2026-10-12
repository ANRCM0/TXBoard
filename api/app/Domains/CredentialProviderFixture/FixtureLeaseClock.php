<?php

namespace App\Domains\CredentialProviderFixture;

/** Server clock; replacement is a trusted test-container fixture, never a request input. */
class FixtureLeaseClock
{
    public function wall(): int { return time(); }
    public function monotonic(): float { return hrtime(true) / 1e9; }
}
