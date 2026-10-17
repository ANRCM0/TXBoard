<?php

namespace App\Domains\Subscription\Contracts;

/** Owner transactions recheck installation, account, evidence and expected versions. */
interface SubscriptionLifecycle
{
    public function suspend(int $userId, int $subscriptionId, string $businessKey, int|string $expectedVersion,
        ?int $now = null, string $reason = 'manual'): array;
    public function resume(int $userId, int $subscriptionId, string $businessKey, int|string $expectedVersion, ?int $now = null): array;
    public function revoke(int $userId, int $subscriptionId, string $businessKey, int|string $expectedVersion, ?int $now = null): array;
    public function expire(int $userId, int $subscriptionId, string $businessKey, int|string $expectedVersion, ?int $now = null): array;
    public function reset(int $userId, int $subscriptionId, string $businessKey, int|string $expectedVersion,
        ?int $now = null, string $trigger = 'manual'): array;
    public function tick(int $limit = 100, ?int $now = null): array;
}
