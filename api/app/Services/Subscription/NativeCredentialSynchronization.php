<?php

namespace App\Services\Subscription;

use App\Domains\Installation\InstallationAuthority;
use App\Domains\Installation\NativeRuntimeAdmission;
use App\Domains\UsageV2\NativeUsageReservations;
use Illuminate\Support\Facades\DB;
use Throwable;

final class NativeCredentialSynchronization
{
    public function stageLocked(int $userId, int $subscriptionId): void
    {
        if (!$this->enabled()) { return; }
        app(InstallationAuthority::class)->assertNativeMutation();
        $subscription = DB::table('tx_subscription')->where('id', $subscriptionId)->where('user_id', $userId)->lockForUpdate()->first();
        if (!$subscription) { throw new \DomainException('NATIVE_SYNCHRONIZATION_OWNER_INVALID'); }
        $user = DB::table('tx_user')->where('id', $userId)->first();
        $periods = DB::table('tx_usage_period')->where('subscription_id', $subscriptionId)->where('status', 'open')
            ->orderBy('id')->lockForUpdate()->get();
        $exhausted = $periods->contains(static fn (object $period): bool =>
            (int) NativeUsageReservations::consumed($period) >= (int) $period->quota_bytes);
        $references = [];
        if ($subscription->status === 'active' && $user && !(bool) $user->banned && !$exhausted) {
            $references = app(NativeCredentialProviderService::class)->recordRotationLocked($userId, $subscriptionId);
        }
        if ($references !== [] || $subscription->status !== 'active' || !$user || (bool) $user->banned || $exhausted) {
            DB::table('tx_auth_snapshot')->forceIndex('tx_native_auth_sub_idx')->where('subscription_id', $subscriptionId)
                ->whereNull('revoked_at')->update(['revoked_at' => time()]);
        }
        DB::table('tx_native_runtime_outbox')->insert([
            'event_key' => 'credential.sync:'.bin2hex(random_bytes(16)), 'kind' => 'credential.sync',
            'user_id' => $userId, 'subscription_id' => $subscriptionId,
            'subscription_version' => $subscription->row_version, 'status' => 'pending', 'created_at' => time(),
        ]);
    }

    /** Provider failure leaves committed business state and a durable retry item. */
    public function synchronize(int $userId, int $subscriptionId): array
    {
        if (!$this->enabled()) { return ['completed' => true, 'operations' => []]; }
        if (DB::transactionLevel() !== 0) { throw new \DomainException('NATIVE_SYNCHRONIZATION_OUTER_TRANSACTION'); }
        $ids = DB::table('tx_native_runtime_outbox')->where('user_id', $userId)->where('subscription_id', $subscriptionId)
            ->where('status', 'pending')->orderBy('id')->limit(1024)->pluck('id')->all();
        try {
            $results = app(NativeCredentialProviderService::class)->synchronize($userId, $subscriptionId);
            foreach ($results as $result) {
                if (in_array($result['status'], ['pending', 'unknown'], true)) {
                    throw new \DomainException('NATIVE_CREDENTIAL_SYNCHRONIZATION_PENDING');
                }
                if (($result['remediation_operation_ref'] ?? null) !== null) {
                    $remediation = app(NativeCredentialProviderService::class)->operation($userId, $result['remediation_operation_ref']);
                    if ($remediation['status'] !== 'succeeded') {
                        throw new \DomainException('NATIVE_CREDENTIAL_SYNCHRONIZATION_PENDING');
                    }
                }
            }
            DB::transaction(function () use ($ids): void {
                app(InstallationAuthority::class)->assertNativeMutation();
                DB::table('tx_native_runtime_outbox')->whereIn('id', $ids)->where('status', 'pending')
                    ->update(['status' => 'completed', 'error_code' => null, 'completed_at' => time()]);
            }, 3);
            return ['completed' => true, 'operations' => $results];
        } catch (Throwable $error) {
            $code = preg_match('/\A[A-Z][A-Z0-9_]{0,79}\z/D', $error->getMessage())
                ? $error->getMessage() : 'NATIVE_CREDENTIAL_SYNCHRONIZATION_FAILED';
            DB::transaction(function () use ($ids, $code): void {
                app(InstallationAuthority::class)->assertNativeMutation();
                DB::table('tx_native_runtime_outbox')->whereIn('id', $ids)->where('status', 'pending')
                    ->update(['attempts' => DB::raw('attempts + 1'), 'error_code' => $code]);
            }, 3);
            return ['completed' => false, 'error_code' => $code, 'operations' => []];
        }
    }

