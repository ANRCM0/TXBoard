<?php

namespace Tests\Feature\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class TrafficResetMigrationAuthorityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'traffic_migration_testing',
            'database.connections.traffic_migration_testing' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
                'foreign_key_constraints' => true,
            ]]);
        DB::purge('traffic_migration_testing');
    }

    protected function tearDown(): void
    {
        DB::purge('traffic_migration_testing');
        parent::tearDown();
    }

    /** @dataProvider migrationModes */
    public function test_legacy_backfill_never_runs_as_native_preparation(string $mode, bool $initial): void
    {
        config(['core_installation.mode' => $mode]);
        Schema::create('tx_user', static function (Blueprint $table) use ($initial): void {
            $table->integer('id', true);
            $table->integer('expired_at')->nullable();
            if (!$initial) $table->integer('next_reset_at')->nullable();
        });
        if ($mode === 'native') Artisan::shouldReceive('call')->never();
        else Artisan::shouldReceive('call')->once()->with('reset:traffic', ['--fix-null' => true])->andReturn(0);

        if ($initial) {
            require_once database_path('migrations/2025_06_21_000003_add_traffic_reset_fields_to_users.php');
            $migration = new \AddTrafficResetFieldsToUsers;
        } else {
            $migration = require database_path('migrations/2026_04_19_000001_backfill_users_next_reset_at.php');
        }
        $migration->up();

        $this->assertTrue(Schema::hasColumn('tx_user', 'next_reset_at'));
        if ($initial) {
            $this->assertTrue(Schema::hasColumn('tx_user', 'last_reset_at'));
            $this->assertTrue(Schema::hasColumn('tx_user', 'reset_count'));
            $this->assertTrue(Schema::hasIndex('tx_user', ['next_reset_at']));
        }
    }

    public static function migrationModes(): array
    {
        return [
            'native initial schema' => ['native', true],
            'legacy initial schema' => ['legacy', true],
            'native later backfill' => ['native', false],
            'legacy later backfill' => ['legacy', false],
        ];
    }
}
