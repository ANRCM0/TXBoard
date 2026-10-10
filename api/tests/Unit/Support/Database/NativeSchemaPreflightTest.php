<?php

namespace Tests\Unit\Support\Database;

use App\Support\Database\NativeSchemaPreflight;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NativeSchemaPreflightTest extends TestCase
{
    public function test_empty_or_framework_only_schema_is_allowed(): void
    {
        NativeSchemaPreflight::inspect([], false);
        NativeSchemaPreflight::inspect(['migrations', 'failed_jobs', 'personal_access_tokens'], false);
        $this->assertTrue(true);
    }

    public function test_native_schema_is_allowed(): void
    {
        NativeSchemaPreflight::inspect(['migrations', 'tx_user', 'tx_order'], true);
        $this->assertTrue(true);
    }

    public function test_schema_qualified_mysql_tables_are_recognized(): void
    {
        NativeSchemaPreflight::inspect(['txboard.migrations', 'txboard.tx_user', 'txboard.tx_order'], true);
        NativeSchemaPreflight::inspect(['txboard.migrations', 'txboard.failed_jobs'], false);
        $this->assertTrue(true);
    }

    public function test_qualified_legacy_table_is_blocked(): void
    {
        $this->expectException(RuntimeException::class);
        NativeSchemaPreflight::inspect(['txboard.tx_user', 'txboard.v2_user'], true);
    }

    public function test_unknown_existing_schema_is_blocked_before_creation(): void
    {
        $this->expectException(RuntimeException::class);
        NativeSchemaPreflight::inspect(['migrations', 'some_existing_user_table'], false);
    }

    public function test_previous_migration_history_without_native_user_is_blocked(): void
    {
        $this->expectException(RuntimeException::class);
        NativeSchemaPreflight::inspect(['migrations'], true);
    }

    public function test_historical_prefix_is_blocked_even_if_native_table_exists(): void
    {
        $this->expectException(RuntimeException::class);
        NativeSchemaPreflight::inspect(['tx_user', 'v' . '2_user'], true);
    }
}
