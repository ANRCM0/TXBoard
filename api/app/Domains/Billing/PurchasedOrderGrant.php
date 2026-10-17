<?php

namespace App\Domains\Billing;

use App\Models\Order;
use App\Models\OrderPurchaseTerms;
use App\Models\Plan;
use App\Services\OrderService;
use App\Domains\Subscription\Services\PlanPublicationService;
use DateTimeZone;
use JsonException;
use RuntimeException;

/** Read-time validation of the immutable grant; never reconstructs damaged evidence from Plan. */
final class PurchasedOrderGrant
{
    /** @return array<string,mixed>|null Null means an explicitly legacy order without purchase evidence. */
    public function resolve(Order $order, ?OrderPurchaseTerms $terms): ?array
    {
        if ($terms === null && $order->purchase_terms_version === null) {
            return null;
        }
        $this->require($terms !== null && in_array($order->purchase_terms_version,
            ['tx-core.purchase-terms/v1', 'tx-core.purchase-terms/v2'], true));
        $published = $order->purchase_terms_version === 'tx-core.purchase-terms/v2';
        try {
            $snapshot = $terms->source_snapshot;
            $this->require(is_array($snapshot) && !array_is_list($snapshot));
            $expectedKeys = ['schema', 'order_id', 'user_id', 'plan_id', 'plan_name', 'period',
                'order_type', 'quote_source', 'list_price_minor', 'price_minor', 'currency', 'currency_minor_exponent',
                'quota_bytes', 'group_id', 'speed_limit_mbps', 'device_limit', 'reset_policy',
                'duration_months', 'calendar_policy', 'purchase_kind', 'open_event_id', 'content_hash'];
            $publishedKeys = [...$expectedKeys, 'catalog'];
            if ($published && array_key_exists('upgrade_credit', $snapshot)) { $publishedKeys[] = 'upgrade_credit'; }
            $this->keys($snapshot, $published ? $publishedKeys : $expectedKeys);
            $hash = $snapshot['content_hash'] ?? null;
            $this->require(is_string($hash) && preg_match('/^[a-f0-9]{64}$/D', $hash) === 1);
            unset($snapshot['content_hash']);
            $this->require(hash_equals($hash, (string) $terms->content_hash)
                && hash_equals($hash, hash('sha256', $this->canonical($snapshot))));
            $this->require(($snapshot['schema'] ?? null) === $order->purchase_terms_version);
            foreach (['order_id', 'user_id', 'plan_id'] as $field) {
                $identity = $field === 'order_id' ? $order->id : $order->{$field};
                $this->require($this->integer($snapshot[$field] ?? null, true) === $this->integer($identity, true)
                    && $this->integer($this->raw($terms, $field), true) === $this->integer($identity, true));
            }
            $this->require(is_string($snapshot['period'] ?? null)
                && array_key_exists($snapshot['period'], Plan::getAvailablePeriods())
                && $snapshot['period'] === (string) $order->period
                && $snapshot['period'] === (string) $terms->period);
            $type = $this->integer($snapshot['order_type'] ?? null, true);
            $this->require(in_array((int) $type, [Order::TYPE_NEW_PURCHASE, Order::TYPE_RENEWAL,
                Order::TYPE_UPGRADE, Order::TYPE_RESET_TRAFFIC], true)
                && $type === $this->integer($order->type, true)
                && $type === $this->integer($this->raw($terms, 'order_type'), true));
            $price = $this->integer($snapshot['price_minor'] ?? null);
            $this->require($price === $this->integer($order->total_amount)
                && $price === $this->integer($this->raw($terms, 'price_minor')));
            $this->require(in_array($snapshot['quote_source'], ['catalog', 'manual_assignment'], true));
            if ($snapshot['quote_source'] === 'manual_assignment') {
                $this->require($snapshot['list_price_minor'] === null);
            } else {
                $this->integer($snapshot['list_price_minor']);
            }
            $this->require(is_string($snapshot['currency'] ?? null)
                && in_array($snapshot['currency'], ['USD', 'CNY', 'EUR', 'GBP', 'HKD', 'SGD', 'CAD', 'AUD', 'NZD', 'TWD'], true)
                && $snapshot['currency'] === (string) $terms->currency
                && $this->integer($this->raw($terms, 'currency_minor_exponent')) === '2');
            $this->require($this->integer($snapshot['currency_minor_exponent']) === '2');
            $this->require(is_string($snapshot['plan_name'] ?? null));
            foreach (['quota_bytes', 'group_id', 'speed_limit_mbps', 'device_limit', 'duration_months'] as $field) {
                $this->require(array_key_exists($field, $snapshot));
                $this->require($this->nullableInteger($snapshot[$field]) === $this->nullableInteger($this->raw($terms, $field)));
            }
            if ($snapshot['quota_bytes'] !== null) {
                // The existing Plan grant API expresses quota as integral GiB. Refuse truncation.
                $this->require((int) $this->integer($snapshot['quota_bytes']) % 1073741824 === 0);
            }
            $kind = match ($snapshot['period']) {
                Plan::PERIOD_ONETIME => 'perpetual',
                Plan::PERIOD_RESET_TRAFFIC => 'traffic_reset',
                default => 'subscription',
            };
            $months = OrderService::STR_TO_TIME[$snapshot['period']] ?? null;
            $this->require(($snapshot['purchase_kind'] ?? null) === $kind
                && $snapshot['purchase_kind'] === (string) $terms->purchase_kind
                && $this->nullableInteger($snapshot['duration_months']) === $this->nullableInteger($months));
            $policy = $snapshot['reset_policy'] ?? null;
            $this->require(is_array($policy) && ($policy['revision'] ?? null) === 'purchased-reset/v1'
                && is_int($policy['method'] ?? null) && $policy['method'] >= 0 && $policy['method'] <= 4
                && is_string($policy['timezone'] ?? null)
                && in_array($policy['timezone'], DateTimeZone::listIdentifiers(), true)
                && $this->canonical($policy) === $this->canonical($terms->reset_policy));
            $this->keys($policy, ['revision', 'method', 'timezone']);
            $calendar = $snapshot['calendar_policy'];
            $this->require(is_array($calendar));
            $this->keys($calendar, ['timezone', 'month_overflow']);
            $this->require($calendar['timezone'] === 'UTC' && is_bool($calendar['month_overflow']));
            $this->require(in_array($this->integer($snapshot['open_event_id']), ['0', '1'], true));
            $snapshot['content_hash'] = $hash;
            if ($published) {
                $this->verifyPublished($order, $snapshot);
            }
            return $snapshot;
        } catch (JsonException|\TypeError|\ValueError $error) {
            throw new RuntimeException('ORDER_PURCHASE_TERMS_INVALID', previous: $error);
        }
    }

