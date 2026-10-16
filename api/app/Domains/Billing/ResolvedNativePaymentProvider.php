<?php

namespace App\Domains\Billing;

use App\Contracts\NativePaymentProviderInterface;

final class ResolvedNativePaymentProvider
{
    public function __construct(
        public readonly string $pluginCode,
        public readonly string $pluginVersion,
        public readonly string $contractVersion,
        public readonly string $providerId,
        public readonly string $snapshotSchema,
        public readonly NativePaymentProviderInterface $adapter,
    ) {}

    public function binding(): array
    {
        return ['plugin_code' => $this->pluginCode, 'plugin_version' => $this->pluginVersion,
            'contract_version' => $this->contractVersion, 'provider' => $this->providerId,
            'provider_snapshot_schema' => $this->snapshotSchema];
    }
}
