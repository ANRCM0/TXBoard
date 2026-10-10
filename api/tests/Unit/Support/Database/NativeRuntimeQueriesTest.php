<?php

namespace Tests\Unit\Support\Database;

use App\Models\Order;
use App\Models\Server;
use App\Support\Database\NativeTableName;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Tests\TestCase;

class NativeRuntimeQueriesTest extends TestCase
{
    public function test_model_raw_query_and_validation_share_legacy_name(): void
    {
        Config::set('database_native.native_tables', false);

        $this->assertSame('v2_order', (new Order())->getTable());
        $this->assertSame('v2_order', NativeTableName::runtime('v2_order'));
        $this->assertSame('select * from "v2_order"', DB::table(NativeTableName::runtime('v2_order'))->toSql());
        $this->assertSame('exists:v2_server,id', (string) Rule::exists(NativeTableName::runtime('v2_server'), 'id'));
    }

    public function test_model_raw_query_and_validation_share_native_name(): void
    {
        Config::set('database_native.native_tables', true);

        $this->assertSame('tx_order', (new Order())->getTable());
        $this->assertSame('tx_order', NativeTableName::runtime('v2_order'));
        $this->assertSame('tx_server', (new Server())->getTable());
        $this->assertSame('select * from "tx_order"', DB::table(NativeTableName::runtime('v2_order'))->toSql());
        $this->assertSame('exists:tx_server,id', (string) Rule::exists(NativeTableName::runtime('v2_server'), 'id'));
    }
}