    public function recover(int $limit = 100): array
    {
        app(InstallationAuthority::class)->assertNativeRuntime();
        if ($limit < 1 || $limit > 1000) { throw new \DomainException('NATIVE_SYNCHRONIZATION_LIMIT'); }
        $items = DB::table('tx_native_runtime_outbox')->where('status', 'pending')->orderBy('attempts')->orderBy('id')->limit($limit)
            ->get(['id', 'user_id', 'subscription_id'])->unique('subscription_id');
        $results = [];
        foreach ($items as $item) { $results[(string) $item->subscription_id] = $this->synchronize((int) $item->user_id, (int) $item->subscription_id); }
        // Credential expiry is independent of Subscription versions.
        $candidates = DB::table('tx_subscription_slot as slot')
            ->join('tx_subscription as subscription', 'subscription.id', '=', 'slot.subscription_id')
            ->join('tx_user as account', 'account.id', '=', 'subscription.user_id')
            ->join('tx_usage_period as period', 'period.subscription_id', '=', 'subscription.id')
            ->where('subscription.status', 'active')
            ->where('account.banned', false)->where('period.status', 'open')
            ->where('subscription.starts_at', '<=', time())
            ->where(fn ($query) => $query->whereNull('subscription.expires_at')->orWhere('subscription.expires_at', '>', time()))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('tx_native_runtime_outbox as work')
                ->whereColumn('work.subscription_id', 'subscription.id')->where('work.status', 'pending'))
            ->where(function ($query): void {
                $query->whereNotExists(fn ($subquery) => $subquery->selectRaw('1')->from('tx_access_subject as subject')
                    ->whereColumn('subject.subscription_id', 'subscription.id')->where('subject.status', 'active'))
                    ->orWhereExists(fn ($subquery) => $subquery->selectRaw('1')->from('tx_access_subject as subject')
                        ->whereColumn('subject.subscription_id', 'subscription.id')->where('subject.status', 'active')
                        ->where('subject.credential_expires_at', '<=', time() + 120));
            });
        NativeUsageReservations::constrainAvailable($candidates);
        $candidates = $candidates->orderBy('subscription.id')->limit($limit)->get(['subscription.id', 'subscription.user_id']);
        foreach ($candidates as $candidate) {
            if (isset($results[(string) $candidate->id])) { continue; }
            DB::transaction(function () use ($candidate): void {
                $installation = app(InstallationAuthority::class)->assertNativeMutation();
                [, , $subscriptions] = app(NativeSubscriptionStore::class)->lockAccount((int) $candidate->user_id, time(), $installation['installation_id']);
                $subscription = $subscriptions->firstWhere('id', $candidate->id);
                if ($subscription) {
                    app(NativeSubscriptionStore::class)->lockHistory($subscription);
                    $this->stageLocked((int) $candidate->user_id, (int) $candidate->id);
                }
            }, 3);
            $results[(string) $candidate->id] = $this->synchronize((int) $candidate->user_id, (int) $candidate->id);
        }
        return $results;
    }

    private function enabled(): bool
    {
        return (app(InstallationAuthority::class)->inspect()['runtime_version'] ?? null) === NativeRuntimeAdmission::VERSION;
    }
}
