<?php

namespace Tests\Support;

use App\Core\Kernel\Clock;
use App\Domains\Subscription\Contracts\EntitlementQuery;
use App\Domains\Subscription\Services\SubscriptionEntitlementResolver;

trait ReadsEntitlements
{
    protected function readEntitlement(int $userId, ?int $at = null): array
    {
        $clock = new class($at ?? time()) implements Clock
        {
            public function __construct(private readonly int $at) {}

            public function now(): int
            {
                return $this->at;
            }
        };
        $resolver = app()->makeWith(SubscriptionEntitlementResolver::class, ['clock' => $clock]);

        return $resolver->forAccount(new EntitlementQuery($userId))->toArray();
    }
}
