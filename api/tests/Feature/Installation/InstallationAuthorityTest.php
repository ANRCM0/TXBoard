<?php

namespace Tests\Feature\Installation;

use App\Domains\Installation\InstallationAuthority;
use App\Domains\Installation\InstallationAuthorityException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InstallationAuthorityTest extends TestCase
{
    private const ID = '550e8400-e29b-41d4-a716-446655440000';
    private const OTHER_ID = '550e8400-e29b-41d4-a716-446655440001';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.installation_authority_fixture' => ['driver' => 'sqlite',
            'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
            'database.default' => 'installation_authority_fixture',
            'core_installation.mode' => 'legacy', 'core_installation.worker_epoch' => '1']);
        DB::purge('installation_authority_fixture');
        Schema::create('tx_authority_writer_fence', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('scope', 32);
            $table->string('version', 32);
            $table->enum('authority', ['legacy']);
            $table->enum('phase', ['open', 'prepared']);
            $table->unsignedBigInteger('authority_epoch');
            $table->string('prepare_key', 64)->nullable();
            $table->unsignedBigInteger('row_version');
            $table->unsignedBigInteger('updated_at');
            $table->char('content_hash', 64);
            $table->char('inventory_hash', 64)->nullable();
        });
        $this->migration()->up();
        Schema::create('tx_user', function (Blueprint $table): void {
            $table->id();
            $table->boolean('is_admin')->default(false);
            foreach (['plan_id', 'group_id', 'invite_user_id', 'telegram_id', 'speed_limit', 'device_limit',
                'last_reset_at', 'next_reset_at'] as $field) {
                $table->unsignedBigInteger($field)->nullable();
            }
            foreach (['expired_at', 'transfer_enable', 'u', 'd', 'balance', 'commission_balance',
                'reset_count', 'discount', 'commission_rate', 'commission_type', 't'] as $field) {
                $table->unsignedBigInteger($field)->default(0);
            }
        });
        foreach (['migrations', 'tx_settings', 'tx_subscribe_templates', 'tx_order', 'jobs', 'failed_jobs', 'tx_subscription',
            'tx_usage_period', 'tx_money_journal', 'tx_legacy_subscription_map',
            'tx_authority_prepare_receipt', 'tx_authority_inventory_upgrade'] as $table) {
            Schema::create($table, fn (Blueprint $blueprint) => $blueprint->id());
        }
    }

    protected function tearDown(): void
    {
        DB::purge('installation_authority_fixture');
        parent::tearDown();
    }

    public function test_native_initialization_is_durable_in_production_and_never_runtime_ready(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['core_shadow.isolated_copy' => false, 'authority_writer_fence.enabled' => false,
            'core_installation.mode' => 'native']);
        DB::table('tx_user')->insert(['is_admin' => true]);
        DB::table('tx_settings')->insert(['id' => 1]);
        DB::table('tx_subscribe_templates')->insert(['id' => 1]);
        DB::table('migrations')->insert(['id' => 1]);
        $result = $this->authority()->initialize(self::ID, 'native', 'first-install');
        $this->assertSame(['initialized' => true, 'mode' => 'native', 'phase' => 'initializing',
            'installation_id' => self::ID, 'authority_epoch' => '1', 'schema_version' => 1,
            'domain_version' => 1], $result);
        $this->assertSame($result, $this->authority()->inspect());
        $this->assertSame(1, DB::table('tx_authority_writer_fence')->count());
        $this->reject('NATIVE_NOT_READY', fn () => $this->authority()->assertRuntime());
        $this->reject('NATIVE_WRITER_REJECTED', fn () => DB::transaction(fn () => $this->authority()->assertLegacyWriter()));
    }

    public function test_replay_is_stable_after_catalog_data_and_mode_cannot_change(): void
    {
        $first = $this->authority()->initialize(self::ID, 'native', 'first-install');
        $stored = (array) DB::table('tx_authority_writer_fence')->first();
        DB::table('tx_order')->insert(['id' => 1]);
        $this->assertSame($first, $this->authority()->initialize(self::ID, 'native', 'first-install'));
        $this->assertSame($stored, (array) DB::table('tx_authority_writer_fence')->first());
        foreach ([[self::OTHER_ID, 'native', 'first-install'], [self::ID, 'legacy', 'first-install'],
            [self::ID, 'native', 'another-key']] as $request) {
            $this->reject('BOOTSTRAP_CONFLICT', fn () => $this->authority()->initialize(...$request));
        }
    }

    public function test_native_bootstrap_rejects_every_non_metadata_table_with_data(): void
    {
        foreach (['tx_order', 'jobs', 'failed_jobs', 'tx_subscription', 'tx_usage_period', 'tx_money_journal',
            'tx_legacy_subscription_map'] as $table) {
            DB::table($table)->insert(['id' => 1]);
            $this->reject('DATABASE_NOT_EMPTY', fn () => $this->authority()->initialize(self::ID, 'native', 'first-install'));
            $this->assertSame(0, DB::table('tx_authority_writer_fence')->count());
            DB::table($table)->delete();
        }
        Schema::create('unknown_extension_state', fn (Blueprint $table) => $table->id());
        DB::table('unknown_extension_state')->insert(['id' => 1]);
        $this->reject('DATABASE_NOT_EMPTY', fn () => $this->authority()->initialize(self::ID, 'native', 'first-install'));
    }

    public function test_bootstrap_rejects_regular_users_and_administrator_assets(): void
    {
        DB::table('tx_user')->insert(['is_admin' => false]);
        $this->reject('DATABASE_NOT_EMPTY', fn () => $this->authority()->initialize(self::ID, 'native', 'first-install'));
        DB::table('tx_user')->delete();
        foreach (['plan_id', 'group_id', 'invite_user_id', 'telegram_id', 'speed_limit', 'device_limit',
            'last_reset_at', 'next_reset_at', 'expired_at', 'transfer_enable', 'u', 'd', 'balance',
            'commission_balance', 'reset_count', 'discount', 'commission_rate', 'commission_type', 't'] as $field) {
            DB::table('tx_user')->insert(['is_admin' => true, $field => 1]);
            $this->reject('DATABASE_NOT_EMPTY', fn () => $this->authority()->initialize(self::ID, 'native', 'first-install'));
            DB::table('tx_user')->delete();
        }
    }

    public function test_legacy_bootstrap_preserves_existing_business_data(): void
    {
        DB::table('tx_order')->insert(['id' => 1]);
        $this->authority()->initialize(self::ID, 'legacy', 'legacy-install');
        $this->authority()->assertRuntime();
        DB::transaction(fn () => $this->authority()->assertLegacyWriter());
        $this->assertSame(1, DB::table('tx_order')->count());
        $this->reject('TRANSACTION_REQUIRED', fn () => $this->authority()->assertLegacyWriter());
    }

    public function test_catalog_mutation_requires_native_initializing_transaction_and_current_worker(): void
    {
        $this->reject('TRANSACTION_REQUIRED', fn () => $this->authority()->assertCatalogMutation());
        $this->reject('CATALOG_REJECTED', fn () => DB::transaction(fn () => $this->authority()->assertCatalogMutation()));
        $this->authority()->initialize(self::ID, 'native', 'first-install');
        $this->reject('WORKER_MISMATCH', fn () => DB::transaction(fn () => $this->authority()->assertCatalogMutation()));
        config(['core_installation.mode' => 'native']);
        DB::transaction(function (): void {
            $this->authority()->assertCatalogMutation();
            $this->assertSame(1, DB::transactionLevel());
        });
        config(['core_installation.worker_epoch' => '2']);
        $this->reject('WORKER_MISMATCH', fn () => DB::transaction(fn () => $this->authority()->assertCatalogMutation()));
    }

    public function test_native_record_cannot_downgrade_by_changing_process_configuration(): void
    {
        $this->authority()->initialize(self::ID, 'native', 'first-install');
        $this->reject('WORKER_MISMATCH', fn () => $this->authority()->assertRuntime());
        $this->reject('WORKER_MISMATCH', fn () => DB::transaction(fn () => $this->authority()->assertLegacyWriter()));
    }

    public function test_missing_native_record_and_invalid_worker_mode_fail_closed(): void
    {
        config(['core_installation.mode' => 'native']);
        $this->reject('RECORD_REQUIRED', fn () => $this->authority()->assertRuntime());
        $this->reject('RECORD_REQUIRED', fn () => $this->authority()->assertLegacyWriter());
        config(['core_installation.mode' => 'invalid']);
        $this->reject('WORKER_MISMATCH', fn () => $this->authority()->assertRuntime());
    }

    public function test_closed_record_hash_and_versions_are_validated(): void
    {
        $this->authority()->initialize(self::ID, 'native', 'first-install');
        $original = (array) DB::table('tx_authority_writer_fence')->first();
        foreach (['content_hash' => str_repeat('0', 64), 'schema_version' => 2, 'domain_version' => 2,
            'version' => 'core-installation/v99', 'authority_epoch' => 2, 'installation_id' => self::OTHER_ID,
            'bootstrap_key' => 'mutated-key', 'inventory_hash' => str_repeat('a', 64),
            'phase' => 'open', 'row_version' => 2] as $field => $value) {
            DB::table('tx_authority_writer_fence')->where('id', 1)->update([$field => $value]);
            $this->reject('STATE_INVALID', fn () => $this->authority()->inspect());
            DB::table('tx_authority_writer_fence')->where('id', 1)->update($original);
        }
    }

    public function test_historical_fence_is_not_reinitialized_or_rewritten(): void
    {
        $historical = ['id' => 1, 'scope' => 'admin-user-editor/v1', 'version' => 'authority-writer-fence/v1',
            'authority' => 'legacy', 'phase' => 'open', 'authority_epoch' => 1, 'prepare_key' => null,
            'row_version' => 1, 'updated_at' => 1, 'content_hash' => str_repeat('a', 64)];
        DB::table('tx_authority_writer_fence')->insert($historical);
        $before = (array) DB::table('tx_authority_writer_fence')->first();
        $this->reject('HISTORICAL_FENCE_PRESENT', fn () => $this->authority()->initialize(self::ID, 'legacy', 'legacy-install'));
        $this->authority()->assertRuntime();
        $this->authority()->assertLegacyWriter();
        $this->assertSame($before, (array) DB::table('tx_authority_writer_fence')->first());
    }

    public function test_orphaned_historical_receipts_prevent_both_initialization_modes(): void
    {
        foreach (['tx_authority_prepare_receipt', 'tx_authority_inventory_upgrade'] as $table) {
            DB::table($table)->insert(['id' => 1]);
            foreach (['legacy', 'native'] as $mode) {
                $this->reject('HISTORICAL_FENCE_PRESENT', fn () => $this->authority()->initialize(self::ID, $mode, 'first-install'));
            }
            DB::table($table)->delete();
        }
    }

    public function test_mixed_or_unknown_historical_scope_and_version_are_not_legacy_fallback(): void
    {
        $row = ['id' => 1, 'scope' => 'admin-user-editor/v1', 'version' => InstallationAuthority::VERSION,
            'authority' => 'legacy', 'phase' => 'open', 'authority_epoch' => 1,
            'row_version' => 1, 'updated_at' => 1, 'content_hash' => str_repeat('a', 64)];
        DB::table('tx_authority_writer_fence')->insert($row);
        $this->reject('STATE_INVALID', fn () => $this->authority()->assertRuntime());
        DB::table('tx_authority_writer_fence')->update(['version' => 'authority-writer-fence/v99']);
        $this->reject('STATE_INVALID', fn () => $this->authority()->assertRuntime());
        Schema::table('tx_authority_writer_fence', fn (Blueprint $table) => $table->dropColumn('domain_version'));
        $this->reject('STATE_INVALID', fn () => $this->authority()->assertRuntime());
    }

    public function test_missing_table_and_old_schema_are_only_legacy_compatible(): void
    {
        $this->migration()->down();
        $this->authority()->assertRuntime();
        $this->authority()->assertLegacyWriter();
        $this->reject('SCHEMA_REQUIRED', fn () => $this->authority()->initialize(self::ID, 'legacy', 'legacy-install'));
        config(['core_installation.mode' => 'native']);
        $this->reject('RECORD_REQUIRED', fn () => $this->authority()->assertRuntime());
        Schema::drop('tx_authority_writer_fence');
        $this->reject('RECORD_REQUIRED', fn () => $this->authority()->assertLegacyWriter());
        config(['core_installation.mode' => 'legacy']);
        $this->authority()->assertRuntime();
    }

    public function test_partial_schema_and_damaged_scope_cannot_hide_existing_installation_metadata(): void
    {
        $this->authority()->initialize(self::ID, 'legacy', 'legacy-install');
        DB::table('tx_authority_writer_fence')->where('id', 1)->update([
            'scope' => 'admin-user-editor/v1', 'version' => 'authority-writer-fence/v1']);
        Schema::table('tx_authority_writer_fence', fn (Blueprint $table) => $table->dropColumn('domain_version'));
        $this->reject('STATE_INVALID', fn () => $this->authority()->assertRuntime());
        $this->reject('STATE_INVALID', fn () => $this->authority()->assertLegacyWriter());
    }

    public function test_storage_errors_do_not_expose_sql_or_connection_details(): void
    {
        Schema::shouldReceive('hasTable')->andThrow(new \RuntimeException('secret-password sql-host.internal'));
        foreach ([fn () => $this->authority()->initialize(self::ID, 'native', 'first-install'),
            fn () => $this->authority()->inspect(), fn () => $this->authority()->assertRuntime(),
            fn () => $this->authority()->assertLegacyWriter()] as $operation) {
            $this->reject('STORAGE_FAILED', $operation);
        }
        DB::transaction(fn () => $this->reject('STORAGE_FAILED', fn () => $this->authority()->assertCatalogMutation()));
    }

    public function test_populated_installation_migration_cannot_be_rolled_back(): void
    {
        $this->authority()->initialize(self::ID, 'legacy', 'legacy-install');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('INSTALLATION_AUTHORITY_POPULATED_ROLLBACK_REFUSED');
        $this->migration()->down();
    }

    public function test_migration_preserves_existing_fence_evidence_and_both_enum_constraints(): void
    {
        $this->migration()->down();
        $historical = ['id' => 1, 'scope' => 'admin-user-editor/v1', 'version' => 'authority-writer-fence/v1',
            'authority' => 'legacy', 'phase' => 'prepared', 'authority_epoch' => 2, 'prepare_key' => 'historical',
            'row_version' => 2, 'updated_at' => 1, 'content_hash' => str_repeat('a', 64), 'inventory_hash' => null];
        DB::table('tx_authority_writer_fence')->insert($historical);
        $this->migration()->up();
        $this->assertSame($historical, (array) DB::table('tx_authority_writer_fence')->first(array_keys($historical)));
        foreach (['authority' => 'unsupported', 'phase' => 'unsupported'] as $field => $value) {
            try {
                DB::table('tx_authority_writer_fence')->where('id', 1)->update([$field => $value]);
                $this->fail('Expected enum constraint rejection');
            } catch (\Illuminate\Database\QueryException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_input_validation_does_not_create_authority_state(): void
    {
        foreach ([['not-a-uuid', 'native', 'valid-key'], [self::ID, 'other', 'valid-key'],
            [self::ID, 'native', ''], [self::ID, 'native', str_repeat('x', 65)]] as $request) {
            $this->reject('INPUT_INVALID', fn () => $this->authority()->initialize(...$request));
        }
        $this->assertSame(0, DB::table('tx_authority_writer_fence')->count());
    }

    public function test_versioned_schema_replay_rejects_arbitrary_enum_supersets(): void
    {
        $schema = new \App\Services\Subscription\ShadowSchemaV1();
        $definition = static function (Blueprint $table): void {
            $table->enum('authority', ['legacy']);
            $table->enum('phase', ['open', 'prepared']);
        };
        $schema->create('tx_authority_writer_fence', $definition);
        Schema::table('tx_authority_writer_fence', static function (Blueprint $table): void {
            $table->enum('authority', ['legacy', 'native', 'unrecognized'])->change();
            $table->enum('phase', ['open', 'prepared', 'initializing'])->change();
        });
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Shadow enum mismatch: tx_authority_writer_fence.authority');
        $schema->create('tx_authority_writer_fence', $definition);
    }

    private function authority(): InstallationAuthority
    {
        return app(InstallationAuthority::class);
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_15_000002_extend_installation_authority.php');
    }

    private function reject(string $code, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected installation authority rejection: '.$code);
        } catch (InstallationAuthorityException $error) {
            $this->assertSame('INSTALLATION_AUTHORITY_'.$code, $error->getMessage());
        }
    }
}
