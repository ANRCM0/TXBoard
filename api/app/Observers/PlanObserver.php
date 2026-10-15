<?php

namespace App\Observers;

use App\Models\Plan;
use App\Models\User;
use App\Services\TrafficResetService;

class PlanObserver
{
    /**
     * reset user  next_reset_at
     */
    public function updated(Plan $plan): void
    {
        if (!$plan->isDirty('reset_traffic_method')) {
            return;
        }
        if (app(\App\Domains\Installation\InstallationAuthority::class)->inspect()['mode'] === 'native') {
            // Native reset policy changes take effect through successor published Terms.
            return;
        }
        if (config('authority_writer_fence.enabled') !== false && \Illuminate\Support\Facades\DB::transactionLevel() === 0) {
            throw new \App\Domains\AuthorityMigration\AuthorityFenceException('AUTHORITY_PLAN_OBSERVER_OUTER_TRANSACTION_REQUIRED');
        }
        $trafficResetService = app(TrafficResetService::class);
        User::where('plan_id', $plan->id)
            ->where('banned', 0)
            ->where(function ($query) {
                $query->where('expired_at', '>', time())
                    ->orWhereNull('expired_at');
            })
            ->lazyById(500)
            ->each(function (User $user) use ($trafficResetService) {
                $nextResetTime = $trafficResetService->calculateNextResetTime($user);
                $user->update([
                    'next_reset_at' => $nextResetTime?->timestamp,
                ]);
            });
    }
}

