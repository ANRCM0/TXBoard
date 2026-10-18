<?php

namespace App\Domains\Billing\Contracts;

/** Host-only native-order reads; never expands a user's order-query scope. */
interface AdminOrderQueries
{
    public function adminListing(AdminOrderListQuery $query): OrderPage;

    public function adminFind(string $tradeNo): ?array;
}
