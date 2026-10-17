<?php

namespace App\Domains\Installation;

use Illuminate\Support\Facades\DB;

/** Installation-owned append-only composition approvals. No enabled state, installation mode or epoch is duplicated here. */
final class ModuleReleaseReceipts
{
    public function currentLocked(string $installationId): ?array
    {
        $this->require(DB::transactionLevel() > 0, 'TRANSACTION_REQUIRED');
        $rows = DB::table('tx_module_release')->where('installation_id', $installationId)->orderBy('version')->limit(1025)->sharedLock()->get();
        $this->require($rows->count() <= 1024, 'HISTORY_LIMIT');
        $current = null;
        foreach ($rows as $index => $row) {
            $payload = $this->payload($row);
            $this->require((int) $row->version === $index + 1 && $row->previous_fingerprint === ($current['fingerprint'] ?? null)
                && hash_equals($row->composition_fingerprint, NativeRuntimeReadiness::hash($payload['composition']))
                && hash_equals($row->content_hash, NativeRuntimeReadiness::hash($payload))
                && hash_equals($row->request_hash, $this->requestHash($installationId, $row->business_key, $row->previous_fingerprint, $row->composition_fingerprint)), 'EVIDENCE_INVALID');
            $current = ['version' => (string) $row->version, 'fingerprint' => $row->composition_fingerprint,
                'composition' => $payload['composition'], 'business_key' => $row->business_key];
        }
        return $current;
    }

    public function approveLocked(string $installationId, string $key, ?string $expected, array $composition): array
    {
        $this->require(DB::transactionLevel() > 0, 'TRANSACTION_REQUIRED');
        $fingerprint = NativeRuntimeReadiness::hash($composition);
        $requestHash = $this->requestHash($installationId, $key, $expected, $fingerprint);
        $current = $this->currentLocked($installationId);
        $existing = DB::table('tx_module_release')->where('installation_id', $installationId)->where('business_key', $key)->sharedLock()->first();
        if ($existing !== null) {
            $this->require(hash_equals($requestHash, $existing->request_hash), 'IDEMPOTENCY_CONFLICT');
            $this->require($current !== null && $current['fingerprint'] === $existing->composition_fingerprint, 'REPLAY_SUPERSEDED');
            return $current;
        }
        $this->require(($current['fingerprint'] ?? null) === $expected, 'VERSION_CONFLICT');
        $version = (int) ($current['version'] ?? 0) + 1;
        $this->require($version <= 1024, 'HISTORY_LIMIT');
        $payload = ['installation_id' => $installationId, 'version' => (string) $version, 'business_key' => $key,
            'previous_fingerprint' => $expected, 'composition_fingerprint' => $fingerprint, 'composition' => $composition,
            'request_hash' => $requestHash, 'created_at' => time()];
        DB::table('tx_module_release')->insert(['installation_id' => $installationId, 'version' => $version,
            'business_key' => $key, 'previous_fingerprint' => $expected, 'composition_fingerprint' => $fingerprint,
            'composition_snapshot' => \App\Domains\CredentialProvider\ProviderCodec::canonical($composition), 'request_hash' => $requestHash,
            'content_hash' => NativeRuntimeReadiness::hash($payload), 'created_at' => $payload['created_at']]);
        return ['version' => (string) $version, 'fingerprint' => $fingerprint,
            'composition' => json_decode(\App\Domains\CredentialProvider\ProviderCodec::canonical($composition), true, 32, JSON_THROW_ON_ERROR),
            'business_key' => $key];
    }

    public function assertCurrentLocked(string $installationId, array $composition): void
    {
        $current = $this->currentLocked($installationId);
        $this->require($current !== null && hash_equals($current['fingerprint'], NativeRuntimeReadiness::hash($composition)), 'WORKER_COMPOSITION_MISMATCH');
    }

    private function payload(object $row): array
    {
        return ['installation_id' => $row->installation_id, 'version' => (string) $row->version, 'business_key' => $row->business_key,
            'previous_fingerprint' => $row->previous_fingerprint, 'composition_fingerprint' => $row->composition_fingerprint,
            'composition' => json_decode($row->composition_snapshot, true, 32, JSON_THROW_ON_ERROR),
            'request_hash' => $row->request_hash, 'created_at' => (int) $row->created_at];
    }

    private function requestHash(string $installationId, string $key, ?string $expected, string $fingerprint): string
    {
        return NativeRuntimeReadiness::hash(['installation_id' => $installationId, 'business_key' => $key,
            'previous_fingerprint' => $expected, 'composition_fingerprint' => $fingerprint]);
    }

    private function require(bool $condition, string $code): void
    {
        if (!$condition) throw new InstallationAuthorityException('INSTALLATION_AUTHORITY_MODULE_'.$code);
    }
}
