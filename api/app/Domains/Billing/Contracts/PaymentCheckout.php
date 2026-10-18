<?php

namespace App\Domains\Billing\Contracts;

interface PaymentCheckout
{
    public function start(int $userId, int $orderId, int $paymentId, string $requestKey, ?string $walletMinor = null): array;
    public function recharge(int $userId, int $rechargeId, int $paymentId, string $requestKey): array;
    public function status(int $userId, int $attemptId): array;
    public function query(int $userId, int $attemptId): array;
    public function recover(int $userId, int $attemptId): array;
    public function recognizes(string $method, string $uuid, array $params): bool;
    public function notify(string $method, string $uuid, array $params): ?array;
}
