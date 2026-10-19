<?php

namespace App\Domains\Identity;

use App\Domains\Identity\Internal\CurrentSessionAuthorization;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

final class NativeCurrentSessionAuthorization implements CurrentSessionAuthorization
{
    public function assertCurrentLocked(int $userId, ?string $permission = null): void
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('CURRENT_SESSION_TRANSACTION_REQUIRED');
        $actor = Auth::guard('sanctum')->user();
        if (!$actor instanceof User || (int) $actor->id !== $userId
            || !$actor->currentAccessToken() instanceof PersonalAccessToken) {
            throw new AuthenticationException();
        }
        // Every built-in owner takes the account before its aggregate locks.
        $current = User::query()->whereKey($userId)->lockForUpdate()->first();
        $token = PersonalAccessToken::query()->whereKey($actor->currentAccessToken()->getKey())
            ->where('tokenable_id', $userId)->where('tokenable_type', $actor->getMorphClass())->sharedLock()->first();
        $expiration = config('sanctum.expiration');
        if ($token === null || $token->expires_at?->isPast()
            || $expiration && !$token->created_at?->gt(now()->subMinutes((int) $expiration))) {
            throw new AuthenticationException();
        }
        if ($current === null || $current->banned) abort(403);
        if ($permission !== null) {
            $grant = DB::table('tx_internal_actor_grant')->where('user_id', $userId)
                ->where('permission', $permission)->sharedLock()->first();
            if (!$current->is_admin || !$token->can($permission) || $grant === null) abort(403);
        }
    }
}
