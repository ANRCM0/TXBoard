<?php

namespace App\Http\Controllers\Txapi;

use App\Core\Http\TxapiResponse;
use App\Domains\CredentialProvider\ProviderError;
use App\Domains\CredentialProvider\RenderGrantCodec;
use App\Domains\Identity\Internal\CurrentSessionAuthorization;
use App\Domains\Subscription\Services\NativeSubscriptionDelivery;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class SubscriptionDeliveryController
{
    public function create(Request $request, NativeSubscriptionDelivery $delivery, CurrentSessionAuthorization $sessions): JsonResponse
    {
        if (array_diff(array_keys($request->all()), ['access_subject_id', 'recipient_public_key', 'format_id', 'client_version'])) {
            return TxapiResponse::error($request, 'NATIVE_SUBSCRIPTION_DELIVERY_INPUT_INVALID', 'Unsupported delivery fields', 422);
        }
        $input = $request->validate([
            'access_subject_id' => ['required', 'integer', 'min:1'],
            'recipient_public_key' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{43}\z/D'],
            'format_id' => ['sometimes', 'string', 'in:'.implode(',', [RenderGrantCodec::FORMAT, ...array_keys(RenderGrantCodec::CLIENT_FORMATS)])],
            'client_version' => ['sometimes', 'required', 'string', 'max:14'],
        ]);
        $actorId = (int) Auth::guard('sanctum')->id();
        try {
            $result = $delivery->authorize($actorId,
                (int) $input['access_subject_id'], $input['recipient_public_key'], null,
                $input['format_id'] ?? RenderGrantCodec::FORMAT, $input['client_version'] ?? null,
                fn () => $sessions->assertCurrentLocked($actorId));
        } catch (ProviderError $error) {
            return TxapiResponse::error($request, $error->codeName, 'Subscription delivery unavailable',
                $error->retryable ? 503 : 409)->header('Cache-Control', 'no-store');
        } catch (DomainException $error) {
            $code = preg_match('/\ANATIVE_SUBSCRIPTION_DELIVERY_[A-Z0-9_]{1,64}\z/D', $error->getMessage())
                ? $error->getMessage() : 'NATIVE_SUBSCRIPTION_DELIVERY_REFUSED';
            $status = str_ends_with($code, '_MISSING') ? 404 : (str_ends_with($code, '_INPUT_INVALID') ? 422 : 409);
            return TxapiResponse::error($request, $code, 'Subscription delivery refused', $status)->header('Cache-Control', 'no-store');
        }
        return TxapiResponse::success($request, $result)->header('Cache-Control', 'private, no-store');
    }
}
