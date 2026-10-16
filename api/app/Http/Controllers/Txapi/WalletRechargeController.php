<?php

namespace App\Http\Controllers\Txapi;

use App\Core\Http\TxapiErrorRenderer;
use App\Core\Http\TxapiResponse;
use App\Domains\Billing\BillingError;
use App\Domains\Billing\NativePaymentProviderRegistry;
use App\Domains\Billing\NativeRechargeService;
use App\Domains\Billing\NativeWalletPayments;
use App\Domains\Installation\InstallationAuthority;
use App\Domains\Installation\InstallationAuthorityException;
use App\Models\Payment;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WalletRechargeController
{
    public function balance(Request $request, NativeWalletPayments $wallet): JsonResponse
    {
        return $this->respond($request, fn (): JsonResponse => TxapiResponse::success($request, [
            'schema' => 'native-wallet/v1', 'balances' => $wallet->balance((int) Auth::guard('sanctum')->id()),
        ]));
    }

    public function methods(Request $request): JsonResponse
    {
        return $this->respond($request, function () use ($request): JsonResponse {
            app(InstallationAuthority::class)->assertNativeRuntime();
            $methods = Payment::query()->where('enable', true)
                ->where('handling_fee_fixed', 0)->where('handling_fee_percent', 0)->orderBy('sort')->orderBy('id')
                ->limit(100)->get()
                ->filter(static function (Payment $method): bool {
                    try {
                        $provider = app(NativePaymentProviderRegistry::class)->forPayment($method);
                        $provider->adapter->accountRef($method);
                        return in_array('CNY', $provider->adapter->capabilities()['currencies'], true);
                    } catch (BillingError) { return false; }
                })
                ->map(static fn (Payment $method): array => [
                    'id' => (string) $method->id, 'name' => (string) $method->name,
                    'payment' => (string) $method->payment, 'icon' => $method->icon,
                    'currency' => 'CNY', 'handling_fee_fixed' => '0', 'handling_fee_percent' => '0',
                    'external_refund_supported' => false,
                ])->values()->all();
            return TxapiResponse::success($request, $methods);
        });
    }

    public function store(Request $request, NativeRechargeService $service): JsonResponse
    {
        return $this->respond($request, function () use ($request, $service): JsonResponse {
            $params = $request->validate([
                'amount_minor' => ['required', 'string', 'regex:/\A[1-9][0-9]{0,18}\z/D'],
                'payment_method_id' => ['required', 'integer', 'min:1'],
            ]);
            $key = (string) $request->header('Idempotency-Key', '');
            if (!Str::isUuid($key)) {
                throw ValidationException::withMessages(['idempotency_key' => 'A UUID Idempotency-Key header is required']);
            }
            $recharge = $service->create((int) Auth::guard('sanctum')->id(), $params['amount_minor'],
                (int) $params['payment_method_id'], $key);
            return TxapiResponse::success($request, $recharge, status: 201);
        });
    }

    public function index(Request $request, NativeRechargeService $service): JsonResponse
    {
        return $this->respond($request, function () use ($request, $service): JsonResponse {
            $params = $request->validate([
                'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
                'after_id' => ['sometimes', 'integer', 'min:0'],
            ]);
            $rows = $service->listing((int) Auth::guard('sanctum')->id(),
                (int) ($params['limit'] ?? 20), (int) ($params['after_id'] ?? 0));
            return TxapiResponse::success($request, $rows, [
                'next_after_id' => $rows === [] ? null : $rows[array_key_last($rows)]['recharge_id'],
            ]);
        });
    }

    public function show(Request $request, string $tradeNo, NativeRechargeService $service): JsonResponse
    {
        return $this->respond($request, fn (): JsonResponse =>
            TxapiResponse::success($request, $service->show((int) Auth::guard('sanctum')->id(), $tradeNo)));
    }

    public function checkout(Request $request, string $tradeNo, NativeRechargeService $service): JsonResponse
    {
        return $this->respond($request, function () use ($request, $tradeNo, $service): JsonResponse {
            $request->validate(['token' => ['prohibited']]);
            return TxapiResponse::success($request, $service->checkout((int) Auth::guard('sanctum')->id(), $tradeNo));
        });
    }

    private function respond(Request $request, Closure $action): JsonResponse
    {
        try {
            $response = $action();
        } catch (InstallationAuthorityException) {
            $response = TxapiResponse::error($request, 'INSTALLATION_AUTHORITY_NATIVE_NOT_READY',
                'Core installation is not ready', 503);
        } catch (BillingError $error) {
            $response = $this->rejected($request, $error);
        } catch (ValidationException $error) {
            $response = TxapiErrorRenderer::render($request, $error);
        }
        return $response->header('Cache-Control', 'private, no-store');
    }

    private function rejected(Request $request, BillingError $error): JsonResponse
    {
        $status = $error->errorCode === 'RECHARGE_MISSING' ? 404
            : (in_array($error->errorCode, ['RECHARGE_INPUT_INVALID', 'RECHARGE_FEES_UNSUPPORTED',
                'RECHARGE_PROVIDER_UNAVAILABLE', 'BILLING_AMOUNT_INVALID'], true) ? 422 : 409);
        return TxapiResponse::error($request, $error->errorCode, 'Native wallet recharge could not be completed', $status);
    }
}
