<?php

namespace Tests\Unit\Billing;

use App\Domains\Billing\Contracts\OrderListQuery;
use App\Domains\Billing\Contracts\OrderPage;
use App\Domains\Billing\NativeOrderQueries;
use InvalidArgumentException;
use Tests\TestCase;

final class OrderReadContractsTest extends TestCase
{
    public function test_read_contract_values_are_immutable_data_with_protocol_independent_page_bounds(): void
    {
        $this->assertSame(['page' => 1, 'perPage' => 20, 'status' => null], get_object_vars(new OrderListQuery));
        $this->assertSame(['page' => 100001, 'perPage' => 100, 'status' => 0],
            get_object_vars(new OrderListQuery(100001, 100, 0)));
        $this->assertSame(4, (new OrderListQuery(1, 1, 4))->status);
        $page = new OrderPage([['trade_no' => 'order']], ['page' => 1, 'per_page' => 20, 'total' => 1, 'last_page' => 1]);
        $this->assertSame([['trade_no' => 'order']], $page->items);
        $this->assertSame(1, $page->meta['total']);
        foreach ([OrderListQuery::class, OrderPage::class] as $class) {
            $reflection = new \ReflectionClass($class);
            $this->assertTrue($reflection->isReadOnly());
            foreach ($reflection->getProperties() as $property) {
                $this->assertTrue($property->getType()->isBuiltin());
            }
        }
        try {
            $page->items = [];
            $this->fail('Read result must be immutable.');
        } catch (\Error $error) {
            $this->assertStringContainsString('readonly', $error->getMessage());
        }
    }

    public function test_invalid_list_values_are_rejected_without_a_query_or_framework_context(): void
    {
        foreach ([[0, 20, null], [-1, 20, null], [1, 0, null], [1, 101, null], [1, 20, -1], [1, 20, 5]] as $values) {
            try {
                new OrderListQuery(...$values);
                $this->fail('Invalid order list input was accepted.');
            } catch (InvalidArgumentException $error) {
                $this->assertSame('ORDER_LIST_INPUT_INVALID', $error->getMessage());
            }
        }
    }

    public function test_nonpositive_owner_is_rejected_by_every_native_query_before_database_access(): void
    {
        $queries = new NativeOrderQueries;
        foreach ([0, -1] as $owner) {
            foreach ([fn () => $queries->listing($owner, new OrderListQuery),
                fn () => $queries->find($owner, 'order'), fn () => $queries->detail($owner, 'order')] as $query) {
                try {
                    $query();
                    $this->fail('Nonpositive order owner was accepted.');
                } catch (InvalidArgumentException $error) {
                    $this->assertSame('ORDER_READ_ACCOUNT_INVALID', $error->getMessage());
                }
            }
        }
    }
}
