<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class TxapiUser
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::guard('sanctum')->user();
        if (!$user instanceof User) {
            throw new AuthenticationException('TXAPI user token required');
        }
        if ($user->banned) {
            abort(403);
        }
        app(\App\Core\Host\Execution\InvocationScope::class)->actor(
            new \App\Core\Kernel\Execution\ActorRef('user', (string) $user->id));
        return $next($request);
    }
}
