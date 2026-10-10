<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Schema;

class InstallState
{
    public function hasAdministrator(): bool
    {
        try {
            if (!Schema::hasTable((new User())->getTable())) {
                return false;
            }

            return User::query()->where('is_admin', 1)->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    public function isInstalled(): bool
    {
        // Database state is authoritative. An environment marker can survive
        // a database reset/volume swap and must never be enough on its own.
        return $this->hasAdministrator();
    }
}
