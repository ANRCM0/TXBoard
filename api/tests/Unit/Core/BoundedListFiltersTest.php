<?php

namespace Tests\Unit\Core;

use App\Domains\Billing\Contracts\AdminOrderListQuery;
use App\Domains\Identity\Contracts\AdminAccountListQuery;
use App\Domains\Identity\AdminAccountFilterFields;
use App\Domains\Billing\AdminOrderFilterFields;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BoundedListFiltersTest extends TestCase
{
    public function test_unknown_columns_sql_operators_invalid_types_and_unbounded_rules_are_rejected(): void
    {
        foreach ([
            [['field' => 'password', 'operator' => 'eq', 'value' => 'x']],
            [['field' => 'email', 'operator' => 'raw', 'value' => '1=1']],
            [['field' => 'banned', 'operator' => 'gt', 'value' => '0']],
            [['field' => 'banned', 'operator' => 'eq', 'value' => 'true']],
            [['field' => 'id', 'operator' => 'eq', 'value' => '1e3']],
            [['field' => 'id', 'operator' => 'eq', 'value' => '9223372036854775808']],
            array_fill(0, 11, ['field' => 'id', 'operator' => 'eq', 'value' => '1']),
        ] as $filters) {
            try { AdminAccountFilterFields::validate(new AdminAccountListQuery(filters: $filters)); self::fail('Unsafe filter accepted'); }
            catch (InvalidArgumentException) { self::assertTrue(true); }
        }
        $this->expectException(InvalidArgumentException::class);
        AdminAccountFilterFields::validate(new AdminAccountListQuery(sortBy: 'password'));
    }

    public function test_order_amount_filters_and_sort_require_an_explicit_currency(): void
    {
        $query = new AdminOrderListQuery(filters: [['field' => 'currency', 'operator' => 'eq', 'value' => 'USD'], ['field' => 'amount_minor', 'operator' => 'gt', 'value' => '9007199254740993']], sortBy: 'amount_minor');
        AdminOrderFilterFields::validate($query);
        self::assertSame('9007199254740993', $query->filters[1]['value']);
        $this->expectException(InvalidArgumentException::class);
        AdminOrderFilterFields::validate(new AdminOrderListQuery(sortBy: 'amount_minor'));
    }
}
