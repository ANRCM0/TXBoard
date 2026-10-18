<?php

namespace App\Domains\Subscription;

use App\Domains\Subscription\Internal\LockedAccountAccess;
use App\Domains\Subscription\Services\NativeSubscriptionStore;
use App\Domains\Subscription\Services\NativeCredentialSynchronization;
use Illuminate\Support\Facades\DB;

final class NativeAccountAccess implements LockedAccountAccess
{
    public function __construct(private readonly NativeSubscriptionStore $store, private readonly NativeCredentialSynchronization $synchronization) {}

    public function lock(array $installation, int $userId, int $now): array
    {
        if (DB::transactionLevel() < 1) throw new \DomainException('NATIVE_ACCOUNT_TRANSACTION_REQUIRED');
        return $this->store->lockAccount($userId, $now, $installation['installation_id']);
    }

    public function stage(int $userId, iterable $subscriptions): void
    {
        if (DB::transactionLevel() < 1) throw new \DomainException('NATIVE_ACCOUNT_TRANSACTION_REQUIRED');
        foreach ($subscriptions as $subscription) $this->synchronization->stageLocked($userId, (int) $subscription->id);
    }
}
