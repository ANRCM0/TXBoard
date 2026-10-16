<?php

namespace App\Domains\Subscription\Services;

use App\Domains\Installation\InstallationAuthority;
use App\Domains\UsageV2\NativeUsageReservations;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/** A coherent, non-secret entitlement projection, not a credential or Node grant. */
final class SubscriptionEntitlementResolver
{
    public const SCHEMA = 'core-entitlement/v1';
    private const HISTORY_LIMIT = 1000;

    public function __construct(
        private readonly NativeSubscriptionStore $store,
        private readonly SubscriptionTermsTimeline $timeline,
    ) {}

    public function resolve(int $userId, ?int $at = null): array
    {
        $at ??= time();
        if ($userId <= 0 || $at < 0 || $at > 9007199254740991) {
            throw new InvalidArgumentException('ENTITLEMENT_INPUT_INVALID');
        }
        if (DB::transactionLevel() !== 0) {
            throw new DomainException('ENTITLEMENT_OUTER_TRANSACTION_UNSUPPORTED');
        }

        return DB::transaction(function () use ($userId, $at): array {
            // Admission owns the first shared lock; preparation must never admit reads.
            $installation = app(InstallationAuthority::class)->assertNativeRead();
            try {
                return $this->readLocked($userId, $at, $installation);
            } catch (QueryException $error) {
                return $this->denied($userId, 'ENTITLEMENT_STORAGE_FAILED');
            } catch (RuntimeException|DomainException|JsonException|InvalidArgumentException $error) {
                return $this->denied($userId, 'ENTITLEMENT_EVIDENCE_INVALID');
            }
        }, 3);
    }

    /** Reuse the authoritative projection inside an account-first aggregate transaction. */
    public function resolveLocked(int $userId, int $at): array
    {
        if (DB::transactionLevel() < 1 || $userId <= 0 || $at < 0 || $at > 9007199254740991) {
            throw new DomainException('ENTITLEMENT_TRANSACTION_REQUIRED');
        }
        $installation = app(InstallationAuthority::class)->assertNativeRead();
        // Unlike the public diagnostic read, damaged evidence aborts the entire signed snapshot.
        return $this->readLocked($userId, $at, $installation);
    }

