<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Domains\Subscription\Services\NativePlanAdministration;
use App\Domains\Subscription\Services\PlanPublicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use DomainException;

final class NativePlanAdminController
{
    public static function publicationRules(): array
    {
        return [
            'plan_id' => ['sometimes', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
            'name' => ['required', 'string', 'min:1', 'max:255'], 'content' => ['sometimes', 'nullable', 'string', 'max:8192'],
            'tags' => ['sometimes', 'array', 'max:40'], 'tags.*' => ['string', 'max:80'],
            'quota_gib' => ['required', 'integer', 'min:0', 'max:8589934591'],
            'group_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:2147483647'],
            'speed_limit_mbps' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:4294967295'],
            'device_limit' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:4294967295'],
            'capacity_limit' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:4294967295'],
            'reset_method' => ['required', 'integer', 'in:0,1,2,3,4'], 'currency' => ['required', 'in:CNY,USD'],
            'prices_minor' => ['required', 'array:monthly,quarterly,half_yearly,yearly,two_yearly,three_yearly,onetime,reset_traffic', 'min:1'],
            'prices_minor.*' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,18})\z/D'],
        ];
    }

    public function publish(Request $request, NativePlanAdministration $plans): JsonResponse
    {
        $fields = array_filter(array_keys(self::publicationRules()), static fn (string $key): bool => !str_contains($key, '.'));
        if (array_diff(array_keys($request->all()), $fields)) {
            return TxapiResponse::error($request, 'NATIVE_PLAN_INPUT_INVALID', 'Unsupported publication fields', 422);
        }
        $input = $request->validate(self::publicationRules());
        try {
            $result = $plans->publish((int) Auth::guard('sanctum')->id(), $input, (string) $request->header('Idempotency-Key'));
        } catch (RuntimeException|DomainException $error) {
            return $this->error($request, $error);
        }
        return TxapiResponse::success($request, $result)->header('Cache-Control', 'private, no-store');
    }

    public function availability(Request $request, PlanPublicationService $plans): JsonResponse
    {
        if (array_diff(array_keys($request->all()), ['show', 'sell', 'renew', 'capacity_limit', 'expected_version'])) {
            return TxapiResponse::error($request, 'NATIVE_PLAN_INPUT_INVALID', 'Unsupported availability fields', 422);
        }
        $input = $request->validate(['show' => ['required', 'boolean'], 'sell' => ['required', 'boolean'],
            'renew' => ['required', 'boolean'], 'capacity_limit' => ['present', 'nullable', 'integer', 'min:0', 'max:4294967295'],
            'expected_version' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX]]);
        try {
            $result = $plans->setAvailability((int) $request->route('offerId'), [
                'show' => (bool) $input['show'], 'sell' => (bool) $input['sell'], 'renew' => (bool) $input['renew'],
                'capacity_limit' => $input['capacity_limit'],
            ], (int) $input['expected_version']);
        } catch (RuntimeException|DomainException $error) {
            return $this->error($request, $error);
        }
        return TxapiResponse::success($request, $result)->header('Cache-Control', 'private, no-store');
    }

    private function error(Request $request, \Throwable $error): JsonResponse
    {
        $code = $error->getMessage();
        $code = preg_match('/\A(?:NATIVE_PLAN|PUBLICATION)_[A-Z_]+\z/D', $code) === 1 ? $code : 'NATIVE_PLAN_REFUSED';
        return TxapiResponse::error($request, $code, 'Publication refused', 409)->header('Cache-Control', 'no-store');
    }
}
