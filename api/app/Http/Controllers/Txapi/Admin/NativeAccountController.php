<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Domains\Identity\Contracts\AccountAdministration;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Native identity management never assigns legacy User entitlements. */
final class NativeAccountController
{
    public function metadata(Request $request, \App\Domains\Identity\NativeAccountMetadata $metadata): JsonResponse
    {
        if (array_diff(array_keys($request->all()), ['expected_revision', 'remarks'])) return TxapiResponse::error($request, 'NATIVE_ACCOUNT_INPUT_INVALID', 'Unsupported metadata fields', 422);
        $input = $request->validate(['expected_revision' => ['required', 'string', 'regex:/\A[0-9a-f]{64}\z/D'], 'remarks' => ['present', 'nullable', 'string', 'max:2000']]);
        try { $result = $metadata->update((int) $request->user()->id, (int) $request->route('id'), $input['expected_revision'], $input['remarks'], (string) $request->header('Idempotency-Key')); }
        catch (DomainException $error) { return $this->failure($request, $error); }
        return TxapiResponse::success($request, $result)->header('Cache-Control', 'private, no-store');
    }
    public function createBatch(Request $request, AccountAdministration $identity): JsonResponse
    {
        if (array_diff(array_keys($request->all()), ['accounts'])) return TxapiResponse::error($request, 'NATIVE_ACCOUNT_INPUT_INVALID', 'Unsupported identity fields', 422);
        $input = $request->validate(['accounts' => ['required', 'array', 'min:1', 'max:20'],
            'accounts.*' => ['required', 'array:email,password'], 'accounts.*.email' => ['required', 'string', 'email:strict', 'max:254'],
            'accounts.*.password' => ['required', 'string', 'min:8', 'max:255']]);
        try { $result = $identity->createBatch((int) $request->user()->id, $input['accounts'], (string) $request->header('Idempotency-Key')); }
        catch (DomainException $error) { return $this->failure($request, $error); }
        return TxapiResponse::success($request, $result, status: 201)->header('Cache-Control', 'private, no-store');
    }

    public function profile(Request $request, AccountAdministration $identity): JsonResponse
    {
        if (array_diff(array_keys($request->all()), ['expected_revision', 'email', 'password'])) return TxapiResponse::error($request, 'NATIVE_ACCOUNT_INPUT_INVALID', 'Unsupported identity fields', 422);
        $input = $request->validate(['expected_revision' => ['required', 'string', 'regex:/\A[0-9a-f]{64}\z/D'],
            'email' => ['sometimes', 'required', 'string', 'email:strict', 'max:254'],
            'password' => ['sometimes', 'required', 'string', 'min:8', 'max:255']]);
        $revision = $input['expected_revision']; unset($input['expected_revision']);
        try { $result = $identity->profile((int) $request->user()->id, (int) $request->route('id'), $revision, $input, (string) $request->header('Idempotency-Key')); }
        catch (DomainException $error) { return $this->failure($request, $error); }
        return TxapiResponse::success($request, $result)->header('Cache-Control', 'private, no-store');
    }

    private function failure(Request $request, DomainException $error): JsonResponse
    {
        $code = preg_match('/\ANATIVE_ACCOUNT_[A-Z0-9_]{1,64}\z/D', $error->getMessage()) ? $error->getMessage() : 'NATIVE_ACCOUNT_OPERATION_REFUSED';
        return TxapiResponse::error($request, $code, 'Identity operation refused', $code === 'NATIVE_ACCOUNT_ACTOR_REJECTED' ? 403 : ($code === 'NATIVE_ACCOUNT_INPUT_INVALID' ? 422 : 409))->header('Cache-Control', 'private, no-store');
    }

    public function create(Request $request, AccountAdministration $identity): JsonResponse
    {
        if (array_diff(array_keys($request->all()), ['email', 'password'])) {
            return TxapiResponse::error($request, 'NATIVE_ACCOUNT_INPUT_INVALID', 'Unsupported identity fields', 422);
        }
        $input = $request->validate([
            'email' => ['required', 'string', 'email:strict', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ]);
        try { $result = $identity->create((int) $request->user()->id, $input['email'], $input['password']); }
        catch (DomainException) { return TxapiResponse::error($request, 'NATIVE_ACCOUNT_ACTOR_REJECTED', 'Account operation refused', 403); }
        if ($result === null) {
            return TxapiResponse::error($request, 'NATIVE_ACCOUNT_CONFLICT', 'Account could not be created', 409);
        }
        return TxapiResponse::success($request, ['id' => (int) $result->id, 'email' => $result->email], status: 201)
            ->header('Cache-Control', 'no-store');
    }

    public function status(Request $request, AccountAdministration $identity): JsonResponse
    {
        if (array_diff(array_keys($request->all()), ['banned', 'expected_banned'])) {
            return TxapiResponse::error($request, 'NATIVE_ACCOUNT_INPUT_INVALID', 'Unsupported status fields', 422);
        }
        $input = $request->validate(['banned' => ['required', 'boolean'], 'expected_banned' => ['required', 'boolean']]);
        try {
            $result = $identity->status((int) $request->user()->id, (int) $request->route('id'),
                (bool) $input['expected_banned'], (bool) $input['banned']);
        } catch (DomainException $error) {
            $code = preg_match('/\ANATIVE_[A-Z0-9_]{1,64}\z/D', $error->getMessage())
                ? $error->getMessage() : 'NATIVE_ACCOUNT_OPERATION_REFUSED';
            return TxapiResponse::error($request, $code, 'Account status change refused', 409);
        }
        return TxapiResponse::success($request, $result);
    }
}
