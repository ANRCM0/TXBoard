<?php

namespace App\Console\Commands;

use App\Domains\Network\NativeTrafficReceipt;
use App\Models\Server;
use App\Models\User;
use App\Services\NodeSyncService;
use App\Services\ServerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

class CheckTrafficExceeded extends Command
{
    protected $signature = 'check:traffic-exceeded {--limit=100 : Maximum durable receipts and legacy candidates per run}';
    protected $description = 'Reconcile current quota users from durable receipts and retryable legacy hints';

    public function handle(): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 1000) {
            $this->error('Limit must be 1..1000.');
            return self::FAILURE;
        }
        $failed = false;
        $checked = 0;
        if (config('native_traffic_receipts.enabled', true)) {
            // A lost Redis hint cannot erase the committed native settlement.
            try {
                $receipts = DB::table('tx_native_traffic_receipt')->where('status', 'settled')
                    ->whereNull('quota_checked_at')->orderBy('id')->limit($limit)->get();
            } catch (Throwable) {
                $this->error('NATIVE_QUOTA_STORAGE_UNAVAILABLE');
                return self::FAILURE;
            }
            $service = app(NativeTrafficReceipt::class);
            foreach ($receipts as $receipt) {
                try {
                    $this->synchronize($service->quotaUserIds($receipt));
                    $service->markQuotaChecked((int) $receipt->id);
                    ++$checked;
                } catch (Throwable) {
                    $failed = true;
                    Log::warning('NATIVE_QUOTA_RECONCILIATION_FAILED');
                }
            }
        }
        try {
            // Bounded sampling does not remove a hint before publication. Native
            // races on SREM are additionally backed by separate immutable receipts.
            $legacyIds = Redis::srandmember('traffic:pending_check', $limit);
            $legacyIds = is_array($legacyIds) ? array_values(array_unique(array_map('intval', $legacyIds))) : [];
            if ($legacyIds !== []) {
                $this->synchronize($legacyIds);
                Redis::srem('traffic:pending_check', ...$legacyIds);
                $checked += count($legacyIds);
            }
        } catch (Throwable) {
            $failed = true;
            Log::warning('LEGACY_QUOTA_RECONCILIATION_FAILED');
        }
        $this->info("Reconciled {$checked} quota candidates.");
        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function synchronize(array $userIds): void
    {
        $groupIds = User::query()->whereIn('id', $userIds)->whereNotNull('group_id')
            ->distinct()->pluck('group_id')->map(static fn ($id): int => (int) $id)->all();
        if ($groupIds === []) return;
        $servers = Server::query()->where('enabled', true)->where(function ($query) use ($groupIds): void {
            foreach ($groupIds as $groupId) {
                $query->orWhereJsonContains('group_ids', $groupId)
                    ->orWhereJsonContains('group_ids', (string) $groupId);
            }
        })->get();
        foreach ($servers as $server) {
            if (!NodeSyncService::isNodeOnline((int) $server->id)) continue;
            // Send current desired state; Workerman rechecks it again at send
            // time, so delayed quota work cannot overwrite a later reset/grant.
            if (!NodeSyncService::push((int) $server->id, 'sync.users', [
                'users' => ServerService::getNativeAvailableUsers($server)->values()->all(),
            ])) throw new \RuntimeException('QUOTA_PUBLICATION_FAILED');
        }
    }
}
