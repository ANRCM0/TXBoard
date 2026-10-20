<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Domains\Subscription\Services\NativeSubscriptionAmendments;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NativeSubscriptionAmendmentController
{
    public function snapshot(Request $request, NativeSubscriptionAmendments $amendments): JsonResponse
    {
        try { return $this->success($request, $amendments->snapshot((int) $request->user()->id, (int) $request->route('id'))); }
        catch (DomainException $error) { return $this->failure($request, $error); }
    }

    public function history(Request $request, NativeSubscriptionAmendments $amendments): JsonResponse
    {
        $input = $request->validate(['before' => ['sometimes', 'required', 'string', 'regex:/\A[1-9][0-9]{0,18}\z/D'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        try { return $this->success($request, $amendments->history((int) $request->user()->id, (int) $request->route('id'), $input['before'] ?? null, (int) ($input['limit'] ?? 25))); }
        catch (DomainException $error) { return $this->failure($request, $error); }
    }

    public function apply(Request $request, NativeSubscriptionAmendments $amendments): JsonResponse
    {
        $fields = ['subscription_id', 'expected_subscription_version', 'expected_terms_id', 'expected_period_version', 'changes', 'reason'];
        if (array_diff(array_keys($request->all()), $fields)) return TxapiResponse::error($request, 'NATIVE_AMENDMENT_INPUT_INVALID', 'Unsupported amendment fields', 422);
        $input = $request->validate(['subscription_id' => ['required', 'string', 'regex:/\A[1-9][0-9]{0,18}\z/D'],
            'expected_subscription_version' => ['required', 'string', 'regex:/\A[1-9][0-9]{0,18}\z/D'],
            'expected_terms_id' => ['required', 'string', 'regex:/\A[1-9][0-9]{0,18}\z/D'],
            'expected_period_version' => ['required', 'string', 'regex:/\A[1-9][0-9]{0,18}\z/D'],
            'changes' => ['required', 'array:plan_revision,quota_bytes,expires_at,speed_limit_mbps,device_limit', 'min:1'],
            'reason' => ['required', 'string', 'regex:/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}\z/D']]);
        try { return $this->success($request, $amendments->apply((int) $request->user()->id, (int) $request->route('id'), $input, (string) $request->header('Idempotency-Key'))); }
        catch (DomainException $error) { return $this->failure($request, $error); }
    }

    private function success(Request $request, array $value): JsonResponse
    {
        return TxapiResponse::success($request, $value)->header('Cache-Control', 'private, no-store');
    }

    private function failure(Request $request, DomainException $error): JsonResponse
    {
        $code = preg_match('/\A(?:NATIVE|TERMS)_[A-Z_]{1,80}\z/D', $error->getMessage()) ? $error->getMessage() : 'NATIVE_AMENDMENT_REFUSED';
        $status = str_ends_with($code, '_INVALID') && str_contains($code, 'INPUT') ? 422
            : (str_ends_with($code, '_UNAVAILABLE') ? 503 : ($code === 'NATIVE_AMENDMENT_ACTOR_REJECTED' ? 403 : 409));
        return TxapiResponse::error($request, $code, 'Subscription amendment refused', $status)->header('Cache-Control', 'private, no-store');
    }
}
