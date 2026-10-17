<?php

namespace App\Domains\Billing;

use App\Contracts\NativePaymentPluginInterface;
use App\Models\Plugin;
use App\Services\Plugin\PluginManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Closed installed-plugin resolution. No request can select a PHP class, package path or provider URL. */
final class NativePaymentProviderRegistry
{
    public const CONTRACT = NativePaymentPluginInterface::CONTRACT;
    public const SNAPSHOT = 'native-payment-dispatch/1';

    public function __construct(private readonly PluginManager $plugins) {}

    public function forPayment(object $payment, bool $lock = false): ResolvedNativePaymentProvider
    {
        $this->require((bool) ($payment->enable ?? false), 'PAYMENT_PROVIDER_UNAVAILABLE');
        $method = MoneyValue::key($payment->payment ?? null, 32);
        $resolved = $this->forMethod($method);
        if ($lock) {
            $this->require(DB::transactionLevel() > 0, 'PAYMENT_TRANSACTION_REQUIRED');
            $row = Plugin::query()->where('code', $resolved->pluginCode)->lockForUpdate()->first();
            $this->require($row !== null && $row->is_enabled && $row->native_payment_mutation_token === null
                && (string) $row->version === $resolved->pluginVersion, 'PAYMENT_PROVIDER_UNAVAILABLE');
            $current = $this->resolve($resolved->pluginCode, false);
            $this->require($current->binding() === $resolved->binding(), 'PAYMENT_PROVIDER_BINDING_INVALID');
        }
        return $resolved;
    }

    /** Management uses the same validated registry as checkout, without booting plugin hooks. */
    public function forMethod(string $method): ResolvedNativePaymentProvider
    {
        MoneyValue::key($method, 32);
        $matches = $this->registeredProviders()[$method] ?? [];
        $this->require(count($matches) === 1, $matches === []
            ? 'NATIVE_PAYMENT_PROVIDER_UNSUPPORTED' : 'PAYMENT_PROVIDER_AMBIGUOUS');
        return $matches[0];
    }

    /** Ambiguous or invalid providers are never advertised as available channels. */
    public function availableMethods(): array
    {
        $methods = [];
        foreach ($this->registeredProviders() as $method => $providers) {
            if (count($providers) === 1) { $methods[$method] = $providers[0]; }
        }
        return $methods;
    }

    private function registeredProviders(): array
    {
        $rows = Plugin::query()->where('type', Plugin::TYPE_PAYMENT)->where('is_enabled', true)
            ->orderBy('code')->limit(65)->get(['code']);
        $this->require($rows->count() <= 64, 'PAYMENT_PROVIDER_REGISTRY_LIMIT');
        $matches = [];
        $bundled = [];
        foreach ($rows as $row) {
            try {
                $resolved = $this->resolve($row->code, false);
                $capabilities = $resolved->adapter->capabilities();
                $this->require($capabilities['checkout'] && $capabilities['verify_notification'] && $capabilities['query'],
                    'PAYMENT_PROVIDER_CAPABILITY_UNSUPPORTED');
                $matches[$resolved->providerId][] = $resolved;
                if ($this->plugins->isCorePlugin($row->code)) { $bundled[$resolved->providerId][] = $resolved; }
            } catch (BillingError $error) {
                Log::warning('Native payment plugin is unavailable', [
                    'plugin' => $row->code, 'code' => $error->errorCode,
                ]);
            }
        }
        return array_replace($matches, $bundled);
    }

    /** Recovery never boots the plugin or consults the current payment registration's merchant config. */
    public function forFrozen(array $snapshot, string $operation): ResolvedNativePaymentProvider
    {
        $this->require(in_array($operation, ['checkout', 'verify_notification', 'query'], true),
            'PAYMENT_PROVIDER_OPERATION_UNSUPPORTED');
        $this->require(($snapshot['schema'] ?? null) === self::SNAPSHOT, 'NATIVE_PAYMENT_DISPATCH_EVIDENCE_INVALID');
        $resolved = $this->resolve(MoneyValue::key($snapshot['plugin_code'] ?? null, 64), $operation !== 'checkout');
        foreach ($resolved->binding() as $field => $value) {
            $this->require(($snapshot[$field] ?? null) === $value, 'PAYMENT_PROVIDER_VERSION_UNAVAILABLE');
        }
        $this->require($resolved->adapter->capabilities()[$operation], 'PAYMENT_PROVIDER_CAPABILITY_UNSUPPORTED');
        if ($operation === 'checkout') {
            $payment = DB::table('tx_payment')->where('id', $snapshot['payment_id'])->first();
            $this->require($payment !== null && (bool) $payment->enable
                && $payment->payment === $snapshot['provider'], 'PAYMENT_PROVIDER_UNAVAILABLE');
        }
        return $resolved;
    }

