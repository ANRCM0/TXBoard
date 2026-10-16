<?php

namespace App\Services\Subscription;

use App\Domains\Subscription\Services\SubscriptionTermsTimeline;

use Carbon\Carbon;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Isolated mutations only. Does not write legacy User/Plan/Order or issue authorization. */
final class SubscriptionTermsShadowService
{
    private const POLICIES = ['renew' => 'shadow-renew-preserve/v1', 'limits' => 'shadow-limits-preserve/v1',
        'quota' => 'shadow-quota-preserve/v1', 'price_evidence' => 'shadow-price-evidence-preserve/v1',
        'admin' => 'shadow-admin-explicit/v1', 'reset' => 'legacy-reset-explicit/v1'];

    public function __construct(private SubscriptionTermsTimeline $timeline) {}

    public function advance(string $userId, string $subscriptionId, string $businessKey, array $request, int $now): array
    {
        $request = $this->request($userId, $subscriptionId, $businessKey, $request, $now, false);
        return $this->mutate($userId, $subscriptionId, $businessKey, $request, $now);
    }

    public function reset(string $userId, string $subscriptionId, string $businessKey, array $request, int $now): array
    {
        $request = $this->request($userId, $subscriptionId, $businessKey, $request, $now, true);
        return $this->mutate($userId, $subscriptionId, $businessKey, $request, $now);
    }

