<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Domains\Billing\BillingError;
use App\Domains\Billing\NativeAccountAdjustments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NativeAccountAdjustmentController
{
    public function balances(Request $request, NativeAccountAdjustments $adjustments): JsonResponse
    {
        $input = $request->validate(['currency' => ['required', 'string', 'regex:/\A[A-Z]{3}\z/D']]);
        try { return $this->success($request, $adjustments->balances((int) $request->user()->id, (int) $request->route('id'), $input['currency'])); }
        catch (BillingError $error) { return $this->failure($request, $error); }
    }

    public function history(Request $request, NativeAccountAdjustments $adjustments): JsonResponse
    {
        $input = $request->validate(['currency' => ['required', 'string', 'regex:/\A[A-Z]{3}\z/D'],
            'before' => ['sometimes', 'required', 'string', 'regex:/\A[1-9][0-9]{0,18}\z/D'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        try { return $this->success($request, $adjustments->history((int) $request->user()->id, (int) $request->route('id'), $input['currency'], $input['before'] ?? null, (int) ($input['limit'] ?? 25))); }
        catch (BillingError $error) { return $this->failure($request, $error); }
    }

    public function create(Request $request, NativeAccountAdjustments $adjustments): JsonResponse
    {
        $fields = ['currency', 'bucket', 'direction', 'amount_minor', 'expected_ledger_revision', 'reason', 'note'];
        if (array_diff(array_keys($request->all()), $fields)) return TxapiResponse::error($request, 'BILLING_ADJUSTMENT_INPUT_INVALID', 'Unsupported adjustment fields', 422);
        $input = $request->validate(['currency' => ['required', 'string', 'regex:/\A[A-Z]{3}\z/D'],
            'bucket' => ['required', 'in:wallet_available,commission_available'], 'direction' => ['required', 'in:credit,debit'],
            'amount_minor' => ['required', 'string', 'regex:/\A[1-9][0-9]{0,18}\z/D'], 'expected_ledger_revision' => ['required', 'string', 'regex:/\A[0-9a-f]{64}\z/D'],
            'reason' => ['required', 'string', 'regex:/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}\z/D'], 'note' => ['sometimes', 'nullable', 'string', 'max:500']]);
        try { return $this->success($request, $adjustments->create((int) $request->user()->id, (int) $request->route('id'), $input, (string) $request->header('Idempotency-Key'))); }
        catch (BillingError $error) { return $this->failure($request, $error); }
    }

    private function success(Request $request, array $value): JsonResponse
    {
        return TxapiResponse::success($request, $value)->header('Cache-Control', 'private, no-store');
    }

    private function failure(Request $request, BillingError $error): JsonResponse
    {
        $code = $error->errorCode;
        $status = in_array($code, ['BILLING_ADJUSTMENT_INPUT_INVALID', 'BILLING_AMOUNT_INVALID', 'BILLING_CURRENCY_INVALID', 'BILLING_ADJUSTMENT_COMMISSION_CURRENCY_UNSUPPORTED'], true) ? 422
            : ($code === 'BILLING_ADJUSTMENT_TARGET_PROTECTED' ? 403 : (in_array($code, ['BILLING_ADJUSTMENT_CAPABILITY_UNAVAILABLE', 'BILLING_ADJUSTMENT_HISTORY_LIMIT'], true) ? 503 : 409));
        return TxapiResponse::error($request, $code, 'Account adjustment refused', $status)->header('Cache-Control', 'private, no-store');
    }
}
