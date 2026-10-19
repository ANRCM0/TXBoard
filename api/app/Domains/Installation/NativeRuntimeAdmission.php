<?php

namespace App\Domains\Installation;

/** Closed runtime inventory. Adding an entry requires native implementation and acceptance. */
final class NativeRuntimeAdmission
{
    public const VERSION = 'native-core-runtime/1';
    public const COMMANDS = ['core:subscriptions-tick', 'core:native-work', 'core:module-work', 'core:module-deliveries', 'core:module-deploy', 'core:provider-trust', 'core:internal-service', 'check:order',
        'schedule:run', 'schedule:work', 'queue:work', 'queue:listen', 'queue:restart',
        'horizon', 'horizon:work', 'horizon:supervisor', 'horizon:terminate', 'horizon:status'];
    public const JOBS = [\App\Jobs\OrderHandleJob::class];
    private const HTTP = [
        'GET' => [
            'gateway/v1/bootstrap', 'gateway/v1/core/(?:user|admin)/capabilities',
            'txapi/readiness', 'txapi/public/config', 'txapi/public/site-config', 'txapi/plans', 'txapi/plans/[1-9][0-9]*',
            'txapi/me', 'txapi/me/subscription', 'txapi/me/preferences',
            'txapi/me/subscription-links', 'txapi/me/usage-periods',
            'txapi/billing/(?:wallet|payment-methods|recharge-payment-methods|recharges)',
            'txapi/billing/recharges/[A-Za-z0-9_-]+', 'txapi/billing/payment-attempts/[1-9][0-9]*',
            'txapi/payment/webhook/[A-Za-z][A-Za-z0-9_-]{0,31}/[A-Za-z0-9_-]{1,128}',
            'txapi/admin/[^/]+/native/billing/reconciliation',
            'txapi/auth/sessions', 'txapi/orders', 'txapi/orders/[A-Za-z0-9_-]+',
            'txapi/orders/[A-Za-z0-9_-]+/detail', 'txapi/me/credential-operations/cp_[a-f0-9]{32}',
            'txapi/node/v1/(?:config|users|machine/nodes|audit/rules)',
            'txapi/extensions/[a-z][a-z0-9_]{0,63}/(?:presentation|resources/ui/[A-Za-z0-9_./-]+)',
            'txapi/admin/[^/]+/extensions/[a-z][a-z0-9_]{0,63}/(?:presentation|resources/ui/[A-Za-z0-9_./-]+)',
        ],
        'POST' => [
            'gateway/v1/core/(?:user|admin)/operations/[a-z][a-z0-9.-]{1,100}',
            'txapi/auth/(?:login|admin/login|register|logout|password)',
            'txapi/me/access-subjects', 'txapi/me/access-subjects/[1-9][0-9]*/(?:revoke|rotate)',
            'txapi/me/credential-operations/cp_[a-f0-9]{32}/recover',
            'txapi/me/subscription-deliveries', 'txapi/orders', 'txapi/orders/[A-Za-z0-9_-]+/(?:cancel|checkout)',
            'txapi/me/subscription-links', 'txapi/me/subscription-links/link_[a-f0-9]{32}/revoke',
            'txapi/subscription-links/link_[a-f0-9]{32}/redeem',
            'txapi/billing/recharges', 'txapi/billing/recharges/[A-Za-z0-9_-]+/checkout',
            'txapi/billing/payment-attempts/[1-9][0-9]*/query',
            'txapi/payment/webhook/[A-Za-z][A-Za-z0-9_-]{0,31}/[A-Za-z0-9_-]{1,128}',
            'txapi/internal/native/v1/(?:user|admin)/operations/[a-z][a-z0-9.-]{1,100}',
            'txapi/admin/[^/]+/native/plans/publications', 'txapi/admin/[^/]+/native/billing/refunds',
            'txapi/node/v1/(?:handshake|report|machine/status|audit/report)',
            'txapi/node/v2/usage/(?:submit|batch/get)',
            'txapi/admin/[^/]+/subscriptions/[1-9][0-9]*/operations/(?:suspend|resume|revoke|expire|reset)',
            'txapi/admin/[^/]+/native/accounts',
            'txapi/extensions/[a-z][a-z0-9_]{0,63}/operations/[a-z][a-z0-9.-]{0,63}',
            'txapi/admin/[^/]+/extensions/[a-z][a-z0-9_]{0,63}/operations/[a-z][a-z0-9.-]{0,63}',
            'txapi/admin/[^/]+/extensions/[a-z][a-z0-9_]{0,63}/configuration',
        ],
        'PATCH' => ['txapi/me/preferences', 'txapi/admin/[^/]+/native/accounts/[1-9][0-9]*/status',
            'txapi/admin/[^/]+/native/plan-offers/[1-9][0-9]*/availability'],
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
