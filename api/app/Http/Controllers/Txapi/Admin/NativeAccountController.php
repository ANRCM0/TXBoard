<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Domains\Installation\InstallationAuthority;
use App\Services\Auth\NativeIdentityService;
use App\Services\Subscription\NativeCredentialSynchronization;
use App\Services\Subscription\NativeSubscriptionStore;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Native identity management never assigns legacy User entitlements. */
final class NativeAccountController
{
    public function create(Request $request, NativeIdentityService $identity): JsonResponse
    {
        if (array_diff(array_keys($request->all()), ['email', 'password'])) {
            return TxapiResponse::error($request, 'NATIVE_ACCOUNT_INPUT_INVALID', 'Unsupported identity fields', 422);
        }
        $input = $request->validate([
            'email' => ['required', 'string', 'email:strict', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ]);
        [$created, $result] = $identity->create($input['email'], $input['password']);
        if (!$created) {
            return TxapiResponse::error($request, 'NATIVE_ACCOUNT_CONFLICT', 'Account could not be created', 409);
        }
        return TxapiResponse::success($request, ['id' => (int) $result->id, 'email' => $result->email], status: 201)
            ->header('Cache-Control', 'no-store');
    }

    public function status(Request $request, NativeSubscriptionStore $subscriptions,
        NativeCredentialSynchronization $synchronization): JsonResponse
    {
        if (array_diff(array_keys($request->all()), ['banned', 'expected_banned'])) {
            return TxapiResponse::error($request, 'NATIVE_ACCOUNT_INPUT_INVALID', 'Unsupported status fields', 422);
        }
        $input = $request->validate(['banned' => ['required', 'boolean'], 'expected_banned' => ['required', 'boolean']]);
        try {
            $result = DB::transaction(function () use ($request, $input, $subscriptions, $synchronization): array {
                $installation = app(InstallationAuthority::class)->assertNativeMutation();
                [$user, , $owned] = $subscriptions->lockAccount((int) $request->route('id'), time(), $installation['installation_id']);
                if ((bool) $user->is_admin || (bool) ($user->is_staff ?? false) || (int) $user->id === (int) $request->user()->id) {
                    throw new DomainException('NATIVE_ACCOUNT_PROTECTED');
                }
                if ((bool) $user->banned !== (bool) $input['expected_banned']) {
                    throw new DomainException('NATIVE_ACCOUNT_VERSION_CONFLICT');
                }
                if ((bool) $user->banned !== (bool) $input['banned']) {
                    DB::table('tx_user')->where('id', $user->id)->update(['banned' => (bool) $input['banned'], 'updated_at' => time()]);
                    foreach ($owned as $subscription) {
                        $synchronization->stageLocked((int) $user->id, (int) $subscription->id);
                    }
                }
                return ['id' => (int) $user->id, 'banned' => (bool) $input['banned']];
            }, 3);
        } catch (DomainException $error) {
            $code = preg_match('/\ANATIVE_[A-Z0-9_]{1,64}\z/D', $error->getMessage())
                ? $error->getMessage() : 'NATIVE_ACCOUNT_OPERATION_REFUSED';
            return TxapiResponse::error($request, $code, 'Account status change refused', 409);
        }
        return TxapiResponse::success($request, $result);
    }
}
