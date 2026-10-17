<?php

namespace App\Core\Host\Modules;

use App\Core\Host\Execution\InvocationScope;
use App\Core\Kernel\Execution\ActorRef;
use App\Extensions\Contracts\ExtensionFailure;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class ExtensionActors
{
    public function authorize(string $kind, ?string $permission): User
    {
        Auth::forgetGuards();
        $actor = Auth::guard('sanctum')->user();
        $current = $actor instanceof User ? User::query()->useWritePdo()->find($actor->id) : null;
        if ($current === null || (bool) $current->banned) throw new ExtensionFailure('MODULE_ACTOR_REJECTED', 403);
        if ($kind === 'admin' && (!(bool) $current->is_admin || $permission === null || !$actor->tokenCan($permission)
            || !DB::table('tx_internal_actor_grant')->useWritePdo()->where('user_id', $current->id)->where('permission', $permission)->exists())) {
            throw new ExtensionFailure('MODULE_PERMISSION_DENIED', 403);
        }
        app(InvocationScope::class)->actor(new ActorRef($kind, (string) $current->id));
        return $current;
    }
}
