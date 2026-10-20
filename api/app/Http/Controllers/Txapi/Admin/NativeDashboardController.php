<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Domains\Analytics\NativeDashboardRead;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NativeDashboardController
{
    public function snapshot(Request $request, NativeDashboardRead $dashboard): JsonResponse
    {
        $input = $request->validate(['start_date' => ['sometimes', 'required_with:end_date', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'required_with:start_date', 'date_format:Y-m-d']]);
        try { $snapshot = $dashboard->snapshot($input, time()); }
        catch (DomainException $error) {
            $code = $error->getMessage();
            if (!str_starts_with($code, 'DASHBOARD_')) throw $error;
            return TxapiResponse::error($request, $code, $code === 'DASHBOARD_RANGE_INVALID'
                ? 'Choose up to 366 UTC days ending today or earlier' : 'Complete native statistics are unavailable',
                $code === 'DASHBOARD_RANGE_INVALID' ? 422 : 503)->header('Cache-Control', 'private, no-store');
        }
        return TxapiResponse::success($request, $snapshot)->header('Cache-Control', 'private, no-store');
    }
}
