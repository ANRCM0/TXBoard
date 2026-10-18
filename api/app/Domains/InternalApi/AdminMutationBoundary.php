<?php

namespace App\Domains\InternalApi;

use App\Domains\Installation\InstallationAuthority;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/** Shared SQL boundary only for the explicitly bound non-financial CRUD adapters. */
final class AdminMutationBoundary
{
    public function run(User $actor, string $permission, \Closure $operation): mixed
    {
        if (DB::transactionLevel() !== 0) throw new \LogicException('ADMIN_OUTER_TRANSACTION_UNSUPPORTED');
        return DB::transaction(function () use ($actor, $permission, $operation): mixed {
            app(InstallationAuthority::class)->assertNativeMutation();
            $current = User::query()->whereKey($actor->id)->sharedLock()->first();
            $token = PersonalAccessToken::query()->whereKey($actor->currentAccessToken()?->getKey())
                ->where('tokenable_id', $actor->id)->where('tokenable_type', $actor->getMorphClass())->sharedLock()->first();
            if ($token === null || $token->expires_at?->isPast()) abort(401);
            $grant = DB::table('tx_internal_actor_grant')->where('user_id', $actor->id)
                ->where('permission', $permission)->sharedLock()->first();
            if ($current === null || !$current->is_admin || $current->banned || $grant === null
                || !$token->can($permission)) abort(403);
            return $operation();
        });
    }
}
