<?php

namespace App\Domains\Subscription;

use App\Models\Plan;
use App\Models\User;
use App\Services\PlanService;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read-only native catalog. Legacy PlanResource, order price calculation,
 * renewal permissions and the existing plan schema remain authoritative.
 */
final class PlanCatalog
{
    /** @return list<array<string, mixed>> */
    public function available(): array
    {
        $plans = Plan::query()
            ->where('show', true)
            ->where('sell', true)
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        $limitedIds = $plans->filter(
            static fn (Plan $plan): bool => $plan->capacity_limit !== null
        )->modelKeys();

        // One bounded grouped query instead of N independent COUNT queries.
        $counts = count($limitedIds) > 0
            ? User::query()->whereIn('plan_id', $limitedIds)
                ->where(static function ($query): void {
                    $query->where('expired_at', '>=', time())
                        ->orWhereNull('expired_at');
                })
                ->selectRaw('plan_id, COUNT(*) AS active_count')
                ->groupBy('plan_id')
                ->pluck('active_count', 'plan_id')
            : collect();

        return $plans
            ->filter(static fn (Plan $plan): bool => $plan->capacity_limit === null
                || ((int) $plan->capacity_limit - (int) ($counts[$plan->id] ?? 0)) > 0)
            ->map(static fn (Plan $plan): array => self::toDto($plan))
            ->values()->all();
    }

    public function availableForUser(int $planId, User $user): ?array
    {
        $plan = Plan::query()->find($planId);
        if ($plan === null || !app(PlanService::class)->isPlanAvailableForUser($plan, $user)) {
            // As with the old API, an unavailable plan is indistinguishable
            // from a nonexistent one to prevent hidden-plan discovery.
            return null;
        }
        return self::toDto($plan);
    }

    /** @return array<string, mixed> */
    public static function toDto(Plan $plan): array
    {
        $prices = [];
        foreach (Plan::getAvailablePeriods() as $period => $label) {
            $price = $plan->prices[$period] ?? null;
            if (!is_numeric($price) || (float) $price <= 0) {
                continue;
            }
            $prices[] = [
                'period' => $period,
                'amount_minor' => (int) round((float) $price * 100),
            ];
        }

        return [
            'id' => (int) $plan->id,
            'name' => (string) $plan->name,
            'content' => (string) ($plan->content ?? ''),
            'tags' => is_array($plan->tags) ? array_values($plan->tags) : [],
            'traffic_limit_bytes' => (int) $plan->transfer_enable * 1073741824,
            'speed_limit_mbps' => $plan->speed_limit === null ? null : (int) $plan->speed_limit,
            'device_limit' => $plan->device_limit === null ? null : (int) $plan->device_limit,
            'capacity_limit' => $plan->capacity_limit === null ? null : (int) $plan->capacity_limit,
            'reset_traffic_method' => $plan->reset_traffic_method === null
                ? null : (int) $plan->reset_traffic_method,
            'prices' => $prices,
            'renewable' => (bool) $plan->renew,
        ];
    }
}
