<?php

namespace App\Domains\CredentialProviderFixture;

use App\Domains\UsageV2\UsageRegistry;
use App\Domains\UsageV2\UsageTermsBoundary;
use App\Models\Plugin;
use App\Services\Subscription\SubscriptionTermsTimeline;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Owning caller holds its transaction; shared current reads never dispatch IPC or grant. */
final class FixtureLeaseCurrentBoundary
{
    public function __construct(private FixturePluginBinding $bindings, private UsageRegistry $registry,
        private UsageTermsBoundary $boundaries, private SubscriptionTermsTimeline $timeline, private FixtureLeaseClock $clock) {}

    public function forCandidate(FixtureScope $scope, string $issueRef, array $input): array
    {
        return $this->resolve($scope, $issueRef, $input);
    }

    /** Only the exact persisted binding transition, validated by the owning authorization service. */
    public function forCommitted(FixtureScope $scope, string $issueRef, array $input, array $transition): array
    {
        FixtureAuthorizationCodec::transition($transition);
        return $this->resolve($scope, $issueRef, $input, $transition);
    }

    private function resolve(FixtureScope $scope, string $issueRef, array $input, ?array $transition = null): array
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
            || $input['expected_terms_id'] !== $issued['terms_id']
            || (string) $subject->auth_version !== ($transition['auth_version'] ?? $issued['expected_subject_version'])
            || $input['expected_subject_version'] !== $issued['expected_subject_version']) { throw new DomainException('LEASE_CURRENT_BINDING_CHANGED'); }
        if ($transition !== null && ($transition['original_auth_version'] !== $issued['expected_subject_version']
            || $transition['subject_ref'] !== $result['provider_subject_ref'] || $subject->provider_subject_ref !== $transition['subject_ref']
            || $subject->status !== 'active' || (string) $subject->row_version !== $transition['row_version'])) {
            throw new DomainException('AUTHORIZATION_BINDING_CHANGED');
        }
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
}
