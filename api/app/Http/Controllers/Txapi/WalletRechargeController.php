<?php

namespace App\Http\Controllers\Txapi;

use App\Core\Http\TxapiResponse;
use App\Domains\Billing\WalletRechargeService;
use App\Exceptions\ApiException;
use App\Models\WalletRecharge;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

final class WalletRechargeController
{
    public function methods(Request $request): JsonResponse
    {
        $methods = Payment::query()->where('enable', true)
            ->whereIn('payment', WalletRechargeService::VERIFIED_RECHARGE_PROVIDERS)
            ->orderBy('sort')->orderBy('id')
            ->get(['id','name','payment','icon','handling_fee_fixed','handling_fee_percent'])
            ->map(static fn (Payment $method): array => [
                'id' => (int) $method->id,
                'name' => (string) $method->name,
                'payment' => (string) $method->payment,
                'icon' => $method->icon,
                'handling_fee_fixed' => (int) ($method->handling_fee_fixed ?? 0),
                'handling_fee_percent' => (float) ($method->handling_fee_percent ?? 0),
            ])->all();
        return TxapiResponse::success($request, $methods);
    }

    public function store(Request $request, WalletRechargeService $service): JsonResponse
    {
        $params = $request->validate([
            'amount_minor' => ['required', 'integer',
                'min:' . WalletRechargeService::MIN_AMOUNT_MINOR,
                'max:' . WalletRechargeService::MAX_AMOUNT_MINOR],
            'payment_method_id' => ['required', 'integer', 'min:1'],
        ]);
        $key = (string) $request->header('Idempotency-Key', '');
        if (!\Illuminate\Support\Str::isUuid($key)) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'A UUID Idempotency-Key header is required',
            ]);
        }
        try {
            $recharge = $service->create(
                (int) Auth::guard('sanctum')->id(),
                (int) $params['amount_minor'], (int) $params['payment_method_id'], $key);
        } catch (ApiException $e) {
            $status = in_array((int) $e->getCode(), [409, 422], true)
                ? (int) $e->getCode() : 409;
            return TxapiResponse::error($request, 'RECHARGE_REJECTED',
                'Recharge could not be created', $status);
        }
        return TxapiResponse::success($request, self::dto($recharge), status: 201);
    }

    public function index(Request $request): JsonResponse
    {
        $params = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
        $page = WalletRecharge::query()
            ->where('user_id', Auth::guard('sanctum')->id())
            ->orderByDesc('id')->paginate((int) ($params['per_page'] ?? 20),
                ['id', 'trade_no', 'payment_id', 'amount_minor', 'fee_minor',
                    'status', 'paid_at', 'created_at'], 'page', (int) ($params['page'] ?? 1));
        return TxapiResponse::success($request,
            $page->getCollection()->map(self::dto(...))->all(),
            ['page' => $page->currentPage(), 'per_page' => $page->perPage(),
                'total' => $page->total(), 'last_page' => $page->lastPage()]);
    }

    public function show(Request $request, string $tradeNo): JsonResponse
    {
        $recharge = WalletRecharge::query()
            ->where('user_id', Auth::guard('sanctum')->id())
            ->where('trade_no', $tradeNo)->firstOrFail();
        return TxapiResponse::success($request, self::dto($recharge));
    }

    public function checkout(Request $request, string $tradeNo,
        WalletRechargeService $service): JsonResponse
    {
        $params = $request->validate([
            'token' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ]);
        try {
            $result = $service->checkout((int) Auth::guard('sanctum')->id(),
                $tradeNo, $params['token'] ?? null);
        } catch (ApiException $e) {
            $code = (int) $e->getCode();
            $status = in_array($code, [404, 409, 422, 502], true) ? $code : 409;
            return TxapiResponse::error($request, 'RECHARGE_CHECKOUT_FAILED',
                'Recharge checkout cannot be completed', $status);
        }
        return TxapiResponse::success($request, $result);
    }

    private static function dto(WalletRecharge $recharge): array
    {
        return [
            'trade_no' => (string) $recharge->trade_no,
            'payment_method_id' => (int) $recharge->payment_id,
            'amount_minor' => (int) $recharge->amount_minor,
            'fee_minor' => (int) $recharge->fee_minor,
            'total_minor' => (int) $recharge->amount_minor + (int) $recharge->fee_minor,
            'status' => (int) $recharge->status,
            'created_at' => (int) $recharge->created_at,
            'paid_at' => $recharge->paid_at === null ? null : (int) $recharge->paid_at,
        ];
    }
}
