<?php

namespace App\Observers;

use App\Jobs\NodeUserSyncJob;
use App\Models\User;
use App\Services\TrafficResetService;
use App\Services\Subscription\LegacyCaptureShadowException;
use App\Services\Subscription\LegacyUserMutationCapture;
use Illuminate\Support\Facades\DB;

class UserObserver
{
  public bool $afterCommit = true;

  public function __construct(
    private readonly TrafficResetService $trafficResetService
  ) {
    $this->afterCommit = !\App\Domains\AuthorityMigration\AuthorityNativeInventory::declared();
  }

  public function updated(User $user): void
  {
    // With $afterCommit = true, isDirty() is always false after commit.
    // Use wasChanged() to detect what was actually modified.
    $syncFields = ['group_id', 'uuid', 'speed_limit', 'device_limit', 'banned', 'expired_at', 'transfer_enable', 'u', 'd', 'plan_id'];
    $needsSync = $user->wasChanged($syncFields);
    $oldGroupId = $user->wasChanged('group_id') ? $user->getOriginal('group_id') : null;

    if (\App\Domains\AuthorityMigration\AuthorityNativeInventory::declared()) {
      $schedule = $user->wasChanged(['plan_id', 'expired_at']);
      if ($schedule || $needsSync) app(\App\Domains\AuthorityMigration\FencedUserPostcommit::class)->stage($user, 'updated', $oldGroupId, $schedule);
      return;
    }
    if ($user->wasChanged(['plan_id', 'expired_at'])) {
      $this->recalculateNextResetAt($user);
    }

    if ($needsSync) {
      NodeUserSyncJob::dispatch($user->id, 'updated', $oldGroupId);
    }
  }

  public function created(User $user): void
  {
    if (\App\Domains\AuthorityMigration\AuthorityNativeInventory::declared()) {
      app(\App\Domains\AuthorityMigration\FencedUserPostcommit::class)->stage($user, 'created', null, true);
      return;
    }
    $this->recalculateNextResetAt($user);
    NodeUserSyncJob::dispatch($user->id, 'created');
  }

  public function deleted(User $user): void
  {
    if (\App\Domains\AuthorityMigration\AuthorityNativeInventory::declared()) {
      if ($user->group_id) app(\App\Domains\AuthorityMigration\FencedUserPostcommit::class)->stage($user, 'deleted', (int)$user->group_id, false);
      return;
    }
    if ($user->group_id) {
      NodeUserSyncJob::dispatch($user->id, 'deleted', $user->group_id);
    }
  }

  /**
   * 根据当前用户状态重新计算 next_reset_at
   */
  private function recalculateNextResetAt(User $user): void
  {
    if (config('legacy_observer_capture.enabled') !== false) {
      if (DB::transactionLevel() !== 0) {
        throw new LegacyCaptureShadowException('CAPTURE_OBSERVER_TRANSACTION_UNSUPPORTED');
      }
      DB::transaction(function () use ($user): void {
        $current = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
        $capture = app(LegacyUserMutationCapture::class);
        $before = $capture->beginObserverSchedule((int) $current->id);
        User::withoutEvents(function () use ($current): void {
          $nextResetTime = $this->trafficResetService->calculateNextResetTime($current);
          $current->next_reset_at = $nextResetTime?->timestamp;
          $current->save();
        });
        $capture->appendObserverSchedule($before);
      });
      $user->refresh();
      return;
    }
    $user->refresh();
    User::withoutEvents(function () use ($user) {
      $nextResetTime = $this->trafficResetService->calculateNextResetTime($user);
      $user->next_reset_at = $nextResetTime?->timestamp;
      $user->save();
    });
  }
}