    public function freeze(ResolvedNativePaymentProvider $provider, object $payment, array $attempt): array
    {
        $this->require($provider->providerId === $attempt['provider'], 'PAYMENT_PROVIDER_BINDING_INVALID');
        $capabilities = $provider->adapter->capabilities();
        $this->require(in_array($attempt['currency'], $capabilities['currencies'], true),
            'PAYMENT_PROVIDER_CURRENCY_UNSUPPORTED');
        $snapshot = $provider->adapter->freeze($payment, $attempt);
        $this->require(($snapshot['schema'] ?? null) === $provider->snapshotSchema, 'PAYMENT_PROVIDER_BINDING_INVALID');
        foreach (['installation_id', 'user_id', 'intent_type', 'recharge_id', 'order_id', 'payment_id',
            'attempt_id', 'provider', 'provider_account_ref', 'order_trade_no', 'amount_minor', 'currency'] as $field) {
            $this->require(array_key_exists($field, $snapshot) && $snapshot[$field] === $attempt[$field],
                'PAYMENT_PROVIDER_BINDING_INVALID');
        }
        $expectedReference = 'tx'.substr(hash('sha256', $attempt['installation_id']."\n".$attempt['attempt_id']), 0, 34);
        $this->require(($snapshot['provider_order_ref'] ?? null) === $expectedReference
            && ($snapshot['callback_uuid'] ?? null) === (string) $payment->uuid
            && ($snapshot['currency_minor_exponent'] ?? null) === $capabilities['currency_minor_exponent'],
            'PAYMENT_PROVIDER_BINDING_INVALID');
        $snapshot['schema'] = self::SNAPSHOT;
        foreach ($provider->binding() as $field => $value) { $snapshot[$field] = $value; }
        return $snapshot;
    }

    /** A typed plugin result still has to satisfy every Core binding; old notify arrays cannot enter. */
    public function verifiedFact(array $snapshot, array $fact): array
    {
        $keys = array_keys($fact);
        sort($keys, SORT_STRING);
        $this->require($keys === ['amount_minor', 'attempt_id', 'currency', 'occurred_at', 'provider',
            'provider_account_ref', 'transaction_ref'], 'PAYMENT_RECEIPT_FACT_INVALID');
        foreach (['attempt_id', 'provider', 'provider_account_ref', 'amount_minor', 'currency'] as $field) {
            $this->require($fact[$field] === $snapshot[$field], 'PAYMENT_RECEIPT_BINDING_CONFLICT');
        }
        MoneyValue::minor($fact['amount_minor'], true);
        MoneyValue::currency($fact['currency']);
        MoneyValue::key($fact['transaction_ref']);
        if ($fact['occurred_at'] !== null) { MoneyValue::minor($fact['occurred_at'], true); }
        return $fact;
    }

    /** Protocol reference parsing is the only plugin call allowed before finding an original dispatch. */
    public function locateNotification(array $params): ?object
    {
        $bindings = DB::table('tx_payment_dispatch')->useWritePdo()
            ->select(['plugin_code', 'plugin_version', 'contract_version', 'provider'])->distinct()->limit(65)->get();
        $this->require($bindings->count() <= 64, 'PAYMENT_PROVIDER_REGISTRY_LIMIT');
        foreach ($bindings as $binding) {
            try {
                $provider = $this->resolve($binding->plugin_code, true);
                $this->require($provider->pluginVersion === $binding->plugin_version
                    && $provider->contractVersion === $binding->contract_version
                    && $provider->providerId === $binding->provider, 'PAYMENT_PROVIDER_VERSION_UNAVAILABLE');
            } catch (BillingError $error) {
                Log::warning('Native payment recovery plugin is unavailable', [
                    'plugin' => $binding->plugin_code, 'code' => $error->errorCode,
                ]);
                continue;
            }
            $reference = $provider->adapter->notificationReference($params);
            if ($reference === null) { continue; }
            $this->require(is_string($reference) && preg_match('/\Atx[a-f0-9]{34}\z/D', $reference) === 1,
                'PAYMENT_PROVIDER_REFERENCE_INVALID');
            $dispatch = DB::table('tx_payment_dispatch')->useWritePdo()->where('plugin_code', $binding->plugin_code)
                ->where('provider_order_ref', $reference)->first();
            if ($dispatch !== null) { return $dispatch; }
        }
        return null;
    }

    private function resolve(string $pluginCode, bool $recovery): ResolvedNativePaymentProvider
    {
        try {
            $registration = $this->plugins->nativePaymentProvider($pluginCode, $recovery);
            $adapter = $registration['adapter'];
            $identity = $adapter->identity();
            $this->require(count($identity) === 3 && ($identity['contract_version'] ?? null) === self::CONTRACT
                && is_string($identity['snapshot_schema'] ?? null) && strlen($identity['snapshot_schema']) <= 64
                && preg_match('~\A[a-z0-9][a-z0-9._-]*/[1-9][0-9]*\z~D', $identity['snapshot_schema']) === 1,
                'PAYMENT_PROVIDER_CONTRACT_INVALID');
            $providerId = MoneyValue::key($identity['provider_id'] ?? null, 32);
            $capabilities = $adapter->capabilities();
            foreach (['checkout', 'verify_notification', 'query', 'external_refund', 'query_refund'] as $field) {
                $this->require(is_bool($capabilities[$field] ?? null), 'PAYMENT_PROVIDER_CONTRACT_INVALID');
            }
            $this->require(!$capabilities['external_refund'] && !$capabilities['query_refund'],
                'PAYMENT_PROVIDER_CAPABILITY_UNSUPPORTED');
            $this->require(count($capabilities) === 7 && is_array($capabilities['currencies'] ?? null)
                && array_is_list($capabilities['currencies']) && count($capabilities['currencies']) >= 1
                && count($capabilities['currencies']) <= 10 && ($capabilities['currency_minor_exponent'] ?? null) === '2',
                'PAYMENT_PROVIDER_CONTRACT_INVALID');
            foreach ($capabilities['currencies'] as $currency) { MoneyValue::currency($currency); }
            return new ResolvedNativePaymentProvider($pluginCode, $registration['version'],
                self::CONTRACT, $providerId, $identity['snapshot_schema'], $adapter);
        } catch (BillingError $error) {
            throw $error;
        } catch (Throwable) {
            throw new BillingError('PAYMENT_PROVIDER_UNAVAILABLE');
        }
    }

    private function require(bool $condition, string $code): void
    {
        if (!$condition) { throw new BillingError($code); }
    }
}
