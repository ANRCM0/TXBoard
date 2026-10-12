<?php

namespace App\Http\Middleware;

use App\Core\Http\TxapiErrorRenderer;
use App\Core\Http\TxapiResponse;
use App\Domains\InternalReadOnly\OperationRegistry;
use App\Domains\InternalReadOnly\ServiceProofVerifier;
use App\Domains\InternalReadOnly\ServiceState;
use App\Domains\InternalReadOnly\SliceFailure;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class InternalServiceProof
{
    public function handle(Request $request, Closure $next)
    {
        if (config('internal_api_slice.enabled') !== true || !app()->environment(['local', 'testing'])) {
            abort(404);
        }
        $identity = null;
        $actor = null;
        try {
            $operation = OperationRegistry::request($request);
            $identity = app(ServiceProofVerifier::class)->verify($request, $operation);
            $anonymous = $operation['definition']['actor'] === 'anonymous';
            if ($anonymous) {
                // Guest hooks must not inherit a previous long-lived request's cached User.
                Auth::forgetGuards();
                foreach (['api', 'sanctum'] as $name) Auth::guard($name)->setRequest($request);
                $request->setUserResolver(static fn () => null);
            } else {
                // No service header/claim supplies an actor. Reevaluate the actual Sanctum credential every request.
                $guard = Auth::guard('sanctum');
                $guard->setRequest($request);
                $guard->forgetUser();
                $actor = $guard->user();
                if (!$actor instanceof User) {
                    throw new SliceFailure('ACTOR_UNAUTHORIZED', 401);
                }
            }
            // The guard supplies current DB state, including revocation/expiry and role changes.
            app(ServiceState::class)->admit($identity);
            $request->attributes->set('internal.readonly.operation', $operation);
            $request->attributes->set('internal.readonly.identity', $identity);
            try {
                $response = $anonymous ? $next($request) : app(TxapiUser::class)->handle($request, $next);
            } catch (\Throwable $exception) {
                $response = TxapiErrorRenderer::render($request, $exception);
            }
            if (time() >= $operation['body']->deadline_at) {
                $response = TxapiResponse::error($request, 'DEADLINE_EXCEEDED', 'Internal operation rejected', 504);
            }
            if (strlen($response->getContent()) > config('internal_api_slice.max_response_bytes')) {
                $response = TxapiResponse::error($request, 'RESPONSE_TOO_LARGE', 'Internal operation rejected', 502);
            }
            $payload = json_decode($response->getContent(), true);
            app(ServiceState::class)->audit($identity, $operation['operation'], $anonymous ? 0 : (int) $actor->id,
                TxapiResponse::requestId($request), $response->getStatusCode(), $payload['error']['code'] ?? null);
        } catch (SliceFailure $failure) {
            $response = TxapiResponse::error($request, $failure->errorCode, 'Internal operation rejected', $failure->status);
        } catch (\Throwable) {
            // Database/config/cryptographic errors never leak through logs or debug envelopes.
            $response = TxapiResponse::error($request, 'DEPENDENCY_UNAVAILABLE', 'Internal operation unavailable', 503);
        }
        if ($response->getStatusCode() === 429) {
            $response->headers->set('Retry-After', (string) (60 - time() % 60));
        }
        return $response->header('Cache-Control', 'private, no-store');
    }
}
