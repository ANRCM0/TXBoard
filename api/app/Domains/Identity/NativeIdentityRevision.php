<?php

namespace App\Domains\Identity;

use App\Models\User;

final class NativeIdentityRevision
{
    /** An opaque concurrency token; password hashes and provider credentials never leave Identity. */
    public static function forUser(User $user): string
    {
        return hash_hmac('sha256', json_encode([(string) $user->id, (string) $user->email,
            (string) $user->getRawOriginal('password'), (bool) $user->banned, (bool) $user->is_admin,
            (bool) $user->is_staff], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}
