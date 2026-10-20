<?php

namespace App\Domains\Subscription\Services;

use App\Domains\Identity\Internal\CurrentSessionAuthorization;
use App\Domains\Installation\InstallationAuthority;
use App\Domains\UsageV2\NativeUsageReservations;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Immediate administrative terms changes with purchased evidence and usage preserved. */
final class NativeSubscriptionAmendments
{
    public function __construct(private NativeSubscriptionStore $store, private NativeSubscriptionAmendmentEvidence $evidence,
        private NativeSubscriptionCalendar $calendar) {}

    public function snapshot(int $actorId, int $userId): array
    {
        $this->outsideTransaction();
        return DB::transaction(function () use ($actorId, $userId): array {
            $installation = app(InstallationAuthority::class)->assertNativeRead();
            $target = $this->authorize($actorId, $userId);
            [, $slot, $subscriptions] = $this->store->lockAccount($userId, time(), $installation['installation_id']);
            $subscription = $subscriptions->firstWhere('id', $slot->subscription_id);
            if (!$subscription) return ['user_id' => (string) $userId, 'amendment' => null];
            $current = $this->store->verifyHistory($subscription, $this->store->lockHistory($subscription), true);
            $period = $current['period']; $terms = $current['terms'];
            return ['user_id' => (string) $userId, 'amendment' => [
                'subscription_id' => (string) $subscription->id, 'subscription_version' => (string) $subscription->row_version,
                'terms_id' => (string) $terms->id, 'usage_period_id' => (string) $period->id, 'period_version' => (string) $period->row_version,
                'status' => $subscription->status, 'plan_id' => $current['source']['plan_id'], 'plan_revision_id' => (string) $terms->plan_revision_id,
                'quota_bytes' => (string) $terms->quota_bytes, 'consumed_bytes' => NativeUsageReservations::consumed($period),
                'expires_at' => $subscription->expires_at === null ? null : (string) $subscription->expires_at,
                'speed_limit_mbps' => $terms->speed_limit_mbps === null ? null : (string) $terms->speed_limit_mbps,
                'device_limit' => $terms->device_limit === null ? null : (string) $terms->device_limit,
                'editable' => !$target->is_admin && !$target->is_staff && $actorId !== $userId && !$target->banned
                    && ($subscription->expires_at === null || (int) $subscription->expires_at > time())
                    && ($period->ends_at === null || (int) $period->ends_at > time()),
            ]];
        }, 3);
    }

