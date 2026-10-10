<?php

namespace Tests\Unit\Support\Database;

use App\Support\Database\NativeTableName;
use PHPUnit\Framework\TestCase;

class NativeTableNameTest extends TestCase
{
    public function test_defaults_to_existing_legacy_schema(): void
    {
        $this->assertSame('v2_order', NativeTableName::resolve('v2_order'));
        $this->assertSame('v2_traffic_batch', NativeTableName::resolve('v2_traffic_batch'));
    }

    public function test_explicit_native_name_is_deterministic(): void
    {
        $this->assertSame('tx_order', NativeTableName::resolve('v2_order', true));
        $this->assertSame('tx_user', NativeTableName::resolve('v2_user', true));
    }

    public function test_rejects_untrusted_sql_identifiers(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        NativeTableName::resolve('v2_order; DROP TABLE users');
    }
}
