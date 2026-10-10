<?php

namespace Tests\Unit\Support\Audit;

use App\Support\Audit\MySqlSchemaInventory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class MySqlSchemaInventoryTest extends TestCase
{
    public function test_preserves_index_column_order_without_exporting_defaults_or_sensitive_rows(): void
    {
        $columns = [
            (object) ['table_name' => 'tx_traffic_batch', 'column_name' => 'server_id',
                'data_type' => 'bigint', 'is_nullable' => 'NO',
                'column_default' => 'DO_NOT_EXPORT_THIS_SECRET'],
            (object) ['table_name' => 'tx_traffic_batch', 'column_name' => 'batch_id',
                'data_type' => 'varchar', 'is_nullable' => 'NO'],
        ];
        $indexes = [
            (object) ['table_name' => 'tx_traffic_batch', 'index_name' => 'uq_batch',
                'non_unique' => 0, 'seq_in_index' => 2, 'column_name' => 'batch_id'],
            (object) ['table_name' => 'tx_traffic_batch', 'index_name' => 'uq_batch',
                'non_unique' => 0, 'seq_in_index' => 1, 'column_name' => 'server_id'],
        ];
        $result = MySqlSchemaInventory::build($columns, $indexes);
        $this->assertSame(['server_id', 'batch_id'], $result['tables'][0]['indexes'][0]['columns']);
        $this->assertTrue($result['invariants'][2]['passed']);
        $this->assertStringNotContainsString('DO_NOT_EXPORT_THIS_SECRET', json_encode($result));
        $this->assertFalse($result['invariants'][0]['passed']);
        $this->assertFalse($result['invariants'][1]['passed']);
    }

    public function test_rejects_index_metadata_for_unknown_table(): void
    {
        $this->expectException(RuntimeException::class);
        MySqlSchemaInventory::build([], [(object) [
            'table_name' => 'unknown', 'index_name' => 'a', 'non_unique' => 0,
            'seq_in_index' => 1, 'column_name' => 'id',
        ]]);
    }

    public function test_nonunique_index_cannot_satisfy_transaction_invariant(): void
    {
        $tables = ['tx_order' => ['indexes' => [
            ['name' => 'idx_trade', 'unique' => false, 'columns' => ['trade_no']],
        ]]];
        $result = MySqlSchemaInventory::validate($tables);
        $this->assertFalse($result[1]['passed']);
    }

    public function test_native_schema_enforces_the_same_billing_and_traffic_keys(): void
    {
        $tables = [
            'tx_user' => ['indexes' => [['unique' => true, 'columns' => ['email']]]],
            'tx_order' => ['indexes' => [['unique' => true, 'columns' => ['trade_no']]]],
            'tx_traffic_batch' => ['indexes' => [['unique' => true, 'columns' => ['server_id', 'batch_id']]]],
        ];
        $result = MySqlSchemaInventory::validate($tables);
        $this->assertSame(['tx_user', 'tx_order', 'tx_traffic_batch'], array_column($result, 'table'));
        $this->assertSame([true, true, true], array_column($result, 'passed'));
    }

    public function test_missing_native_tables_fail_the_uniqueness_invariants(): void
    {
        $checks = MySqlSchemaInventory::validate([]);
        $this->assertSame([false, false, false], array_column($checks, 'passed'));
    }
}
