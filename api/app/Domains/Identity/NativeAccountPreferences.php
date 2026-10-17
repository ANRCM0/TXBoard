<?php

namespace App\Domains\Identity;

use App\Domains\Identity\Contracts\AccountPreferences;
use App\Domains\Identity\Contracts\PreferenceChange;
use App\Domains\Identity\Contracts\Preferences;
use App\Domains\Installation\Contracts\InstallationAdmission;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class NativeAccountPreferences implements AccountPreferences
{
    public function __construct(private readonly AccountSqlPhase $phase, private readonly InstallationAdmission $installation) {}

    public function read(int $userId): Preferences
    {
        return DB::transaction(function () use ($userId): Preferences {
            if ($this->installation->inspect()['mode'] === 'native') $this->installation->assertNativeRead();
            else $this->installation->assertRuntime();
            return $this->result(User::query()->useWritePdo()->whereKey($userId)->sharedLock()->firstOrFail());
        }, 3);
    }

    public function update(int $userId, PreferenceChange $change): Preferences
    {
        return $this->phase->run(AccountSqlPhase::USER_ACCOUNT_PREFERENCES, function () use ($userId, $change): Preferences {
            $user = User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            if ((bool) $user->banned) throw new \DomainException('IDENTITY_ACTOR_UNAVAILABLE');
            if ($change->remindExpire !== null) $user->remind_expire = $change->remindExpire;
            if ($change->remindTraffic !== null) $user->remind_traffic = $change->remindTraffic;
            $user->saveOrFail();
            return $this->result($user);
        }, 3);
    }

    private function result(User $user): Preferences
    {
        if ((bool) $user->banned) throw new \DomainException('IDENTITY_ACTOR_UNAVAILABLE');
        return new Preferences((bool) $user->remind_expire, (bool) $user->remind_traffic);
    }
}
