<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Domains\Billing\BillingError;
use App\Domains\Billing\NativeCustomerPolicies;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NativeCustomerPolicyController
{
    public function snapshot(Request $request, NativeCustomerPolicies $policies): JsonResponse
    {
        return $this->respond($request, fn () => $policies->read((int) $request->user()->id, (int) $request->route('id')));
    }
    public function history(Request $request, NativeCustomerPolicies $policies): JsonResponse
    {
        $input = $request->validate(['before' => ['sometimes', 'required', 'string', 'regex:/\A[1-9][0-9]{0,18}\z/D'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        return $this->respond($request, fn () => $policies->history((int) $request->user()->id, (int) $request->route('id'), $input['before'] ?? null, (int) ($input['limit'] ?? 25)));
    }
    public function update(Request $request, NativeCustomerPolicies $policies): JsonResponse
    {
        if (array_diff(array_keys($request->all()), ['expected_policy_revision', 'changes', 'reason'])) return TxapiResponse::error($request, 'CUSTOMER_POLICY_INPUT_INVALID', 'Unsupported policy fields', 422);
        $input = $request->validate(['expected_policy_revision' => ['required', 'string', 'regex:/\A[0-9a-f]{64}\z/D'],
            'changes' => ['required', 'array:discount_bps,commission_mode,commission_rate_bps', 'min:1'], 'reason' => ['required', 'string', 'regex:/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}\z/D']]);
        return $this->respond($request, fn () => $policies->update((int) $request->user()->id, (int) $request->route('id'), $input, (string) $request->header('Idempotency-Key')));
    }
    private function respond(Request $request, \Closure $operation): JsonResponse
    {
        try { return TxapiResponse::success($request, $operation())->header('Cache-Control', 'private, no-store'); }
        catch (BillingError $error) {
            $code = $error->errorCode;
            $status = in_array($code, ['CUSTOMER_POLICY_INPUT_INVALID', 'CUSTOMER_POLICY_NO_CHANGE'], true) ? 422
                : ($code === 'CUSTOMER_POLICY_TARGET_PROTECTED' ? 403 : ($code === 'CUSTOMER_POLICY_ACCOUNT_MISSING' ? 404 : (str_ends_with($code, '_UNAVAILABLE') ? 503 : 409)));
            return TxapiResponse::error($request, $code, 'Customer policy refused', $status)->header('Cache-Control', 'private, no-store');
        }
    }
}
