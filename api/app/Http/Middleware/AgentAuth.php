<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;

class AgentAuth
{
    public function handle($request, Closure $next)
    {
        $user = Auth::guard('sanctum')->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $token = $user->currentAccessToken();
        if (!$user->is_admin || !$token || !str_starts_with((string) $token->name, 'agent:')) {
            return response()->json(['message' => 'Agent credential required'], 403);
        }

        // A renamed general-purpose Sanctum token with '*' is not an Agent
        // credential. It must carry explicit, least-privilege Agent scopes.
        $abilities = $token->abilities ?? null;
        $validScope = static function (mixed $ability): bool {
            if (!is_string($ability)) {
                return false;
            }
            if (in_array($ability, \App\Services\AgentOps\AgentAbility::ALL, true)
                || $ability === \App\Services\AgentOps\AgentTargetScope::RESTRICTED) {
                return true;
            }
            foreach ([\App\Services\AgentOps\AgentTargetScope::NODE_PREFIX,
                \App\Services\AgentOps\AgentTargetScope::MACHINE_PREFIX] as $prefix) {
                if (str_starts_with($ability, $prefix)) {
                    $id = substr($ability, strlen($prefix));
                    return (bool) preg_match('/^[1-9][0-9]*$/D', $id)
                        && filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;
                }
            }
            return false;
        };
        $hasRestricted = is_array($abilities)
            && in_array(\App\Services\AgentOps\AgentTargetScope::RESTRICTED, $abilities, true);
        $hasTargetId = is_array($abilities)
            && count(array_filter($abilities, static fn ($ability) =>
                is_string($ability) && (
                    str_starts_with($ability, \App\Services\AgentOps\AgentTargetScope::NODE_PREFIX)
                    || str_starts_with($ability, \App\Services\AgentOps\AgentTargetScope::MACHINE_PREFIX)
                ))) > 0;
        if (!is_array($abilities) || $abilities === []
            || in_array('*', $abilities, true)
            || array_filter($abilities, static fn ($ability) => !$validScope($ability)) !== []
            || $hasRestricted !== $hasTargetId) {
            return response()->json(['message' => 'Invalid Agent scopes'], 403);
        }

        $request->setUserResolver(static fn () => $user);

        return $next($request);
    }
}
