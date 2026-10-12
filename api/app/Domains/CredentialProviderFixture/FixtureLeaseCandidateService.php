<?php

namespace App\Domains\CredentialProviderFixture;

use App\Domains\UsageV2\UsageError;
use App\Domains\UsageV2\UsagePolicy;
use App\Domains\UsageV2\UsageRegistry;
use App\Domains\UsageV2\UsageTermsBoundary;
use App\Models\Plugin;
use App\Services\Subscription\SubscriptionTermsTimeline;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Core-only preparation; no auth snapshot, activation, IPC, delivery or Node consumer. */
final class FixtureLeaseCandidateService
{
    public const TABLE = 'tx_credential_provider_shadow_lease_candidate';
    public function __construct(private FixturePluginBinding $bindings, private UsagePolicy $policy,
        private UsageRegistry $registry, private UsageTermsBoundary $boundaries,
        private SubscriptionTermsTimeline $timeline, private FixtureLeaseClock $clock) {}

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
        // Resolve owner only as a hint; revalidate after stable User-first lock.
        $owner = DB::table('tx_access_subject as s')->join('tx_subscription as u', 'u.id', '=', 's.subscription_id')
            ->where('s.id', $scope->subjectId)->value('u.user_id');
        $user = $owner === null ? null : DB::table('tx_user')->where('id', $owner)->select(['id', 'banned'])->lockForUpdate()->first();
        if (!$user) { throw new DomainException('LEASE_USER_UNAVAILABLE'); }
        $this->bindings->guard();
        $plugin = Plugin::query()->where('code', FixturePluginBinding::CODE)->lockForUpdate()->first();
        if (!$plugin || !$plugin->is_enabled) { throw new DomainException('PROVIDER_DISABLED'); }
        [$binding, $registration] = $this->bindings->locked($plugin, true);
        if ($registration->provider_id !== $scope->providerId) { throw new DomainException('UNAUTHORIZED_SCOPE'); }
        // Binding runtime check covers issue only; explicitly require query and revoke as well.
        $grants = json_decode($registration->operation_grants, true, flags: JSON_THROW_ON_ERROR);
        if (array_diff(['issue', 'query', 'revoke'], $grants)) { throw new DomainException('UNSUPPORTED_OPERATION'); }
        FixtureLifecycleService::assertIssuable($registration, $scope->subjectId);
        $issue = DB::table('tx_credential_provider_shadow_operation')->where('operation_ref', $issueRef)->lockForUpdate()->first();
        if (!$issue) { throw new DomainException('LEASE_ISSUE_INVALID'); }
        [$issued, $result] = FixtureLeaseCandidateCodec::issue($issue, $registration, $scope);
        $source = DB::table('tx_node_usage_source')->where('id', $input['source_id'])->lockForUpdate()->first();
        if (!$source || !$source->current) { throw new DomainException('LEASE_SOURCE_UNAVAILABLE'); }
        foreach (['id', 'node_id', 'source_epoch', 'receipt_generation'] as $field) { FixtureCodec::decimal((string) $source->$field); }
        $this->registry->identity($source); // No Server lookup or public source/grant call.
        $subject = DB::table('tx_access_subject')->where('id', $scope->subjectId)->lockForUpdate()->first();
        $sub = $subject ? DB::table('tx_subscription')->where('id', $subject->subscription_id)->lockForUpdate()->first() : null;
        if (!$subject || !$sub || (string) $sub->user_id !== (string) $user->id || $subject->provider_id !== $scope->providerId
            || (string) $sub->id !== $issued['subscription_id'] || (string) $sub->current_terms_id !== $issued['terms_id']
            || $input['expected_terms_id'] !== $issued['terms_id'] || (string) $subject->auth_version !== $issued['expected_subject_version']
            || $input['expected_subject_version'] !== $issued['expected_subject_version']) { throw new DomainException('LEASE_CURRENT_BINDING_CHANGED'); }
        if ($user->banned || $subject->revoked_at !== null || !in_array($subject->status, ['pending', 'active'], true)
            || $sub->status !== 'active' || $sub->ended_at !== null) { throw new DomainException('LEASE_CURRENT_DISABLED'); }
        $terms = DB::table('tx_subscription_terms')->where('id', $input['expected_terms_id'])->sharedLock()->first();
        $periods = DB::table('tx_usage_period')->where('subscription_id', $sub->id)->orderBy('id')->limit(1001)->lockForUpdate()->get();
        $period = $periods->first(fn ($p) => (string) $p->id === $input['usage_period_id']);
        $tariff = DB::table('tx_tariff_revision')->where('id', $input['tariff_revision_id'])->sharedLock()->first();
        if (!$terms || (string) $terms->subscription_id !== (string) $sub->id || !$period || $periods->count() > 1000
            || $period->status === 'closed' || $period->boundary_kind !== 'known' || $period->starts_at === null || $period->ends_at === null
            || (string) $period->quota_bytes !== (string) $terms->quota_bytes) { throw new DomainException('LEASE_PERIOD_INVALID'); }
        $quota = FixtureLeaseCandidateCodec::number((string) $period->quota_bytes);
        $billable = FixtureLeaseCandidateCodec::number((string) $period->billable_bytes);
        FixtureLeaseCandidateCodec::number((string) $subject->revocation_fact_version);
        if (!is_string($subject->provider_subject_ref) || strlen($subject->provider_subject_ref) > 128) { throw new DomainException('LEASE_CURRENT_BINDING_CHANGED'); }
        if (bccomp($billable, $quota, 0) >= 0) { throw new DomainException('LEASE_SETTLED_QUOTA_EXHAUSTED'); }
        if (!$tariff || (string) $tariff->node_id !== (string) $source->node_id) { throw new DomainException('LEASE_TARIFF_INVALID'); }
        $tariffData = ['node_id' => (int) $tariff->node_id,
            'numerator' => FixtureLeaseCandidateCodec::number((string) $tariff->numerator),
            'denominator' => FixtureCodec::decimal((string) $tariff->denominator),
            'effective_from' => FixtureLeaseCandidateCodec::dbTime($tariff->effective_from),
            'effective_until' => FixtureLeaseCandidateCodec::dbTime($tariff->effective_until), 'rounding_revision' => $tariff->rounding_revision];
        if ($tariff->rounding_revision !== 'floor-per-direction-v1' || !hash_equals(FixtureLeaseCandidateCodec::hash($tariff->content_hash), UsageRegistry::hash($tariffData))) {
            throw new DomainException('LEASE_TARIFF_INVALID');
        }
        $segments = $this->timeline->resolve((string) $sub->id, true);
        $monotonic = $this->clock->monotonic(); $now = FixtureCodec::time($this->clock->wall());
        $segment = null; foreach ($segments as $s) { if ($s['terms_id'] === $issued['terms_id']) { $segment = $s; } }
        if (!$segment) { throw new DomainException('LEASE_INTERVAL_INVALID'); }
        $end = min($now + 60, $result['credential_expires_at'], $sub->expires_at === null ? PHP_INT_MAX : FixtureLeaseCandidateCodec::dbTime($sub->expires_at),
            $segment['effective_until'] ?? PHP_INT_MAX, FixtureLeaseCandidateCodec::dbTime($period->ends_at), $tariffData['effective_until']);
        if ($issued['not_before_at'] > $now || $result['issued_at'] > $now || $end <= $now
            || $now < FixtureLeaseCandidateCodec::dbTime($sub->starts_at) || $now < $tariffData['effective_from']) { throw new DomainException('LEASE_INTERVAL_INVALID'); }
        $boundary = $this->boundaries->freeze((string) $sub->id, $issued['terms_id'], $period, $now, $end);
        return [['binding_id' => (string) $binding->id, 'registration_id' => (string) $registration->id, 'provider_instance_epoch' => (string) $registration->instance_epoch,
            'issue_operation_id' => (string) $issue->id, 'issue_input' => $issued, 'issue_result' => $result,
            'issue_input_hash' => $issue->input_hash, 'issue_result_hash' => $issue->result_hash,
            'source_id' => (string) $source->id, 'node_id' => (string) $source->node_id, 'source_epoch' => (string) $source->source_epoch,
            'core_instance_id' => $source->core_instance_id, 'receipt_generation' => (string) $source->receipt_generation,
            'user_id' => (string) $user->id, 'subscription_id' => (string) $sub->id, 'terms_id' => $issued['terms_id'],
            'usage_period_id' => (string) $period->id, 'tariff_revision_id' => (string) $tariff->id,
            'subject_core_ref' => $subject->provider_subject_ref, 'auth_version' => (string) $subject->auth_version,
            'revocation_version' => (string) $subject->revocation_fact_version, 'terms_hash' => FixtureLeaseCandidateCodec::hash($terms->content_hash),
            'period_quota_bytes' => $quota, 'tariff_hash' => $tariff->content_hash, 'boundary' => $boundary,
            'policy_revision' => FixtureLeaseCandidateCodec::POLICY,
            'policy_digest' => FixtureCodec::hash(['revision' => FixtureLeaseCandidateCodec::POLICY, 'ttl_seconds' => 60]),
            'prepared_at' => $now, 'candidate_expires_at' => $end], $monotonic];
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
