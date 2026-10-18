<?php

namespace App\Domains\Billing\Contracts;

interface Recharges
{
    public function create(int $userId, string $amountMinor, int $paymentId, string $requestKey): array;
    public function checkout(int $userId, string $tradeNo): array;
    public function show(int $userId, string $tradeNo): array;
    public function listing(int $userId, int $limit = 20, int $afterId = 0): array;
}