    private function verifyPublished(Order $order, array $snapshot): void
    {
        foreach (['balance_amount', 'surplus_amount', 'surplus_credit', 'discount_amount', 'commission_balance',
            'actual_commission_balance', 'commission_rate', 'commission_status', 'commission_auto_check',
            'coupon_id', 'invite_user_id'] as $field) {
            $value = $order->getAttribute($field);
            $this->require($value === null || $value === 0 || $value === '0');
        }
        $this->require($order->surplus_order_ids === null || $order->surplus_order_ids === []);
        $binding = $snapshot['catalog'];
        $this->require(is_array($binding));
        $this->keys($binding, ['revision_id', 'offer_id', 'publication_hash']);
        $this->require(is_string($binding['revision_id']) && is_string($binding['offer_id'])
            && is_string($binding['publication_hash'])
            && preg_match('/^[a-f0-9]{64}$/D', $binding['publication_hash']) === 1);
        $this->integer($binding['revision_id'], true);
        $offerId = (int) $this->integer($binding['offer_id'], true);
        try {
            // Publication evidence is immutable. Do not acquire current Plan/availability locks
            // while fulfillment holds the subscription aggregate and purchased terms.
            $publication = app(PlanPublicationService::class)->verifiedOffer($offerId);
            $expected = app(OrderPurchaseTermsSnapshot::class)->buildPublished($order, $publication);
            $this->require($this->canonical($expected) === $this->canonical($snapshot));
        } catch (\InvalidArgumentException|RuntimeException $error) {
            throw new RuntimeException('ORDER_PURCHASE_TERMS_INVALID', previous: $error);
        }
    }

    /** Build an independent frozen Plan view for the existing grant/reset implementations. */
    public function plan(Order $order, ?OrderPurchaseTerms $terms): ?Plan
    {
        $grant = $this->resolve($order, $terms);
        if ($grant === null) {
            return null;
        }
        $plan = new Plan([
            'name' => $grant['plan_name'], 'group_id' => $grant['group_id'],
            'transfer_enable' => $grant['quota_bytes'] === null ? null : intdiv((int) $grant['quota_bytes'], 1073741824),
            'speed_limit' => $grant['speed_limit_mbps'], 'device_limit' => $grant['device_limit'],
            'reset_traffic_method' => $grant['reset_policy']['method'],
        ]);
        $plan->id = (int) $grant['plan_id'];
        return $plan;
    }

    /** Reject both omitted and unknown fields before interpreting a versioned snapshot. */
    private function keys(array $value, array $expected): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        $this->require($actual === $expected);
    }

    private function raw(OrderPurchaseTerms $terms, string $field): mixed
    {
        $attributes = $terms->getAttributes();
        $this->require(array_key_exists($field, $attributes));
        return $attributes[$field];
    }

    private function nullableInteger(mixed $value): ?string
    {
        return $value === null ? null : $this->integer($value);
    }

    private function integer(mixed $value, bool $positive = false): string
    {
        $this->require((is_int($value) || is_string($value))
            && preg_match('/^(0|[1-9][0-9]*)$/D', (string) $value) === 1);
        $value = (string) $value;
        $maximum = (string) PHP_INT_MAX;
        $this->require(strlen($value) < strlen($maximum)
            || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) <= 0));
        $this->require(!$positive || $value !== '0');
        return $value;
    }

    private function canonical(mixed $value): string
    {
        $normalize = static function (mixed $item) use (&$normalize): mixed {
            if (!is_array($item)) {
                return $item;
            }
            if (!array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
            return array_map($normalize, $item);
        };
        return json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function require(bool $condition): void
    {
        if (!$condition) {
            throw new RuntimeException('ORDER_PURCHASE_TERMS_INVALID');
        }
    }
}
