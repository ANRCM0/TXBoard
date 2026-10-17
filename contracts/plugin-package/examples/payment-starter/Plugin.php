<?php

namespace Plugin\ExamplePay;

use App\Contracts\NativePaymentPluginInterface;
use App\Contracts\NativePaymentProviderInterface;
use App\Services\Plugin\AbstractPlugin;

final class Plugin extends AbstractPlugin implements NativePaymentPluginInterface
{
    public function nativePaymentPackageVersion(): string
    {
        return '0.1.0';
    }

    public function form(): array
    {
        return [];
    }

    public function nativePaymentProvider(): NativePaymentProviderInterface
    {
        require_once __DIR__.'/NativeAdapter.php';
        return new NativeAdapter;
    }
}
