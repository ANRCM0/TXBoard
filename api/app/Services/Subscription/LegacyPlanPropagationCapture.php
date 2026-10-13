<?php

namespace App\Services\Subscription;

use App\Models\User;
use Throwable;

/** Adapter for the existing Plan force update, not a subscription writer. */
final class LegacyPlanPropagationCapture
{
    public function apply(int $planId, int $adminId, array $attributes): void
    {
        if (config('legacy_plan_propagation_capture.enabled') === false) {
            User::query()->where('plan_id', $planId)->update($attributes);
            return;
        }
        try {
            $capture = app(LegacyUserMutationCapture::class);
            $capture->assertPlanPropagationContext();
            $ids = User::query()->where('plan_id', $planId)->orderBy('id')
                ->limit(1001)->lockForUpdate()->pluck('id')->all();
            if (count($ids) > 1000) {
                throw new LegacyCaptureShadowException('CAPTURE_PLAN_SCOPE_LIMIT');
            }
            $tickets = [];
            foreach ($ids as $id) {
                $tickets[] = $capture->beginPlanPropagation((int) $id, $adminId);
            }
            // Preserve bulk SQL semantics: no per-User Eloquent events. Freeze
            // the bounded selected set so no newly matching User is unrecorded.
            if ($ids !== []) {
                User::query()->where('plan_id', $planId)->whereIn('id', $ids)->update($attributes);
            }
            foreach ($tickets as $ticket) {
                $capture->appendPlanPropagation($ticket);
            }
        } catch (LegacyCaptureShadowException $error) {
            throw $error;
        } catch (Throwable) {
            throw new LegacyCaptureShadowException('CAPTURE_PLAN_WRITE_FAILED');
        }
    }
}