    public function apply(int $actorId, int $userId, array $input, string $key): array
    {
        $this->outsideTransaction();
        $request = $this->normalize($actorId, $userId, $input, $key);
        $now = time();
        $result = DB::transaction(function () use ($actorId, $userId, $request, $now): array {
            $installation = app(InstallationAuthority::class)->assertNativeMutation();
            $target = $this->authorize($actorId, $userId);
            if ($target->is_admin || $target->is_staff || $actorId === $userId) throw new DomainException('NATIVE_AMENDMENT_TARGET_PROTECTED');
            if (!Schema::hasTable('tx_native_subscription_amendment')) throw new DomainException('NATIVE_AMENDMENT_CAPABILITY_UNAVAILABLE');
            $request['installation_id'] = $installation['installation_id'];
            $old = DB::table('tx_native_subscription_amendment')->where('installation_id', $installation['installation_id'])
                ->where('actor_id', $actorId)->where('business_key', $request['business_key'])->lockForUpdate()->first();
            if ($old && !hash_equals((string) $old->request_hash, $this->evidence->hash($request))) {
                throw new DomainException('NATIVE_AMENDMENT_IDEMPOTENCY_CONFLICT');
            }
            [, $slot, $subscriptions] = $this->store->lockAccount($userId, $now, $installation['installation_id']);
            $subscription = $subscriptions->firstWhere('id', $request['subscription_id']);
            if (!$subscription) throw new DomainException('NATIVE_SUBSCRIPTION_MISSING');
            $history = $this->store->lockHistory($subscription);
            $current = $this->store->verifyHistory($subscription, $history, true);
            if ($old) return $this->replay($old, $subscription, $request);
            $previous = $current['terms']; $period = $current['period'];
            if ((string) $subscription->row_version !== $request['expected_subscription_version']
                || (string) $previous->id !== $request['expected_terms_id'] || !$period
                || (string) $period->row_version !== $request['expected_period_version']) throw new DomainException('NATIVE_AMENDMENT_VERSION_CONFLICT');
            if ((string) $slot->subscription_id !== (string) $subscription->id || !in_array($subscription->status, ['active', 'suspended'], true)
                || $subscription->ended_at !== null || (int) $subscription->starts_at > $now || $target->banned
                || $now <= (int) $previous->effective_from || $now < (int) $period->starts_at
                || ($subscription->expires_at !== null && (int) $subscription->expires_at <= $now)
                || ($period->ends_at !== null && (int) $period->ends_at <= $now)) throw new DomainException('NATIVE_AMENDMENT_STATE_INVALID');
            $this->store->protectAcceptedUsage((string) $subscription->id, $now);
            $amended = NativeSubscriptionAmendmentEvidence::isAmendment($current['source']['schema'] ?? null);
            $purchase = $amended
                ? $current['source']['purchase'] : $current['source'];
            $effective = ['plan_id' => $current['source']['plan_id'], 'quota_bytes' => (string) $previous->quota_bytes,
                'group_id' => $previous->group_id === null ? null : (string) $previous->group_id,
                'speed_limit_mbps' => $previous->speed_limit_mbps === null ? null : (string) $previous->speed_limit_mbps,
                'device_limit' => $previous->device_limit === null ? null : (string) $previous->device_limit,
                'reset_policy' => $current['source']['reset_policy']];
            $expiry = $subscription->expires_at === null ? null : (int) $subscription->expires_at;
            $previousSource = $this->evidence->decode($previous->source_snapshot);
            $publication = $previousSource['plan_publication'] ?? null; $revisionId = (string) $previous->plan_revision_id;
            $changed = false;
            if (isset($request['changes']['plan_revision'])) {
                $selected = $request['changes']['plan_revision'];
                if (!DB::table('tx_plan')->where('id', $selected['plan_id'])->lockForUpdate()->exists()) throw new DomainException('NATIVE_AMENDMENT_PLAN_MISSING');
                $head = DB::table('tx_plan_revision')->where('plan_id', $selected['plan_id'])->orderByDesc('id')->sharedLock()->first();
                if (!$head || (string) $head->id !== $selected['revision_id'] || !hash_equals($head->content_hash, $selected['content_hash'])) throw new DomainException('NATIVE_AMENDMENT_PLAN_VERSION_CONFLICT');
                $verified = $this->evidence->publication($selected); $plan = $verified['source'];
                $changed = $revisionId !== $selected['revision_id']; $revisionId = $selected['revision_id']; $publication = $selected;
                foreach (['plan_id', 'quota_bytes', 'group_id', 'speed_limit_mbps', 'device_limit'] as $field) {
                    if ($effective[$field] !== $plan[$field]) $changed = true;
                    $effective[$field] = $plan[$field];
                }
            }
            foreach ($request['changes'] as $field => $value) {
                if ($field === 'plan_revision') continue;
                $oldValue = $field === 'expires_at' ? ($expiry === null ? null : (string) $expiry) : $effective[$field];
                if ($oldValue !== $value) $changed = true;
                if ($field === 'expires_at') $expiry = $value === null ? null : (int) $value;
                else $effective[$field] = $value;
            }
            if (!$changed || ($expiry !== null && $expiry <= $now)) throw new DomainException('NATIVE_AMENDMENT_INPUT_INVALID');
            if (bccomp($effective['quota_bytes'], NativeUsageReservations::consumed($period), 0) < 0) {
                throw new DomainException('NATIVE_AMENDMENT_QUOTA_BELOW_CONSUMED');
            }
            $source = ['schema' => $publication === null ? NativeSubscriptionAmendmentEvidence::SOURCE : NativeSubscriptionAmendmentEvidence::PLAN_SOURCE, 'actor_id' => (string) $actorId,
                'business_key' => $request['business_key'], 'reason' => $request['reason'], 'previous_terms_id' => (string) $previous->id,
                'previous_content_hash' => $previous->content_hash, 'purchase' => $purchase, 'effective' => $effective,
                'reset_anchor_expires_at' => $amended
                    ? $this->evidence->decode($previous->source_snapshot)['reset_anchor_expires_at']
                    : ($previous->effective_until === null ? null : (int) $previous->effective_until)];
            if ($publication !== null) $source['plan_publication'] = $publication;
            $terms = $this->append($subscription, $previous, $source, $expiry, $now, $revisionId);
            $transition = $this->store->appendTransition($subscription, $previous, $terms, $request['business_key'], $now);
            $adjustment = $this->store->adjustQuota($period, $terms, $transition, $request['business_key'], $now, 'core-admin-quota-amendment/v1');
            // Existing reset anchors and accepted counters stay in their current period.
            $period = $this->store->updatePeriod($period, ['quota_bytes' => $effective['quota_bytes'],
                'ends_at' => $this->calendar->periodEnd($period->next_reset_at === null ? null : (int) $period->next_reset_at, $expiry)], $now);
            $subscription = $this->store->updateSubscription($subscription, ['current_terms_id' => $terms->id, 'plan_id' => $effective['plan_id'], 'expires_at' => $expiry], $now);
            $result = $this->store->result($subscription, $period, $now, ['previous_terms_id' => (string) $previous->id,
                'transition_id' => $transition, 'quota_adjustment_id' => $adjustment]);
            $result = $this->evidence->decode(SubscriptionTermsTimeline::json($result));
            DB::table('tx_native_subscription_amendment')->insert(['installation_id' => $installation['installation_id'],
                'actor_id' => $actorId, 'user_id' => $userId, 'business_key' => $request['business_key'], 'subscription_id' => $subscription->id,
                'terms_id' => $terms->id, 'transition_id' => $transition, 'request_hash' => $this->evidence->hash($request),
                'request_snapshot' => SubscriptionTermsTimeline::json($request), 'source_snapshot' => SubscriptionTermsTimeline::json($source),
                'result_snapshot' => SubscriptionTermsTimeline::json($result),
                'content_hash' => $this->evidence->hash(['request' => $request, 'source' => $source, 'result' => $result]), 'created_at' => $now]);
            $this->store->verifyHistory($subscription, $this->store->lockHistory($subscription), true);
            app(NativeCredentialSynchronization::class)->stageLocked($userId, (int) $subscription->id);
            return $result;
        }, 3);
        app(NativeCredentialSynchronization::class)->synchronize($userId, (int) $request['subscription_id']);
        return $result;
    }

