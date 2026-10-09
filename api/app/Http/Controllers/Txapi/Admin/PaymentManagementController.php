<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Utils\Helper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * TXAPI-owned payment method management. Gateway plugins remain authoritative
 * for form schemas and payment execution; no provider-specific logic lives here.
 * Credentials are intentionally returned ONLY on the admin-secured read route.
 */
final class PaymentManagementController
{
    public function index(Request $request): JsonResponse
    {
        $methods = Payment::query()->orderBy('sort')->orderBy('id')->get();

        return TxapiResponse::success($request, $methods->map(
            static function (Payment $method): array {
                $path = config('billing.native_webhook_enabled', false)
                    ? "/txapi/payment/webhook/{$method->payment}/{$method->uuid}"
                    : "/api/v1/guest/payment/notify/{$method->payment}/{$method->uuid}";
                $notifyUrl = url($path);
                if ($method->notify_domain) {
                    $notifyUrl = rtrim((string) $method->notify_domain, '/') . $path;
                }

                return [
                    'id' => (int) $method->id,
                    'name' => (string) $method->name,
                    'icon' => $method->icon,
                    'payment' => (string) $method->payment,
                    'config' => $method->config ?? [],
                    'notify_domain' => $method->notify_domain,
                    'handling_fee_fixed' => $method->handling_fee_fixed,
                    'handling_fee_percent' => $method->handling_fee_percent,
                    'enable' => (bool) $method->enable,
                    'sort' => (int) $method->sort,
                    'uuid' => (string) $method->uuid,
                    'notify_url' => $notifyUrl,
                    'created_at' => $method->created_at,
                    'updated_at' => $method->updated_at,
                ];
            }
        )->all());
    }

    public function providers(Request $request): JsonResponse
    {
        return TxapiResponse::success($request, array_values(array_unique(
            PaymentService::getAllPaymentMethodNames()
        )));
    }

    public function form(Request $request): JsonResponse
    {
        $params = $request->validate([
            'payment' => ['required', 'string', 'max:120'],
            'id' => ['sometimes', 'integer', 'min:1'],
        ]);

        if (isset($params['id'])) {
            $saved = Payment::query()->findOrFail((int) $params['id']);
            if ($saved->payment !== $params['payment']) {
                throw ValidationException::withMessages(['payment' => 'Payment provider cannot be changed']);
            }
        }

        try {
            $service = new PaymentService($params['payment'], $params['id'] ?? null);
            return TxapiResponse::success($request, $service->form());
        } catch (\Throwable $e) {
            // Missing/disabled gateway modules should not expose plugin internals.
            return TxapiResponse::error($request, 'PAYMENT_PROVIDER_UNAVAILABLE',
                'Payment provider is not available', 422);
        }
    }

    public function save(Request $request): JsonResponse
    {
        if (!admin_setting('app_url')) {
            return TxapiResponse::error($request, 'APP_URL_REQUIRED',
                'Configure the site URL before adding payment methods', 422);
        }

        $params = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:2048'],
            'payment' => ['required', 'string', 'max:120'],
            'config' => ['required', 'array', 'max:200'],
            'notify_domain' => ['nullable', 'url:http,https', 'max:2048'],
            'handling_fee_fixed' => ['nullable', 'integer', 'min:0'],
            'handling_fee_percent' => ['nullable', 'numeric', 'between:0,100'],
        ]);

        $routeId = $request->route('id');
        $new = $routeId === null;
        $id = DB::transaction(static function () use ($params, $routeId): int {
            if ($routeId === null) {
                $payment = Payment::query()->create($params + ['uuid' => Helper::randomChar(8)]);
                return (int) $payment->id;
            }

            $payment = Payment::query()->lockForUpdate()->findOrFail((int) $routeId);
            if ($payment->payment !== $params['payment']) {
                throw ValidationException::withMessages([
                    'payment' => 'Changing the provider of an existing payment method is not allowed',
                ]);
            }
            $payment->fill($params)->saveOrFail();
            return (int) $payment->id;
        });

        return TxapiResponse::success($request, ['id' => $id], status: $new ? 201 : 200);
    }

    public function toggle(Request $request): JsonResponse
    {
        $enabled = DB::transaction(static function () use ($request): bool {
            $payment = Payment::query()->lockForUpdate()->findOrFail((int) $request->route('id'));
            $payment->enable = !$payment->enable;
            $payment->saveOrFail();
            return (bool) $payment->enable;
        });
        return TxapiResponse::success($request, ['enable' => $enabled]);
    }

    public function sort(Request $request): JsonResponse
    {
        $ids = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:1000'],
            'ids.*' => ['required', 'integer', 'distinct', 'min:1'],
        ])['ids'];

        DB::transaction(static function () use ($ids): void {
            $found = Payment::query()->whereIn('id', $ids)
                ->lockForUpdate()->pluck('id')->all();
            if (count($found) !== count($ids)) {
                throw ValidationException::withMessages(['ids' => 'Unknown payment method']);
            }
            foreach ($ids as $position => $id) {
                Payment::query()->whereKey((int) $id)->update(['sort' => $position + 1]);
            }
        });

        return TxapiResponse::success($request, ['ok' => true]);
    }
}
