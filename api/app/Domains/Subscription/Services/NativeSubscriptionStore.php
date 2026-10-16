<?php

namespace App\Domains\Subscription\Services;

use App\Domains\Billing\PurchasedOrderGrant;
use App\Models\Order;
use App\Models\OrderPurchaseTerms;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Shared native aggregate integrity and transaction-local persistence primitives. */
final class NativeSubscriptionStore
{
    public const AUTHORITY = 'core-subscription/v1';
    public const TERMS = 'core-subscription-terms/v1';
    public const OPERATION = 'core-subscription-operation/v1';

    public function __construct(
        private PurchasedOrderGrant $grants,
        private SubscriptionTermsTimeline $timeline,
        private NativeSubscriptionCalendar $calendar,
    ) {}

    public function assertOwnership(object $subscription, int $userId, string $installationId): void
    {
        if ((string) $subscription->user_id !== (string) $userId
            || ($subscription->authority_version ?? null) !== self::AUTHORITY
            || ($subscription->installation_id ?? null) !== $installationId) {
            throw new DomainException('NATIVE_SUBSCRIPTION_OWNERSHIP_INVALID');
        }
        SubscriptionTermsTimeline::decimal($subscription->id, true);
        SubscriptionTermsTimeline::decimal($subscription->row_version, true);
        SubscriptionTermsTimeline::timestamp($subscription->starts_at);
        SubscriptionTermsTimeline::timestamp($subscription->expires_at, true);
        SubscriptionTermsTimeline::timestamp($subscription->ended_at, true);
    }