    private function mutate(string $userId, string $subId, string $businessKey, array $request, int $now): array
    {
        // A fresh transaction is required for the first nonlocking B3 evidence read below.
        // It cannot inherit a caller's older REPEATABLE READ view.
        if (DB::transactionLevel() !== 0) {
            throw new DomainException('TERMS_OUTER_TRANSACTION_UNSUPPORTED');
        }
        return DB::transaction(function () use ($userId, $subId, $businessKey, $request, $now) {
            $user = DB::table('tx_user')->where('id', $userId)->lockForUpdate()->first();
            if (!$user) {
                throw new DomainException('TERMS_USER_MISSING');
            }
            $slot = DB::table('tx_subscription_slot')->where('user_id', $userId)->where('slot_no', 1)->lockForUpdate()->first();
            if (!$slot || (string) $slot->subscription_id !== $subId) {
                throw new DomainException('TERMS_SLOT_NOT_OWNED');
            }
            $subs = DB::table('tx_subscription')->where('user_id', $userId)->whereIn('id', [$subId, $slot->subscription_id])
                ->orderBy('id')->lockForUpdate()->get();
            $sub = $subs->firstWhere('id', (int) $subId);
            if (!$sub) {
                throw new DomainException('TERMS_SUBSCRIPTION_MISSING');
            }
            $operation = DB::table('tx_subscription_terms_shadow_operation')->where('subscription_id', $subId)
                ->where('business_key', $businessKey)->lockForUpdate()->first();
            if ($operation) {
                if (!hash_equals($operation->request_hash, SubscriptionTermsTimeline::hash($request))) {
                    throw new DomainException('TERMS_IDEMPOTENCY_CONFLICT');
                }
                $storedRequest = json_decode($operation->request_snapshot, true, flags: JSON_THROW_ON_ERROR);
                $result = json_decode($operation->result_snapshot, true, flags: JSON_THROW_ON_ERROR);
                if (!hash_equals($operation->request_hash, SubscriptionTermsTimeline::hash($storedRequest))
                    || !hash_equals($operation->content_hash, SubscriptionTermsTimeline::hash(['request' => $storedRequest, 'result' => $result]))) {
                    throw new DomainException('TERMS_OPERATION_DAMAGED');
                }
                return $result;
            }
            $periods = DB::table('tx_usage_period')->where('subscription_id', $subId)->orderBy('id')->limit(1001)->lockForUpdate()->get();
            if ($periods->count() > 1000) {
                throw new DomainException('TERMS_PERIOD_HISTORY_LIMIT');
            }
            if ($request['effective_at'] !== $now) {
                throw new DomainException('TERMS_EFFECTIVE_BOUNDARY_INVALID');
            }
            if ((string) $sub->row_version !== $request['expected_subscription_version']) {
                throw new DomainException('TERMS_SUBSCRIPTION_VERSION_CONFLICT');
            }
            if (!in_array($sub->status, ['active', 'suspended'], true) || $sub->ended_at !== null
                || ($sub->expires_at !== null && (int) $sub->expires_at <= $now)) {
                throw new DomainException('TERMS_SUBSCRIPTION_INACTIVE');
            }
            $segments = $this->timeline->resolve($subId, true);
            $segment = end($segments);
            if ($segment['effective_from'] >= $now || ($segment['effective_until'] !== null && $segment['effective_until'] <= $now)) {
                throw new DomainException('TERMS_EFFECTIVE_BOUNDARY_INVALID');
            }
            $terms = DB::table('tx_subscription_terms')->where('id', $sub->current_terms_id)->sharedLock()->first();
            if (!$terms || $terms->plan_revision_id !== null) {
                throw new DomainException('TERMS_CATALOG_REFERENCE_UNSUPPORTED');
            }
            $activePeriods = $periods->filter(fn ($period) => $period->status !== 'closed');
            if ($activePeriods->count() !== 1) {
                throw new DomainException('TERMS_PERIOD_AMBIGUOUS');
            }
            $period = $activePeriods->first();
            if ((string) $period->row_version !== $request['expected_period_version']) {
                throw new DomainException('TERMS_PERIOD_VERSION_CONFLICT');
            }
            if ($period->boundary_kind !== 'known' || $period->starts_at === null || (int) $period->starts_at >= $now
                || ($period->ends_at !== null && (int) $period->ends_at < $now)
                || ($request['kind'] !== 'reset' && $period->ends_at !== null && (int) $period->ends_at === $now)) {
                throw new DomainException('TERMS_PERIOD_BOUNDARY_UNKNOWN');
            }
            $previousEnd = null;
            foreach ($periods->sortBy('period_no') as $history) {
                if ($history->boundary_kind !== 'known' || $history->starts_at === null
                    || ($history->ends_at !== null && (int) $history->ends_at <= (int) $history->starts_at)
                    || ($previousEnd !== null && (int) $history->starts_at < $previousEnd)) {
                    throw new DomainException('TERMS_PERIOD_HISTORY_INVALID');
                }
                $previousEnd = $history->ends_at === null ? PHP_INT_MAX : (int) $history->ends_at;
            }
            $quota = SubscriptionTermsTimeline::decimal($period->quota_bytes);
            if ($terms->quota_bytes === null || $quota !== SubscriptionTermsTimeline::decimal($terms->quota_bytes)) {
                throw new DomainException('TERMS_QUOTA_PROJECTION_UNKNOWN');
            }
            foreach (['upload_bytes', 'download_bytes', 'billable_bytes', 'legacy_reset_count'] as $counter) {
                SubscriptionTermsTimeline::decimal($period->$counter);
            }
            $this->protectAcceptedUsage($subId, $now);
            $result = $request['kind'] === 'reset'
                ? $this->resetLocked($user, $sub, $terms, $period, $periods->all(), $request, $now)
                : $this->advanceLocked($sub, $terms, $period, $businessKey, $request, $now);
            $result = json_decode(SubscriptionTermsTimeline::json($result), true, flags: JSON_THROW_ON_ERROR);
            DB::table('tx_subscription_terms_shadow_operation')->insert([
                'user_id' => $userId, 'subscription_id' => $subId, 'business_key' => $businessKey,
                'kind' => $request['kind'], 'policy_version' => $request['policy_version'],
                'request_hash' => SubscriptionTermsTimeline::hash($request), 'request_snapshot' => SubscriptionTermsTimeline::json($request),
                'result_snapshot' => SubscriptionTermsTimeline::json($result),
                'content_hash' => SubscriptionTermsTimeline::hash(['request' => $request, 'result' => $result]),
                'terms_id' => $result['terms_id'], 'transition_id' => $result['transition_id'],
                'previous_period_id' => $result['previous_period_id'], 'next_period_id' => $result['usage_period_id'], 'created_at' => $now,
            ]);
            $this->timeline->resolve($subId, true);
            return $result;
        }, 5);
    }

