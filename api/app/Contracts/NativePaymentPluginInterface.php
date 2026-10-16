<?php

namespace App\Contracts;

/** An installed payment plugin must opt in explicitly; legacy notify arrays are not native facts. */
interface NativePaymentPluginInterface
{
    public function nativePaymentPackageVersion(): string;
    public function nativePaymentProvider(): NativePaymentProviderInterface;
}
