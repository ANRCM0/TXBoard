<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PlanMutationController
{
    public function save(Request $request): JsonResponse
    {
        $input = $request->validate([
            'id' => ['sometimes','integer','min:1'],
            'name' => ['required','string','max:255'],
            'content' => ['sometimes','nullable','string'],
            'transfer_enable' => ['required','integer','min:1'],
            'group_id' => ['sometimes','nullable','integer','min:1'],
            'speed_limit' => ['sometimes','nullable','integer','min:0'],
            'device_limit' => ['sometimes','nullable','integer','min:0'],
            'capacity_limit' => ['sometimes','nullable','integer','min:0'],
            'reset_traffic_method' => ['sometimes','nullable','integer','in:0,1,2,3,4'],
            'prices' => ['sometimes','array'],
            'prices.*' => ['nullable','numeric','min:0'],
            'tags' => ['sometimes','nullable','array','max:40'],
            'tags.*' => ['string','max:80'],
            'force_update' => ['sometimes','boolean'],
        ]);
        $periods = array_keys(Plan::getAvailablePeriods());
        $prices = [];
        foreach (($input['prices'] ?? []) as $period => $amount) {
            if (!in_array((string) $period, $periods, true)) {
                throw ValidationException::withMessages(['prices' => 'Unsupported subscription period']);
            }
            if ($amount !== null && (float) $amount > 0) {
                $prices[$period] = round((float) $amount, 2);
            }
        }
        $attributes = collect($input)->except(['id','force_update'])->all();
        if (array_key_exists('prices', $input)) $attributes['prices'] = $prices;
        $force = $request->boolean('force_update');
        $id = DB::transaction(static function () use ($input, $attributes, $force): int {
            if (isset($input['id'])) {
                $plan = Plan::query()->lockForUpdate()->findOrFail((int) $input['id']);
                if ($force) {
                    User::query()->where('plan_id', $plan->id)->update([
                        'group_id' => $attributes['group_id'] ?? $plan->group_id,
                        'transfer_enable' => (int) $attributes['transfer_enable'] * 1073741824,
                        'speed_limit' => $attributes['speed_limit'] ?? $plan->speed_limit,
                        'device_limit' => $attributes['device_limit'] ?? $plan->device_limit,
                    ]);
                }
                $plan->fill($attributes)->saveOrFail();
            } else {
                if ($force) {
                    throw ValidationException::withMessages(['force_update' => 'Cannot force update without an existing plan']);
                }
                $plan = Plan::query()->create($attributes);
            }
            return (int) $plan->id;
        });
        return TxapiResponse::success($request, ['id' => $id]);
    }

    public function flags(Request $request): JsonResponse
    {
        $params = $request->validate([
            'show' => ['sometimes','boolean'],
            'sell' => ['sometimes','boolean'],
            'renew' => ['sometimes','boolean'],
        ]);
        if ($params === []) {
            return TxapiResponse::error($request, 'FLAGS_REQUIRED', 'At least one flag is required', 422);
        }
        DB::transaction(static function () use ($request, $params): void {
            $plan = Plan::query()->lockForUpdate()->findOrFail((int) $request->route('id'));
            foreach ($params as $field => $value) $plan->$field = (bool) $value;
            $plan->saveOrFail();
        });
        return TxapiResponse::success($request, ['ok' => true]);
    }

    public function sort(Request $request): JsonResponse
    {
        $params = $request->validate([
            'ids' => ['required','array','min:1','max:1000'],
            'ids.*' => ['required','integer','distinct','min:1'],
        ]);
        $ids = array_map('intval', $params['ids']);
        DB::transaction(static function () use ($ids): void {
            $found = Plan::query()->whereIn('id', $ids)->lockForUpdate()->get(['id'])->pluck('id')->all();
            if (count($found) !== count($ids)) {
                throw ValidationException::withMessages(['ids' => 'Unknown subscription plan']);
            }
            foreach ($ids as $position => $id) {
                Plan::query()->whereKey($id)->update(['sort' => $position + 1]);
            }
        });
        return TxapiResponse::success($request, ['ok' => true]);
    }

    public function delete(Request $request): JsonResponse
    {
        $id = (int) $request->route('id');
        $deleted = DB::transaction(static function () use ($id): bool {
            $plan = Plan::query()->lockForUpdate()->findOrFail($id);
            if (Order::query()->where('plan_id', $id)->exists() ||
                User::query()->where('plan_id', $id)->exists()) {
                return false;
            }
            return (bool) $plan->delete();
        });
        if (!$deleted) return TxapiResponse::error($request, 'PLAN_IN_USE', 'Subscription is in use', 409);
        return TxapiResponse::success($request, ['ok' => true]);
    }
}
