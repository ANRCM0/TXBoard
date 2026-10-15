<?php

namespace App\Domains\Billing;

use App\Models\Order;
use App\Models\Plan;
use App\Services\OrderService;
use App\Domains\Subscription\CatalogValue;
use App\Models\OrderPurchaseTerms;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/** Builds the immutable entitlement/price snapshot at order creation. */
final class OrderPurchaseTermsSnapshot
{
    public function __construct(private readonly CatalogValue $catalog) {}

    /** @return array<string,mixed> */
    public function build(Order $order, Plan $plan, string $currency, int $resetMethod, string $timezone = 'UTC', int $openEventId = 0, bool $manualAssignment = false): array
    {
        if ($order->total_amount === null || !preg_match('/^(0|[1-9][0-9]*)$/D', (string) $order->total_amount)
            || (string) (int) $order->total_amount !== (string) $order->total_amount) {
            throw new InvalidArgumentException('ORDER_PURCHASE_TERMS_AMOUNT_INVALID');
        }
        if ($order->id <= 0 || $order->user_id <= 0 || $plan->id <= 0 || $order->plan_id !== $plan->id) {
            throw new InvalidArgumentException('ORDER_PURCHASE_TERMS_IDENTITY_INVALID');
        }
        if (!preg_match('/^[A-Z]{3}$/D', $currency) || !in_array($currency, ['USD','CNY','EUR','GBP','HKD','SGD','CAD','AUD','NZD','TWD'], true)) {
            throw new InvalidArgumentException('ORDER_PURCHASE_TERMS_CURRENCY_INVALID');
        }
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('ORDER_PURCHASE_TERMS_TIMEZONE_INVALID');
        }
        if (!in_array($openEventId, [0, 1], true)) throw new InvalidArgumentException('ORDER_PURCHASE_TERMS_EVENT_INVALID');
        $period = (string) $order->period;
        if (!array_key_exists($period, Plan::getAvailablePeriods())) {
            throw new InvalidArgumentException('ORDER_PURCHASE_TERMS_PERIOD_INVALID');
        }
        $months = OrderService::STR_TO_TIME[$period] ?? null;
        if (($period === Plan::PERIOD_RESET_TRAFFIC) !== ((int) $order->type === Order::TYPE_RESET_TRAFFIC)) throw new InvalidArgumentException('ORDER_PURCHASE_TERMS_TYPE_INVALID');
        $kind = $period === Plan::PERIOD_ONETIME ? 'perpetual' : ($period === Plan::PERIOD_RESET_TRAFFIC ? 'traffic_reset' : 'subscription');
        $quotaGiB = $plan->getRawOriginal('transfer_enable');
        if ($quotaGiB === null && $plan->transfer_enable !== null) { $quotaGiB = $plan->transfer_enable; }
        $quotaBytes = $quotaGiB === null ? null : $this->quotaBytes($quotaGiB);
        $resetMethod = $resetMethod >= 0 && $resetMethod <= 4 ? $resetMethod : throw new InvalidArgumentException('ORDER_PURCHASE_TERMS_RESET_INVALID');
        $source = [
            'schema' => 'tx-core.purchase-terms/v1', 'order_id' => (string) $order->id, 'user_id' => (string) $order->user_id, 'plan_id' => (string) $plan->id,
            'plan_name' => (string) $plan->name, 'period' => $period, 'order_type' => (int) $order->type,
            'quote_source' => $manualAssignment ? 'manual_assignment' : 'catalog',
            'list_price_minor' => $manualAssignment ? null : $this->catalog->legacyPriceMinor($plan->prices[$period] ?? null),
            'price_minor' => (string) $order->total_amount, 'currency' => $currency, 'currency_minor_exponent' => 2,
            'quota_bytes' => $quotaBytes, 'group_id' => $plan->group_id === null ? null : (int) $plan->group_id,
            'speed_limit_mbps' => $this->optionalLimit($plan->speed_limit),
            'device_limit' => $this->optionalLimit($plan->device_limit),
            'reset_policy' => ['revision' => 'purchased-reset/v1', 'method' => $resetMethod, 'timezone' => $timezone],
            'duration_months' => $months, 'calendar_policy' => ['timezone' => 'UTC', 'month_overflow' => Carbon::shouldOverflowMonths()],
            'purchase_kind' => $kind, 'open_event_id' => $openEventId,
        ];
        return $source + ['content_hash' => $this->catalog->hash($source)];
    }

    public function capture(Order $order, Plan $plan, bool $manualAssignment = false): OrderPurchaseTerms
    {
        if (DB::transactionLevel() === 0 || $order->purchase_terms_version !== 'tx-core.purchase-terms/v1') {
            throw new RuntimeException('ORDER_PURCHASE_TERMS_TRANSACTION_REQUIRED');
        }
        $purchase = $this->build($order, $plan, (string) admin_setting('currency', 'CNY'),
            $plan->reset_traffic_method === null ? (int) admin_setting('reset_traffic_method', Plan::RESET_TRAFFIC_MONTHLY) : (int) $plan->reset_traffic_method,
            (string) config('app.timezone', 'UTC'), $this->eventFor($order), $manualAssignment);
        return OrderPurchaseTerms::query()->create([
            'order_id' => $order->id, 'user_id' => $order->user_id, 'plan_id' => $order->plan_id,
            'period' => $purchase['period'], 'order_type' => $purchase['order_type'],
            'price_minor' => $purchase['price_minor'], 'currency' => $purchase['currency'], 'currency_minor_exponent' => 2,
            'quota_bytes' => $purchase['quota_bytes'], 'group_id' => $purchase['group_id'],
            'speed_limit_mbps' => $purchase['speed_limit_mbps'], 'device_limit' => $purchase['device_limit'],
            'reset_policy' => $purchase['reset_policy'], 'duration_months' => $purchase['duration_months'],
            'purchase_kind' => $purchase['purchase_kind'], 'source_snapshot' => $purchase,
            'content_hash' => $purchase['content_hash'], 'created_at' => time(),
        ]);
    }

    private function eventFor(Order $order): int
    {
        $setting = match ((int) $order->type) {
            Order::TYPE_NEW_PURCHASE => 'new_order_event_id',
            Order::TYPE_RENEWAL => 'renew_order_event_id',
            Order::TYPE_UPGRADE => 'change_order_event_id',
            Order::TYPE_RESET_TRAFFIC => null,
            default => throw new InvalidArgumentException('ORDER_PURCHASE_TERMS_TYPE_INVALID'),
        };
        $value = $setting === null ? 0 : admin_setting($setting, 0);
        if (!in_array($value, [0, 1, '0', '1'], true)) throw new InvalidArgumentException('ORDER_PURCHASE_TERMS_EVENT_INVALID');
        return (int) $value;
    }

    private function optionalLimit(mixed $value): ?int
    {
        if ($value === null) return null;
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^(0|[1-9][0-9]*)$/D', (string) $value)
            || (string) (int) $value !== (string) $value || (int) $value > 4294967295) {
            throw new InvalidArgumentException('ORDER_PURCHASE_TERMS_LIMIT_INVALID');
        }
        return (int) $value;
    }

    private function quotaBytes(mixed $value): string
    {
        if (!is_int($value) && !is_string($value) || !preg_match('/^(0|[1-9][0-9]*)$/D', (string) $value)) throw new InvalidArgumentException('ORDER_PURCHASE_TERMS_QUOTA_INVALID');
        $limit = (string) intdiv(PHP_INT_MAX, 1073741824);
        if (strlen((string) $value) > strlen($limit) || (strlen((string) $value) === strlen($limit) && strcmp((string) $value, $limit) > 0)) throw new InvalidArgumentException('ORDER_PURCHASE_TERMS_QUOTA_OUT_OF_RANGE');
        return (string) ((int) $value * 1073741824);
    }
}
