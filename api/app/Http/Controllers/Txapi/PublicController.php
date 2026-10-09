<?php

namespace App\Http\Controllers\Txapi;

use App\Core\Http\TxapiResponse;
use App\Models\Plan;
use App\Services\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PublicController
{
    public function config(Request $request): JsonResponse
    {
        return TxapiResponse::success($request, [
            'name' => (string) config('app.name', 'TXBoard'),
            'api_prefix' => '/txapi',
        ]);
    }

    public function plans(Request $request): JsonResponse
    {
        $plans = Plan::query()->where('show', true)->where('sell', true)
            ->orderBy('sort')->orderBy('id')->get();
        $available = $plans->filter(
            static fn (Plan $plan): bool => app(PlanService::class)->hasCapacity($plan)
        )->map(static function (Plan $plan): array {
            $prices = [];
            foreach (Plan::getAvailablePeriods() as $period => $label) {
                $price = $plan->prices[$period] ?? null;
                if ($price === null) {
                    continue;
                }
                // Legacy price model stores currency-major amounts. TXAPI only
                // outputs integer minor units; legacy period names never leak.
                $prices[] = ['period' => $period, 'amount_minor' => (int) round((float) $price * 100)];
            }
            return [
                'id' => (int) $plan->id,
                'name' => (string) $plan->name,
                'traffic_limit_bytes' => (int) $plan->transfer_enable * 1073741824,
                'prices' => $prices,
                'renewable' => (bool) $plan->renew,
            ];
        })->values()->all();

        return TxapiResponse::success($request, $available);
    }
}
