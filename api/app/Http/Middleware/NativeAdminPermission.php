<?php

namespace App\Http\Middleware;

use App\Core\Http\TxapiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** New native administrative capabilities require an explicit live grant. */
final class NativeAdminPermission
{
    public function handle(Request $request, Closure $next, string $permission)
    {
        $actor = Auth::guard('sanctum')->user();
        if (!$actor || !$actor->is_admin || $actor->banned || !$actor->tokenCan($permission)
            || !DB::table('tx_internal_actor_grant')->useWritePdo()->where('user_id', $actor->id)
                ->where('permission', $permission)->exists()) {
            return TxapiResponse::error($request, 'FORBIDDEN', 'Administrative capability required', 403);
        }
        return $next($request);
    }
}
