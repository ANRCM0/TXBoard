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
        if (!is_array($abilities) || $abilities === []
            || in_array('*', $abilities, true)
            || array_diff($abilities, \App\Services\AgentOps\AgentAbility::ALL) !== []) {
            return response()->json(['message' => 'Invalid Agent scopes'], 403);
        }

        $request->setUserResolver(static fn () => $user);

        return $next($request);
    }
}