    public function history(int $actorId, int $userId, ?string $before = null, int $limit = 25): array
    {
        $this->outsideTransaction();
        if ($limit < 1 || $limit > 100) throw new DomainException('NATIVE_AMENDMENT_INPUT_INVALID');
        if ($before !== null) SubscriptionTermsTimeline::decimal($before, true);
        return DB::transaction(function () use ($actorId, $userId, $before, $limit): array {
            $installation = app(InstallationAuthority::class)->assertNativeRead(); $this->authorize($actorId, $userId);
            if (!Schema::hasTable('tx_native_subscription_amendment')) throw new DomainException('NATIVE_AMENDMENT_CAPABILITY_UNAVAILABLE');
            $query = DB::table('tx_native_subscription_amendment')->where('installation_id', $installation['installation_id'])->where('user_id', $userId);
            if ($before !== null) $query->where('id', '<', $before);
            $rows = $query->orderByDesc('id')->limit($limit + 1)->sharedLock()->get(); $items = [];
            foreach ($rows->take($limit) as $row) {
                [$request, , $result] = $this->evidence->snapshots($row);
                $subscription = DB::table('tx_subscription')->where('id', $row->subscription_id)->lockForUpdate()->first();
                if (!$subscription) throw new DomainException('NATIVE_AMENDMENT_EVIDENCE_INVALID');
                $this->store->assertOwnership($subscription, $userId, $installation['installation_id']);
                $this->store->verifyHistory($subscription, $this->store->lockHistory($subscription), true);
                $this->replay($row, $subscription, $request);
                $items[] = ['id' => (string) $row->id, 'actor_id' => (string) $row->actor_id, 'reason' => $request['reason'],
                    'changes' => $request['changes'], 'result' => $result];
            }
            return ['items' => $items, 'next_cursor' => $rows->count() > $limit ? (string) $rows[$limit - 1]->id : null];
        }, 3);
    }

    private function replay(object $row, object $subscription, array $request): array
    {
        [$stored, , $result] = $this->evidence->snapshots($row);
        if (SubscriptionTermsTimeline::json($stored) !== SubscriptionTermsTimeline::json($request)) throw new DomainException('NATIVE_AMENDMENT_IDEMPOTENCY_CONFLICT');
        // Reuse the aggregate's permanent result verifier; this owner remains the sole receipt store.
        return $this->store->replay((object) ['request_snapshot' => SubscriptionTermsTimeline::json($stored),
            'result_snapshot' => SubscriptionTermsTimeline::json($result), 'request_hash' => SubscriptionTermsTimeline::hash($stored),
            'content_hash' => SubscriptionTermsTimeline::hash(['request' => $stored, 'result' => $result]),
            'subscription_id' => $row->subscription_id, 'user_id' => $row->user_id, 'business_key' => $row->business_key,
            'kind' => 'admin.amendment', 'order_id' => null, 'created_at' => $row->created_at], $request, $subscription, true);
    }

