<?php

namespace App\Domains\UsageV2;

use App\Domains\Subscription\Services\SubscriptionTermsTimeline;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Consumes the subscription projection; never moves usage to the current Terms or period. */
final class UsageTermsBoundary
{
    private const REVISION = 'terms-period-half-open-v1';

    public function __construct(private SubscriptionTermsTimeline $timeline) {}

    /** The immutable native proof records the complete original window before any later shortening. */
    public function nativeCutoff(object $grant, object $subscription, object $subject, object $period, array $original): ?int
    {
        try {
            $selected = null;
            foreach ($this->timeline->resolve((string) $subscription->id, currentRead: true) as $segment) {
                if ($segment['terms_id'] === (string) $grant->terms_id) { $selected = $segment; }
            }
            if ($selected === null || ($original['boundary_revision'] ?? null) !== self::REVISION
                || (string) $period->terms_id !== ($original['period_creation_terms_id'] ?? null)
                || SubscriptionTermsTimeline::timestamp($subscription->starts_at) !== $original['subscription_started_at']
                || SubscriptionTermsTimeline::timestamp($period->starts_at) !== $original['period_started_at']
                || $selected['effective_from'] !== $original['terms_effective_from']) {
                throw new UsageError('USAGE_NATIVE_CUTOFF_AMBIGUOUS');
            }
            $cutoff = null;
            $candidates = [$grant->revoked_at, $subject->revoked_at, $subscription->ended_at];
            foreach ([
                [$subscription->expires_at, $original['subscription_expires_at']],
                [$period->ends_at, $original['period_ended_at']],
                [$selected['effective_until'], $original['terms_effective_until']],
            ] as [$current, $previous]) {
                $current = SubscriptionTermsTimeline::timestamp($current, nullable: true);
                if ($current !== null && ($previous === null || $current < $previous)) { $candidates[] = $current; }
            }
            foreach ($candidates as $candidate) {
                $at = SubscriptionTermsTimeline::timestamp($candidate, nullable: true);
                if ($at === null) { continue; }
                if ($at < (int) $grant->issued_at) { throw new UsageError('USAGE_NATIVE_CUTOFF_AMBIGUOUS'); }
                if ($at < (int) $grant->lease_expires_at) { $cutoff = $cutoff === null ? $at : min($cutoff, $at); }
            }
            return $cutoff;
        } catch (DomainException) {
            throw new UsageError('USAGE_NATIVE_CUTOFF_AMBIGUOUS');
        }
    }

    /** Call after locking the subscription and selected period in the owning transaction. */
    public function freeze(string $subscription, string $terms, object $period, int $start, int $end): array
    {
        if ((string) $period->subscription_id !== $subscription || $period->boundary_kind !== 'known'
            || $period->starts_at === null || $start >= $end
            || !UsageEvidence::covers($start, $end, $period->starts_at, $period->ends_at)) {
            throw new UsageError('USAGE_PERIOD_BOUNDARY');
        }
        try {
            $segments = $this->timeline->resolve($subscription, currentRead: true);
            $periods = DB::table('tx_usage_period')->where('subscription_id', $subscription)->orderBy('id')->limit(1001)->sharedLock()->get();
            if ($periods->count() > 1000) { throw new UsageError('USAGE_PERIOD_EVIDENCE_LIMIT'); }
            foreach ($periods as $other) {
                if ($other->boundary_kind !== 'known' || $other->starts_at === null) { throw new UsageError('USAGE_PERIOD_BOUNDARY_UNKNOWN'); }
                $from = SubscriptionTermsTimeline::timestamp($other->starts_at);
                $until = SubscriptionTermsTimeline::timestamp($other->ends_at, nullable: true);
                if (($until !== null && $from >= $until)
                    || ((string) $other->id !== (string) $period->id && $from < $end && ($until === null || $until > $start))) {
                    throw new UsageError('USAGE_PERIOD_BOUNDARY_AMBIGUOUS');
                }
            }
        } catch (DomainException) {
            throw new UsageError('USAGE_TERMS_TIMELINE_INVALID');
        }
        $selected = null; $creation = null;
        foreach ($segments as $segment) {
            if ($segment['terms_id'] === $terms) { $selected = $segment; }
            if ($segment['terms_id'] === (string) $period->terms_id) { $creation = $segment; }
        }
        if (!$creation) {
            throw new UsageError('USAGE_PERIOD_TERMS_OWNERSHIP');
        }
        if (!$selected || !UsageEvidence::covers($start, $end, $selected['effective_from'], $selected['effective_until'])) {
            throw new UsageError('USAGE_TERMS_BOUNDARY');
        }
        return ['boundary_revision' => self::REVISION, 'period_creation_terms_id' => (string) $period->terms_id,
            'terms_effective_from' => $selected['effective_from'], 'terms_effective_until' => $selected['effective_until'],
            'terms_ending_transition_id' => $selected['ending_transition_id']];
    }

    /** New boundaries may narrow a historical range, but never reassign an accepted interval. */
    public function assertFrozen(array $entry, object $period, int $start, int $end): void
    {
        if (($entry['boundary_revision'] ?? null) !== self::REVISION
            || !isset($entry['period_creation_terms_id'], $entry['terms_effective_from'])
            || !array_key_exists('terms_effective_until', $entry)
            || !array_key_exists('terms_ending_transition_id', $entry)
            || !UsageEvidence::covers($start, $end, $entry['terms_effective_from'], $entry['terms_effective_until'])) {
            throw new UsageError('USAGE_FROZEN_TERMS_BOUNDARY');
        }
        if ((string) $period->terms_id !== $entry['period_creation_terms_id']
            || !UsageEvidence::covers($start, $end, $entry['period_started_at'], $entry['period_ended_at'])) {
            throw new UsageError('USAGE_FROZEN_PERIOD_BOUNDARY');
        }
        $this->freeze($entry['subscription_id'], $entry['terms_id'], $period, $start, $end);
        $terms = DB::table('tx_subscription_terms')->where('id', $entry['terms_id'])->sharedLock()->first();
        if (!$terms || !hash_equals($entry['terms_hash'], $terms->content_hash)) {
            throw new UsageError('USAGE_FROZEN_TERMS_CHANGED');
        }
    }
}
