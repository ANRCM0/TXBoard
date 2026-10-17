<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Domains\Billing\BillingError;
use App\Domains\Billing\NativeBillingReconciliation;
use App\Domains\Billing\NativeRefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class NativeBillingAdminController
{
    public function refund(Request $request, NativeRefundService $refunds): JsonResponse
    {
        if (array_diff(array_keys($request->all()), ['user_id', 'source_type', 'source_id', 'amount_minor', 'reason'])) {
            return TxapiResponse::error($request, 'BILLING_REQUEST_INVALID', 'Unsupported refund fields', 422);
        }
        $input = $request->validate([
            'user_id' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
            'source_type' => ['required', 'in:receipt,wallet_payment'],
            'source_id' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
            'amount_minor' => ['required', 'string', 'regex:/\A[1-9][0-9]{0,18}\z/D'],
            'reason' => ['required', 'string', 'regex:/\A[A-Za-z0-9_.:-]{1,64}\z/D'],
        ]);
        try {
            $result = $refunds->refundToWallet((int) $input['user_id'], $input['source_type'], (int) $input['source_id'],
                $input['amount_minor'], (string) $request->header('Idempotency-Key'),
                (int) Auth::guard('sanctum')->id(), $input['reason']);
        } catch (BillingError $error) {
            return TxapiResponse::error($request, $error->errorCode, 'Refund refused', 409)->header('Cache-Control', 'no-store');
        }
        return TxapiResponse::success($request, $result)->header('Cache-Control', 'private, no-store');
    }

    public function reconcile(Request $request, NativeBillingReconciliation $reconciliation): JsonResponse
    {
        if (array_diff(array_keys($request->all()), ['user_id', 'limit', 'after_id'])) {
            return TxapiResponse::error($request, 'BILLING_REQUEST_INVALID', 'Unsupported reconciliation fields', 422);
        }
        $input = $request->validate(['user_id' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'after_id' => ['sometimes', 'integer', 'min:0', 'max:'.PHP_INT_MAX]]);
        try {
            $result = $reconciliation->inspect((int) $input['user_id'], (int) ($input['limit'] ?? 100), (int) ($input['after_id'] ?? 0));
        } catch (BillingError $error) {
            return TxapiResponse::error($request, $error->errorCode, 'Reconciliation unavailable', 409)->header('Cache-Control', 'no-store');
        }
        return TxapiResponse::success($request, $result)->header('Cache-Control', 'private, no-store');
    }
}
