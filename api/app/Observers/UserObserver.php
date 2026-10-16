<?php

namespace App\Observers;

use App\Jobs\NodeUserSyncJob;
use App\Models\User;
use App\Services\TrafficResetService;

class UserObserver
{
  public bool $afterCommit = false;

  public function __construct(
    private readonly TrafficResetService $trafficResetService
  ) {
  }

  public function updated(User $user): void
  {
    // Capture each completed save before finishSave() synchronizes the model.
    // Deferred callbacks must not share a model that a later save can mutate.
    $syncFields = ['group_id', 'uuid', 'speed_limit', 'device_limit', 'banned', 'expired_at', 'transfer_enable', 'u', 'd', 'plan_id'];
    $needsSync = $user->wasChanged($syncFields);
    $oldGroup = $user->wasChanged('group_id') ? $user->getRawOriginal('group_id') : null;
    $oldGroupId = $oldGroup === null ? null : (int) $oldGroup;

    $this->nativeUpdated($user, $oldGroupId, $needsSync, $user->wasChanged(['plan_id', 'expired_at']));
  }

  public function created(User $user): void
  {
    $this->recalculateNextResetAt($user);
    NodeUserSyncJob::dispatch($user->id, 'created');
  }

  public function deleted(User $user): void
  {
    if ($user->group_id) {
      NodeUserSyncJob::dispatch($user->id, 'deleted', (int) $user->group_id);
    }
  }

  /** Eloquent adapters freeze one event before later saves mutate its source model. */
  public function onUpdated(User $user): void
  {
    $needsSync = $user->wasChanged(['group_id', 'uuid', 'speed_limit', 'device_limit', 'banned', 'expired_at', 'transfer_enable', 'u', 'd', 'plan_id']);
    $schedule = $user->wasChanged(['plan_id', 'expired_at']);
    if (!$schedule && !$needsSync) return;
    $oldGroup = $user->wasChanged('group_id') ? $user->getRawOriginal('group_id') : null;
    $oldGroupId = $oldGroup === null ? null : (int) $oldGroup;
    $snapshot = $user->getConnection()->transactionLevel() === 0 ? $user : clone $user;
    $user->getConnection()->afterCommit(function () use ($snapshot, $oldGroupId, $needsSync, $schedule): void {
      $this->nativeUpdated($snapshot, $oldGroupId, $needsSync, $schedule);
    });
  }

  public function onCreated(User $user): void
  {
    $snapshot = $user->getConnection()->transactionLevel() === 0 ? $user : clone $user;
    $user->getConnection()->afterCommit(function () use ($snapshot): void {
      $this->created($snapshot);
    });
  }

  public function onDeleted(User $user): void
  {
    $snapshot = clone $user;
    $user->getConnection()->afterCommit(function () use ($snapshot): void {
      $this->deleted($snapshot);
    });
  }

  private function nativeUpdated(User $user, ?int $oldGroupId, bool $needsSync, bool $schedule): void
  {
    if ($schedule) $this->recalculateNextResetAt($user);
    if ($needsSync) NodeUserSyncJob::dispatch($user->id, 'updated', $oldGroupId);
  }

  /**
   * 根据当前用户状态重新计算 next_reset_at
   */
  private function recalculateNextResetAt(User $user): void
  {
    $user->getConnection()->transaction(function () use ($user): void {
      $current = $user->newQuery()->whereKey($user->id)->lockForUpdate()->firstOrFail();
      User::withoutEvents(function () use ($current): void {
        $nextResetTime = $this->trafficResetService->calculateNextResetTime($current);
        $current->next_reset_at = $nextResetTime?->timestamp;
        $current->saveOrFail();
      });
    });
    $user->refresh();
  }
}
