<?php

namespace App\Core\Http;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Scoped to /txapi only; legacy exception envelopes remain untouched. */
final class TxapiErrorRenderer
{
    public static function render(Request $request, Throwable $exception): JsonResponse
    {
        // Laravel wraps the named ingress throttle callback in this exception.
        // Preserve only its canonical response, not arbitrary exception payloads.
        if ($exception instanceof HttpResponseException && $exception->getResponse() instanceof JsonResponse) {
            $response = $exception->getResponse();
            $body = $response->getData(true);
            if ($response->getStatusCode() === 429 && is_array($body) && count($body) === 2
                && ($body['error'] ?? null) === ['code' => 'RATE_LIMITED', 'message' => 'Service ingress limit exceeded']
                && ($body['request_id'] ?? null) === TxapiResponse::requestId($request)
                && $response->headers->get('X-Request-Id') === TxapiResponse::requestId($request)) {
                return $response;
            }
        }
        $status = match (true) {
            $exception instanceof ValidationException => 422,
            $exception instanceof AuthenticationException => 401,
            $exception instanceof AuthorizationException => 403,
            $exception instanceof ModelNotFoundException => 404,
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            default => 500,
        };
        if ($status < 400 || $status > 599) {
            $status = 500;
        }

        [$code, $message] = match ($status) {
            400 => ['BAD_REQUEST', 'Invalid request'],
            401 => ['UNAUTHENTICATED', 'Authentication required'],
            403 => ['FORBIDDEN', 'Access denied'],
            404 => ['NOT_FOUND', 'Resource not found'],
            405 => ['METHOD_NOT_ALLOWED', 'Method not allowed'],
            409 => ['CONFLICT', 'Resource conflict'],
            422 => ['VALIDATION_FAILED', 'Validation failed'],
            429 => ['RATE_LIMITED', 'Too many requests'],
            default => ['INTERNAL_ERROR', 'An unexpected error occurred'],
        };

        // A caller may inspect invalid field names; never echo input values,
        // SQL, token material, exception messages or stack traces.
        $fields = $exception instanceof ValidationException
            ? array_values(array_keys($exception->errors())) : [];
        return TxapiResponse::error($request, $code, $message, $status, $fields);
    }
}
