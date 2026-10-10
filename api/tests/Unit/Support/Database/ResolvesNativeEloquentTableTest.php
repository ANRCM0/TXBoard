<?php

namespace Tests\Unit\Support\Database;

use App\Models\Order;
use App\Models\User;
use App\Models\WalletRecharge;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ResolvesNativeEloquentTableTest extends TestCase
{
    public function test_legacy_tables_remain_default(): void
    {
        Config::set('database_native.native_tables', false);
        $this->assertSame('v2_user', (new User())->getTable());
        $this->assertSame('v2_order', (new Order())->getTable());
        $this->assertSame('v2_wallet_recharge', (new WalletRecharge())->getTable());
    }

    public function test_explicit_native_mode_resolves_opted_in_models(): void
    {
        Config::set('database_native.native_tables', true);
        $this->assertSame('tx_user', (new User())->getTable());
        $this->assertSame('tx_order', (new Order())->getTable());
        $this->assertSame('tx_wallet_recharge', (new WalletRecharge())->getTable());
    }
}
