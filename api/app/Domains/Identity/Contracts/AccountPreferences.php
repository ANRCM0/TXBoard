<?php

namespace App\Domains\Identity\Contracts;

interface AccountPreferences
{
    public function read(int $userId): Preferences;
    public function update(int $userId, PreferenceChange $change): Preferences;
}
