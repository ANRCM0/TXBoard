<?php

namespace App\Observers;

use App\Models\User;
use App\Services\TrafficResetService;

final class UserObserver
{
    public function __construct(private readonly TrafficResetService $trafficResetService) {}

    public function onUpdated(User $user): void
    {
        if (!$user->wasChanged(['plan_id', 'expired_at'])) return;
        $this->scheduleReset($user);
    }

    public function onCreated(User $user): void
    {
        $this->scheduleReset($user);
    }

    private function scheduleReset(User $user): void
    {
        // Freeze this committed event; another save may mutate the original model.
        $snapshot = $user->getConnection()->transactionLevel() === 0 ? $user : clone $user;
        $user->getConnection()->afterCommit(function () use ($snapshot): void {
            $snapshot->getConnection()->transaction(function () use ($snapshot): void {
                $current = $snapshot->newQuery()->whereKey($snapshot->id)->lockForUpdate()->first();
                if ($current === null) return;
                User::withoutEvents(function () use ($current): void {
                    $current->next_reset_at = $this->trafficResetService->calculateNextResetTime($current)?->timestamp;
                    $current->saveOrFail();
                });
            });
            $snapshot->refresh();
        });
    }
}
