<?php

namespace App\Domains\CredentialProvider;

use App\Domains\CredentialProvider\Contracts\ProviderClient;
use App\Domains\Installation\InstallationAuthority;
use App\Domains\Installation\NativeRuntimeReadiness;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Operator-approved trust transitions retain the original installation activation. */
final class NativeProviderTrust
{
    private const TABLE = 'tx_native_runtime_trust_transition';

    public function currentLocked(string $installationId, array $activation): array
    {
        $this->require(DB::transactionLevel() > 0, 'TRANSACTION_REQUIRED');
        $rows = DB::table(self::TABLE)->where('installation_id', $installationId)
            ->orderBy('version')->limit(1025)->sharedLock()->get();
        $this->require($rows->count() <= 1024, 'HISTORY_LIMIT');
        $current = $activation;
        $version = 0;
        foreach ($rows as $row) {
            $payload = $this->payload($row);
            $next = $payload['configuration'];
            $this->require((int) $row->version === ++$version
                && hash_equals($row->content_hash, NativeRuntimeReadiness::hash($payload))
                && hash_equals($row->previous_digest, NativeRuntimeReadiness::hash($current))
                && hash_equals($row->configuration_digest, NativeRuntimeReadiness::hash($next))
                && hash_equals($row->request_hash, $this->requestHash($installationId,
                    $row->business_key, $row->previous_digest, $row->configuration_digest)), 'EVIDENCE_INVALID');
            $this->assertChange($current, $next);
            $this->assertCapabilities($payload['capabilities'], $next['provider']);
            $current = $next;
        }
        return $current;
    }

    public function preflight(): array
    {
        $this->require(DB::transactionLevel() === 0, 'OUTER_TRANSACTION_UNSUPPORTED');
        $before = DB::transaction(fn (): array => $this->candidateLocked(), 3);
        $capabilities = app(ProviderClient::class)->capabilities($before['configuration']['provider']);
        unset($capabilities['request_id']);
        $this->assertCapabilities($capabilities, $before['configuration']['provider']);
        $after = DB::transaction(fn (): array => $this->candidateLocked(), 3);
        $this->require($before === $after, 'CONFIGURATION_CHANGED');
        return $after + ['capabilities' => $capabilities];
    }

