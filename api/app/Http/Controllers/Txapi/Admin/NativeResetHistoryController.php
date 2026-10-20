<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Domains\Subscription\Services\NativeResetHistory;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NativeResetHistoryController
{
    public function index(Request $request, NativeResetHistory $history): JsonResponse
    {
        $input = $request->validate([
            'start_date' => ['sometimes', 'required_with:end_date', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'required_with:start_date', 'date_format:Y-m-d'],
            'user_id' => ['sometimes', 'string', 'regex:/\A[1-9][0-9]{0,18}\z/D'],
            'before_id' => ['sometimes', 'string', 'regex:/\A[1-9][0-9]{0,18}\z/D'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        try { $result = $history->read($input, time()); }
        catch (DomainException $error) {
            if ($error->getMessage() === 'DASHBOARD_RANGE_INVALID') return TxapiResponse::error($request, 'NATIVE_RESET_RANGE_INVALID', 'Choose up to 366 UTC dates', 422);
            return TxapiResponse::error($request, 'NATIVE_RESET_HISTORY_UNAVAILABLE', 'Reset evidence could not be verified', 409);
        }
        return TxapiResponse::success($request, $result)->header('Cache-Control', 'private, no-store');
    }
}
