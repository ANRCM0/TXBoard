<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Domains\Billing\IdempotencyException;
use App\Domains\Billing\OrderIdempotency;
use App\Domains\Installation\InstallationAuthority;
use App\Domains\Subscription\Services\SubscriptionLifecycleService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class SubscriptionOperationsController
{
    public function execute(Request $request, SubscriptionLifecycleService $service): JsonResponse
    {
        if (app(InstallationAuthority::class)->inspect()['mode'] !== 'native') {
            return TxapiResponse::error($request, 'NATIVE_SUBSCRIPTION_REQUIRED', 'Native subscription required', 409);
        }
        $input = $request->validate([
            'expected_version' => ['required', 'regex:/\A[1-9][0-9]{0,18}\z/D'],
            'reason' => ['sometimes', 'string', 'regex:/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}\z/D'],
        ]);
        if (array_diff(array_keys($request->all()), ['expected_version', 'reason'])) {
            return TxapiResponse::error($request, 'INVALID_SUBSCRIPTION_OPERATION', 'Unsupported operation fields', 422);
        }
        $operation = (string) $request->route('operation');
        if (!in_array($operation, ['suspend', 'resume', 'revoke', 'expire', 'reset'], true)) abort(404);
        if ($operation !== 'suspend' && array_key_exists('reason', $input)) {
            return TxapiResponse::error($request, 'INVALID_SUBSCRIPTION_OPERATION', 'Reason applies only to suspension', 422);
        }
        try {
            $key = OrderIdempotency::clientKey($request->header('Idempotency-Key'));
            if ($key === null) return TxapiResponse::error($request, 'IDEMPOTENCY_KEY_REQUIRED', 'Idempotency-Key is required', 422);
            $id = (int) $request->route('id');
            $owner = DB::table('tx_subscription')->useWritePdo()->where('id', $id)->value('user_id');
            if ($owner === null) abort(404);
            $result = $operation === 'suspend'
                ? $service->suspend((int) $owner, $id, $key, $input['expected_version'], reason: $input['reason'] ?? 'manual')
                : $service->{$operation}((int) $owner, $id, $key, $input['expected_version']);
        } catch (IdempotencyException $exception) {
            return TxapiResponse::error($request, $exception->errorCode, 'Invalid Idempotency-Key', (int) $exception->getCode());
        } catch (DomainException $exception) {
            $code = $exception->getMessage();
            if (!preg_match('/\ANATIVE_[A-Z0-9_]+\z/D', $code)) $code = 'NATIVE_SUBSCRIPTION_OPERATION_REFUSED';
            return TxapiResponse::error($request, $code, 'Subscription operation refused', 409);
        }
        return TxapiResponse::success($request, $result);
    }
}
