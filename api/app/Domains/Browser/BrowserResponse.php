<?php

namespace App\Domains\Browser;

use App\Core\Http\TxapiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BrowserResponse
{
    public static function success(Request $request, mixed $data, array $coreMeta = [], ?string $coreTrace = null): JsonResponse
    {
        $meta = ['version' => '1', 'requestId' => TxapiResponse::requestId($request)];
        if ($coreTrace !== null) $meta += ['coreRequestId' => $coreTrace, 'coreMeta' => $coreMeta === [] ? (object) [] : $coreMeta];
        return response()->json(['ok' => true, 'data' => $data, 'meta' => $meta])->header('Cache-Control', 'private, no-store')
            ->header('X-Request-Id', TxapiResponse::requestId($request))->header('X-Content-Type-Options', 'nosniff');
    }

    public static function error(Request $request, string $code, int $status): JsonResponse
    {
        return response()->json(['ok' => false, 'error' => ['code' => $code, 'message' => match ($code) {
            'UNAUTHORIZED' => 'Current session required', 'FORBIDDEN' => 'Core operation not granted',
            'CAPTCHA_REQUIRED' => 'Verification required', 'RATE_LIMITED' => 'Too many requests',
            'UPSTREAM_UNAVAILABLE' => 'Core result unknown; retain the original idempotency key',
            default => 'Core request rejected',
        }], 'meta' => ['version' => '1', 'requestId' => TxapiResponse::requestId($request)]], $status)
            ->header('Cache-Control', 'private, no-store')->header('X-Request-Id', TxapiResponse::requestId($request))
            ->header('X-Content-Type-Options', 'nosniff');
    }

    public static function core(Request $request, JsonResponse $response, string $operation): JsonResponse
    {
        $body = $response->getData(true);
        $status = $response->getStatusCode();
        if ($status < 400 && !isset($body['error'])) {
            $result = self::success($request, $body['data'], $body['meta'] ?? [], $body['request_id']);
            if ($response->headers->get('Pragma') === 'no-cache') $result->header('Pragma', 'no-cache');
            return $result;
        }
        $code = $body['error']['code'] ?? '';
        [$public, $status] = match (true) {
            $status >= 500 => ['UPSTREAM_UNAVAILABLE', $status],
            in_array($code, ['ACTOR_UNAUTHORIZED', 'INVALID_CREDENTIALS'], true) => ['UNAUTHORIZED', 401],
            $code === 'CAPTCHA_INVALID' => ['CAPTCHA_REQUIRED', 428],
            in_array($code, ['FORBIDDEN', 'ADMIN_ACCESS_DENIED'], true) => ['FORBIDDEN', 403],
            $status === 404 => ['NOT_FOUND', 404],
            $code === 'INVALID_REQUEST' => ['VALIDATION_ERROR', 422],
            $code === 'RATE_LIMITED' => ['RATE_LIMITED', 429],
            in_array($code, ['IDEMPOTENCY_CONFLICT', 'IDEMPOTENCY_IN_PROGRESS'], true) => [$code, 409],
            in_array($code, ['SETTINGS_VERSION_CONFLICT', 'PAYMENT_CONFIGURATION_CONFLICT'], true) => ['BUSINESS_REJECTED', 409],
            in_array($code, ['SETTINGS_UPDATE_EMPTY', 'PAYMENT_CONFIGURATION_INVALID'], true) => ['VALIDATION_ERROR', 422],
            str_starts_with($operation, 'network.nodes.ordering.') && str_starts_with($code, 'NETWORK_NODE_') && in_array($status, [409, 422], true) => [$status === 422 ? 'VALIDATION_ERROR' : 'BUSINESS_REJECTED', $status],
            $code === 'BUSINESS_REJECTED' && $status === 422 => ['BUSINESS_REJECTED', 422],
            (str_starts_with($operation, 'platform.module-') || str_starts_with($operation, 'platform.theme-packages.')) && preg_match('/\A(?:MODULE|THEME)_[A-Z_]{1,80}\z/D', $code)
                && in_array($status, [400, 409, 422], true) => [$status === 422 ? 'VALIDATION_ERROR' : 'BUSINESS_REJECTED', $status],
            (str_starts_with($operation, 'subscription.admin.amendment.') || str_starts_with($operation, 'subscription.admin-templates.'))
                && preg_match('/\A(?:NATIVE|TERMS|SUBSCRIPTION_TEMPLATE)_[A-Z_]{1,80}\z/D', $code)
                && in_array($status, [400, 409, 422], true) => [$status === 422 ? 'VALIDATION_ERROR' : 'BUSINESS_REJECTED', $status],
            str_starts_with($operation, 'billing.admin-account-') && preg_match('/\ABILLING_[A-Z_]{1,80}\z/D', $code)
                && in_array($status, [400, 409, 422], true) => [$status === 422 ? 'VALIDATION_ERROR' : 'BUSINESS_REJECTED', $status],
            str_starts_with($operation, 'billing.admin-account-') && $code === 'BILLING_ADJUSTMENT_TARGET_PROTECTED' => ['FORBIDDEN', 403],
            (str_starts_with($operation, 'billing.admin-customer-policy.') || str_starts_with($operation, 'usage.admin-'))
                && in_array($code, ['CUSTOMER_POLICY_TARGET_PROTECTED', 'USAGE_CORRECTION_TARGET_PROTECTED'], true) => ['FORBIDDEN', 403],
            (str_starts_with($operation, 'billing.admin-customer-policy.') || str_starts_with($operation, 'usage.admin-'))
                && preg_match('/\A(?:CUSTOMER_POLICY|USAGE_CORRECTION)_[A-Z_]{1,80}\z/D', $code)
                && in_array($status, [400, 409, 422], true) => [$status === 422 ? 'VALIDATION_ERROR' : 'BUSINESS_REJECTED', $status],
            \App\Domains\InternalApi\OperationSchemas::operation($operation)['idempotency'] === 'domain'
                && preg_match('/^(?:support[.]|content[.]|billing[.](?:coupons|gift-cards|admin-gift-cards|commissions|admin-commissions)[.]|identity[.]referrals[.])/', $operation) => ['BUSINESS_REJECTED', $status],
            default => ['UPSTREAM_ERROR', $status],
        };
        $result = self::error($request, $public, $status);
        $retry = $response->headers->get('Retry-After');
        if (is_string($retry) && preg_match('/\A[0-9]{1,5}\z/D', $retry) && (int) $retry <= 86400) {
            $result->headers->set('Retry-After', $retry);
        }
        return $result;
    }
}