    private function append(object $subscription, object $previous, array $source, ?int $expiry, int $now, string $revisionId): object
    {
        $row = ['subscription_id' => (string) $subscription->id, 'version_no' => $this->store->increment($previous->version_no),
            'plan_revision_id' => $revisionId, 'schema_version' => NativeSubscriptionStore::TERMS,
            'source_order_id' => (string) $previous->source_order_id, 'source_snapshot' => $source,
            'effective_from' => $now, 'effective_until' => $expiry, 'quota_bytes' => $source['effective']['quota_bytes'],
            'group_id' => $source['effective']['group_id'], 'speed_limit_mbps' => $source['effective']['speed_limit_mbps'],
            'device_limit' => $source['effective']['device_limit'], 'billing_timezone' => $previous->billing_timezone,
            'reset_policy' => $source['effective']['reset_policy'], 'legacy_snapshot' => null, 'created_at' => $now];
        $row['content_hash'] = $this->store->termsHash($row); $row['source_snapshot'] = SubscriptionTermsTimeline::json($source);
        $row['reset_policy'] = SubscriptionTermsTimeline::json($source['effective']['reset_policy']);
        $row['id'] = DB::table('tx_subscription_terms')->insertGetId($row);
        return (object) $row;
    }

    private function authorize(int $actorId, int $userId): User
    {
        $users = User::query()->whereIn('id', [$actorId, $userId])->orderBy('id')->lockForUpdate()->get();
        $actor = $users->firstWhere('id', $actorId); $target = $users->firstWhere('id', $userId);
        if (!$actor || !$actor->is_admin || $actor->banned) throw new DomainException('NATIVE_AMENDMENT_ACTOR_REJECTED');
        app(CurrentSessionAuthorization::class)->assertCurrentLocked($actorId, 'subscription.lifecycle.manage');
        if (!$target) throw new DomainException('NATIVE_USER_MISSING');
        return $target;
    }

    private function normalize(int $actorId, int $userId, array $input, string $key): array
    {
        $fields = ['subscription_id', 'expected_subscription_version', 'expected_terms_id', 'expected_period_version', 'changes', 'reason'];
        if (array_diff(array_keys($input), $fields) || array_diff($fields, array_keys($input)) || !is_array($input['changes'])
            || $input['changes'] === [] || array_diff(array_keys($input['changes']), ['plan_revision', 'quota_bytes', 'expires_at', 'speed_limit_mbps', 'device_limit'])
            || !is_string($input['reason']) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}\z/D', $input['reason'])
            || !preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD', $key)) {
            throw new DomainException('NATIVE_AMENDMENT_INPUT_INVALID');
        }
        $request = ['schema' => NativeSubscriptionStore::OPERATION, 'kind' => 'admin.amendment', 'order_id' => null,
            'actor_id' => SubscriptionTermsTimeline::decimal($actorId, true), 'user_id' => SubscriptionTermsTimeline::decimal($userId, true),
            'business_key' => 'admin/'.$actorId.'/'.strtolower($key)];
        foreach (array_slice($fields, 0, 4) as $field) $request[$field] = SubscriptionTermsTimeline::decimal($input[$field], true);
        $changes = [];
        foreach ($input['changes'] as $field => $value) {
            if ($field === 'plan_revision') {
                if (!is_array($value) || count($value) !== 3 || array_diff(array_keys($value), ['plan_id', 'revision_id', 'content_hash'])
                    || !is_string($value['content_hash'] ?? null) || !preg_match('/\A[0-9a-f]{64}\z/D', $value['content_hash'])) throw new DomainException('NATIVE_AMENDMENT_INPUT_INVALID');
                $changes[$field] = ['plan_id' => SubscriptionTermsTimeline::decimal($value['plan_id'] ?? null, true),
                    'revision_id' => SubscriptionTermsTimeline::decimal($value['revision_id'] ?? null, true), 'content_hash' => $value['content_hash']];
                continue;
            }
            if ($value !== null && !is_string($value)) throw new DomainException('NATIVE_AMENDMENT_INPUT_INVALID');
            if ($field === 'expires_at') $changes[$field] = $value === null ? null : (string) SubscriptionTermsTimeline::timestamp($value);
            else {
                $changes[$field] = SubscriptionTermsTimeline::decimal($value, nullable: $field !== 'quota_bytes');
                if ($field !== 'quota_bytes' && $value !== null && bccomp($value, '4294967295', 0) > 0) throw new DomainException('NATIVE_AMENDMENT_INPUT_INVALID');
            }
        }
        ksort($changes);
        return $request + ['changes' => $changes, 'reason' => $input['reason']];
    }

    private function outsideTransaction(): void
    {
        if (DB::transactionLevel() !== 0) throw new DomainException('NATIVE_OUTER_TRANSACTION_UNSUPPORTED');
    }
}
