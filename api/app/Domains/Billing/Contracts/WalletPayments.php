<?php

namespace App\Domains\Billing\Contracts;

interface WalletPayments
{
    public function balance(int $userId): array;
    public function pay(int $userId, int $orderId, string $requestKey): array;
}
