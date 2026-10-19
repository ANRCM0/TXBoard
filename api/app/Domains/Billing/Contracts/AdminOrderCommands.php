<?php

namespace App\Domains\Billing\Contracts;

/** Administrator commands preserve the original purchase and fulfillment identity. */
interface AdminOrderCommands
{
    public function execute(int $actorId, string $action, array $input, string $key): array;

    public function audit(int $actorId, string $tradeNo, string $afterId, int $limit): ?OrderPage;
}
