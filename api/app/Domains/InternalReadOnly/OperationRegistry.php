<?php

namespace App\Domains\InternalReadOnly;

use Illuminate\Http\Request;

final class OperationRegistry
{
    public const OPERATIONS = [
        'user.profile.get' => ['service' => 'user-gateway', 'actor' => 'user', 'versions' => ['1', '2']],
        'user.order.get' => ['service' => 'user-gateway', 'actor' => 'user', 'versions' => ['1', '2']],
        'admin.order.get' => ['service' => 'admin-gateway', 'actor' => 'admin', 'versions' => ['1']],
    ];

    public static function request(Request $request): array
    {
        $operation = (string) $request->route('id');
        $definition = self::OPERATIONS[$operation] ?? null;
        if ($definition === null) {
            throw new SliceFailure('UNKNOWN_OPERATION', 400);
        }
        if ($request->method() !== 'POST' || $request->getRequestUri() !== '/txapi/internal/v1/operations/'.$operation
            || !preg_match('/\Aapplication\/json(?:;\s*charset=utf-8)?\z/iD', $request->header('Content-Type', ''))) {
            throw new SliceFailure('INVALID_REQUEST', 400);
        }
        $raw = $request->getContent();
        if (strlen($raw) > config('internal_api_slice.max_body_bytes')) {
            throw new SliceFailure('PAYLOAD_TOO_LARGE', 413);
        }
        $body = StrictJson::object($raw);
        StrictJson::exact($body, ['contract_version', 'request_id', 'deadline_at', 'input']);
        if (!in_array($body->contract_version, $definition['versions'], true) || !is_string($body->request_id)
            || !preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD', $body->request_id)
            || !is_int($body->deadline_at) || !$body->input instanceof \stdClass) {
            throw new SliceFailure('INVALID_REQUEST', 400);
        }
        $now = time();
        if ($body->deadline_at <= $now) {
            throw new SliceFailure('DEADLINE_EXCEEDED', 504);
        }
        if ($body->deadline_at > $now + config('internal_api_slice.max_deadline_seconds')) {
            throw new SliceFailure('INVALID_REQUEST', 400);
        }
        $input = $body->input;
        if ($operation === 'user.profile.get') {
            StrictJson::exact($input, []);
        } elseif ($operation === 'user.order.get') {
            StrictJson::exact($input, ['trade_no']);
            $max = $body->contract_version === '2' ? 128 : 64;
            if (!is_string($input->trade_no) || !preg_match('/\A[A-Za-z0-9_-]{1,'.$max.'}\z/D', $input->trade_no)) {
                throw new SliceFailure('INVALID_REQUEST', 400);
            }
        } else {
            StrictJson::exact($input, ['id', 'admin_path']);
            if (!is_string($input->id) || !preg_match('/\A[1-9][0-9]{0,17}\z/D', $input->id)
                || !is_string($input->admin_path) || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $input->admin_path)) {
                throw new SliceFailure('INVALID_REQUEST', 400);
            }
        }
        return compact('operation', 'definition', 'body');
    }
}
