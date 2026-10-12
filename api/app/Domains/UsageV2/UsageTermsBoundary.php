<?php

namespace App\Domains\UsageV2;

use App\Services\Subscription\SubscriptionTermsTimeline;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Consumes the subscription projection; never moves usage to the current Terms or period. */
final class UsageTermsBoundary
{
    private const REVISION = 'terms-period-half-open-v1';

    public function __construct(private SubscriptionTermsTimeline $timeline) {}

    /** Call after locking the subscription and selected period in the owning transaction. */
    public function freeze(string $subscription, string $terms, object $period, int $start, int $end): array
    {
        if ((string) $period->subscription_id !== $subscription || $period->boundary_kind !== 'known'
            || $period->starts_at === null || $period->ends_at === null || $start >= $end
            || !UsageRegistry::covers($start, $end, $period->starts_at, $period->ends_at)) {
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
        if (!$selected || !UsageRegistry::covers($start, $end, $selected['effective_from'], $selected['effective_until'])) {
            throw new UsageError('USAGE_TERMS_BOUNDARY');
        }
        return ['boundary_revision' => self::REVISION, 'period_creation_terms_id' => (string) $period->terms_id,
            'terms_effective_from' => $selected['effective_from'], 'terms_effective_until' => $selected['effective_until'],
            'terms_ending_transition_id' => $selected['ending_transition_id']];
    }

    /** New boundaries may narrow a historical range, but never reassign an accepted interval. */
    public function assertFrozen(array $entry, object $period, int $start, int $end): void
    {
        $creation = $entry['period_creation_terms_id'] ?? $entry['terms_id']; // B3.1 retained evidence.
        if ((string) $period->terms_id !== $creation
            || !UsageRegistry::covers($start, $end, $entry['period_started_at'], $entry['period_ended_at'])) {
            throw new UsageError('USAGE_FROZEN_PERIOD_BOUNDARY');
        }
        if (isset($entry['boundary_revision']) && ($entry['boundary_revision'] !== self::REVISION
            || !array_key_exists('terms_effective_until', $entry) || !isset($entry['terms_effective_from'])
            || !UsageRegistry::covers($start, $end, $entry['terms_effective_from'], $entry['terms_effective_until']))) {
            throw new UsageError('USAGE_FROZEN_TERMS_BOUNDARY');
        }
        $this->freeze($entry['subscription_id'], $entry['terms_id'], $period, $start, $end);
        $terms = DB::table('tx_subscription_terms')->where('id', $entry['terms_id'])->sharedLock()->first();
        if (!$terms || !hash_equals($entry['terms_hash'], $terms->content_hash)) {
            throw new UsageError('USAGE_FROZEN_TERMS_CHANGED');
        }
    }
}
