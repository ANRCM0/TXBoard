<?php

namespace App\Domains\Installation;

/** Closed runtime inventory. Adding an entry requires native implementation and acceptance. */
final class NativeRuntimeAdmission
{
    public const VERSION = 'native-core-runtime/1';
    public const COMMANDS = ['core:subscriptions-tick', 'core:native-work', 'check:order',
        'schedule:run', 'schedule:work', 'queue:work', 'queue:listen', 'queue:restart',
        'horizon', 'horizon:work', 'horizon:supervisor', 'horizon:terminate', 'horizon:status'];
    public const JOBS = [\App\Jobs\OrderHandleJob::class];
    private const HTTP = [
        'GET' => [
            'txapi/readiness', 'txapi/public/config', 'txapi/plans', 'txapi/plans/[1-9][0-9]*',
            'txapi/me', 'txapi/me/subscription', 'txapi/me/preferences',
            'txapi/auth/sessions', 'txapi/orders', 'txapi/orders/[A-Za-z0-9_-]+',
            'txapi/orders/[A-Za-z0-9_-]+/detail', 'txapi/me/credential-operations/cp_[a-f0-9]{32}',
            'txapi/node/v1/(?:config|users|machine/nodes|audit/rules)',
        ],
        'POST' => [
            'txapi/auth/(?:login|admin/login|register|logout|password)',
            'txapi/me/access-subjects', 'txapi/me/access-subjects/[1-9][0-9]*/(?:revoke|rotate)',
            'txapi/me/credential-operations/cp_[a-f0-9]{32}/recover',
            'txapi/me/subscription-deliveries', 'txapi/orders', 'txapi/orders/[A-Za-z0-9_-]+/(?:cancel|checkout)',
            'txapi/node/v1/(?:handshake|report|machine/status|audit/report)',
            'txapi/node/v2/usage/(?:submit|batch/get)',
            'txapi/admin/[^/]+/subscriptions/[1-9][0-9]*/operations/(?:suspend|resume|revoke|expire|reset)',
            'txapi/admin/[^/]+/native/accounts',
        ],
        'PATCH' => ['txapi/me/preferences', 'txapi/admin/[^/]+/native/accounts/[1-9][0-9]*/status'],
        'DELETE' => ['txapi/auth/sessions/[1-9][0-9]*'],
    ];

    public static function http(string $method, string $path): bool
    {
        foreach (self::HTTP[$method] ?? [] as $pattern) {
            if (preg_match('#\A'.$pattern.'\z#D', $path) === 1) { return true; }
        }
        return false;
    }

    public static function digest(): string
    {
        return hash('sha256', json_encode([self::VERSION, self::HTTP, self::COMMANDS, self::JOBS], JSON_THROW_ON_ERROR));
    }
}
