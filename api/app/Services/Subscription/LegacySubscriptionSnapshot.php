<?php

namespace App\Services\Subscription;

use App\Models\Plan;
use App\Models\User;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/** Non-secret migration evidence. Never copies UUIDs, tokens or passwords. */
final class LegacySubscriptionSnapshot
{
    public const VERSION = 'subscription-shadow/v1';

    public function context(string $timezone, string $currency): array
    {
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)
            || !preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new InvalidArgumentException('INVALID_BILLING_CONTEXT');
        }

        return ['mapping_version' => self::VERSION, 'billing_timezone' => $timezone, 'currency' => $currency];
    }

    public function capture(User $user, array $context): array
    {
        $source = [];
        foreach (['id', 'plan_id', 'group_id', 'transfer_enable', 'u', 'd', 'speed_limit',
            'device_limit', 'expired_at', 'last_reset_at', 'next_reset_at', 'reset_count',
            'balance', 'commission_balance'] as $field) {
            $source[$field] = $this->decimal($user->getRawOriginal($field), true);
        }
        $source['banned'] = (bool) $user->banned;
        foreach (['transfer_enable', 'u', 'd', 'balance', 'commission_balance', 'reset_count'] as $field) {
            if ($source[$field] === null || str_starts_with($source[$field], '-')) {
                throw new RuntimeException('INVALID_LEGACY_'.strtoupper($field));
            }
        }
        foreach (['speed_limit', 'device_limit', 'last_reset_at', 'next_reset_at', 'expired_at'] as $field) {
            if ($source[$field] !== null && str_starts_with($source[$field], '-')) {
                throw new RuntimeException('INVALID_LEGACY_'.strtoupper($field));
            }
        }
        if ((int) $source['u'] > PHP_INT_MAX - (int) $source['d']) {
            throw new RuntimeException('LEGACY_TRAFFIC_OVERFLOW');
        }
        if ($source['last_reset_at'] !== null && $source['next_reset_at'] !== null
            && (int) $source['next_reset_at'] <= (int) $source['last_reset_at']) {
            throw new RuntimeException('INVALID_LEGACY_RESET_BOUNDARY');
        }

        $plan = $source['plan_id'] === null ? null : Plan::query()->sharedLock()->find($source['plan_id']);
        if ($source['plan_id'] !== null && !$plan) {
            throw new RuntimeException('LEGACY_PLAN_MISSING');
        }
        $reset = $plan?->reset_traffic_method;
        if ($reset === null) {
            $reset = admin_setting('reset_traffic_method', Plan::RESET_TRAFFIC_MONTHLY);
        }
        $source['reset_policy'] = [
            'revision' => 'legacy-current/v1',
            'method' => (int) $reset,
            'plan_method' => $plan?->reset_traffic_method,
            'boundary' => $source['last_reset_at'] === null ? 'legacy_boundary_unknown' : 'known',
        ];
        if (!in_array($source['reset_policy']['method'], [0, 1, 2, 3, 4], true)) {
            throw new RuntimeException('INVALID_LEGACY_RESET_POLICY');
        }

        // Stream all existing orders without constructing new historical contracts.
        $digest = hash_init('sha256');
        $orderCount = 0;
        $fields = ['id', 'user_id', 'plan_id', 'payment_id', 'period', 'type', 'status',
            'total_amount', 'handling_amount', 'discount_amount', 'balance_amount',
            'surplus_amount', 'surplus_credit', 'surplus_order_ids', 'paid_at'];
        foreach (DB::table('tx_order')->where('user_id', $user->id)->orderBy('id')->select($fields)->cursor() as $order) {
            $row = [];
            foreach ($fields as $field) {
                $value = $order->$field;
                $row[$field] = $value === null ? null : (string) $value;
            }
            hash_update($digest, $this->json($row)."\n");
            $orderCount++;
        }
        $source['order_count'] = (string) $orderCount;
        $source['order_hash'] = hash_final($digest);
        $source['context'] = $context;

        return $source;
    }

    public function hash(array $snapshot): string
    {
        return hash('sha256', $this->json($snapshot));
    }

    public function status(array $snapshot, int $at): string
    {
        if ($snapshot['plan_id'] === null) {
            return 'pending';
        }
        if ($snapshot['expired_at'] !== null && (int) $snapshot['expired_at'] <= $at) {
            return 'expired';
        }

        return $snapshot['banned'] ? 'suspended' : 'active';
    }

    public function decimal(mixed $value, bool $nullable = false): ?string
    {
        if ($value === null && $nullable) {
            return null;
        }
        $value = (string) $value;
        if (!preg_match('/^(0|[1-9][0-9]*|-[1-9][0-9]*)$/D', $value)
            || (string) (int) $value !== $value) {
            throw new RuntimeException('LEGACY_INTEGER_OUT_OF_RANGE');
        }

        return $value;
    }

    private function json(array $value): string
    {
        // MySQL JSON may reorder object keys. Hash semantic content, not storage formatting.
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (!is_array($item)) {
                return $item;
            }
            if (!array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
            return array_map($normalize, $item);
        };
        $value = $normalize($value);
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