    public function rotate(string $key, string $expectedDigest, string $candidateDigest): array
    {
        $this->require(DB::transactionLevel() === 0
            && preg_match('/\A[A-Za-z0-9._~:-]{1,128}\z/D', $key) === 1
            && preg_match('/\A[a-f0-9]{64}\z/D', $expectedDigest) === 1
            && preg_match('/\A[a-f0-9]{64}\z/D', $candidateDigest) === 1, 'INPUT_INVALID');
        $state = app(InstallationAuthority::class)->inspect();
        $this->require(($state['mode'] ?? null) === 'native' && ($state['phase'] ?? null) === 'open', 'NOT_READY');
        $installationId = $state['installation_id'];
        $requestHash = $this->requestHash($installationId, $key, $expectedDigest, $candidateDigest);
        $existing = DB::transaction(function () use ($installationId, $key): ?object {
            $this->activationLocked($installationId);
            return DB::table(self::TABLE)->where('installation_id', $installationId)
                ->where('business_key', $key)->sharedLock()->first();
        }, 3);
        if ($existing !== null) {
            $this->require(hash_equals($existing->request_hash, $requestHash), 'IDEMPOTENCY_CONFLICT');
            $payload = $this->payload($existing);
            $this->require(hash_equals($existing->content_hash, NativeRuntimeReadiness::hash($payload)), 'EVIDENCE_INVALID');
            return $this->result($existing);
        }
        $candidate = $this->preflight();
        $this->require(hash_equals($candidate['previous_digest'], $expectedDigest)
            && hash_equals($candidate['configuration_digest'], $candidateDigest), 'VERSION_CONFLICT');
        return DB::transaction(function () use ($candidate, $key, $requestHash, $installationId): array {
            // The installation row serializes rotation with every business mutation.
            $state = app(InstallationAuthority::class)->inspect();
            $activation = $this->activationLocked($installationId);
            $this->require($state['installation_id'] === $installationId && $state['phase'] === 'open', 'NOT_READY');
            $existing = DB::table(self::TABLE)->where('installation_id', $installationId)
                ->where('business_key', $key)->lockForUpdate()->first();
            if ($existing !== null) {
                $this->require(hash_equals($existing->request_hash, $requestHash), 'IDEMPOTENCY_CONFLICT');
                return $this->result($existing);
            }
            $after = $this->candidateLocked();
            $comparison = $candidate;
            unset($comparison['capabilities']);
            $this->require($after === $comparison, 'CONFIGURATION_CHANGED');
            $registration = DB::table('tx_credential_provider_registration')
                ->where('provider_id', $after['configuration']['provider']['provider_id'])->lockForUpdate()->first();
            $previous = $this->currentLocked($installationId, $activation);
            $this->require($registration !== null && $registration->installation_id === $installationId
                && $registration->config_digest === $previous['provider']['config_digest'], 'REGISTRATION_CHANGED');
            $registered = [
                'installation_id' => $installationId, 'provider_id' => $registration->provider_id,
                'provider_instance_epoch' => (string) $registration->provider_instance_epoch,
                'config_digest' => $registration->config_digest,
                'profiles' => json_decode($registration->profiles, true, 16, JSON_THROW_ON_ERROR),
                'business_key' => $registration->business_key,
            ];
            $this->require(hash_equals($registration->content_hash, NativeRuntimeReadiness::hash($registered)), 'REGISTRATION_CHANGED');
            $registered['config_digest'] = $after['configuration']['provider']['config_digest'];
            $version = (int) DB::table(self::TABLE)->where('installation_id', $installationId)->max('version') + 1;
            $payload = [
                'installation_id' => $installationId, 'version' => (string) $version, 'business_key' => $key,
                'request_hash' => $requestHash, 'previous_digest' => $after['previous_digest'],
                'configuration_digest' => $after['configuration_digest'],
                'configuration' => $after['configuration'], 'capabilities' => $candidate['capabilities'], 'created_at' => time(),
            ];
            $id = DB::table(self::TABLE)->insertGetId([
                'installation_id' => $installationId, 'version' => $version, 'business_key' => $key,
                'request_hash' => $requestHash, 'previous_digest' => $payload['previous_digest'],
                'configuration_digest' => $payload['configuration_digest'],
                'configuration_snapshot' => ProviderCodec::canonical($payload['configuration']),
                'provider_capabilities' => ProviderCodec::canonical($payload['capabilities']),
                'content_hash' => NativeRuntimeReadiness::hash($payload), 'created_at' => $payload['created_at'],
            ]);
            DB::table('tx_credential_provider_registration')->where('id', $registration->id)->update([
                'config_digest' => $registered['config_digest'], 'content_hash' => NativeRuntimeReadiness::hash($registered),
            ]);
            return $this->result(DB::table(self::TABLE)->find($id));
        }, 3);
    }

    private function candidateLocked(): array
    {
        $state = app(InstallationAuthority::class)->inspect();
        $this->require(($state['mode'] ?? null) === 'native' && ($state['phase'] ?? null) === 'open', 'NOT_READY');
        $installationId = $state['installation_id'];
        $activation = $this->activationLocked($installationId);
        $before = $this->currentLocked($installationId, $activation);
        $after = app(NativeRuntimeReadiness::class)->configuration($installationId);
        $this->assertChange($before, $after);
        $previousDigest = NativeRuntimeReadiness::hash($before);
        $configurationDigest = NativeRuntimeReadiness::hash($after);
        $this->require(!hash_equals($previousDigest, $configurationDigest), 'NO_CHANGE');
        return ['installation_id' => $installationId, 'previous_digest' => $previousDigest,
            'configuration_digest' => $configurationDigest, 'configuration' => $after];
    }

