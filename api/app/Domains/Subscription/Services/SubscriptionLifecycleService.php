<?php

namespace App\Domains\Subscription\Services;

use App\Domains\Installation\InstallationAuthority;
use DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Versioned native lifecycle; account entitlement and usage fields are never authoritative. */
final class SubscriptionLifecycleService implements \App\Domains\Subscription\Contracts\SubscriptionLifecycle
{
    public function __construct(private NativeSubscriptionStore $store) {}

    public function suspend(int $userId, int $subscriptionId, string $businessKey, int|string $expectedVersion,
        ?int $now = null, string $reason = 'manual'): array
    {
        if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}\z/D', $reason)) {
            throw new DomainException('NATIVE_SUSPENSION_REASON_INVALID');
        }
        return $this->mutate('suspend', $userId, $subscriptionId, $businessKey, $expectedVersion, $now, ['reason' => $reason]);
    }

    public function resume(int $userId, int $subscriptionId, string $businessKey, int|string $expectedVersion, ?int $now = null): array
    {
        return $this->mutate('resume', $userId, $subscriptionId, $businessKey, $expectedVersion, $now);
    }

    public function revoke(int $userId, int $subscriptionId, string $businessKey, int|string $expectedVersion, ?int $now = null): array
    {
        return $this->mutate('revoke', $userId, $subscriptionId, $businessKey, $expectedVersion, $now);
    }

    public function expire(int $userId, int $subscriptionId, string $businessKey, int|string $expectedVersion, ?int $now = null): array
    {
        return $this->mutate('expire', $userId, $subscriptionId, $businessKey, $expectedVersion, $now);
    }

    public function reset(int $userId, int $subscriptionId, string $businessKey, int|string $expectedVersion,
        ?int $now = null, string $trigger = 'manual'): array
    {
        if (!in_array($trigger, ['manual', 'scheduled'], true)) {
            throw new DomainException('NATIVE_RESET_TRIGGER_INVALID');
        }
        return $this->mutate('reset', $userId, $subscriptionId, $businessKey, $expectedVersion, $now, ['trigger' => $trigger]);
    }

    public function tick(int $limit = 100, ?int $now = null): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new DomainException('NATIVE_TICK_LIMIT_INVALID');
        }
        if (DB::transactionLevel() !== 0) {
            throw new DomainException('NATIVE_OUTER_TRANSACTION_UNSUPPORTED');
        }
        $now = SubscriptionTermsTimeline::timestamp($now ?? time());
        $installation = DB::transaction(fn (): array => app(InstallationAuthority::class)->assertNativeRead());
        $result = ['attempted' => 0, 'completed' => 0, 'failed' => 0, 'errors' => []];
        $failed = [];
        while ($result['attempted'] < $limit) {
            // Each locator is revalidated by the fresh account-first mutation transaction.
            $candidate = DB::table('tx_subscription as subscription')->useWritePdo()
                ->join('tx_subscription_slot as slot', function ($join): void {
                    $join->on('slot.subscription_id', '=', 'subscription.id')
                        ->on('slot.user_id', '=', 'subscription.user_id')->where('slot.slot_no', 1);
                })
                ->join('tx_usage_period as period', 'period.subscription_id', '=', 'subscription.id')
                ->where('subscription.authority_version', NativeSubscriptionStore::AUTHORITY)
                ->where('subscription.installation_id', $installation['installation_id'])
                ->whereIn('subscription.status', ['active', 'suspended'])
                ->whereNull('subscription.ended_at')->where('period.status', 'open')
                ->whereNotIn('subscription.id', $failed)
                ->where(function ($query) use ($now): void {
                    $query->where('subscription.expires_at', '<=', $now)->orWhere('period.next_reset_at', '<=', $now);
                })
                ->orderByRaw('CASE WHEN period.next_reset_at IS NULL THEN subscription.expires_at '
                    .'WHEN subscription.expires_at IS NULL THEN period.next_reset_at '
                    .'WHEN period.next_reset_at < subscription.expires_at THEN period.next_reset_at '
                    .'ELSE subscription.expires_at END')
                ->orderBy('subscription.id')->orderBy('period.id')
                ->first(['subscription.id', 'subscription.user_id', 'subscription.row_version',
                    'subscription.expires_at', 'period.id as period_id', 'period.next_reset_at']);
            if (!$candidate) {
                break;
            }
            $result['attempted']++;
            $resetAt = $candidate->next_reset_at === null ? null : (int) $candidate->next_reset_at;
            $expiry = $candidate->expires_at === null ? null : (int) $candidate->expires_at;
            $reset = $resetAt !== null && ($expiry === null || $resetAt < $expiry);
            $boundary = $reset ? $resetAt : $expiry;
            $kind = $reset ? 'reset' : 'expire';
            $key = 'tick/'.$kind.'/'.$candidate->id.'/'.($reset ? $candidate->period_id.'/' : '').$boundary;
            try {
                if ($reset) {
                    $this->reset((int) $candidate->user_id, (int) $candidate->id, $key,
                        $candidate->row_version, $boundary, 'scheduled');
                } else {
                    $this->expire((int) $candidate->user_id, (int) $candidate->id, $key,
                        $candidate->row_version, $boundary);
                }
                $result['completed']++;
            } catch (Throwable $error) {
                $failed[] = $candidate->id;
                $result['failed']++;
                $result['errors'][] = ['subscription_id' => (string) $candidate->id,
                    'error_code' => preg_match('/\ANATIVE_[A-Z_]{1,64}\z/D', $error->getMessage())
                        ? $error->getMessage() : 'NATIVE_TICK_OPERATION_FAILED'];
            }
        }
        return $result;
    }

    private function mutate(string $kind, int $userId, int $subscriptionId, string $key, int|string $expectedVersion,
        ?int $at, array $parameters = []): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new DomainException('NATIVE_OUTER_TRANSACTION_UNSUPPORTED');
        }
        $request = [
            'schema' => NativeSubscriptionStore::OPERATION, 'kind' => $kind,
            'user_id' => SubscriptionTermsTimeline::decimal($userId, true),
            'subscription_id' => SubscriptionTermsTimeline::decimal($subscriptionId, true),
            'business_key' => $this->store->businessKey($key),
            'expected_version' => SubscriptionTermsTimeline::decimal($expectedVersion, true),
            'effective_at' => SubscriptionTermsTimeline::timestamp($at, true), 'parameters' => $parameters,
        ];
        $now = SubscriptionTermsTimeline::timestamp($at ?? time());
        $result = DB::transaction(function () use ($request, $kind, $userId, $subscriptionId, $key, $now, $parameters): array {
            $installation = app(InstallationAuthority::class)->assertNativeMutation();
            [$user, $slot, $subscriptions] = $this->store->lockAccount($userId, $now, $installation['installation_id']);
            $subscription = $subscriptions->firstWhere('id', $subscriptionId);
            if (!$subscription) {
                throw new DomainException('NATIVE_SUBSCRIPTION_MISSING');
            }
            $operation = DB::table('tx_subscription_operation')->where('user_id', $userId)->where('business_key', $key)->lockForUpdate()->first();
            $history = $this->store->lockHistory($subscription);
            if ($operation) {
                $this->store->verifyHistory($subscription, $history);
                return $this->store->replay($operation, $request, $subscription);
            }
            if ((string) $subscription->row_version !== $request['expected_version']) {
                throw new DomainException('NATIVE_SUBSCRIPTION_VERSION_CONFLICT');
            }
            if ((string) $slot->subscription_id !== (string) $subscriptionId
                || !in_array($subscription->status, ['active', 'suspended'], true)
                || $subscription->ended_at !== null || (int) $subscription->starts_at > $now) {
                throw new DomainException('NATIVE_SUBSCRIPTION_INACTIVE');
            }
            $expiry = $subscription->expires_at === null ? null : (int) $subscription->expires_at;
            if ($kind === 'expire' ? ($expiry === null || $expiry > $now) : ($expiry !== null && $expiry <= $now)) {
                throw new DomainException($kind === 'expire' ? 'NATIVE_EXPIRY_NOT_DUE' : 'NATIVE_SUBSCRIPTION_EXPIRED');
            }
            $boundary = $kind === 'expire' ? $expiry : $now;
            $this->store->protectAcceptedUsage((string) $subscriptionId, $boundary);
            $current = $this->store->verifyHistory($subscription, $history);
            $period = $current['period'];
            if (!$period || $now < (int) $period->starts_at || $now < (int) $current['terms']->effective_from) {
                throw new DomainException('NATIVE_PERIOD_AMBIGUOUS');
            }
            $extra = [];
            if ($kind === 'suspend' || $kind === 'resume') {
                if (($kind === 'suspend' && $subscription->status !== 'active')
                    || ($kind === 'resume' && ($subscription->status !== 'suspended' || (bool) ($user->banned ?? false)))) {
                    throw new DomainException('NATIVE_LIFECYCLE_TRANSITION_INVALID');
                }
                $subscription = $this->store->updateSubscription($subscription, [
                    'status' => $kind === 'suspend' ? 'suspended' : 'active',
                    'suspended_reason' => $kind === 'suspend' ? $parameters['reason'] : null,
                ], $now);
            } elseif ($kind === 'reset') {
                if (((bool) ($user->banned ?? false) && $parameters['trigger'] !== 'scheduled') || $now <= (int) $period->starts_at
                    || ($period->ends_at !== null && (int) $period->ends_at < $now)
                    || ($parameters['trigger'] === 'scheduled'
                        && ($period->next_reset_at === null || (int) $period->next_reset_at !== $now || (int) $period->ends_at !== $now))) {
                    throw new DomainException('NATIVE_RESET_BOUNDARY_INVALID');
                }
                $this->store->closePeriod($period, $now, $now);
                $extra['previous_period_id'] = (string) $period->id;
                $period = $this->store->openPeriod($subscription, $current['terms'], $now, $period);
                $subscription = $this->store->updateSubscription($subscription, [], $now);
            } else {
                $period = $this->store->closePeriod($period, $boundary, $now);
                $subscription = $this->store->updateSubscription($subscription, [
                    'status' => $kind === 'expire' ? 'expired' : 'revoked', 'ended_at' => $boundary,
                ], $now);
                $this->store->replaceSlot($slot, null, $now);
            }
            $result = $this->store->result($subscription, $period, $now, $extra);
            $this->store->record($request, $result, $now);
            app(NativeCredentialSynchronization::class)->stageLocked($userId, $subscriptionId);
            return $result;
        }, 3);
        app(NativeCredentialSynchronization::class)->synchronize($userId, $subscriptionId);
        return $result;
    }
}
