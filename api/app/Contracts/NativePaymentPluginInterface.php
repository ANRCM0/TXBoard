<?php

namespace App\Contracts;

/** The only current payment plugin entry point; Core owns execution and financial facts. */
interface NativePaymentPluginInterface
{
    public const CONTRACT = 'native-payment-provider/1';

    public function nativePaymentPackageVersion(): string;
    public function form(): array;
    public function nativePaymentProvider(): NativePaymentProviderInterface;
}
