<?php

namespace App\Domains\Installation;

use App\Domains\CredentialProvider\ProviderCodec;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Initial setup evidence only; installation admission remains InstallationAuthority's. */
final class NativePreparationSteps
{
    public function execute(string $owner, string $key, #[\SensitiveParameter] array $input, callable $write): array
    {
        if (DB::transactionLevel() !== 0 || !in_array($owner, ['manifest', 'identity', 'network', 'subscription'], true)
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $key) !== 1) {
            throw new RuntimeException('NATIVE_PREPARATION_INPUT_INVALID');
        }
        // A keyed digest also binds secret input without publishing a password verifier.
        $hash = hash_hmac('sha256', ProviderCodec::canonical($input), app('encrypter')->getKey());
        return DB::transaction(function () use ($owner, $key, $hash, $write): array {
            $authority = app(InstallationAuthority::class);
            $authority->assertCatalogMutation();
            $state = $authority->inspect();
            $row = DB::table('tx_native_preparation_step')->where('installation_id', $state['installation_id'])
                ->where('owner', $owner)->lockForUpdate()->first();
            if ($row !== null) {
                if ($row->business_key !== $key || !hash_equals($row->request_hash, $hash)
                    || !hash_equals($row->result_hash, hash('sha256', $row->result_snapshot))) {
                    throw new RuntimeException('NATIVE_PREPARATION_CONFLICT');
                }
                return json_decode($row->result_snapshot, true, 32, JSON_THROW_ON_ERROR);
            }
            $result = $write();
            $snapshot = ProviderCodec::canonical($result);
            DB::table('tx_native_preparation_step')->insert(['installation_id' => $state['installation_id'],
                'owner' => $owner, 'business_key' => $key, 'request_hash' => $hash,
                'result_snapshot' => $snapshot, 'result_hash' => hash('sha256', $snapshot), 'created_at' => time()]);
            return $result;
        }, 3);
    }
}