    private function advanceLocked(object $sub, object $old, object $period, string $businessKey, array $request, int $now): array
    {
        $fields = ['quota_bytes', 'group_id', 'speed_limit_mbps', 'device_limit', 'billing_timezone'];
        $next = [];
        foreach ($fields as $field) {
            $next[$field] = $old->$field;
        }
        $expiry = $sub->expires_at === null ? null : (int) $sub->expires_at;
        $changes = $request['changes'];
        if ($request['kind'] === 'renew') {
            if ($expiry === null) {
                throw new DomainException('TERMS_PERPETUAL_RENEWAL_UNSUPPORTED');
            }
            $expiry = Carbon::createFromTimestamp($expiry, 'UTC')->settings(['monthOverflow' => Carbon::shouldOverflowMonths()])
                ->addMonths($changes['calendar_months'])->timestamp;
            SubscriptionTermsTimeline::timestamp($expiry);
        } else {
            foreach ($fields as $field) {
                if (array_key_exists($field, $changes)) {
                    $next[$field] = $changes[$field];
                }
            }
            if (array_key_exists('expires_at', $changes)) {
                $expiry = $changes['expires_at'];
                if ($expiry !== null && $expiry <= $now) {
                    throw new DomainException('TERMS_EXPIRY_INVALID');
                }
            }
        }
        $next['quota_bytes'] = SubscriptionTermsTimeline::decimal($next['quota_bytes']);
        $version = $this->increment($old->version_no);
        $payload = $next + ['subscription_id' => (string) $sub->id, 'version_no' => $version,
            'plan_revision_id' => null, 'effective_from' => $now, 'effective_until' => $expiry,
            'reset_policy' => json_decode($old->reset_policy, true, flags: JSON_THROW_ON_ERROR),
            'legacy_snapshot' => ['predecessor_terms_id' => (string) $old->id, 'policy_version' => $request['policy_version'],
                'legacy_snapshot_provenance_hash' => $old->content_hash, 'shadow_change' => $changes]];
        $newId = DB::table('tx_subscription_terms')->insertGetId(array_diff_key($payload, ['reset_policy' => true, 'legacy_snapshot' => true]) + [
            'reset_policy' => SubscriptionTermsTimeline::json($payload['reset_policy']),
            'legacy_snapshot' => SubscriptionTermsTimeline::json($payload['legacy_snapshot']),
            'content_hash' => SubscriptionTermsTimeline::hash($payload), 'created_at' => $now,
        ]);
        $transition = ['subscription_id' => (string) $sub->id, 'previous_terms_id' => (string) $old->id,
            'next_terms_id' => (string) $newId, 'effective_at' => $now, 'business_key' => $businessKey];
        $transitionId = DB::table('tx_subscription_terms_transition')->insertGetId($transition + [
            'content_hash' => SubscriptionTermsTimeline::hash($transition), 'created_at' => $now,
        ]);
        $adjustmentId = null;
        $quotaChanged = (string) $period->quota_bytes !== $next['quota_bytes'];
        if ($quotaChanged) {
            $delta = (string) ((int) $next['quota_bytes'] - (int) $period->quota_bytes);
            $adjustment = ['usage_period_id' => (string) $period->id, 'terms_transition_id' => (string) $transitionId,
                'business_key' => $businessKey, 'kind' => 'quota_change', 'quota_delta_bytes' => $delta, 'policy_version' => $request['policy_version']];
            $adjustmentId = DB::table('tx_usage_adjustment')->insertGetId($adjustment + [
                'content_hash' => SubscriptionTermsTimeline::hash($adjustment), 'created_at' => $now,
            ]);
        }
        // Version changes serialize even limit/price operations with concurrent period writers.
        $periodVersion = $this->increment($period->row_version);
        $changedPeriod = DB::table('tx_usage_period')->where('id', $period->id)->where('row_version', $period->row_version)->update([
            'quota_bytes' => $next['quota_bytes'], 'row_version' => $periodVersion, 'updated_at' => $now,
        ]);
        $subVersion = $this->increment($sub->row_version);
        $changedSub = DB::table('tx_subscription')->where('id', $sub->id)->where('row_version', $sub->row_version)->update([
            'current_terms_id' => $newId, 'expires_at' => $expiry, 'row_version' => $subVersion, 'updated_at' => $now,
        ]);
        if ($changedPeriod !== 1 || $changedSub !== 1) {
            throw new DomainException('TERMS_PROJECTION_VERSION_CONFLICT');
        }
        return $this->result($sub, (string) $newId, (string) $transitionId, $period, (string) $period->id,
            $subVersion, $periodVersion, $adjustmentId === null ? null : (string) $adjustmentId, $expiry);
    }

