<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/** Shared serialization for the current and compatibility native settings writers. */
final class NativeSettingsStorage
{
    public function lock(): void
    {
        if (DB::transactionLevel() === 0) throw new \LogicException('SETTINGS_TRANSACTION_REQUIRED');
        DB::table('tx_settings')->insertOrIgnore(['name' => 'native_management_settings_lock', 'value' => '1',
            'created_at' => time(), 'updated_at' => time()]);
        DB::table('tx_settings')->where('name', 'native_management_settings_lock')->lockForUpdate()->first();
    }

    public function persist(array $values): void
    {
        if (DB::transactionLevel() === 0) throw new \LogicException('SETTINGS_TRANSACTION_REQUIRED');
        foreach ($values as $name => $value) Setting::createOrUpdate($name, $value);
        DB::afterCommit(static fn () => admin_setting([]));
    }
}