    private function readLocked(int $userId, int $at, array $installation): array
    {
        $user = DB::table('tx_user')->where('id', $userId)->sharedLock()->first(['id', 'banned']);
        if (!$user) {
            return $this->denied($userId, 'ENTITLEMENT_ACCOUNT_MISSING');
        }
        $this->require(in_array($user->banned, [true, false, 0, 1, '0', '1'], true));
        if ((bool) $user->banned) {
            return $this->denied($userId, 'ENTITLEMENT_ACCOUNT_BANNED');
        }
        $slots = DB::table('tx_subscription_slot')->where('user_id', $userId)
            ->orderBy('slot_no')->limit(2)->sharedLock()->get();
        $this->require($slots->count() <= 1);
        $slot = $slots->first();
        if (!$slot) {
            return $this->denied($userId, 'ENTITLEMENT_SLOT_MISSING');
        }
        $this->require((string) $slot->slot_no === '1');
        SubscriptionTermsTimeline::decimal($slot->row_version, true);
        if ($slot->subscription_id === null) {
            $this->require($slot->reserved_at === null);
            return $this->denied($userId, 'ENTITLEMENT_SLOT_EMPTY');
        }
        $subscriptionId = SubscriptionTermsTimeline::decimal($slot->subscription_id, true);
        $reservedAt = SubscriptionTermsTimeline::timestamp($slot->reserved_at);
        $this->require($reservedAt <= $at);
        $subscriptions = DB::table('tx_subscription')->forceIndex('tx_sub_user_id_idx')->where('user_id', $userId)->orderBy('id')
            ->limit(self::HISTORY_LIMIT + 1)->sharedLock()->get();
        $this->require($subscriptions->count() <= self::HISTORY_LIMIT);
        $subscription = null;
        foreach ($subscriptions as $candidate) {
            $this->store->assertOwnership($candidate, $userId, $installation['installation_id'] ?? '');
            if ((string) $candidate->id === $subscriptionId) {
                $subscription = $candidate;
            } elseif (in_array($candidate->status, ['active', 'suspended'], true)
                && ($candidate->expires_at === null || SubscriptionTermsTimeline::timestamp($candidate->expires_at) > $at)) {
                throw new DomainException('ENTITLEMENT_SLOT_AMBIGUOUS');
            }
        }
        $this->require($subscription !== null);
        $version = SubscriptionTermsTimeline::decimal($subscription->row_version, true);
        $startsAt = SubscriptionTermsTimeline::timestamp($subscription->starts_at);
        $expiresAt = SubscriptionTermsTimeline::timestamp($subscription->expires_at, true);
        $this->require($expiresAt === null || $expiresAt > $startsAt);
        if ($subscription->status !== 'active') {
            $this->require(in_array($subscription->status, ['pending', 'suspended', 'expired', 'revoked'], true));
            return $this->denied($userId, match ($subscription->status) {
                'suspended' => 'ENTITLEMENT_SUSPENDED',
                'expired' => 'ENTITLEMENT_EXPIRED',
                'revoked' => 'ENTITLEMENT_REVOKED',
                default => 'ENTITLEMENT_PENDING',
            });
        }
        $this->require($subscription->ended_at === null);
        if ($expiresAt !== null && $expiresAt <= $at) {
            return $this->denied($userId, 'ENTITLEMENT_EXPIRED');
        }
        if ($startsAt > $at) {
            return $this->denied($userId, 'ENTITLEMENT_NOT_STARTED');
        }

        $terms = DB::table('tx_subscription_terms')->where('subscription_id', $subscriptionId)
            ->orderBy('version_no')->limit(self::HISTORY_LIMIT + 1)->sharedLock()->get();
        $transitions = DB::table('tx_subscription_terms_transition')->where('subscription_id', $subscriptionId)
            ->orderBy('id')->limit(self::HISTORY_LIMIT + 1)->sharedLock()->get();
        $this->require($terms->count() <= self::HISTORY_LIMIT && $transitions->count() <= self::HISTORY_LIMIT);
        $tip = SubscriptionTermsTimeline::decimal($subscription->current_terms_id, true);
        $segments = $this->timeline->fromRows($subscriptionId,
            $terms->map(static fn (object $row): array => (array) $row)->all(),
            $transitions->map(static fn (object $row): array => (array) $row)->all(), $tip);
        $periods = DB::table('tx_usage_period')->where('subscription_id', $subscriptionId)->orderBy('id')
            ->limit(self::HISTORY_LIMIT + 1)->sharedLock()->get();
        $this->require($periods->count() <= self::HISTORY_LIMIT);
        $this->store->verifyHistory($subscription, [
            'terms' => $terms, 'transitions' => $transitions, 'periods' => $periods,
        ]);

        $snapshots = [];
        foreach ($terms as $term) {
            $snapshots[(string) $term->id] = $this->store->verifyTerms($term, $subscription);
        }
        foreach ($transitions as $transition) {
            $this->store->verifyTransition($transition, $subscription);
        }
        $effective = null;
        foreach ($segments as $segment) {
            if ($segment['effective_from'] <= $at
                && ($segment['effective_until'] === null || $segment['effective_until'] > $at)) {
                $effective = $segment;
            }
        }
        $this->require($effective !== null && $effective['terms_id'] === $tip);
        $current = $terms->firstWhere('id', $tip);
        $this->require($current !== null && $effective['effective_until'] === $expiresAt);
        $quota = SubscriptionTermsTimeline::decimal($current->quota_bytes);
        $period = $this->period($periods->all(), $segments, $subscriptionId);
        $this->require((string) $period->quota_bytes === $quota);
        if ((int) $period->starts_at > $at) {
            return $this->denied($userId, 'ENTITLEMENT_PERIOD_NOT_STARTED');
        }
        if (($period->ends_at !== null && (int) $period->ends_at <= $at)
            || ($period->next_reset_at !== null && (int) $period->next_reset_at <= $at)) {
            return $this->denied($userId, 'ENTITLEMENT_PERIOD_EXPIRED');
        }
        $consumed = NativeUsageReservations::consumed($period);
        if ($quota === '0' || (int) $consumed >= (int) $quota) {
            return $this->denied($userId, 'ENTITLEMENT_QUOTA_EXHAUSTED');
        }
        $snapshot = $snapshots[$tip];

        return [
            'schema' => self::SCHEMA, 'authorized' => true, 'reason' => 'ENTITLEMENT_GRANTED',
            'user_id' => (string) $userId, 'subscription_id' => $subscriptionId,
            'subscription_version' => $version, 'plan_id' => (string) $snapshot['plan_id'],
            'terms_id' => $tip, 'terms_version' => (string) $current->version_no,
            'plan_revision_id' => (string) $current->plan_revision_id,
            'usage_period_id' => (string) $period->id, 'period_terms_id' => (string) $period->terms_id,
            'period_version' => (string) $period->row_version,
            'group_id' => SubscriptionTermsTimeline::decimal($current->group_id, nullable: true),
            'speed_limit_mbps' => SubscriptionTermsTimeline::decimal($current->speed_limit_mbps, nullable: true),
            'device_limit' => SubscriptionTermsTimeline::decimal($current->device_limit, nullable: true),
            'expires_at' => $expiresAt === null ? null : (string) $expiresAt,
            'next_reset_at' => $period->next_reset_at === null ? null : (string) $period->next_reset_at,
            'quota_bytes' => $quota, 'consumed_bytes' => $consumed,
            'remaining_bytes' => (string) ((int) $quota - (int) $consumed),
            'upload_bytes' => (string) $period->upload_bytes, 'download_bytes' => (string) $period->download_bytes,
        ];
    }

