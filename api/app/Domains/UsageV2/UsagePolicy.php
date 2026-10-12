<?php

namespace App\Domains\UsageV2;

final class UsagePolicy
{
    public function enabled(): bool
    {
        return app()->environment(['local','testing']) && config('core_usage_v2.isolated_copy') === true;
    }

    public function requireIsolated(): void
    {
        if (!$this->enabled()) { throw new UsageError('USAGE_V2_ISOLATED_ONLY', 404); }
        if (!preg_match('/^[A-Za-z0-9:_-]{8,80}$/D', (string) config('core_usage_v2.core_instance_id'))) {
            throw new UsageError('USAGE_V2_INSTANCE_NOT_CONFIGURED', 503);
        }
        UsagePayload::decimal((string) config('core_usage_v2.receipt_generation'), false);
        if (!extension_loaded('bcmath')) { throw new UsageError('USAGE_V2_INTEGER_ARITHMETIC_UNAVAILABLE', 503); }
    }
}
