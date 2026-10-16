<?php

namespace App\Http\Controllers\Txapi;

use App\Core\Http\TxapiResponse;
use App\Domains\CredentialProvider\ProviderError;
use App\Domains\Subscription\Services\NativeSubscriptionLinks;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class SubscriptionLinkController
{
    public function index(Request $request, NativeSubscriptionLinks $links): JsonResponse
    {
        return $this->run($request, ['after_id'], function () use ($request, $links): array {
            $input = $request->validate(['after_id' => ['sometimes', 'integer', 'min:0', 'max:'.PHP_INT_MAX]]);
            return $links->listing((int) Auth::guard('sanctum')->id(), (int) ($input['after_id'] ?? 0));
        });
    }

    public function create(Request $request, NativeSubscriptionLinks $links): JsonResponse
    {
        return $this->run($request, ['access_subject_id', 'expires_at'], function () use ($request, $links): array {
            $input = $request->validate(['access_subject_id' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
                'expires_at' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX]]);
            return $links->issue((int) Auth::guard('sanctum')->id(), (int) $input['access_subject_id'],
                (int) $input['expires_at'], (string) $request->header('Idempotency-Key'));
        });
    }

    public function revoke(Request $request, NativeSubscriptionLinks $links): JsonResponse
    {
        return $this->run($request, [], fn (): array =>
            $links->revoke((int) Auth::guard('sanctum')->id(), (string) $request->route('linkRef')));
    }

    public function redeem(Request $request, NativeSubscriptionLinks $links): JsonResponse
    {
        return $this->run($request, ['recipient_public_key'], function () use ($request, $links): array {
            $input = $request->validate(['recipient_public_key' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{43}\z/D']]);
            return $links->redeem((string) $request->route('linkRef'), (string) $request->bearerToken(), $input['recipient_public_key']);
        });
    }

    private function run(Request $request, array $fields, \Closure $operation): JsonResponse
    {
        if (array_diff(array_keys($request->all()), $fields)) {
            return TxapiResponse::error($request, 'NATIVE_SUBSCRIPTION_LINK_INPUT_INVALID', 'Unsupported link fields', 422);
        }
        try {
            $result = $operation();
        } catch (ProviderError) {
            return TxapiResponse::error($request, 'NATIVE_SUBSCRIPTION_LINK_PROVIDER_UNAVAILABLE',
                'Subscription delivery unavailable', 503)->header('Cache-Control', 'no-store');
        } catch (DomainException $error) {
            $code = $error->getMessage();
            $code = preg_match('/\ANATIVE_[A-Z_]{1,100}\z/D', $code) === 1 ? $code : 'NATIVE_SUBSCRIPTION_LINK_REFUSED';
            return TxapiResponse::error($request, $code, 'Subscription link unavailable',
                str_ends_with($code, '_MISSING') ? 404 : (str_ends_with($code, '_INPUT_INVALID') ? 422 : 409))
                ->header('Cache-Control', 'no-store');
        }
        return TxapiResponse::success($request, $result)->header('Cache-Control', 'private, no-store');
    }
}
