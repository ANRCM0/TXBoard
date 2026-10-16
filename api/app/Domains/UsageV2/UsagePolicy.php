<?php

namespace App\Domains\UsageV2;

use App\Domains\Installation\InstallationAuthority;
use Illuminate\Support\Facades\DB;

final class UsagePolicy
{
    public function native(): bool
    {
        return app(InstallationAuthority::class)->inspect()['mode'] === 'native';
    }

    public function enabled(): bool
    {
        return $this->native()
            || (app()->environment(['local','testing']) && config('core_usage_v2.isolated_copy') === true);
    }

    public function requireIsolated(): void
    {
        if (!$this->enabled()) { throw new UsageError('USAGE_V2_ISOLATED_ONLY', 404); }
        if ($this->native()) {
            DB::transaction(fn () => app(InstallationAuthority::class)->assertNativeRead());
        }
        if (!preg_match('/^[A-Za-z0-9:_-]{8,80}$/D', (string) config('core_usage_v2.core_instance_id'))) {
            throw new UsageError('USAGE_V2_INSTANCE_NOT_CONFIGURED', 503);
        }
        UsagePayload::decimal((string) config('core_usage_v2.receipt_generation'), false);
        if (!extension_loaded('bcmath')) { throw new UsageError('USAGE_V2_INTEGER_ARITHMETIC_UNAVAILABLE', 503); }
    }

    /** Recheck admission while holding the installation lock for the full write. */
    public function requireTransaction(): ?array
    {
        return $this->native() ? app(InstallationAuthority::class)->assertNativeMutation() : null;
    }

    public function assertSubscription(object $subscription): void
    {
        $installation = $this->requireTransaction();
        if ($installation !== null && (($subscription->authority_version ?? null) !== 'core-subscription/v1'
            || ($subscription->installation_id ?? null) !== $installation['installation_id'])) {
            throw new UsageError('USAGE_NATIVE_OWNERSHIP');
        }
    }
}
