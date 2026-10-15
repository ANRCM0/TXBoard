<?php

namespace Tests\Unit\Domains\Billing;

use App\Domains\Billing\PurchasedOrderGrant;
use App\Models\Order;
use App\Models\OrderPurchaseTerms;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PurchasedOrderGrantTest extends TestCase
{
    public function test_resolves_canonical_snapshot_and_builds_plan_without_reading_mutable_catalog(): void
    {
        [$order, $terms, $snapshot] = $this->fixture();
        $resolver = new PurchasedOrderGrant;
        self::assertSame($snapshot, $resolver->resolve($order, $terms));
        $plan = $resolver->plan($order, $terms);
        self::assertSame(9, $plan->id);
        self::assertSame('Purchased Gold', $plan->name);
        self::assertSame(2, $plan->transfer_enable);
        self::assertSame(7, $plan->group_id);
        self::assertSame(30, $plan->speed_limit);
        self::assertSame(4, $plan->device_limit);
        self::assertSame(1, $plan->reset_traffic_method);
        self::assertFalse($plan->exists);
    }

    public function test_only_unmarked_orders_without_evidence_use_the_legacy_path(): void
    {
        [$order] = $this->fixture();
        $order->purchase_terms_version = null;
        self::assertNull((new PurchasedOrderGrant)->resolve($order, null));
        self::assertNull((new PurchasedOrderGrant)->plan($order, null));
    }

    public function test_required_evidence_missing_fails_instead_of_legacy_fallback(): void
    {
        [$order] = $this->fixture();
        $this->expectException(RuntimeException::class);
        (new PurchasedOrderGrant)->resolve($order, null);
    }

    public function test_record_without_matching_order_version_is_refused(): void
    {
        [$order, $terms] = $this->fixture();
        $order->purchase_terms_version = null;
        $this->expectException(RuntimeException::class);
        (new PurchasedOrderGrant)->resolve($order, $terms);
    }

    #[DataProvider('orderBindingChanges')]
    public function test_bound_order_mutations_are_rejected(string $field, mixed $value): void
    {
        [$order, $terms] = $this->fixture();
        $order->{$field} = $value;
        $this->expectException(RuntimeException::class);
        (new PurchasedOrderGrant)->resolve($order, $terms);
    }

    public static function orderBindingChanges(): array
    {
        return [['id', 45], ['user_id', 4], ['plan_id', 10], ['period', 'yearly'],
            ['type', Order::TYPE_RENEWAL], ['total_amount', 876]];
    }

    public function test_corrupt_hash_and_column_drift_fail_without_consulting_plan(): void
    {
        [$order, $terms] = $this->fixture();
        $terms->quota_bytes = '3221225472';
        $this->expectException(RuntimeException::class);
        (new PurchasedOrderGrant)->resolve($order, $terms);
    }

    public function test_changed_source_with_old_hash_is_refused(): void
    {
        [$order, $terms, $snapshot] = $this->fixture();
        $snapshot['device_limit'] = 400;
        $terms->source_snapshot = $snapshot;
        $this->expectException(RuntimeException::class);
        (new PurchasedOrderGrant)->resolve($order, $terms);
    }

    public function test_recomputed_hash_cannot_make_unknown_schema_fields_valid(): void
    {
        [$order, $terms, $snapshot] = $this->fixture();
        $snapshot['provider_secret'] = 'unrecognized';
        $snapshot = $this->hash($snapshot);
        $terms->source_snapshot = $snapshot;
        $terms->content_hash = $snapshot['content_hash'];
        $this->expectException(RuntimeException::class);
        (new PurchasedOrderGrant)->resolve($order, $terms);
    }

    public function test_recursive_key_order_does_not_change_hash_interpretation(): void
    {
        [$order, $terms, $snapshot] = $this->fixture();
        $snapshot = array_reverse($snapshot, true);
        $snapshot['reset_policy'] = array_reverse($snapshot['reset_policy'], true);
        $snapshot['calendar_policy'] = array_reverse($snapshot['calendar_policy'], true);
        $terms->source_snapshot = $snapshot;
        self::assertSame('2147483648', (new PurchasedOrderGrant)->resolve($order, $terms)['quota_bytes']);
    }

    private function fixture(): array
    {
        $order = new Order(['user_id' => 3, 'plan_id' => 9, 'period' => 'monthly',
            'type' => Order::TYPE_NEW_PURCHASE, 'total_amount' => 875,
            'purchase_terms_version' => 'tx-core.purchase-terms/v1']);
        $order->id = 44;
        $snapshot = $this->hash([
            'schema' => 'tx-core.purchase-terms/v1', 'order_id' => '44', 'user_id' => '3',
            'plan_id' => '9', 'plan_name' => 'Purchased Gold', 'period' => 'monthly',
            'order_type' => Order::TYPE_NEW_PURCHASE, 'quote_source' => 'catalog', 'list_price_minor' => '1000', 'price_minor' => '875',
            'currency' => 'CNY', 'currency_minor_exponent' => 2, 'quota_bytes' => '2147483648',
            'group_id' => 7, 'speed_limit_mbps' => 30, 'device_limit' => 4,
            'reset_policy' => ['revision' => 'purchased-reset/v1', 'method' => 1, 'timezone' => 'Asia/Shanghai'],
            'duration_months' => 1, 'calendar_policy' => ['timezone' => 'UTC', 'month_overflow' => true],
            'purchase_kind' => 'subscription', 'open_event_id' => 0,
        ]);
        $terms = new OrderPurchaseTerms([
            'order_id' => 44, 'user_id' => 3, 'plan_id' => 9, 'period' => 'monthly',
            'order_type' => Order::TYPE_NEW_PURCHASE, 'price_minor' => '875', 'currency' => 'CNY',
            'currency_minor_exponent' => 2, 'quota_bytes' => '2147483648', 'group_id' => 7,
            'speed_limit_mbps' => 30, 'device_limit' => 4, 'reset_policy' => $snapshot['reset_policy'],
            'duration_months' => 1, 'purchase_kind' => 'subscription',
            'source_snapshot' => $snapshot, 'content_hash' => $snapshot['content_hash'],
        ]);
        return [$order, $terms, $snapshot];
    }

    private function hash(array $source): array
    {
        unset($source['content_hash']);
        $normalize = static function (mixed $value) use (&$normalize): mixed {
            if (!is_array($value)) return $value;
            if (!array_is_list($value)) ksort($value, SORT_STRING);
            return array_map($normalize, $value);
        };
        return $source + ['content_hash' => hash('sha256', json_encode($normalize($source), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))];
    }
}
