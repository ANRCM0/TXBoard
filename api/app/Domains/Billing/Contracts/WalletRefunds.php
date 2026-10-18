<?php

namespace App\Domains\Billing\Contracts;

interface WalletRefunds
{
    public function refundToWallet(int $userId, string $sourceType, int $sourceId, string $amountMinor,
        string $requestKey, int $actorId, string $reason): array;
}
