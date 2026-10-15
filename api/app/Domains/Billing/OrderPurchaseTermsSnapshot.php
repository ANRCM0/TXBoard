<?php

namespace App\Domains\Billing;

use App\Models\Order;
use App\Models\Plan;
use App\Services\OrderService;
use App\Services\Subscription\PlanCatalogShadowService;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/** Builds the immutable entitlement/price snapshot at order creation. */
final class OrderPurchaseTermsSnapshot
{
    /** @return array<string,mixed> */
    public function build(Order $order, Plan $plan, string $currency, int $resetMethod, string $timezone = 'UTC'): array
    {
        if ($order->id <= 0 || $order->user_id <= 0 || $plan->id <= 0 || $order->plan_id !== $plan->id) {
            throw new InvalidArgumentException('ORDER_PURCHASE_TERMS_IDENTITY_INVALID');
        }
        if (!preg_match('/^[A-Z]{3}$/D', $currency) || !in_array($currency, ['USD','CNY','EUR','GBP','HKD','SGD','CAD','AUD','NZD','TWD'], true)) {
            throw new InvalidArgumentException('ORDER_PURCHASE_TERMS_CURRENCY_INVALID');
        }
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('ORDER_PURCHASE_TERMS_TIMEZONE_INVALID');
        }
        $period = (string) $order->period;
        if (!array_key_exists($period, Plan::getAvailablePeriods())) {
            throw new InvalidArgumentException('ORDER_PURCHASE_TERMS_PERIOD_INVALID');
        }
        $months = OrderService::STR_TO_TIME[$period] ?? null;
        $kind = $period === Plan::PERIOD_ONETIME ? 'perpetual' : ($period === Plan::PERIOD_RESET_TRAFFIC ? 'traffic_reset' : 'subscription');
        $quotaGiB = $plan->getRawOriginal('transfer_enable');
        if ($quotaGiB === null && $plan->transfer_enable !== null) { $quotaGiB = $plan->transfer_enable; }
        $quotaBytes = $quotaGiB === null ? null : $this->quotaBytes($quotaGiB);
        $resetMethod = $resetMethod >= 0 && $resetMethod <= 4 ? $resetMethod : throw new InvalidArgumentException('ORDER_PURCHASE_TERMS_RESET_INVALID');
        $source = [
            'schema' => 'tx-core.purchase-terms/v1', 'plan_id' => (string) $plan->id,
            'plan_name' => (string) $plan->name, 'period' => $period, 'order_type' => (int) $order->type,
            'list_price_minor' => app(PlanCatalogShadowService::class)->legacyPriceMinor($plan->prices[$period] ?? null),
            'price_minor' => (string) $order->total_amount, 'currency' => $currency,
            'quota_bytes' => $quotaBytes, 'group_id' => $plan->group_id === null ? null : (int) $plan->group_id,
            'speed_limit_mbps' => $plan->speed_limit === null ? null : (int) $plan->speed_limit,
            'device_limit' => $plan->device_limit === null ? null : (int) $plan->device_limit,
            'reset_policy' => ['revision' => 'legacy-current/v1', 'method' => $resetMethod, 'timezone' => $timezone],
            'duration_months' => $months, 'purchase_kind' => $kind,
        ];
        return $source + ['content_hash' => app(PlanCatalogShadowService::class)->hash($source)];
    }

    private function quotaBytes(mixed $value): string
    {
        if (!is_int($value) && !is_string($value) || !preg_match('/^(0|[1-9][0-9]*)$/D', (string) $value)) throw new InvalidArgumentException('ORDER_PURCHASE_TERMS_QUOTA_INVALID');
        $value = (int) $value;
        if ($value < 0 || $value > intdiv(PHP_INT_MAX, 1073741824)) throw new InvalidArgumentException('ORDER_PURCHASE_TERMS_QUOTA_OUT_OF_RANGE');
        return (string) ($value * 1073741824);
    }


}
