<?php

namespace App\Domains\Identity;

use App\Domains\AuthorityMigration\AuthorityWriterFence;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdminAccountModeration
{
    /** Freeze the complete bounded selection before changing any account. */
    public function ban(array $userIds, int $adminId): int
    {
        if ($userIds === [] || count($userIds) > 500
            || array_filter($userIds, static fn ($id) => !is_int($id) || $id < 1)
            || count(array_unique($userIds)) !== count($userIds)) {
            throw ValidationException::withMessages(['user_ids' => 'Select between 1 and 500 distinct users']);
        }

        return DB::transaction(static function () use ($userIds, $adminId): int {
            app(AuthorityWriterFence::class)->admit('admin.user.bulk-ban');
            $users = User::query()->whereIn('id', $userIds)->orderBy('id')
                ->lockForUpdate()->get();
            if ($users->count() !== count($userIds)
                || $users->contains(static fn (User $user) => $user->is_admin || $user->is_staff
                    || (int) $user->id === $adminId)) {
                throw ValidationException::withMessages(['user_ids' => 'Selection contains missing or protected users']);
            }

            // Retain bulk SQL semantics and revoke sessions under the same account locks.
            User::query()->whereIn('id', $users->modelKeys())->update(['banned' => 1]);
            foreach ($users as $user) {
                (new AuthService($user))->removeAllSessions();
            }
            return $users->count();
        });
    }
}
