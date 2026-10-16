<?php

namespace App\Domains\UsageV2;

use App\Domains\Installation\InstallationAuthority;
use App\Services\NodeSyncService;
use App\Services\Subscription\NativeCredentialSynchronization;
use App\Services\Subscription\NativeSubscriptionStore;
use App\Services\Subscription\SubscriptionEntitlementResolver;
use Illuminate\Support\Facades\DB;
use Throwable;

final class NativeRuntimeWorker
{
    public function run(int $limit): array
    {
        app(InstallationAuthority::class)->assertNativeRuntime();
        if ($limit < 1 || $limit > 1000) { throw new UsageError('USAGE_SCAN_LIMIT'); }
        $settlements = app(UsageSettlement::class)->scan($limit, time());
        $notifications = [];
        $events = DB::table('tx_usage_outbox')->where('kind', 'usage.settled')->where('status', 'pending')
            ->orderBy('id')->limit($limit)->get();
        foreach ($events as $event) {
            try {
                $batch = DB::table('tx_usage_batch')->where('id', $event->batch_id)->first();
                if (!$batch || $batch->status !== 'settled') { throw new UsageError('USAGE_NOTIFICATION_BATCH_INVALID'); }
                $entries = json_decode($batch->frozen_entries, true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($entries) || !hash_equals($batch->frozen_hash, UsageRegistry::hash($entries))) {
                    throw new UsageError('USAGE_NOTIFICATION_EVIDENCE_INVALID');
                }
                $owners = [];
                foreach ($entries as $entry) { $owners[(string) $entry['subscription_id']] = (int) $entry['user_id']; }
                foreach ($owners as $subscriptionId => $userId) {
                    DB::transaction(function () use ($subscriptionId, $userId): void {
                        $installation = app(InstallationAuthority::class)->assertNativeMutation();
                        $store = app(NativeSubscriptionStore::class);
                        [, , $subscriptions] = $store->lockAccount($userId, time(), $installation['installation_id']);
                        $subscription = $subscriptions->firstWhere('id', $subscriptionId);
                        if (!$subscription) { throw new UsageError('USAGE_NATIVE_OWNER_MISSING'); }
                        $store->lockHistory($subscription);
                        $entitlement = app(SubscriptionEntitlementResolver::class)->resolveLocked($userId, time());
                        if (!$entitlement['authorized']) {
                            app(NativeCredentialSynchronization::class)->stageLocked($userId, (int) $subscriptionId);
                        }
                    }, 3);
                }
                // A fresh online snapshot or expiry also consumes this hint. Redis cannot certify Node application.
                $delivered = NodeSyncService::push((int) $batch->node_id, 'sync.users', ['refresh_required' => true]);
                $latest = DB::table('tx_node_authorization_state')->where('node_id', $batch->node_id)->value('issued_at');
                $expired = time() >= (int) $batch->settled_at + 120;
                $completed = $delivered || ($latest !== null && (int) $latest > (int) $batch->settled_at) || $expired;
                if ($completed) {
                    DB::transaction(function () use ($event): void {
                        app(InstallationAuthority::class)->assertNativeMutation();
                        DB::table('tx_usage_outbox')->where('id', $event->id)->where('status', 'pending')
                            ->update(['status' => 'completed', 'completed_at' => time()]);
                    }, 3);
                }
                $notifications[(string) $event->id] = $completed ? 'completed' : 'pending';
            } catch (Throwable) {
                $notifications[(string) $event->id] = 'pending';
            }
        }
        return ['settlement' => $settlements, 'notifications' => $notifications,
            'credentials' => app(NativeCredentialSynchronization::class)->recover($limit)];
    }
}
