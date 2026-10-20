<?php

namespace App\Domains\Subscription\Services;

use App\Domains\Installation\InstallationAuthority;
use App\Domains\Subscription\Contracts\SubscriptionLifecycleQueries;
use Illuminate\Support\Facades\DB;

/** Current-owner-view read for administrator lifecycle presentation and recovery. */
final class NativeSubscriptionLifecycleQueries implements SubscriptionLifecycleQueries
{
    public function __construct(private NativeSubscriptionStore $store) {}

    public function current(int $userId): ?array
    {
        $installation = DB::transaction(fn (): array => app(InstallationAuthority::class)->assertNativeRead());
        return $this->store->currentLifecycle($userId, $installation['installation_id']);
    }
}
