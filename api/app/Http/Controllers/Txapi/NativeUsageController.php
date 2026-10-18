<?php

namespace App\Http\Controllers\Txapi;

use App\Core\Http\TxapiResponse;
use App\Domains\UsageV2\Contracts\UsageQueries;
use App\Domains\UsageV2\Contracts\UsageQuery;
use App\Domains\UsageV2\Contracts\UsageTargetMissing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class NativeUsageController
{
    public function index(Request $request, UsageQueries $queries): JsonResponse
    {
        if (array_diff(array_keys($request->all()), ['subscription_id', 'after_id', 'limit'])) {
            return TxapiResponse::error($request, 'NATIVE_USAGE_INPUT_INVALID', 'Unsupported usage fields', 422);
        }
        $input = $request->validate(['subscription_id' => ['sometimes', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
            'after_id' => ['sometimes', 'integer', 'min:0', 'max:'.PHP_INT_MAX],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        try {
            $page = $queries->listing((int) Auth::guard('sanctum')->id(), new UsageQuery(
                isset($input['subscription_id']) ? (int) $input['subscription_id'] : null,
                (int) ($input['after_id'] ?? 0), (int) ($input['limit'] ?? 100),
            ));
        } catch (UsageTargetMissing) {
            abort(404);
        }
        return TxapiResponse::success($request, $page->items,
            ['schema' => 'native-usage-periods/v1', 'next_after_id' => $page->nextAfterId])->header('Cache-Control', 'private, no-store');
    }
}
