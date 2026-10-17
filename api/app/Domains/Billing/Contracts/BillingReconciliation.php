<?php

namespace App\Domains\Billing\Contracts;

interface BillingReconciliation
{
    public function inspect(int $userId, int $limit = 50, int $afterId = 0): array;
}
