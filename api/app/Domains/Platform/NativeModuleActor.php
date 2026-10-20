<?php

namespace App\Domains\Platform;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/** Revalidate browser identity and all grants inside the current owner's transaction. */
final class NativeModuleActor
{
    public static function assertLocked(User $actor, array $permissions): void
    {
        if (DB::transactionLevel() === 0) throw new \LogicException('MODULE_AUTHORIZATION_TRANSACTION_REQUIRED');
        $current = User::query()->whereKey($actor->id)->sharedLock()->first();
        $token = PersonalAccessToken::query()->whereKey($actor->currentAccessToken()?->getKey())
            ->where('tokenable_id', $actor->id)->where('tokenable_type', $actor->getMorphClass())->sharedLock()->first();
        if ($token === null || $token->expires_at?->isPast()) abort(401);
        if ($current === null || !$current->is_admin || $current->banned) abort(403);
        foreach (array_unique($permissions) as $permission) {
            if (!is_string($permission) || !$token->can($permission) || !DB::table('tx_internal_actor_grant')
                ->where('user_id', $actor->id)->where('permission', $permission)->sharedLock()->exists()) abort(403);
        }
    }

    public static function permitted(User $actor, string $permission): bool
    {
        return $actor->tokenCan($permission) && DB::table('tx_internal_actor_grant')->useWritePdo()
            ->where('user_id', $actor->id)->where('permission', $permission)->exists();
    }

    public static function deploymentId(User $actor, string $code, string $action, string $key): string
    {
        $hash = hash('sha256', 'native-module-admin/v1:'.$actor->id.':'.$code.':'.$action.':'.$key);
        return substr($hash, 0, 8).'-'.substr($hash, 8, 4).'-5'.substr($hash, 13, 3).'-a'.substr($hash, 17, 3).'-'.substr($hash, 20, 12);
    }
}
