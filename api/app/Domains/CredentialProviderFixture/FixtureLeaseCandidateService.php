<?php

namespace App\Domains\CredentialProviderFixture;

use App\Domains\UsageV2\UsageError;
use App\Domains\UsageV2\UsagePolicy;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Core-only preparation; no auth snapshot, activation, IPC, delivery or Node consumer. */
final class FixtureLeaseCandidateService
{
    public const TABLE = 'tx_credential_provider_shadow_lease_candidate';
    public function __construct(private UsagePolicy $policy, private FixtureLeaseCurrentBoundary $currentBoundary,
        private FixtureLeaseClock $clock) {}

    public function prepare(FixtureScope $scope, string $issueRef, string $key, array $input): array
    {
        $this->guard(); $request = FixtureLeaseCandidateCodec::request($scope, $issueRef, $key, $input);
        // History replay intentionally precedes current User/provider/source checks.
        $existing = $this->existing($scope, $key);
        if ($existing) { return $this->replay($existing, $scope, $request); }
        return DB::transaction(function () use ($scope, $issueRef, $key, $input, $request) {
            try { [$e, $monotonic] = $this->current($scope, $issueRef, $input); }
            catch (DomainException|UsageError $error) {
                // A competing commit may have created history while we waited for a current fence.
                $existing = $this->existing($scope, $key, true);
                if ($existing) { return $this->replay($existing, $scope, $request); }
                throw $error;
            }
            // Registration is held exclusively: another same-scope preparer cannot insert concurrently.
            $existing = $this->existing($scope, $key, true);
            if ($existing) { return $this->replay($existing, $scope, $request); }
            $id = DB::table(self::TABLE)->insertGetId(['candidate_ref' => 'lc_'.bin2hex(random_bytes(16)),
                'provider_id' => $scope->providerId, 'access_subject_id' => $scope->subjectId, 'business_key' => $key,
                'request_hash' => FixtureCodec::hash($request), 'request_snapshot' => FixtureCodec::json($request),
                'evidence_snapshot' => FixtureCodec::json($e), 'evidence_hash' => FixtureCodec::hash($e),
                'prepared_at' => $e['prepared_at'], 'candidate_expires_at' => $e['candidate_expires_at'],
                'state' => 'prepared', 'reason_code' => null, 'last_observed_at' => $e['prepared_at']]
                + array_intersect_key($e, array_flip(['binding_id', 'registration_id', 'issue_operation_id', 'source_id',
                    'subscription_id', 'terms_id', 'usage_period_id', 'tariff_revision_id'])));
            $row = DB::table(self::TABLE)->where('id', $id)->lockForUpdate()->first();
            return $this->finish($row, $scope, true, $e['prepared_at'], $monotonic);
        }, 3);
    }

    public function inspect(FixtureScope $scope, string $ref): array
    {
        $this->guard(); return $this->receipt($this->find($scope, $ref), $scope, true);
    }

    public function checkCurrent(FixtureScope $scope, string $ref): array
    {
        $this->guard(); $hint = $this->find($scope, $ref); FixtureLeaseCandidateCodec::retained($hint, $scope);
        return DB::transaction(function () use ($scope, $ref, $hint) {
            $failure = null; $e = null;
            if ($hint->state === 'prepared') {
                try {
                    $request = FixtureLeaseCandidateCodec::decode($hint->request_snapshot);
                    [$e, $monotonic] = $this->current($scope, $request['issue_operation_ref'], $request['input']);
                } catch (DomainException|UsageError $error) {
                    $failure = $error instanceof UsageError ? $error->errorCode : $error->getMessage();
                }
            }
            // Acquire candidate last; never reverse the current User/plugin/source/Subject lock order.
            $row = $this->find($scope, $ref, true); $old = FixtureLeaseCandidateCodec::retained($row, $scope);
            if ($row->state !== 'prepared') { return $this->receipt($row, $scope, false); }
            if ($failure !== null) {
                $this->observe($row, $this->clock->wall(), $this->clock->monotonic());
                $this->close($row, 'refused', $this->safe($failure), $this->clock->wall());
            }
            elseif ($e !== null) {
                // Mutable usage may rise, but immutable credential/source/owner/Terms binding cannot change.
                foreach (['binding_id', 'registration_id', 'provider_instance_epoch', 'issue_operation_id', 'issue_input_hash',
                    'issue_result_hash', 'source_id', 'source_epoch', 'core_instance_id', 'receipt_generation', 'user_id',
                    'subscription_id', 'terms_id', 'usage_period_id', 'tariff_revision_id', 'auth_version', 'revocation_version',
                    'subject_core_ref', 'terms_hash', 'period_quota_bytes', 'tariff_hash'] as $field) {
                    if ($e[$field] !== $old[$field]) { $this->close($row, 'refused', 'LEASE_CURRENT_BINDING_CHANGED', $e['prepared_at']); break; }
                }
                return $this->finish($row, $scope, false, $e['prepared_at'], $monotonic);
            }
            return $this->receipt($row, $scope, false); // Terminal writes COMMIT by returning, not throwing.
        }, 3);
    }

