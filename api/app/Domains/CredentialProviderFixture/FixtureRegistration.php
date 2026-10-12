<?php

namespace App\Domains\CredentialProviderFixture;

use DomainException;
use Illuminate\Support\Facades\DB;

/** Current registered identity; callers acquire this first in their SQL transaction. */
final class FixtureRegistration
{
    public static function lock(string $provider, ?string $operation = null, bool $runtimeCheck = true): object
    {
        $row = DB::table('tx_credential_provider_shadow_registration')->where('provider_id', $provider)->lockForUpdate()->first();
        if (!$row) { throw new DomainException('CAPABILITY_UNAVAILABLE'); }
        if ($row->runtime_code !== 'synthetic-fixture-v1' || ($runtimeCheck && !hash_equals($row->script_digest, FixtureProcess::digest()))) {
            throw new DomainException('RUNTIME_UNAVAILABLE');
        }
        $grants = json_decode($row->operation_grants, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($grants) || array_diff($grants, ['issue', 'query', 'revoke']) || count(array_unique($grants)) !== count($grants)
            || ($operation !== null && !in_array($operation, $grants, true))) { throw new DomainException('UNSUPPORTED_OPERATION'); }
        if ($operation !== null && json_decode($row->profile_grants, true, flags: JSON_THROW_ON_ERROR) !== ['synthetic-v1']) { throw new DomainException('UNSUPPORTED_PROFILE'); }
        return $row;
    }
}
