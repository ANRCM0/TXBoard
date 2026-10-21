<?php

namespace App\Domains\Subscription\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Authenticates an administrator's amendment without altering the purchased anchor. */
final class NativeSubscriptionAmendmentEvidence
{
    public const SOURCE = 'core-admin-subscription-amendment/v1';
    public const PLAN_SOURCE = 'core-admin-subscription-amendment/v2';

    public static function isAmendment(?string $schema): bool { return in_array($schema, [self::SOURCE, self::PLAN_SOURCE], true); }

    /** Publication prices are evidence only; entitlement reassignment creates no financial event. */
    public function publication(array $binding): array
    {
        if (array_diff(array_keys($binding), ['plan_id', 'revision_id', 'content_hash']) || count($binding) !== 3
            || !is_string($binding['content_hash'] ?? null) || !preg_match('/\A[0-9a-f]{64}\z/D', $binding['content_hash'])) {
            throw new DomainException('NATIVE_AMENDMENT_EVIDENCE_INVALID');
        }
        $planId = SubscriptionTermsTimeline::decimal($binding['plan_id'] ?? null, true);
        $revisionId = SubscriptionTermsTimeline::decimal($binding['revision_id'] ?? null, true);
        $read = fn () => app(PlanPublicationService::class)->verifiedRevision((int) $revisionId);
        $verified = DB::transactionLevel() > 0 ? $read() : DB::transaction($read);
        if ((string) $verified['revision']->plan_id !== $planId || !hash_equals($verified['revision']->content_hash, $binding['content_hash'])) {
            throw new DomainException('NATIVE_AMENDMENT_EVIDENCE_INVALID');
        }
        return $verified;
    }

    public function hash(array $payload): string
    {
        return hash_hmac('sha256', SubscriptionTermsTimeline::json($payload), (string) config('app.key'));
    }

    public function decode(string $json): array
    {
        try { $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new DomainException('NATIVE_AMENDMENT_EVIDENCE_INVALID'); }
        if (!is_array($value) || array_is_list($value)) throw new DomainException('NATIVE_AMENDMENT_EVIDENCE_INVALID');
        return $value;
    }

    public function snapshots(object $row): array
    {
        $request = $this->decode($row->request_snapshot); $source = $this->decode($row->source_snapshot);
        $result = $this->decode($row->result_snapshot);
        if (!hash_equals((string) $row->request_hash, $this->hash($request))
            || !hash_equals((string) $row->content_hash, $this->hash(['request' => $request, 'source' => $source, 'result' => $result]))
            || ($request['installation_id'] ?? null) !== $row->installation_id
            || ($request['actor_id'] ?? null) !== (string) $row->actor_id
            || ($request['user_id'] ?? null) !== (string) $row->user_id
            || ($request['subscription_id'] ?? null) !== (string) $row->subscription_id
            || ($request['business_key'] ?? null) !== $row->business_key
            || !self::isAmendment($source['schema'] ?? null)
            || ($source['business_key'] ?? null) !== $row->business_key
            || ($source['actor_id'] ?? null) !== (string) $row->actor_id
            || ($source['reason'] ?? null) !== ($request['reason'] ?? null)
            || ($result['terms_id'] ?? null) !== (string) $row->terms_id
            || ($result['transition_id'] ?? null) !== (string) $row->transition_id
            || ($result['user_id'] ?? null) !== (string) $row->user_id
            || ($result['subscription_id'] ?? null) !== (string) $row->subscription_id
            || ($result['applied_at'] ?? null) !== (int) $row->created_at) {
            throw new DomainException('NATIVE_AMENDMENT_EVIDENCE_INVALID');
        }
        return [$request, $source, $result];
    }