    private function current(FixtureScope $scope, string $issueRef, array $input): array
    {
        return $this->currentBoundary->forCandidate($scope, $issueRef, $input);
    }
    private function observe(object $row, int $now, float $monotonic): void
    {
        if ($row->state !== 'prepared') { return; }
        $last = max((int) $row->last_observed_at, (int) $row->prepared_at);
        $wall = FixtureCodec::time($this->clock->wall()); $elapsed = $this->clock->monotonic() - $monotonic;
        if ($now < $last || $wall < $now || $elapsed < 0) { $this->close($row, 'refused', 'LEASE_CLOCK_REGRESSION', max($last, $now)); }
        elseif ($wall >= (int) $row->candidate_expires_at || $now + $elapsed >= (int) $row->candidate_expires_at) { $this->close($row, 'expired', 'LEASE_EXPIRED', max($wall, $now)); }
        else {
            DB::table(self::TABLE)->where('id', $row->id)->update(['last_observed_at' => max($wall, $last), 'row_version' => (int) $row->row_version + 1]);
            $row->last_observed_at = max($wall, $last);
        }
    }
    private function close(object $row, string $state, string $reason, int $now): void
    {
        if ($row->state !== 'prepared') { return; }
        DB::table(self::TABLE)->where('id', $row->id)->update(['state' => $state, 'reason_code' => $reason,
            'last_observed_at' => max((int) $row->last_observed_at, $now), 'row_version' => (int) $row->row_version + 1]);
        $row->state = $state; $row->reason_code = $reason;
    }
    private function safe(string $reason): string
    {
        return preg_match('/\A[A-Z][A-Z0-9_]{2,79}\z/', $reason) ? $reason : 'LEASE_CURRENT_REFUSED';
    }
    private function replay(object $row, FixtureScope $scope, array $request): array
    {
        if (!hash_equals($row->request_hash, FixtureCodec::hash($request))) { throw new DomainException('IDEMPOTENCY_CONFLICT'); }
        return $this->receipt($row, $scope, true);
    }
    private function receipt(object $row, FixtureScope $scope, bool $history): array
    {
        $e = FixtureLeaseCandidateCodec::retained($row, $scope);
        return ['mode' => 'local_synthetic_lease_candidate', 'candidate_ref' => $row->candidate_ref,
            'evidence' => $e, 'evidence_hash' => $row->evidence_hash, 'historical_only' => $history,
            'local_validation' => ['state' => $row->state, 'reason_code' => $row->reason_code],
            'admission_granted' => false, 'auth_snapshot_id' => null, 'delivery_status' => 'not_implemented', 'node_convergence' => 'not_implemented'];
    }
    private function finish(object $row, FixtureScope $scope, bool $history, int $sampled, float $monotonic): array
    {
        $receipt = $this->receipt($row, $scope, $history); // Complete bounded encoding/schema work first.
        $this->observe($row, $sampled, $monotonic); // Last check before the callback returns/commits.
        $receipt['local_validation'] = ['state' => $row->state, 'reason_code' => $row->reason_code];
        return $receipt;
    }
    private function existing(FixtureScope $scope, string $key, bool $lock = false): ?object
    {
        $query = DB::table(self::TABLE)->where('provider_id', $scope->providerId)->where('access_subject_id', $scope->subjectId)->where('business_key', $key);
        return ($lock ? $query->lockForUpdate() : $query)->first();
    }
    private function find(FixtureScope $scope, string $ref, bool $lock = false): object
    {
        if (!preg_match('/\Alc_[a-f0-9]{32}\z/', $ref)) { throw new DomainException('PROVIDER_INPUT_INVALID'); }
        $query = DB::table(self::TABLE)->where('candidate_ref', $ref)->where('provider_id', $scope->providerId)->where('access_subject_id', $scope->subjectId);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        if (!$row) { throw new DomainException('UNAUTHORIZED_SCOPE'); } return $row;
    }
    private function guard(): void
    {
        if (config('credential_provider_lease_fixture.enabled') !== true || config('credential_provider_fixture.enabled') !== true
            || config('credential_provider_fixture.plugin_bridge_enabled') !== true || config('core_shadow.isolated_copy') !== true
            || !app()->environment('local', 'testing')) { throw new DomainException('CAPABILITY_UNAVAILABLE'); }
        $this->policy->requireIsolated();
        if (DB::transactionLevel() !== 0) { throw new DomainException('PROVIDER_OUTER_TRANSACTION_UNSUPPORTED'); }
    }
}
