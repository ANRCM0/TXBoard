<?php

namespace App\Http\Controllers\Txapi;

use App\Core\Http\TxapiResponse;
use App\Domains\Subscription\PlanCatalog;
use App\Models\User;
use App\Services\SiteConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class PublicController
{
    public function config(Request $request): JsonResponse
    {
        return TxapiResponse::success($request, [
            'name' => (string) config('app.name', 'TXBoard'),
            'api_prefix' => '/txapi',
        ]);
    }

    public function siteConfig(Request $request, SiteConfigService $settings): JsonResponse
    {
        return TxapiResponse::success($request, $settings->guest());
    }

    public function plans(Request $request, PlanCatalog $catalog): JsonResponse
    {
        return TxapiResponse::success($request, $catalog->available());
    }

    public function plan(Request $request, PlanCatalog $catalog, int $planId): JsonResponse
    {
        $user = Auth::guard('sanctum')->user();
        if (!$user instanceof User) {
            abort(401);
        }
        $plan = $catalog->availableForUser($planId, $user);
        if ($plan === null) {
            abort(404);
        }
        return TxapiResponse::success($request, $plan);
    }
}