    public function effective(object $terms, object $subscription, array $source, bool $currentRead): array
    {
        if (!Schema::hasTable('tx_native_subscription_amendment')) throw new DomainException('NATIVE_AMENDMENT_CAPABILITY_UNAVAILABLE');
        $query = DB::table('tx_native_subscription_amendment')->where('terms_id', $terms->id);
        $row = ($currentRead ? $query->sharedLock() : $query)->first();
        if ($row === null) throw new DomainException('NATIVE_AMENDMENT_EVIDENCE_INVALID');
        [$request, $stored, $result] = $this->snapshots($row);
        $previousQuery = DB::table('tx_subscription_terms')->where('id', $source['previous_terms_id'] ?? null);
        $transitionQuery = DB::table('tx_subscription_terms_transition')->where('id', $row->transition_id);
        $previous = ($currentRead ? $previousQuery->sharedLock() : $previousQuery)->first();
        $transition = ($currentRead ? $transitionQuery->sharedLock() : $transitionQuery)->first();
        $store = app(NativeSubscriptionStore::class);
        if ($row->installation_id !== $subscription->installation_id
            || (string) $row->subscription_id !== (string) $subscription->id || (string) $row->user_id !== (string) $subscription->user_id
            || SubscriptionTermsTimeline::json($stored) !== SubscriptionTermsTimeline::json($source)
            || !$previous || (string) $previous->subscription_id !== (string) $subscription->id
            || (string) $previous->source_order_id !== (string) $terms->source_order_id
            || ($source['schema'] !== self::PLAN_SOURCE && (string) $previous->plan_revision_id !== (string) $terms->plan_revision_id)
            || !hash_equals((string) $previous->content_hash, (string) ($source['previous_content_hash'] ?? ''))
            || !hash_equals((string) $previous->content_hash, $store->termsHash($previous))
            || (int) $terms->version_no !== (int) $previous->version_no + 1
            || (int) $terms->effective_from !== (int) $row->created_at
            || !$transition || (string) $transition->previous_terms_id !== (string) $previous->id
            || (string) $transition->next_terms_id !== (string) $terms->id || $transition->business_key !== $row->business_key
            || (int) $transition->effective_at !== (int) $row->created_at
            || ($request['expected_terms_id'] ?? null) !== (string) $previous->id) {
            throw new DomainException('NATIVE_AMENDMENT_EVIDENCE_INVALID');
        }
        $store->verifyTransition($transition, $subscription);
        $previousSource = $this->decode($previous->source_snapshot);
        $anchor = self::isAmendment($previousSource['schema'] ?? null) ? ($previousSource['reset_anchor_expires_at'] ?? null)
            : ($previous->effective_until === null ? null : (int) $previous->effective_until);
        if (!array_key_exists('reset_anchor_expires_at', $source) || $source['reset_anchor_expires_at'] !== $anchor) {
            throw new DomainException('NATIVE_AMENDMENT_EVIDENCE_INVALID');
        }
        $effective = $source['effective'] ?? null;
        $publication = null;
        if ($source['schema'] === self::PLAN_SOURCE) {
            $binding = $source['plan_publication'] ?? null;
            if (!is_array($binding) || (string) $terms->plan_revision_id !== ($binding['revision_id'] ?? null)) throw new DomainException('NATIVE_AMENDMENT_EVIDENCE_INVALID');
            $selected = $request['changes']['plan_revision'] ?? ($previousSource['plan_publication'] ?? null);
            if (SubscriptionTermsTimeline::json($binding) !== SubscriptionTermsTimeline::json($selected ?? [])) throw new DomainException('NATIVE_AMENDMENT_EVIDENCE_INVALID');
            $publication = $this->publication($binding)['source'];
        } elseif (isset($request['changes']['plan_revision']) || isset($source['plan_publication'])) throw new DomainException('NATIVE_AMENDMENT_EVIDENCE_INVALID');
        if (!is_array($effective) || array_diff(array_keys($effective), ['quota_bytes', 'group_id', 'speed_limit_mbps', 'device_limit', 'reset_policy', 'plan_id'])
            || SubscriptionTermsTimeline::decimal($effective['plan_id'] ?? null, true) !== SubscriptionTermsTimeline::decimal($publication['plan_id'] ?? $source['purchase']['plan_id'] ?? null, true)
            || SubscriptionTermsTimeline::decimal($effective['group_id'] ?? null, nullable: true) !== SubscriptionTermsTimeline::decimal($publication['group_id'] ?? ($publication !== null ? null : ($source['purchase']['group_id'] ?? null)), nullable: true)
            || SubscriptionTermsTimeline::json($effective['reset_policy'] ?? []) !== SubscriptionTermsTimeline::json($source['purchase']['reset_policy'] ?? [])) {
            throw new DomainException('NATIVE_AMENDMENT_EVIDENCE_INVALID');
        }
        foreach (['quota_bytes', 'speed_limit_mbps', 'device_limit', 'expires_at'] as $field) {
            $expected = $field === 'expires_at' ? ($previous->effective_until === null ? null : (string) $previous->effective_until)
                : ($previous->$field === null ? null : (string) $previous->$field);
            if ($field !== 'expires_at' && isset($request['changes']['plan_revision'])) $expected = $publication[$field];
            if (array_key_exists($field, $request['changes'] ?? [])) $expected = $request['changes'][$field];
            $actual = $field === 'expires_at' ? ($terms->effective_until === null ? null : (string) $terms->effective_until) : ($effective[$field] ?? null);
            if ($expected !== $actual) throw new DomainException('NATIVE_AMENDMENT_EVIDENCE_INVALID');
        }
        if (($result['expires_at'] ?? null) !== ($terms->effective_until === null ? null : (int) $terms->effective_until)) {
            throw new DomainException('NATIVE_AMENDMENT_EVIDENCE_INVALID');
        }
        $periodQuery = DB::table('tx_usage_period')->where('id', $result['usage_period_id'] ?? null);
        $period = ($currentRead ? $periodQuery->sharedLock() : $periodQuery)->first();
        if (!$period || (string) $period->subscription_id !== (string) $subscription->id
            || (int) SubscriptionTermsTimeline::decimal($result['period_version'] ?? null, true) > (int) $period->row_version
            || (int) SubscriptionTermsTimeline::decimal($result['subscription_version'] ?? null, true) > (int) $subscription->row_version) {
            throw new DomainException('NATIVE_AMENDMENT_EVIDENCE_INVALID');
        }
        $quotaChanged = (string) $previous->quota_bytes !== (string) $terms->quota_bytes;
        if ($quotaChanged !== (($result['quota_adjustment_id'] ?? null) !== null)) throw new DomainException('NATIVE_AMENDMENT_EVIDENCE_INVALID');
        if ($quotaChanged) {
            $adjustmentQuery = DB::table('tx_usage_adjustment')->where('id', $result['quota_adjustment_id']);
            $adjustment = ($currentRead ? $adjustmentQuery->sharedLock() : $adjustmentQuery)->first();
            $payload = ['usage_period_id' => (string) $period->id, 'terms_transition_id' => (string) $row->transition_id,
                'business_key' => $row->business_key, 'kind' => 'quota_change',
                'quota_delta_bytes' => bcsub((string) $terms->quota_bytes, (string) $previous->quota_bytes, 0),
                'policy_version' => 'core-admin-quota-amendment/v1'];
            if (!$adjustment || (string) $adjustment->usage_period_id !== $payload['usage_period_id']
                || (string) $adjustment->terms_transition_id !== $payload['terms_transition_id']
                || $adjustment->business_key !== $payload['business_key'] || $adjustment->kind !== $payload['kind']
                || (string) $adjustment->quota_delta_bytes !== $payload['quota_delta_bytes']
                || $adjustment->policy_version !== $payload['policy_version']
                || !hash_equals((string) $adjustment->content_hash, SubscriptionTermsTimeline::hash($payload))) {
                throw new DomainException('NATIVE_AMENDMENT_EVIDENCE_INVALID');
            }
        }
        return $effective;
    }
}
