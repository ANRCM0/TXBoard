<?php

namespace App\Domains\Billing\Contracts;

interface OrderQueries
{
    public function listing(int $userId, OrderListQuery $query): OrderPage;

    public function find(int $userId, string $tradeNo): ?array;

    public function detail(int $userId, string $tradeNo): ?array;

    public function events(int $userId, string $tradeNo, string $afterSequence, int $limit): ?OrderPage;
}