    private function period(array $periods, array $segments, string $subscriptionId): object
    {
        usort($periods, static fn (object $left, object $right): int => (int) $left->period_no <=> (int) $right->period_no);
        $byTerms = array_column($segments, null, 'terms_id');
        $previousEnd = null;
        $previousNumber = 0;
        $open = null;
        foreach ($periods as $period) {
            $number = SubscriptionTermsTimeline::decimal($period->period_no, true);
            $startsAt = SubscriptionTermsTimeline::timestamp($period->starts_at);
            $endsAt = SubscriptionTermsTimeline::timestamp($period->ends_at, true);
            $lastReset = SubscriptionTermsTimeline::timestamp($period->last_reset_at, true);
            $nextReset = SubscriptionTermsTimeline::timestamp($period->next_reset_at, true);
            $this->require((string) $period->subscription_id === $subscriptionId
                && $period->boundary_kind === 'known' && in_array($period->status, ['open', 'closed'], true)
                && (int) $number > $previousNumber
                && ($endsAt === null || $endsAt > $startsAt)
                && ($lastReset === null || $lastReset <= $startsAt)
                && ($nextReset === null || $nextReset > $startsAt)
                && ($previousEnd === null || $startsAt >= $previousEnd)
                && ($period->status !== 'closed' || $endsAt !== null));
            foreach (['row_version', 'quota_bytes', 'upload_bytes', 'download_bytes', 'billable_bytes', 'legacy_reset_count'] as $field) {
                SubscriptionTermsTimeline::decimal($period->$field, $field === 'row_version');
            }
            $opening = $byTerms[(string) $period->terms_id] ?? null;
            $this->require($opening !== null && $startsAt >= $opening['effective_from']
                && ($opening['effective_until'] === null || $startsAt < $opening['effective_until']));
            if ($period->status === 'open') {
                $this->require($open === null);
                $open = $period;
            }
            $previousEnd = $endsAt ?? PHP_INT_MAX;
            $previousNumber = (int) $number;
        }
        $this->require($open !== null && $periods[array_key_last($periods)]->id === $open->id);
        return $open;
    }

    private function denied(int $userId, string $reason): array
    {
        return ['schema' => self::SCHEMA, 'authorized' => false, 'reason' => $reason, 'user_id' => (string) $userId];
    }

    private function require(bool $condition): void
    {
        if (!$condition) {
            throw new DomainException('ENTITLEMENT_EVIDENCE_INVALID');
        }
    }
}