    private function activationLocked(string $installationId): array
    {
        $fence = DB::table('tx_authority_writer_fence')->where('id', 1)->lockForUpdate()->first();
        $this->require($fence !== null && $fence->installation_id === $installationId, 'NOT_READY');
        $activation = DB::table('tx_native_runtime_activation')->where('installation_id', $installationId)->sharedLock()->first();
        $this->require($activation !== null, 'NOT_READY');
        $snapshot = json_decode($activation->configuration_snapshot, true, 32, JSON_THROW_ON_ERROR);
        $this->require(hash_equals($activation->configuration_hash, NativeRuntimeReadiness::hash($snapshot)), 'EVIDENCE_INVALID');
        return $snapshot['configuration'];
    }

    private function assertChange(array $before, array $after): void
    {
        $left = $before;
        $right = $after;
        unset($left['provider']['config_digest'], $right['provider']['config_digest'], $left['signer'], $right['signer']);
        $this->require(ProviderCodec::canonical($left) === ProviderCodec::canonical($right), 'SCOPE_CHANGED');
        if (ProviderCodec::canonical($before['signer']) !== ProviderCodec::canonical($after['signer'])) {
            $old = $before['signer'];
            $new = $after['signer'];
            $oldEpoch = $old['key_epoch'];
            $newEpoch = $new['key_epoch'];
            $this->require(preg_match('/\A[1-9][0-9]{0,18}\z/D', (string) $newEpoch) === 1
                && (int) $newEpoch > (int) $oldEpoch && $old['key_id'] !== $new['key_id']
                && $old['public_key'] !== $new['public_key'], 'SIGNER_VERSION_INVALID');
            unset($old['key_id'], $old['key_epoch'], $old['public_key'], $new['key_id'], $new['key_epoch'], $new['public_key']);
            $this->require(ProviderCodec::canonical($old) === ProviderCodec::canonical($new), 'SCOPE_CHANGED');
        }
    }

    private function assertCapabilities(array $capabilities, array $provider): void
    {
        $this->require(($capabilities['contract_version'] ?? null) === ProviderCapabilityCodec::VERSION
            && ($capabilities['provider_id'] ?? null) === $provider['provider_id']
            && ($capabilities['provider_instance_epoch'] ?? null) === $provider['provider_instance_epoch']
            && ($capabilities['store_version'] ?? null) === 2
            && ($capabilities['operations'] ?? null) === ['issue', 'query', 'revoke', 'rotate']
            && ($capabilities['node_delivery'] ?? null) === true && ($capabilities['subscription_render'] ?? null) === true
            && is_array($capabilities['profiles'] ?? null)
            && array_diff($provider['profiles'], $capabilities['profiles']) === [], 'CAPABILITY_INVALID');
    }

    private function payload(object $row): array
    {
        return ['installation_id' => $row->installation_id, 'version' => (string) $row->version,
            'business_key' => $row->business_key, 'request_hash' => $row->request_hash,
            'previous_digest' => $row->previous_digest, 'configuration_digest' => $row->configuration_digest,
            'configuration' => json_decode($row->configuration_snapshot, true, 32, JSON_THROW_ON_ERROR),
            'capabilities' => json_decode($row->provider_capabilities, true, 16, JSON_THROW_ON_ERROR),
            'created_at' => (int) $row->created_at];
    }

    private function requestHash(string $installationId, string $key, string $previous, string $candidate): string
    {
        return NativeRuntimeReadiness::hash(['installation_id' => $installationId, 'business_key' => $key,
            'previous_digest' => $previous, 'configuration_digest' => $candidate]);
    }

    private function result(object $row): array
    {
        return ['schema' => 'native-provider-trust-transition/v1', 'version' => (string) $row->version,
            'previous_digest' => $row->previous_digest, 'configuration_digest' => $row->configuration_digest,
            'committed_at' => (int) $row->created_at];
    }

    private function require(bool $condition, string $code): void
    {
        if (!$condition) {
            throw new DomainException('NATIVE_PROVIDER_TRUST_'.$code);
        }
    }
}
