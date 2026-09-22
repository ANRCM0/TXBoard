<?php

namespace App\Services\AgentOps;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class AgentAbility
{
    public const SYSTEM_READ = 'agent:system:read';
    public const MACHINES_READ = 'agent:machines:read';
    public const NODES_READ = 'agent:nodes:read';
    public const METRICS_READ = 'agent:metrics:read';
    public const TRAFFIC_READ = 'agent:traffic:read';
    public const AUDIT_READ = 'agent:audit:read';
    public const INSIGHTS_READ = 'agent:insights:read';
    public const NODES_SYNC = 'agent:nodes:sync';
    public const NODES_DIAGNOSE = 'agent:nodes:diagnose';
    public const NODES_OPERATE = 'agent:nodes:operate';
    public const NODES_WRITE = 'agent:nodes:write';
    public const USERS_READ = 'agent:users:read';
    public const USERS_WRITE = 'agent:users:write';
    public const SYSTEM_DANGEROUS = 'agent:system:dangerous';

    public const DEFAULT_READ = [
        self::SYSTEM_READ,
        self::MACHINES_READ,
        self::NODES_READ,
        self::METRICS_READ,
        self::TRAFFIC_READ,
        self::AUDIT_READ,
        self::INSIGHTS_READ,
        self::NODES_DIAGNOSE,
    ];

    public const ALL = [
        self::SYSTEM_READ,
        self::MACHINES_READ,
        self::NODES_READ,
        self::METRICS_READ,
        self::TRAFFIC_READ,
        self::AUDIT_READ,
        self::INSIGHTS_READ,
        self::NODES_SYNC,
        self::NODES_DIAGNOSE,
        self::NODES_OPERATE,
        self::NODES_WRITE,
        self::USERS_READ,
        self::USERS_WRITE,
        self::SYSTEM_DANGEROUS,
    ];

    public static function assert(Request $request, string $ability): void
    {
        $token = $request->user()?->currentAccessToken();
        if (!$token || !$token->can($ability)) {
            throw new AccessDeniedHttpException("Agent token is missing required ability: {$ability}");
        }
    }

    public static function validate(array $abilities): array
    {
        $abilities = array_values(array_unique(array_map('strval', $abilities)));
        foreach ($abilities as $ability) {
            if (!in_array($ability, self::ALL, true)) {
                throw new \InvalidArgumentException("Unknown Agent ability: {$ability}");
            }
        }
        return $abilities;
    }
}
