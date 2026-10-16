<?php

namespace App\Domains\Subscription\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Account plan projection only; purchased Subscription Terms remain immutable. */
final class PlanAccountPropagation
{
    /** Caller holds the plan lock and writer admission in the save transaction. */
    public function applyLocked(int $planId, array $attributes): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Plan propagation requires the plan save transaction');
        }
        if (array_diff(array_keys($attributes), ['group_id', 'transfer_enable', 'speed_limit', 'device_limit'])) {
            throw new LogicException('Unsupported plan account fields');
        }

        $ids = User::query()->where('plan_id', $planId)->orderBy('id')
            ->lockForUpdate()->pluck('id')->all();
        // Updating the frozen set retains bulk SQL behavior without including later matches.
        foreach (array_chunk($ids, 500) as $chunk) {
            User::query()->where('plan_id', $planId)->whereIn('id', $chunk)->update($attributes);
        }
    }
}
