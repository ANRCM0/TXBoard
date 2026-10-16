<?php

namespace App\Domains\UsageV2;

use App\Domains\Installation\InstallationAuthority;

final class UsagePolicy
{
    public function requireNative(): void
    {
        app(InstallationAuthority::class)->assertNativeRuntime();
        if (!preg_match('/^[A-Za-z0-9:_-]{8,80}$/D', (string) config('core_usage_v2.core_instance_id'))) {
            throw new UsageError('USAGE_V2_INSTANCE_NOT_CONFIGURED', 503);
        }
        UsagePayload::decimal((string) config('core_usage_v2.receipt_generation'), false);
        if (!extension_loaded('bcmath')) { throw new UsageError('USAGE_V2_INTEGER_ARITHMETIC_UNAVAILABLE', 503); }
    }

    /** Recheck admission while holding the installation lock for the full write. */
    public function requireTransaction(): array
    {
        return app(InstallationAuthority::class)->assertNativeMutation();
    }

    public function assertSubscription(object $subscription): void
    {
        $installation = $this->requireTransaction();
        if (($subscription->authority_version ?? null) !== 'core-subscription/v1'
            || ($subscription->installation_id ?? null) !== $installation['installation_id']) {
            throw new UsageError('USAGE_NATIVE_OWNERSHIP');
        }
    }
}
