<?php

namespace Tests\Feature\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class IndexedControlMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'indexed_controls_testing',
            'database.connections.indexed_controls_testing' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
                'foreign_key_constraints' => true,
            ]]);
        DB::purge('indexed_controls_testing');
    }

    protected function tearDown(): void
    {
        DB::purge('indexed_controls_testing');
        parent::tearDown();
    }

    /** @dataProvider indexedControls */
    public function test_indexed_controls_roll_back_and_reapply_without_losing_original_rows(string $table, string $file): void
    {
        Schema::create($table, static function (Blueprint $blueprint): void {
            $blueprint->integer('id', true);
            $blueprint->string('label');
        });
        DB::table($table)->insert(['id' => 1, 'label' => 'retained']);
        $migration = require database_path('migrations/'.$file);
        foreach ([1, 2] as $_cycle) {
            $migration->up();
            $this->assertTrue(Schema::hasColumn($table, 'sort'));
            $this->assertTrue(Schema::hasIndex($table, ['sort']));
            $migration->down();
            $this->assertFalse(Schema::hasColumn($table, 'sort'));
            $this->assertFalse(Schema::hasIndex($table, ['sort']));
            $this->assertSame(['id' => 1, 'label' => 'retained'], (array) DB::table($table)->first());
        }
    }

    public static function indexedControls(): array
    {
        return [
            'route controls' => ['tx_server_route', '2026_09_23_000001_add_route_controls.php'],
            'notice sort' => ['tx_notice', '2025_01_12_190315_add_sort_to_tx_notice_table.php'],
        ];
    }
}
