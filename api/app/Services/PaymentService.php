<?php

namespace App\Services;

use App\Domains\Billing\NativePaymentProviderRegistry;
use App\Exceptions\ApiException;
use App\Models\Payment;
use App\Services\Plugin\PluginManager;

/** Management facade only. Payment execution requires Core's frozen native dispatch. */
final class PaymentService
{
    private array $config = [];
    private ?string $pluginCode = null;

    public function __construct(public string $method, ?int $id = null, ?string $uuid = null)
    {
        if ($method === 'temp') { return; }
        if ($id !== null || $uuid !== null) {
            $payment = $id !== null ? Payment::query()->find($id) : Payment::query()->where('uuid', $uuid)->first();
            if ($payment === null || $payment->payment !== $method) {
                throw new ApiException('payment not found');
            }
            $this->config = is_array($payment->config) ? $payment->config : [];
        }
        try {
            $this->pluginCode = app(NativePaymentProviderRegistry::class)->forMethod($method)->pluginCode;
        } catch (\Throwable) {
            throw new ApiException('payment method not found or disabled');
        }
    }

    public function notify($params): never
    {
        throw new ApiException('Payment execution requires a native frozen dispatch', 409);
    }

    public function pay($order): never
    {
        throw new ApiException('Payment execution requires a native frozen dispatch', 409);
    }

    public function form(): array
    {
        if ($this->pluginCode === null) {
            throw new ApiException('payment method not found or disabled');
        }
        $registration = app(PluginManager::class)->nativePaymentProvider($this->pluginCode);
        $result = [];
        foreach ($registration['plugin']->form() as $key => $field) {
            $result[$key] = [
                'type' => $field['type'],
                'label' => $field['label'] ?? '',
                'placeholder' => $field['placeholder'] ?? '',
                'description' => $field['description'] ?? '',
                'value' => $this->config[$key] ?? $field['default'] ?? '',
                'options' => $field['select_options'] ?? $field['options'] ?? [],
            ];
        }
        return $result;
    }

    public function getAvailablePaymentMethods(): array
    {
        $methods = [];
        foreach (app(NativePaymentProviderRegistry::class)->availableMethods() as $method => $provider) {
            $methods[$method] = ['name' => (string) $method, 'plugin_code' => $provider->pluginCode, 'type' => 'plugin'];
        }
        return $methods;
    }

    public static function getAllPaymentMethodNames(): array
    {
        return array_map('strval', array_keys(app(NativePaymentProviderRegistry::class)->availableMethods()));
    }
}
