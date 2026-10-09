<?php

namespace App\Core\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class TxapiResponse
{
    public static function requestId(Request $request): string
    {
        $id = $request->attributes->get('txapi.request_id');
        if (!is_string($id) || $id === '') {
            $id = (string) Str::uuid();
            $request->attributes->set('txapi.request_id', $id);
        }
        return $id;
    }

    public static function success(Request $request, mixed $data, array $meta = [], int $status = 200): JsonResponse
    {
        $payload = ['data' => $data];
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }
        $payload['request_id'] = self::requestId($request);
        return response()->json($payload, $status)
            ->header('X-Request-Id', $payload['request_id']);
    }

    public static function error(Request $request, string $code, string $message, int $status, array $fields = []): JsonResponse
    {
        $payload = [
            'error' => ['code' => $code, 'message' => $message],
            'request_id' => self::requestId($request),
        ];
        if ($fields !== []) {
            $payload['error']['fields'] = $fields;
        }
        return response()->json($payload, $status)
            ->header('X-Request-Id', $payload['request_id']);
    }
}
