<?php

namespace App\Domains\InternalReadOnly;

use Illuminate\Http\Request;

final class OperationRegistry
{
    public const OPERATIONS = [
        'auth.login' => ['service' => 'user-gateway', 'actor' => 'login', 'versions' => ['1']],
        'auth.register' => ['service' => 'user-gateway', 'actor' => 'register', 'versions' => ['1']],
        'auth.email-code.send' => ['service' => 'user-gateway', 'actor' => 'email-code', 'versions' => ['1']],
        'public.site-config.get' => ['service' => 'user-gateway', 'actor' => 'anonymous', 'versions' => ['1']],
        'public.plan.list' => ['service' => 'user-gateway', 'actor' => 'anonymous', 'versions' => ['1']],
        'user.profile.get' => ['service' => 'user-gateway', 'actor' => 'user', 'versions' => ['1', '2']],
        'user.order.get' => ['service' => 'user-gateway', 'actor' => 'user', 'versions' => ['1', '2']],
        'user.order.list' => ['service' => 'user-gateway', 'actor' => 'user', 'versions' => ['2']],
        'user.dashboard.get' => ['service' => 'user-gateway', 'actor' => 'user', 'versions' => ['2']],
        'user.payment-methods.list' => ['service' => 'user-gateway', 'actor' => 'user', 'versions' => ['2']],
        'user.subscription-summary.get' => ['service' => 'user-gateway', 'actor' => 'user', 'versions' => ['2']],
        'user.notice.list' => ['service' => 'user-gateway', 'actor' => 'user', 'versions' => ['2']],
        'user.order-status.get' => ['service' => 'user-gateway', 'actor' => 'user', 'versions' => ['2']],
        'user.order.create' => ['service' => 'user-gateway', 'actor' => 'user', 'versions' => ['1']],
        'admin.order.get' => ['service' => 'admin-gateway', 'actor' => 'admin', 'versions' => ['1']],
    ];

    public static function request(Request $request): array
    {
        $operation = (string) $request->route('id');
        $definition = self::OPERATIONS[$operation] ?? null;
        if ($definition === null) {
            throw new SliceFailure('UNKNOWN_OPERATION', 400);
        }
        if ($definition['actor'] === 'anonymous' && config('internal_api_slice.public_read_enabled') !== true) {
            throw new SliceFailure('OPERATION_DISABLED', 404);
        }
        if ($definition['actor'] === 'login' && (config('internal_api_slice.login_enabled') !== true
            || config('core_shadow.isolated_copy') !== true)) {
            throw new SliceFailure('OPERATION_DISABLED', 404);
        }
        if ($definition['actor'] === 'email-code' && (config('internal_api_slice.email_code_enabled') !== true
            || config('core_shadow.isolated_copy') !== true)) {
            throw new SliceFailure('OPERATION_DISABLED', 404);
        }
        if ($definition['actor'] === 'register' && (config('internal_api_slice.register_enabled') !== true
            || config('core_shadow.isolated_copy') !== true)) {
            throw new SliceFailure('OPERATION_DISABLED', 404);
        }
        if ($operation === 'user.order.create' && (config('internal_api_slice.order_write_enabled') !== true
            || config('core_shadow.isolated_copy') !== true)) {
            throw new SliceFailure('OPERATION_DISABLED', 404);
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
        if ($definition['actor'] === 'email-code') {
            $keys = ['email'];
            foreach (['email' => 254, 'turnstile_token' => 4096, 'recaptcha_v3_token' => 4096, 'recaptcha_data' => 4096] as $field => $max) {
                if (property_exists($input, $field)) {
                    if ($field !== 'email') $keys[] = $field;
                    if (!is_string($input->$field) || mb_strlen($input->$field) > $max) {
                        throw new SliceFailure('INVALID_REQUEST', 400);
                    }
                }
            }
            StrictJson::exact($input, $keys);
        } elseif (in_array($definition['actor'], ['login', 'register'], true)) {
            $keys = ['email', 'password'];
            $limits = ['email' => 254, 'password' => 1024, 'turnstile_token' => 4096,
                'recaptcha_v3_token' => 4096, 'recaptcha_data' => 4096, 'email_code' => 128];
            if ($definition['actor'] === 'register') $limits['invite_code'] = 128;
            foreach ($limits as $field => $max) {
                if (property_exists($input, $field)) {
                    if (!in_array($field, $keys, true)) $keys[] = $field;
                    if (!is_string($input->$field) || mb_strlen($input->$field) > $max) {
                        throw new SliceFailure('INVALID_REQUEST', 400);
                    }
                }
            }
            StrictJson::exact($input, $keys);
        } elseif ($definition['actor'] === 'anonymous') {
            StrictJson::exact($input, []);
        } elseif ($operation === 'user.order.create') {
            $keys = ['plan_id', 'period', 'idempotency_key'];
            if (property_exists($input, 'coupon_code')) $keys[] = 'coupon_code';
            StrictJson::exact($input, $keys);
            if (!is_string($input->plan_id) || !preg_match('/\A[1-9][0-9]{0,9}\z/D', $input->plan_id)
                || (int) $input->plan_id > 2147483647
                || !is_string($input->period) || !in_array($input->period, \App\Services\PlanService::getNewPeriods(), true)
                || !is_string($input->idempotency_key) || !preg_match('/\A[A-Za-z0-9._~-]{1,128}\z/D', $input->idempotency_key)
                || (property_exists($input, 'coupon_code') && (!is_string($input->coupon_code)
                    || mb_strlen($input->coupon_code) < 1 || mb_strlen($input->coupon_code) > 64))) {
                throw new SliceFailure('INVALID_REQUEST', 400);
            }
        } elseif (in_array($operation, ['user.profile.get', 'user.dashboard.get', 'user.payment-methods.list', 'user.subscription-summary.get'], true)) {
            StrictJson::exact($input, []);
        } elseif (in_array($operation, ['user.order.list', 'user.notice.list'], true)) {
            $keys = ['page', 'per_page'];
            if ($operation === 'user.order.list' && property_exists($input, 'status')) {
                $keys[] = 'status';
            }
            StrictJson::exact($input, $keys);
            if (!is_int($input->page) || $input->page < 1 || $input->page > 9999
                || !is_int($input->per_page) || $input->per_page < 1 || $input->per_page > 100
                || (property_exists($input, 'status')
                    && (!is_int($input->status) || $input->status < 0 || $input->status > 4))) {
                throw new SliceFailure('INVALID_REQUEST', 400);
            }
        } elseif (in_array($operation, ['user.order.get', 'user.order-status.get'], true)) {
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