    private function resetLocked(object $user, object $sub, object $terms, object $old, array $periods, array $request, int $now): array
    {
        if ($sub->status !== 'active' || (property_exists($user, 'banned') && (bool) $user->banned)) {
            throw new DomainException('TERMS_RESET_USER_INACTIVE');
        }
        $trigger = $request['changes']['trigger'];
        if ($trigger === 'scheduled' && ($old->next_reset_at === null || (int) $old->next_reset_at !== $now || (int) $old->ends_at !== $now)) {
            throw new DomainException('TERMS_SCHEDULED_BOUNDARY_UNPROVEN');
        }
        $nextReset = $this->nextReset($terms, $sub, $now);
        $changedPeriod = DB::table('tx_usage_period')->where('id', $old->id)->where('row_version', $old->row_version)->update([
            'status' => 'closed', 'ends_at' => $now, 'row_version' => $this->increment($old->row_version), 'updated_at' => $now,
        ]);
        $number = '0';
        foreach ($periods as $period) {
            if ((int) $period->period_no > (int) $number) {
                $number = (string) $period->period_no;
            }
        }
        $newId = DB::table('tx_usage_period')->insertGetId([
            'subscription_id' => $sub->id, 'terms_id' => $terms->id, 'period_no' => $this->increment($number),
            'starts_at' => $now, 'ends_at' => $nextReset, 'quota_bytes' => $terms->quota_bytes,
            'upload_bytes' => '0', 'download_bytes' => '0', 'billable_bytes' => '0', 'status' => $old->status,
            'boundary_kind' => 'known', 'last_reset_at' => $now, 'next_reset_at' => $nextReset,
            'legacy_reset_count' => $this->increment($old->legacy_reset_count), 'row_version' => '1', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $subVersion = $this->increment($sub->row_version);
        $changedSub = DB::table('tx_subscription')->where('id', $sub->id)->where('row_version', $sub->row_version)->update([
            'row_version' => $subVersion, 'updated_at' => $now,
        ]);
        if ($changedPeriod !== 1 || $changedSub !== 1) {
            throw new DomainException('TERMS_PROJECTION_VERSION_CONFLICT');
        }
        return $this->result($sub, (string) $terms->id, null, $old, (string) $newId, $subVersion, '1', null,
            $sub->expires_at === null ? null : (int) $sub->expires_at);
    }

    private function nextReset(object $terms, object $sub, int $now): ?int
    {
        $policy = json_decode($terms->reset_policy, true, flags: JSON_THROW_ON_ERROR);
        $method = $policy['method'] ?? null;
        if (!is_int($method) || !in_array($method, [0, 1, 2, 3, 4], true)
            || !in_array($terms->billing_timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new DomainException('TERMS_RESET_POLICY_UNKNOWN');
        }
        if ($method === 2 || $sub->expires_at === null) {
            return null;
        }
        $from = Carbon::createFromTimestamp($now, $terms->billing_timezone);
        if ($method === 0) {
            return $from->copy()->addMonth()->startOfMonth()->timestamp;
        }
        if ($method === 3) {
            return $from->copy()->addYear()->startOfYear()->timestamp;
        }
        $expiry = Carbon::createFromTimestamp($sub->expires_at, $terms->billing_timezone);
        $time = [$expiry->hour, $expiry->minute, $expiry->second];
        if ($method === 1) {
            $candidate = $from->copy()->startOfMonth()->day(min($expiry->day, $from->daysInMonth))->setTime(...$time);
            if ($candidate->timestamp <= $now) {
                $candidate = $from->copy()->startOfMonth()->addMonthNoOverflow();
                $candidate->day(min($expiry->day, $candidate->daysInMonth))->setTime(...$time);
            }
        } else {
            $candidate = $from->copy()->startOfYear()->month($expiry->month);
            $candidate->day(min($expiry->day, $candidate->daysInMonth))->setTime(...$time);
            if ($candidate->timestamp <= $now) {
                $candidate = $from->copy()->startOfYear()->addYearNoOverflow()->month($expiry->month);
                $candidate->day(min($expiry->day, $candidate->daysInMonth))->setTime(...$time);
            }
        }
        return SubscriptionTermsTimeline::timestamp($candidate->timestamp);
    }

    private function protectAcceptedUsage(string $subId, int $at): void
    {
        $batchTable = Schema::hasTable('tx_usage_batch');
        $entryTable = Schema::hasTable('tx_usage_entry');
        if ($batchTable !== $entryTable) {
            throw new DomainException('TERMS_USAGE_EVIDENCE_UNAVAILABLE');
        }
        if (!$batchTable) {
            return;
        }
        // This is the transaction's FIRST ordinary business read, after its subscription/period locks.
        // Existing B3 receive/settle paths lock the same subscription before writing evidence.
        // Do not lock batch/grant after subscription: those workers acquire them in the opposite order.
        $batches = DB::table('tx_usage_batch')->where('ended_at', '>', $at)->orderBy('id')->limit(1001)->get(['id', 'frozen_entries', 'frozen_hash']);
        if ($batches->count() > 1000) {
            throw new DomainException('TERMS_USAGE_EVIDENCE_LIMIT');
        }
        foreach ($batches as $batch) {
            $entries = json_decode($batch->frozen_entries, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($entries) || !array_is_list($entries) || !$entries || count($entries) > 1000
                || !hash_equals($batch->frozen_hash, SubscriptionTermsTimeline::hash($entries))) {
                throw new DomainException('TERMS_USAGE_EVIDENCE_CORRUPT');
            }
            foreach ($entries as $entry) {
                if (!isset($entry['subscription_id']) || !is_string($entry['subscription_id'])) {
                    throw new DomainException('TERMS_USAGE_EVIDENCE_CORRUPT');
                }
                if ($entry['subscription_id'] === $subId) {
                    throw new DomainException('TERMS_ACCEPTED_USAGE_WOULD_BE_REASSIGNED');
                }
            }
        }
        if (DB::table('tx_usage_entry as entry')->join('tx_usage_batch as batch', 'batch.id', '=', 'entry.batch_id')
            ->where('entry.subscription_id', $subId)->where('batch.ended_at', '>', $at)->exists()) {
            throw new DomainException('TERMS_ACCEPTED_USAGE_WOULD_BE_REASSIGNED');
        }
    }

    private function request(string $user, string $sub, string $key, array $input, int $now, bool $reset): array
    {
        if (!config('core_shadow.isolated_copy') || !app()->environment('local', 'testing')) {
            throw new DomainException('ISOLATED_COPY_REQUIRED');
        }
        SubscriptionTermsTimeline::decimal($user, true); SubscriptionTermsTimeline::decimal($sub, true); SubscriptionTermsTimeline::timestamp($now);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $key)) {
            throw new DomainException('TERMS_BUSINESS_KEY_INVALID');
        }
        if (array_diff(array_keys($input), ['kind', 'policy_version', 'effective_at', 'expected_subscription_version', 'expected_period_version', 'changes'])
            || !array_key_exists('effective_at', $input) || !is_array($input['changes'] ?? null)) {
            throw new DomainException('TERMS_REQUEST_INVALID');
        }
        $kind = $input['kind'] ?? '';
        if (!isset(self::POLICIES[$kind]) || ($kind === 'reset') !== $reset || ($input['policy_version'] ?? null) !== self::POLICIES[$kind]) {
            throw new DomainException('TERMS_POLICY_UNSUPPORTED');
        }
        $changes = $input['changes'];
        $allowed = match ($kind) {
            'renew' => ['calendar_months'], 'limits' => ['speed_limit_mbps', 'device_limit'], 'quota' => ['quota_bytes'],
            'price_evidence' => ['quote_evidence'], 'admin' => ['quota_bytes', 'group_id', 'speed_limit_mbps', 'device_limit', 'expires_at'],
            'reset' => ['trigger', 'verified_legacy_rule'],
        };
        if (!$changes || array_diff(array_keys($changes), $allowed)) {
            throw new DomainException('TERMS_CHANGE_UNSUPPORTED');
        }
        foreach (['quota_bytes', 'group_id', 'speed_limit_mbps', 'device_limit'] as $field) {
            if (array_key_exists($field, $changes)) {
                $changes[$field] = SubscriptionTermsTimeline::decimal($changes[$field], nullable: $field !== 'quota_bytes');
                if ($changes[$field] !== null && $field !== 'quota_bytes' && (int) $changes[$field] > 2147483647) {
                    throw new DomainException('TERMS_LIMIT_INVALID');
                }
            }
        }
        if (array_key_exists('expires_at', $changes)) {
            $changes['expires_at'] = SubscriptionTermsTimeline::timestamp($changes['expires_at'], true);
        }
        if ($kind === 'renew' && (!is_int($changes['calendar_months'] ?? null) || !in_array($changes['calendar_months'], [1, 3, 6, 12, 24, 36], true))) {
            throw new DomainException('TERMS_CALENDAR_PERIOD_INVALID');
        }
        if ($kind === 'price_evidence') {
            $quote = $changes['quote_evidence'] ?? null;
            if (!is_array($quote) || count($quote) !== 4 || array_diff(array_keys($quote), ['currency', 'price_minor', 'offer_code', 'catalog_snapshot_hash'])
                || !in_array($quote['currency'] ?? '', ['USD', 'CNY', 'EUR', 'GBP', 'HKD', 'SGD', 'CAD', 'AUD', 'NZD', 'TWD'], true)
                || !in_array($quote['offer_code'] ?? '', ['monthly', 'quarterly', 'half_yearly', 'yearly', 'two_yearly', 'three_yearly', 'onetime', 'reset_traffic'], true)
                || !preg_match('/^[a-f0-9]{64}$/D', $quote['catalog_snapshot_hash'] ?? '')) {
                throw new DomainException('TERMS_QUOTE_EVIDENCE_INVALID');
            }
            $changes['quote_evidence']['price_minor'] = SubscriptionTermsTimeline::decimal($quote['price_minor']);
        }
        if ($reset && (!in_array($changes['trigger'] ?? '', ['scheduled', 'manual', 'order_reset', 'order_onetime', 'order_event'], true)
            || ($changes['verified_legacy_rule'] ?? '') !== 'legacy-performReset/'.$changes['trigger'].'/v1')) {
            throw new DomainException('TERMS_RESET_RULE_UNPROVEN');
        }
        return ['user_id' => $user, 'subscription_id' => $sub, 'business_key' => $key, 'kind' => $kind,
            'policy_version' => self::POLICIES[$kind], 'effective_at' => SubscriptionTermsTimeline::timestamp($input['effective_at']),
            'expected_subscription_version' => SubscriptionTermsTimeline::decimal($input['expected_subscription_version'] ?? null, true),
            'expected_period_version' => SubscriptionTermsTimeline::decimal($input['expected_period_version'] ?? null, true), 'changes' => $changes];
    }

    private function increment(mixed $value): string
    {
        $value = SubscriptionTermsTimeline::decimal($value);
        if ($value === '9223372036854775807') {
            throw new DomainException('TERMS_VERSION_OVERFLOW');
        }
        return (string) ((int) $value + 1);
    }

    private function result(object $sub, string $terms, ?string $transition, object $previous, string $period,
        string $subVersion, string $periodVersion, ?string $adjustment, ?int $expiry): array
    {
        return ['mode' => 'isolated_shadow', 'legacy_authority' => 'retained', 'subscription_id' => (string) $sub->id,
            'terms_id' => $terms, 'transition_id' => $transition, 'previous_period_id' => (string) $previous->id,
            'usage_period_id' => $period, 'quota_adjustment_id' => $adjustment, 'subscription_version' => $subVersion,
            'period_version' => $periodVersion, 'expires_at' => $expiry];
    }
}
