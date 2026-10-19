<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Domains\Billing\BillingError;
use App\Domains\Billing\NativePaymentProviderRegistry;
use App\Domains\Billing\Contracts\PaymentMethods;
use App\Domains\CredentialProvider\ProviderCodec;
use App\Domains\Installation\InstallationAuthority;
use App\Models\Payment;
use App\Models\Plugin;
use App\Services\Plugin\PluginManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class NativePaymentConfigurationController
{
    public function index(Request $request, PaymentMethods $methods): JsonResponse
    {
        $available = array_column($methods->available(), 'id');
        $page = Payment::query()->useWritePdo()->orderBy('sort')->orderBy('id')
            ->paginate((int) $request->input('per_page', 20), ['*'], 'page', (int) $request->input('page', 1));
        $items = $page->getCollection()->map(fn (Payment $row): array => [
            'id' => (string) $row->id, 'name' => $row->name, 'provider' => $row->payment,
            'icon' => $row->icon, 'enabled' => (bool) $row->enable,
            'available' => in_array((string) $row->id, $available, true), 'version' => $this->version($row),
            'sort' => (int) ($row->sort ?? 0), 'fee_fixed_minor' => (string) ($row->handling_fee_fixed ?? 0),
            'fee_percent' => (string) ($row->handling_fee_percent ?? 0),
        ])->all();
        return TxapiResponse::success($request, $items, ['page' => $page->currentPage(), 'per_page' => $page->perPage(),
            'total' => $page->total(), 'last_page' => $page->lastPage()]);
    }

    public function providers(Request $request, NativePaymentProviderRegistry $providers): JsonResponse
    {
        $items = [];
        foreach ($providers->availableMethods() as $provider => $resolved) {
            $capabilities = $resolved->adapter->capabilities();
            $items[] = ['provider' => (string) $provider, 'plugin_code' => $resolved->pluginCode,
                'version' => $resolved->pluginVersion, 'currencies' => $capabilities['currencies'],
                'external_refund' => $capabilities['external_refund']];
        }
        return TxapiResponse::success($request, $items);
    }

    public function form(Request $request): JsonResponse
    {
        $provider = $request->input('provider');
        $row = $request->has('payment_id') ? Payment::query()->useWritePdo()->find($request->input('payment_id')) : null;
        if ($request->has('payment_id') && ($row === null || $row->payment !== $provider)) abort(404);
        $form = $this->definition($provider);
        $configuration = $row?->config ?? [];
        $fields = [];
        foreach ($form as $name => $field) {
            $fields[] = ['name' => $name, 'label' => mb_substr((string) ($field['label'] ?? $name), 0, 255),
                'kind' => in_array($name, ['url', 'pid', 'type'], true) ? 'text' : 'password',
                'value' => null, 'configured' => isset($configuration[$name]) && $configuration[$name] !== ''];
        }
        return TxapiResponse::success($request, ['provider' => $provider, 'fields' => $fields]);
    }

    public function save(Request $request, NativePaymentProviderRegistry $providers): JsonResponse
    {
        if (DB::transactionLevel() === 0) throw new \LogicException('ADMIN_TRANSACTION_REQUIRED');
        $provider = $request->input('provider');
        $resolved = $providers->forMethod($provider);
        Plugin::query()->where('code', $resolved->pluginCode)->lockForUpdate()->first();
        $resolved = $providers->forMethod($provider);
        $form = $this->definition($provider);
        $existing = $request->has('payment_id');
        $row = $existing ? Payment::query()->lockForUpdate()->find($request->input('payment_id')) : new Payment;
        if ($row === null) abort(404);
        if ($existing && ($row->payment !== $provider || !$request->has('expected_version')
            || !hash_equals($this->version($row), $request->input('expected_version')))
            || !$existing && $request->has('expected_version')) {
            return TxapiResponse::error($request, 'PAYMENT_CONFIGURATION_CONFLICT', 'Read the current payment method again', 409);
        }
        $configuration = $row->config ?? [];
        $seen = [];
        foreach ($request->input('configuration') as $field) {
            if (!isset($form[$field['name']]) || isset($seen[$field['name']])) {
                return TxapiResponse::error($request, 'PAYMENT_CONFIGURATION_INVALID', 'Unsupported configuration field', 422);
            }
            $seen[$field['name']] = true;
            $configuration[$field['name']] = $field['value'];
        }
        foreach ($form as $name => $field) {
            if (($field['required'] ?? false) && (!isset($configuration[$name]) || $configuration[$name] === '')) {
                return TxapiResponse::error($request, 'PAYMENT_CONFIGURATION_INVALID', 'Required configuration is missing', 422);
            }
        }
        $row->fill(['name' => $request->input('name'), 'payment' => $provider, 'icon' => $request->input('icon'),
            'enable' => $request->boolean('enabled'), 'sort' => $request->integer('sort'), 'config' => $configuration]);
        if (!$existing) $row->fill(['uuid' => Str::random(32), 'handling_fee_fixed' => 0, 'handling_fee_percent' => 0]);
        $candidate = clone $row;
        $candidate->enable = true;
        $resolved->adapter->accountRef($candidate);
        $row->saveOrFail();
        return TxapiResponse::success($request, ['id' => (string) $row->id]);
    }

    private function definition(string $provider): array
    {
        $resolved = app(NativePaymentProviderRegistry::class)->forMethod($provider);
        $form = app(PluginManager::class)->nativePaymentProvider($resolved->pluginCode)['plugin']->form();
        if (count($form) > 32) throw new BillingError('PAYMENT_CONFIGURATION_UNSUPPORTED');
        foreach ($form as $name => $field) {
            if (!is_string($name) || !preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,63}\z/D', $name)
                || ($field['type'] ?? null) !== 'string') throw new BillingError('PAYMENT_CONFIGURATION_UNSUPPORTED');
        }
        return $form;
    }

    private function version(Payment $row): string
    {
        return hash_hmac('sha256', ProviderCodec::canonical($row->only([
            'id', 'name', 'payment', 'icon', 'enable', 'sort', 'config', 'uuid', 'notify_domain',
            'handling_fee_fixed', 'handling_fee_percent', 'updated_at',
        ])), (string) config('app.key'));
    }
}