    /** Installation admission must already hold its lock. User serializes initial slot insertion. */
    public function lockAccount(int $userId, int $now, string $installationId): array
    {
        $user = DB::table('tx_user')->where('id', $userId)->lockForUpdate()->first();
        if (!$user) {
            throw new DomainException('NATIVE_USER_MISSING');
        }
        $slot = DB::table('tx_subscription_slot')->where('user_id', $userId)->where('slot_no', 1)->lockForUpdate()->first();
        if (!$slot) {
            DB::table('tx_subscription_slot')->insert([
                'user_id' => $userId, 'slot_no' => 1, 'subscription_id' => null, 'reserved_at' => null,
                'row_version' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $slot = DB::table('tx_subscription_slot')->where('user_id', $userId)->where('slot_no', 1)->lockForUpdate()->first();
        }
        // Keep locking scans inside this account even when a small-table optimizer
        // would otherwise prefer a primary-key scan and lock other users' rows.
        $subscriptions = DB::table('tx_subscription')->forceIndex('tx_sub_user_id_idx')
            ->where('user_id', $userId)->orderBy('id')->limit(1001)->lockForUpdate()->get();
        if ($subscriptions->count() > 1000) {
            throw new DomainException('NATIVE_SUBSCRIPTION_HISTORY_LIMIT');
        }
        foreach ($subscriptions as $subscription) {
            $this->assertOwnership($subscription, $userId, $installationId);
            if (in_array($subscription->status, ['active', 'suspended'], true)
                && (string) $subscription->id !== (string) $slot->subscription_id) {
                throw new DomainException('NATIVE_UNSLOTTED_SUBSCRIPTION');
            }
        }
        if ($slot->subscription_id !== null && !$subscriptions->contains('id', $slot->subscription_id)) {
            throw new DomainException('NATIVE_SLOT_OWNERSHIP_INVALID');
        }
        SubscriptionTermsTimeline::decimal($slot->row_version, true);
        return [$user, $slot, $subscriptions];
    }

    public function lockHistory(object $subscription): array
    {
        $terms = DB::table('tx_subscription_terms')->where('subscription_id', $subscription->id)
            ->orderBy('version_no')->limit(1001)->lockForUpdate()->get();
        $transitions = DB::table('tx_subscription_terms_transition')->where('subscription_id', $subscription->id)
            ->orderBy('id')->limit(1001)->lockForUpdate()->get();
        $periods = DB::table('tx_usage_period')->where('subscription_id', $subscription->id)
            ->orderBy('id')->limit(1001)->lockForUpdate()->get();
        if (max($terms->count(), $transitions->count(), $periods->count()) > 1000) {
            throw new DomainException('NATIVE_AGGREGATE_HISTORY_LIMIT');
        }
        return ['terms' => $terms, 'transitions' => $transitions, 'periods' => $periods];
    }

    public function verifyTerms(object $terms, object $subscription): array
    {
        if (($subscription->authority_version ?? null) !== self::AUTHORITY
            || !is_string($subscription->installation_id ?? null) || $subscription->installation_id === ''
            || (string) $terms->subscription_id !== (string) $subscription->id
            || ($terms->schema_version ?? null) !== self::TERMS || ($terms->legacy_snapshot ?? null) !== null) {
            throw new DomainException('NATIVE_TERMS_OWNERSHIP_INVALID');
        }
        $source = $this->jsonObject($terms->source_snapshot);
        if (($source['schema'] ?? null) !== 'tx-core.purchase-terms/v2'
            || (string) $terms->source_order_id !== ($source['order_id'] ?? null)
            || (string) $subscription->user_id !== ($source['user_id'] ?? null)
            || (string) $terms->plan_revision_id !== ($source['catalog']['revision_id'] ?? null)) {
            throw new DomainException('NATIVE_TERMS_SOURCE_INVALID');
        }
        // These immutable source reads are deliberately nonlocking: writers check accepted usage first.
        $order = Order::query()->useWritePdo()->find($terms->source_order_id);
        $purchase = OrderPurchaseTerms::query()->useWritePdo()->where('order_id', $terms->source_order_id)->first();
        if (!$order || !$purchase || !in_array((int) $order->status, [Order::STATUS_PROCESSING, Order::STATUS_COMPLETED], true)
            || SubscriptionTermsTimeline::json($this->grants->resolve($order, $purchase) ?? []) !== SubscriptionTermsTimeline::json($source)) {
            throw new DomainException('NATIVE_TERMS_SOURCE_INVALID');
        }
        foreach (['quota_bytes', 'group_id', 'speed_limit_mbps', 'device_limit'] as $field) {
            if (SubscriptionTermsTimeline::decimal($terms->$field, nullable: $field !== 'quota_bytes')
                !== SubscriptionTermsTimeline::decimal($source[$field] ?? null, nullable: $field !== 'quota_bytes')) {
                throw new DomainException('NATIVE_TERMS_MIRROR_INVALID');
            }
        }
        if ($terms->billing_timezone !== $source['reset_policy']['timezone']
            || SubscriptionTermsTimeline::json($this->jsonObject($terms->reset_policy))
                !== SubscriptionTermsTimeline::json($source['reset_policy'])
            || !is_string($terms->content_hash) || !hash_equals($terms->content_hash, $this->termsHash($terms))) {
            throw new DomainException('NATIVE_TERMS_HASH_INVALID');
        }
        return $source;
    }

    public function termsHash(array|object $row): string
    {
        $row = (array) $row;
        $payload = [];
        foreach (['subscription_id', 'version_no', 'plan_revision_id', 'source_order_id'] as $field) {
            $payload[$field] = SubscriptionTermsTimeline::decimal($row[$field] ?? null, true);
        }
        foreach (['quota_bytes', 'group_id', 'speed_limit_mbps', 'device_limit'] as $field) {
            $payload[$field] = SubscriptionTermsTimeline::decimal($row[$field] ?? null, nullable: $field !== 'quota_bytes');
        }
        $payload += [
            'schema_version' => $row['schema_version'], 'effective_from' => SubscriptionTermsTimeline::timestamp($row['effective_from']),
            'effective_until' => SubscriptionTermsTimeline::timestamp($row['effective_until'], true),
            'billing_timezone' => $row['billing_timezone'], 'reset_policy' => $this->jsonObject($row['reset_policy']),
            'source_snapshot' => $this->jsonObject($row['source_snapshot']), 'legacy_snapshot' => $row['legacy_snapshot'] ?? null,
        ];
        return SubscriptionTermsTimeline::hash($payload);
    }

    public function verifyTransition(object $transition, ?object $subscription = null): void
    {
        $payload = [];
        foreach (['subscription_id', 'previous_terms_id', 'next_terms_id'] as $field) {
            $payload[$field] = SubscriptionTermsTimeline::decimal($transition->$field, true);
        }
        $payload['effective_at'] = SubscriptionTermsTimeline::timestamp($transition->effective_at);
        $payload['business_key'] = $this->businessKey($transition->business_key);
        if (($subscription !== null && (string) $transition->subscription_id !== (string) $subscription->id)
            || !is_string($transition->content_hash)
            || !hash_equals($transition->content_hash, SubscriptionTermsTimeline::hash($payload))) {
            throw new DomainException('NATIVE_TRANSITION_INVALID');
        }
    }

    public function verifyHistory(object $subscription, array $history): array
    {
        $sources = [];
        $version = 0;
        foreach ($history['terms'] as $terms) {
            $sources[(string) $terms->id] = $this->verifyTerms($terms, $subscription);
            if ((int) $terms->version_no !== ++$version
                || (int) DB::table('tx_order')->where('id', $terms->source_order_id)->value('status') !== Order::STATUS_COMPLETED) {
                throw new DomainException('NATIVE_TERMS_SOURCE_INVALID');
            }
        }
        foreach ($history['transitions'] as $transition) {
            $this->verifyTransition($transition, $subscription);
        }
        $segments = $this->timeline->fromRows((string) $subscription->id,
            $history['terms']->map(fn ($row) => (array) $row)->all(),
            $history['transitions']->map(fn ($row) => (array) $row)->all(), (string) $subscription->current_terms_id);
        $previousEnd = null;
        $number = 0;
        $sawOpen = false;
        foreach ($history['periods']->sortBy('period_no') as $period) {
            $start = SubscriptionTermsTimeline::timestamp($period->starts_at);
            $end = SubscriptionTermsTimeline::timestamp($period->ends_at, true);
            $opening = $history['terms']->firstWhere('id', $period->terms_id);
            $segment = collect($segments)->firstWhere('terms_id', (string) $period->terms_id);
            if (!$opening || $period->boundary_kind !== 'known' || !in_array($period->status, ['open', 'closed'], true)
                || (int) $period->period_no !== ++$number || ($end !== null && $end <= $start)
                || ($previousEnd !== null && $start !== $previousEnd) || $sawOpen
                || !$segment || $start < $segment['effective_from']
                || ($segment['effective_until'] !== null && $start >= $segment['effective_until'])
                || ($period->status === 'closed' && $end === null)) {
                throw new DomainException('NATIVE_PERIOD_HISTORY_INVALID');
            }
            foreach (['quota_bytes', 'upload_bytes', 'download_bytes', 'billable_bytes', 'legacy_reset_count'] as $field) {
                SubscriptionTermsTimeline::decimal($period->$field);
            }
            SubscriptionTermsTimeline::decimal($period->row_version, true);
            $lastReset = SubscriptionTermsTimeline::timestamp($period->last_reset_at);
            $nextReset = SubscriptionTermsTimeline::timestamp($period->next_reset_at, true);
            if ($lastReset !== $start || ($nextReset !== null && ($nextReset <= $start || ($end !== null && $end > $nextReset)))) {
                throw new DomainException('NATIVE_PERIOD_RESET_INVALID');
            }
            $sawOpen = $period->status === 'open';
            $previousEnd = $end ?? PHP_INT_MAX;
        }
        $open = $history['periods']->where('status', 'open');
        $live = in_array($subscription->status, ['active', 'suspended'], true);
        if (($live && $open->count() !== 1) || (!$live && $open->isNotEmpty())) {
            throw new DomainException('NATIVE_PERIOD_AMBIGUOUS');
        }
        $tip = $history['terms']->firstWhere('id', $subscription->current_terms_id);
        if (!$tip || ($live && (string) $open->first()->quota_bytes !== (string) $tip->quota_bytes)
            || (string) $tip->effective_until !== (string) $subscription->expires_at
            || ($sources[(string) $tip->id]['plan_id'] ?? null) !== (string) $subscription->plan_id) {
            throw new DomainException('NATIVE_TERMS_PROJECTION_INVALID');
        }
        $expiry = SubscriptionTermsTimeline::timestamp($subscription->expires_at, true);
        $ended = SubscriptionTermsTimeline::timestamp($subscription->ended_at, true);
        if (!in_array($subscription->status, ['active', 'suspended', 'expired', 'revoked'], true)
            || ($expiry !== null && $expiry <= (int) $subscription->starts_at)
            || ($live && $ended !== null) || (!$live && ($ended === null || $ended <= (int) $subscription->starts_at))
            || ($subscription->status === 'expired' && ($expiry === null || $ended !== $expiry))
            || ($subscription->status === 'revoked' && $expiry !== null && $ended > $expiry)
            || ($live && $open->first()->ends_at !== null && (int) $open->first()->ends_at
                !== $this->calendar->periodEnd($open->first()->next_reset_at === null ? null : (int) $open->first()->next_reset_at, $expiry))
            || ($live && $open->first()->ends_at === null && $this->calendar->periodEnd(
                $open->first()->next_reset_at === null ? null : (int) $open->first()->next_reset_at, $expiry) !== null)) {
            throw new DomainException('NATIVE_SUBSCRIPTION_PROJECTION_INVALID');
        }
        return ['terms' => $tip, 'period' => $open->first(), 'segments' => $segments, 'source' => $sources[(string) $tip->id]];
    }

    public function protectAcceptedUsage(string $subscriptionId, int $boundary): void
    {
        $batchesExist = Schema::hasTable('tx_usage_batch');
        $entriesExist = Schema::hasTable('tx_usage_entry');
        if ($batchesExist !== $entriesExist) {
            throw new DomainException('NATIVE_USAGE_EVIDENCE_UNAVAILABLE');
        }
        if (!$batchesExist) {
            return;
        }
        // First ordinary business read after aggregate locks: do not inherit a stale RR view
        // or lock batches in reverse order from the usage settlement worker.
        $batches = DB::table('tx_usage_batch')->where('ended_at', '>', $boundary)->orderBy('id')
            ->limit(1001)->get(['id', 'frozen_entries', 'frozen_hash']);
        if ($batches->count() > 1000) {
            throw new DomainException('NATIVE_USAGE_EVIDENCE_LIMIT');
        }
        foreach ($batches as $batch) {
            $entries = json_decode($batch->frozen_entries, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($entries) || !array_is_list($entries) || !$entries || count($entries) > 1000
                || !hash_equals($batch->frozen_hash, SubscriptionTermsTimeline::hash($entries))) {
                throw new DomainException('NATIVE_USAGE_EVIDENCE_INVALID');
            }
            foreach ($entries as $entry) {
                if (!is_array($entry) || !is_string($entry['subscription_id'] ?? null)) {
                    throw new DomainException('NATIVE_USAGE_EVIDENCE_INVALID');
                }
                if ($entry['subscription_id'] === $subscriptionId) {
                    throw new DomainException('NATIVE_ACCEPTED_USAGE_WOULD_BE_REASSIGNED');
                }
            }
        }
        if (DB::table('tx_usage_entry as entry')->join('tx_usage_batch as batch', 'batch.id', '=', 'entry.batch_id')
            ->where('entry.subscription_id', $subscriptionId)->where('batch.ended_at', '>', $boundary)->exists()) {
            throw new DomainException('NATIVE_ACCEPTED_USAGE_WOULD_BE_REASSIGNED');
        }
    }

    public function replay(object $operation, array $request, object $subscription): array
    {
        $stored = $this->jsonObject($operation->request_snapshot);
        $result = $this->jsonObject($operation->result_snapshot);
        if (!hash_equals((string) $operation->request_hash, SubscriptionTermsTimeline::hash($request))) {
            throw new DomainException('NATIVE_OPERATION_CONFLICT');
        }
        if (!hash_equals((string) $operation->request_hash, SubscriptionTermsTimeline::hash($stored))
            || !hash_equals((string) $operation->content_hash, SubscriptionTermsTimeline::hash(['request' => $stored, 'result' => $result]))
            || (string) $operation->subscription_id !== (string) $subscription->id
            || (string) $operation->user_id !== (string) $subscription->user_id
            || ($stored['user_id'] ?? null) !== (string) $operation->user_id
            || ($stored['business_key'] ?? null) !== $operation->business_key
            || ($stored['kind'] ?? null) !== $operation->kind
            || ($stored['order_id'] ?? null) !== ($operation->order_id === null ? null : (string) $operation->order_id)
            || ($result['schema'] ?? null) !== self::OPERATION
            || ($result['subscription_id'] ?? null) !== (string) $subscription->id
            || ($result['user_id'] ?? null) !== (string) $subscription->user_id) {
            throw new DomainException('NATIVE_OPERATION_DAMAGED');
        }
        SubscriptionTermsTimeline::decimal($result['subscription_version'] ?? null, true);
        SubscriptionTermsTimeline::decimal($result['period_version'] ?? null, true);
        SubscriptionTermsTimeline::timestamp($result['applied_at'] ?? null);
        SubscriptionTermsTimeline::timestamp($result['expires_at'] ?? null, true);
        $terms = DB::table('tx_subscription_terms')->where('id', $result['terms_id'] ?? null)->first();
        $period = DB::table('tx_usage_period')->where('id', $result['usage_period_id'] ?? null)->first();
        if (!$terms || !$period || (string) $period->subscription_id !== (string) $subscription->id
            || (string) $terms->subscription_id !== (string) $subscription->id
            || (int) $result['subscription_version'] > (int) $subscription->row_version
            || (int) $result['period_version'] > (int) $period->row_version
            || (int) $operation->created_at !== $result['applied_at']
            || !in_array($result['status'] ?? null, ['active', 'suspended', 'expired', 'revoked'], true)
            || ($operation->order_id !== null && (string) $terms->source_order_id !== (string) $operation->order_id)) {
            throw new DomainException('NATIVE_OPERATION_DAMAGED');
        }
        $this->verifyTerms($terms, $subscription);
        if (($result['transition_id'] ?? null) !== null) {
            $transition = DB::table('tx_subscription_terms_transition')->where('id', $result['transition_id'])->first();
            if (!$transition || (string) $transition->next_terms_id !== (string) $terms->id
                || $transition->business_key !== $operation->business_key) {
                throw new DomainException('NATIVE_OPERATION_DAMAGED');
            }
            $this->verifyTransition($transition, $subscription);
        }
        if (($result['previous_period_id'] ?? null) !== null) {
            $previous = DB::table('tx_usage_period')->where('id', $result['previous_period_id'])->first();
            if (!$previous || (string) $previous->subscription_id !== (string) $subscription->id || $previous->status !== 'closed'
                || (int) $previous->ends_at !== (int) $period->starts_at) {
                throw new DomainException('NATIVE_OPERATION_DAMAGED');
            }
        }
        if (($result['quota_adjustment_id'] ?? null) !== null) {
            $adjustment = DB::table('tx_usage_adjustment')->where('id', $result['quota_adjustment_id'])->first();
            if (!$adjustment || (string) $adjustment->usage_period_id !== (string) $period->id
                || (string) $adjustment->terms_transition_id !== (string) $result['transition_id']
                || $adjustment->business_key !== $operation->business_key || $adjustment->kind !== 'quota_change') {
                throw new DomainException('NATIVE_OPERATION_DAMAGED');
            }
            $payload = ['usage_period_id' => (string) $period->id, 'terms_transition_id' => (string) $adjustment->terms_transition_id,
                'business_key' => $adjustment->business_key, 'kind' => $adjustment->kind,
                'quota_delta_bytes' => (string) $adjustment->quota_delta_bytes, 'policy_version' => $adjustment->policy_version];
            if (!hash_equals($adjustment->content_hash, SubscriptionTermsTimeline::hash($payload))) {
                throw new DomainException('NATIVE_OPERATION_DAMAGED');
            }
        }
        return $result;
    }

    public function record(array $request, array $result, int $now): void
    {
        DB::table('tx_subscription_operation')->insert([
            'user_id' => $request['user_id'], 'subscription_id' => $result['subscription_id'],
            'business_key' => $request['business_key'], 'kind' => $request['kind'], 'order_id' => $request['order_id'] ?? null,
            'request_hash' => SubscriptionTermsTimeline::hash($request), 'request_snapshot' => SubscriptionTermsTimeline::json($request),
            'result_snapshot' => SubscriptionTermsTimeline::json($result),
            'content_hash' => SubscriptionTermsTimeline::hash(['request' => $request, 'result' => $result]), 'created_at' => $now,
        ]);
    }

    public function appendTerms(object $subscription, array $grant, int $now, ?int $expiry, ?object $previous = null): object
    {
        if ($previous !== null && $now <= (int) $previous->effective_from) {
            throw new DomainException('NATIVE_TERMS_BOUNDARY_INVALID');
        }
        $row = [
            'subscription_id' => (string) $subscription->id,
            'version_no' => $previous === null ? '1' : $this->increment($previous->version_no),
            'plan_revision_id' => $grant['catalog']['revision_id'], 'schema_version' => self::TERMS,
            'source_order_id' => $grant['order_id'], 'source_snapshot' => $grant,
            'effective_from' => $now, 'effective_until' => $expiry, 'quota_bytes' => $grant['quota_bytes'],
            'group_id' => $grant['group_id'], 'speed_limit_mbps' => $grant['speed_limit_mbps'],
            'device_limit' => $grant['device_limit'], 'billing_timezone' => $grant['reset_policy']['timezone'],
            'reset_policy' => $grant['reset_policy'], 'legacy_snapshot' => null, 'created_at' => $now,
        ];
        $row['content_hash'] = $this->termsHash($row);
        $row['source_snapshot'] = SubscriptionTermsTimeline::json($grant);
        $row['reset_policy'] = SubscriptionTermsTimeline::json($grant['reset_policy']);
        $row['id'] = DB::table('tx_subscription_terms')->insertGetId($row);
        return (object) $row;
    }

    public function appendTransition(object $subscription, object $previous, object $next, string $key, int $now): string
    {
        $payload = [
            'subscription_id' => (string) $subscription->id, 'previous_terms_id' => (string) $previous->id,
            'next_terms_id' => (string) $next->id, 'effective_at' => $now, 'business_key' => $key,
        ];
        return (string) DB::table('tx_subscription_terms_transition')->insertGetId($payload + [
            'content_hash' => SubscriptionTermsTimeline::hash($payload), 'created_at' => $now,
        ]);
    }

    public function openPeriod(object $subscription, object $terms, int $now, ?object $previous = null): object
    {
        $policy = $this->jsonObject($terms->reset_policy);
        $next = $this->calendar->nextReset($policy, $subscription->expires_at === null ? null : (int) $subscription->expires_at, $now);
        $row = [
            'subscription_id' => (string) $subscription->id, 'terms_id' => (string) $terms->id,
            'period_no' => $previous === null ? '1' : $this->increment($previous->period_no),
            'starts_at' => $now, 'ends_at' => $this->calendar->periodEnd($next,
                $subscription->expires_at === null ? null : (int) $subscription->expires_at),
            'quota_bytes' => (string) $terms->quota_bytes, 'upload_bytes' => '0', 'download_bytes' => '0', 'billable_bytes' => '0',
            'status' => 'open', 'boundary_kind' => 'known', 'last_reset_at' => $now, 'next_reset_at' => $next,
            'legacy_reset_count' => '0', 'row_version' => '1', 'created_at' => $now, 'updated_at' => $now,
        ];
        $row['id'] = DB::table('tx_usage_period')->insertGetId($row);
        return (object) $row;
    }

    public function closePeriod(object $period, int $boundary, int $now): object
    {
        if ($boundary <= (int) $period->starts_at) {
            throw new DomainException('NATIVE_PERIOD_BOUNDARY_INVALID');
        }
        $end = $period->ends_at === null ? $boundary : min($boundary, (int) $period->ends_at);
        return $this->updatePeriod($period, ['status' => 'closed', 'ends_at' => $end], $now);
    }

    public function adjustQuota(object $period, object $terms, string $transitionId, string $key, int $now): ?string
    {
        if ((string) $period->quota_bytes === (string) $terms->quota_bytes) {
            return null;
        }
        $payload = [
            'usage_period_id' => (string) $period->id, 'terms_transition_id' => $transitionId,
            'business_key' => $key, 'kind' => 'quota_change',
            'quota_delta_bytes' => (string) ((int) $terms->quota_bytes - (int) $period->quota_bytes),
            'policy_version' => 'core-purchase-quota/v1',
        ];
        return (string) DB::table('tx_usage_adjustment')->insertGetId($payload + [
            'content_hash' => SubscriptionTermsTimeline::hash($payload), 'created_at' => $now,
        ]);
    }

    public function result(object $subscription, object $period, int $now, array $extra = []): array
    {
        return $extra + [
            'schema' => self::OPERATION, 'user_id' => (string) $subscription->user_id,
            'subscription_id' => (string) $subscription->id, 'terms_id' => (string) $subscription->current_terms_id,
            'usage_period_id' => (string) $period->id, 'subscription_version' => (string) $subscription->row_version,
            'period_version' => (string) $period->row_version, 'status' => $subscription->status,
            'expires_at' => $subscription->expires_at === null ? null : (int) $subscription->expires_at,
            'applied_at' => $now, 'transition_id' => null, 'previous_period_id' => null, 'quota_adjustment_id' => null,
        ];
    }

    public function replaceSlot(object $slot, ?string $subscriptionId, int $now): void
    {
        $updated = DB::table('tx_subscription_slot')->where('user_id', $slot->user_id)->where('slot_no', 1)
            ->where('row_version', $slot->row_version)->update([
                'subscription_id' => $subscriptionId, 'reserved_at' => $subscriptionId === null ? null : $now,
                'row_version' => $this->increment($slot->row_version), 'updated_at' => $now,
            ]);
        if ($updated !== 1) {
            throw new DomainException('NATIVE_SLOT_VERSION_CONFLICT');
        }
    }

    public function updateSubscription(object $subscription, array $changes, int $now): object
    {
        $changes += ['row_version' => $this->increment($subscription->row_version), 'updated_at' => $now];
        if (DB::table('tx_subscription')->where('id', $subscription->id)->where('row_version', $subscription->row_version)->update($changes) !== 1) {
            throw new DomainException('NATIVE_SUBSCRIPTION_VERSION_CONFLICT');
        }
        return (object) array_replace((array) $subscription, $changes);
    }

    public function updatePeriod(object $period, array $changes, int $now): object
    {
        $changes += ['row_version' => $this->increment($period->row_version), 'updated_at' => $now];
        if (DB::table('tx_usage_period')->where('id', $period->id)->where('row_version', $period->row_version)->update($changes) !== 1) {
            throw new DomainException('NATIVE_PERIOD_VERSION_CONFLICT');
        }
        return (object) array_replace((array) $period, $changes);
    }

    public function increment(mixed $value): string
    {
        $value = SubscriptionTermsTimeline::decimal($value);
        if ($value === '9223372036854775807') {
            throw new DomainException('NATIVE_VERSION_OVERFLOW');
        }
        return (string) ((int) $value + 1);
    }

    public function businessKey(string $key): string
    {
        if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:\/-]{0,127}\z/D', $key)) {
            throw new DomainException('NATIVE_BUSINESS_KEY_INVALID');
        }
        return $key;
    }

    private function jsonObject(mixed $value): array
    {
        $value = is_string($value) ? json_decode($value, true, 64, JSON_THROW_ON_ERROR) : $value;
        if (!is_array($value) || array_is_list($value)) {
            throw new DomainException('NATIVE_JSON_INVALID');
        }
        return $value;
    }
}
