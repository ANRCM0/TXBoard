<?php

namespace App\Domains\Billing\Contracts;

interface OrderPaymentQueries
{
    public function attempts(int $actorId, string $tradeNo, ?string $beforeId = null, int $limit = 20): ?OrderPage;
}
