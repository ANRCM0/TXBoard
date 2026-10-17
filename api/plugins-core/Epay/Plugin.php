<?php

namespace Plugin\Epay;

use App\Services\Plugin\AbstractPlugin;
use App\Contracts\NativePaymentPluginInterface;
use App\Contracts\NativePaymentProviderInterface;

final class Plugin extends AbstractPlugin implements NativePaymentPluginInterface
{
    public function nativePaymentPackageVersion(): string
    {
        return '1.0.0';
    }

    public function nativePaymentProvider(): NativePaymentProviderInterface
    {
        require_once __DIR__.'/NativeAdapter.php';
        return new NativeAdapter;
    }

    public function form(): array
    {
        return [
            'url' => [
                'label' => '支付网关地址',
                'type' => 'string',
                'required' => true,
                'description' => '请填写完整的 HTTPS 支付网关地址'
            ],
            'pid' => [
                'label' => '商户ID',
                'type' => 'string',
                'description' => '请填写商户ID',
                'required' => true
            ],
            'key' => [
                'label' => '通信密钥',
                'type' => 'string',
                'required' => true,
                'description' => '请填写通信密钥'
            ],
            'type' => [
                'label' => '支付类型',
                'type' => 'string',
                'description' => '支付类型，如: alipay, wxpay, qqpay 等，可自定义'
            ],
        ];
    }
}
