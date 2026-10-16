<?php

namespace App\Http\Controllers\Txapi;

use App\Core\Http\TxapiResponse;
use App\Domains\Billing\IdempotencyException;
use App\Domains\Billing\OrderIdempotency;
use App\Domains\CredentialProvider\ProviderError;
use App\Domains\Installation\InstallationAuthority;
use App\Services\Subscription\NativeCredentialProviderService;
use Closure;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class CredentialProviderController
{
    public function issue(Request $request, NativeCredentialProviderService $service): JsonResponse
    {
        return $this->run($request, ['subscription_id', 'provider_id', 'credential_profile_id', 'expected_version'],
            function () use ($request, $service): array {
                $input = $request->validate([
                    'subscription_id' => ['required', 'integer', 'min:1'],
                    'provider_id' => ['required', 'string', 'regex:/\A[a-z][a-z0-9_-]{0,63}\z/D'],
                    'credential_profile_id' => ['required', 'string', 'in:vless-uuid/v1,vmess-uuid/v1'],
                    'expected_version' => ['required', 'regex:/\A[1-9][0-9]{0,18}\z/D'],
                ]);
                return $service->issue((int) Auth::guard('sanctum')->id(), (int) $input['subscription_id'],
                    $input['provider_id'], $input['credential_profile_id'], $this->key($request), $input['expected_version']);
            });
    }

    public function revoke(Request $request, NativeCredentialProviderService $service): JsonResponse
    {
        return $this->run($request, ['expected_version'], function () use ($request, $service): array {
            $input = $request->validate(['expected_version' => ['required', 'regex:/\A[1-9][0-9]{0,18}\z/D']]);
            return $service->revoke((int) Auth::guard('sanctum')->id(), (int) $request->route('id'),
                $this->key($request), $input['expected_version'], 'MANUAL');
        });
    }

    public function recover(Request $request, NativeCredentialProviderService $service): JsonResponse
    {
        return $this->run($request, [], fn (): array => $service->recover(
            (int) Auth::guard('sanctum')->id(), (string) $request->route('operation_ref')));
    }

    public function show(Request $request, NativeCredentialProviderService $service): JsonResponse
    {
        return $this->run($request, [], fn (): array => $service->operation(
            (int) Auth::guard('sanctum')->id(), (string) $request->route('operation_ref')));
    }

    private function run(Request $request, array $fields, Closure $operation): JsonResponse
    {
        if (app(InstallationAuthority::class)->inspect()['mode'] !== 'native') {
            return TxapiResponse::error($request, 'NATIVE_SUBSCRIPTION_REQUIRED', 'Native subscription required', 409);
        }
        if (array_diff(array_keys($request->all()), $fields)) {
            return TxapiResponse::error($request, 'PROVIDER_INPUT_INVALID', 'Unsupported credential operation fields', 422);
        }
        try {
            $result = $operation();
        } catch (IdempotencyException $error) {
            return TxapiResponse::error($request, $error->errorCode, 'Idempotency-Key is required and must be valid', (int) $error->getCode());
        } catch (ProviderError $error) {
            return TxapiResponse::error($request, $error->codeName, 'Credential provider operation refused',
                $error->retryable ? 503 : 409)->header('Cache-Control', 'no-store');
        } catch (DomainException $error) {
            $code = preg_match('/\A(?:NATIVE|PROVIDER|CAPABILITY)_[A-Z0-9_]{1,64}\z/D', $error->getMessage())
                ? $error->getMessage() : 'PROVIDER_OPERATION_REFUSED';
            $status = str_ends_with($code, '_MISSING') ? 404
                : (str_ends_with($code, '_INVALID') || str_ends_with($code, '_UNSUPPORTED') ? 422 : 409);
            return TxapiResponse::error($request, $code, 'Credential provider operation refused', $status)
                ->header('Cache-Control', 'no-store');
        }
        $status = $request->isMethod('GET') ? 200 : match ($result['status'] ?? null) {
            'succeeded' => 200,
            'rejected', 'orphaned' => 409,
            default => 202,
        };
        return TxapiResponse::success($request, $result, status: $status)->header('Cache-Control', 'no-store');
    }

    private function key(Request $request): string
    {
        return OrderIdempotency::clientKey($request->header('Idempotency-Key'))
            ?? throw new IdempotencyException('Idempotency-Key required', 422, 'IDEMPOTENCY_KEY_REQUIRED');
    }
}
