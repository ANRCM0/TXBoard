<?php

namespace Tests\Feature\CredentialProviderFixture;

use App\Domains\CredentialProviderFixture\FixtureLifecycleService;
use App\Domains\CredentialProviderFixture\FixturePluginBinding;
use App\Domains\CredentialProviderFixture\FixturePluginLifecycleGate;
use App\Domains\CredentialProviderFixture\FixtureProviderService;
use App\Domains\CredentialProviderFixture\FixtureScope;
use App\Models\Plugin;
use App\Services\Module\Adapters\PluginLifecycleAdapter;
use App\Services\Module\ModuleLifecycle;
use App\Services\Module\ModuleLifecycleOperation;
use App\Services\Module\ModuleRegistry;
use App\Services\Plugin\HookManager;
use App\Services\Plugin\PluginManager;
use DomainException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Plugin\CredentialProviderFixture\Plugin as FixturePlugin;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

final class FixturePluginBoundaryTest extends TestCase
{
    private string $privateRoot;
    private const CODE = FixturePluginBinding::CODE;
    private const TABLES = [FixturePluginBinding::TABLE, 'tx_plugins', 'tx_credential_provider_shadow_revocation',
        'tx_credential_provider_shadow_lifecycle', 'tx_credential_provider_shadow_operation', 'tx_credential_provider_shadow_registration',
        'tx_usage_adjustment', 'tx_access_subject', 'tx_legacy_subscription_map', 'tx_legacy_subscription_exception',
        'tx_migration_checkpoint', 'tx_subscription_slot', 'tx_usage_period', 'tx_subscription_terms_transition', 'tx_subscription_terms', 'tx_subscription'];

    protected function setUp(): void
    {
        parent::setUp();
        $mysql = getenv('CORE_PROVIDER_PLUGIN_MYSQL') === '1';
        config(['database.default' => 'provider_plugin_testing', 'database.connections.provider_plugin_testing' => $mysql
            ? config('database.connections.mysql') : array_merge(config('database.connections.sqlite'), ['database' => ':memory:', 'foreign_key_constraints' => true])]);
        DB::purge('provider_plugin_testing');
        if ($mysql) { $this->dropDedicatedTables(); }
        foreach (['2025_01_18_140511_create_plugins_table.php', '2025_07_26_000001_add_type_to_plugins_table.php',
            '2026_10_12_000001_create_subscription_shadow_tables.php', '2026_10_12_000007_create_credential_provider_shadow_tables.php',
            '2026_10_12_000009_create_credential_provider_lifecycle_shadow_tables.php', '2026_10_12_000011_create_provider_plugin_fixture_binding.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        $this->privateRoot = sys_get_temp_dir().'/txboard-provider-plugin-'.bin2hex(random_bytes(8)); mkdir($this->privateRoot, 0700);
        config(['core_shadow.isolated_copy' => true, 'credential_provider_fixture.enabled' => true,
            'credential_provider_fixture.plugin_bridge_enabled' => true, 'credential_provider_fixture.private_root' => $this->privateRoot,
            'credential_provider_fixture.wall_timeout_seconds' => 1.0, 'credential_provider_fixture.test_fault' => null]);
        $package = base_path('plugins/CredentialProviderFixture');
        if (File::exists($package)) { throw new RuntimeException('FIXTURE_PACKAGE_ALREADY_EXISTS'); }
        File::ensureDirectoryExists($package);
        foreach (['Plugin.php', 'config.json'] as $file) { File::copy(base_path('tests/Fixtures/CredentialProviderPlugin/'.$file), $package.'/'.$file); }
        $assets = public_path('plugins/'.self::CODE);
        if (File::exists($assets)) { throw new RuntimeException('FIXTURE_ASSETS_ALREADY_EXIST'); }
        File::ensureDirectoryExists($assets); File::put($assets.'/keep.txt', 'retained fixture asset');
        require_once base_path('tests/Fixtures/CredentialProviderPlugin/Plugin.php'); FixturePlugin::resetFixture(); HookManager::reset();
        Plugin::create(['code' => self::CODE, 'name' => 'Credential Provider Boundary Fixture', 'version' => '1.0.0',
            'type' => Plugin::TYPE_FEATURE, 'config' => '{"keep":"original"}', 'is_enabled' => true, 'installed_at' => now()]);
        app(FixtureProviderService::class)->register('fixture_provider', '1', ['issue', 'query', 'revoke']);
        app(FixturePluginBinding::class)->bind('fixture_provider');
    }

    protected function tearDown(): void
    {
        if (getenv('CORE_PROVIDER_PLUGIN_MYSQL') === '1') { $this->dropDedicatedTables(); }
        File::deleteDirectory(base_path('plugins/CredentialProviderFixture')); File::deleteDirectory(public_path('plugins/'.self::CODE));
        if (isset($this->privateRoot)) { File::deleteDirectory($this->privateRoot); }
        FixturePlugin::resetFixture(); HookManager::reset(); DB::purge('provider_plugin_testing'); parent::tearDown();
    }

    public function test_actual_manager_fences_both_states_before_cleanup_and_replays_when_already_disabled(): void
    {
        $scope = $this->subject(); $issue = $this->issue($scope); $history = $this->history();
        $manager = app(PluginManager::class); $manager->initializeEnabledPlugins();
        $this->assertSame(1, FixturePlugin::$bootCount); $this->assertSame(2, HookManager::filter('fixture.plugin.boundary', 1));
        FixturePlugin::$failCleanup = true;
        $this->assertTrue($manager->disable(self::CODE));
        $this->assertFalse(Plugin::where('code', self::CODE)->value('is_enabled'));
        $this->assertFalse((bool) DB::table('tx_credential_provider_shadow_registration')->value('enabled'));
        $this->assertSame(1, FixturePlugin::$cleanupCount); $this->assertSame(1, HookManager::filter('fixture.plugin.boundary', 1));
        $state = $this->gate()->status(); $this->assertSame((string) DB::table('tx_credential_provider_shadow_operation')->where('operation_ref', $issue['operation_ref'])->value('id'), $state['high_water_id']);
        $this->assertTrue($manager->disable(self::CODE)); $this->assertSame($state, $this->gate()->status());
        $this->assertSame(1, FixturePlugin::$cleanupCount); $this->assertSame($history, $this->history());
        $this->refused(fn () => $this->issue($this->subject(), 'fixture_after_disable'), 'PROVIDER_DISABLED');
    }

    public function test_actual_module_adapter_disable_and_uninstall_keep_safe_runtime_failure(): void
    {
        $registry = app(ModuleRegistry::class);
        $lifecycle = new ModuleLifecycle($registry, [new PluginLifecycleAdapter(app(PluginManager::class))]);
        $result = $lifecycle->execute(self::CODE, ModuleLifecycleOperation::DISABLE);
        $this->assertTrue($result->success); $this->assertFalse($result->module->enabled);
        $failed = $lifecycle->execute(self::CODE, ModuleLifecycleOperation::UNINSTALL);
        $this->assertFalse($failed->success); $this->assertSame('runtime_error', $failed->error->code->value);
        $this->assertTrue($failed->module->installed); $this->retained();
    }

    public function test_incomplete_and_complete_cleanup_both_block_uninstall_and_delete_without_erasing_history(): void
    {
        $scope = $this->subject(); $issued = $this->issue($scope); $history = $this->history();
        $manager = app(PluginManager::class); $manager->disable(self::CODE);
        $this->refused(fn () => $manager->uninstall(self::CODE), 'PROVIDER_PLUGIN_CLEANUP_PENDING'); $this->retained();
        $complete = $this->gate()->cleanup(1, time()+5); $this->assertTrue($complete['fixture_cleanup_complete']);
        $this->assertSame('not_implemented', $complete['node_convergence']);
        foreach (['uninstall', 'delete'] as $method) { $this->refused(fn () => $manager->$method(self::CODE), 'PROVIDER_NODE_DRAIN_UNVERIFIED'); $this->retained(); }
        $this->assertSame($history, $this->history());
        $state = json_decode(file_get_contents($this->privateRoot.'/fixture_provider/state.json'), true);
        $this->assertCount(1, $state['tombstones']); $this->assertCount(2, $state['operations']);
        $this->assertArrayNotHasKey('fixture_secret', $state['operations'][$issued['operation_ref']]);
        $this->assertSame(1, DB::table('tx_credential_provider_shadow_revocation')->count());
    }

    public function test_lost_revoke_ack_recovers_original_receipt_without_reopening_or_removing_runtime(): void
    {
        $scope = $this->subject(); $this->issue($scope); app(PluginManager::class)->disable(self::CODE);
        config(['credential_provider_fixture.test_fault' => 'after_commit_drop']);
        $pending = $this->gate()->cleanup(1, time()+5); $this->assertFalse($pending['fixture_cleanup_complete']);
        $this->assertSame('0', $pending['cursor']);
        $operation = DB::table('tx_credential_provider_shadow_operation')->where('operation', 'revoke')->value('operation_ref');
        config(['credential_provider_fixture.test_fault' => null]);
        $this->assertTrue($this->gate()->cleanup(1, time()+5)['fixture_cleanup_complete']);
        $this->assertSame($operation, DB::table('tx_credential_provider_shadow_operation')->where('operation', 'revoke')->value('operation_ref'));
        $this->assertSame(1, DB::table('tx_credential_provider_shadow_operation')->where('operation', 'revoke')->count()); $this->retained();
    }

    public function test_sql_failure_rolls_back_both_states_and_does_not_call_php_cleanup(): void
    {
        DB::unprepared(DB::getDriverName() === 'mysql'
            ? "CREATE TRIGGER cp_plugin_fail BEFORE UPDATE ON tx_plugins FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture failure'"
            : "CREATE TRIGGER cp_plugin_fail BEFORE UPDATE ON tx_plugins BEGIN SELECT RAISE(ABORT,'fixture failure'); END");
        try { app(PluginManager::class)->disable(self::CODE); $this->fail('SQL failure must stop the operation'); } catch (QueryException) { $this->assertTrue(true); }
        $this->assertTrue(Plugin::where('code', self::CODE)->value('is_enabled'));
        $this->assertTrue((bool) DB::table('tx_credential_provider_shadow_registration')->value('enabled'));
        $this->assertSame(0, DB::table('tx_credential_provider_shadow_lifecycle')->count()); $this->assertSame(0, FixturePlugin::$cleanupCount);
        DB::unprepared('DROP TRIGGER cp_plugin_fail');
    }

    public function test_flags_and_production_cannot_turn_binding_into_ordinary_v1_mutation(): void
    {
        foreach (['credential_provider_fixture.plugin_bridge_enabled', 'credential_provider_fixture.enabled', 'core_shadow.isolated_copy'] as $flag) {
            config([$flag => false]);
            foreach (['disable', 'uninstall', 'delete'] as $method) { $this->refused(fn () => app(PluginManager::class)->$method(self::CODE), 'PROVIDER_PLUGIN_CAPABILITY_UNAVAILABLE'); }
            config([$flag => true]);
        }
        $this->app->detectEnvironment(static fn () => 'production');
        $this->refused(fn () => app(PluginManager::class)->disable(self::CODE), 'PROVIDER_PLUGIN_CAPABILITY_UNAVAILABLE');
        $this->assertTrue(Plugin::where('code', self::CODE)->value('is_enabled')); $this->assertSame(0, FixturePlugin::$cleanupCount); $this->retained();
    }

    public function test_reserved_reactivation_and_asset_publication_refuse_before_any_boot_or_file_effect(): void
    {
        foreach (['install', 'enable', 'update', 'publishAssets'] as $method) {
            $this->refused(fn () => app(PluginManager::class)->$method(self::CODE), 'PROVIDER_PLUGIN_REACTIVATION_UNSUPPORTED');
        }
        $this->assertSame(0, FixturePlugin::$bootCount); $this->retained();
    }

    public function test_reserved_archive_replacement_is_denied_before_promotion(): void
    {
        if (!class_exists(ZipArchive::class)) { $this->markTestSkipped('Zip extension required for real archive promotion check.'); }
        $path = tempnam(sys_get_temp_dir(), 'txboard-provider-package-'); $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $manifest = json_decode(File::get(base_path('tests/Fixtures/CredentialProviderPlugin/config.json')), true); $manifest['version'] = '2.0.0';
        $zip->addFromString('config.json', json_encode($manifest)); $zip->addFromString('Plugin.php', '<?php // replacement fixture'); $zip->close();
        try { $this->refused(fn () => app(PluginManager::class)->upload(new UploadedFile($path, 'fixture.zip', 'application/zip', null, true)), 'PROVIDER_PLUGIN_REACTIVATION_UNSUPPORTED'); }
        finally { unlink($path); }
        $this->assertSame('1.0.0', json_decode(File::get(base_path('plugins/CredentialProviderFixture/config.json')), true)['version']);
        $this->assertSame(0, FixturePlugin::$bootCount); $this->retained();
    }

    public function test_bound_worker_boot_and_schedule_refuse_unknown_or_disabled_gates(): void
    {
        config(['credential_provider_fixture.plugin_bridge_enabled' => false]);
        $manager = app(PluginManager::class); $manager->initializeEnabledPlugins(); $manager->registerPluginSchedules(app(Schedule::class));
        $this->assertSame(0, FixturePlugin::$bootCount); $this->assertSame([], $manager->getEnabledPlugins());
    }

    public function test_missing_or_damaged_schema_and_missing_binding_never_infer_unbound(): void
    {
        Schema::rename(FixturePluginBinding::TABLE, FixturePluginBinding::TABLE.'_saved');
        try { $this->refused(fn () => app(PluginManager::class)->disable(self::CODE), 'PROVIDER_PLUGIN_SCHEMA_UNKNOWN'); }
        finally { Schema::rename(FixturePluginBinding::TABLE.'_saved', FixturePluginBinding::TABLE); }
        $binding = DB::table(FixturePluginBinding::TABLE)->first(); DB::table(FixturePluginBinding::TABLE)->delete();
        $this->refused(fn () => app(PluginManager::class)->disable(self::CODE), 'PROVIDER_PLUGIN_BINDING_UNKNOWN');
        $this->refused(fn () => app(PluginManager::class)->enable(self::CODE), 'PROVIDER_PLUGIN_REACTIVATION_UNSUPPORTED');
        DB::table(FixturePluginBinding::TABLE)->insert((array) $binding);
        $this->assertTrue(Plugin::where('code', self::CODE)->value('is_enabled')); $this->assertSame(0, FixturePlugin::$cleanupCount);
    }

    public function test_binding_replay_conflicts_constraints_and_nonempty_rollback_preserve_history(): void
    {
        $id = DB::table(FixturePluginBinding::TABLE)->value('id');
        $this->assertSame((string) $id, app(FixturePluginBinding::class)->bind('fixture_provider'));
        app(FixtureProviderService::class)->register('fixture_other_provider', '1', ['issue', 'query', 'revoke']);
        $this->refused(fn () => app(FixturePluginBinding::class)->bind('fixture_other_provider'), 'PROVIDER_PLUGIN_BINDING_CONFLICT');
        foreach ([fn () => Plugin::where('code', self::CODE)->delete(), fn () => DB::table('tx_credential_provider_shadow_registration')->where('provider_id', 'fixture_provider')->delete(),
            fn () => DB::table(FixturePluginBinding::TABLE)->update(['plugin_code' => 'ordinary_v1_plugin'])] as $operation) {
            try { $operation(); $this->fail('Constraint must preserve the closed technical link'); } catch (QueryException) { $this->assertTrue(true); }
        }
        $migration = require database_path('migrations/2026_10_12_000011_create_provider_plugin_fixture_binding.php'); $migration->up();
        $this->refused(fn () => $migration->down(), 'Provider plugin binding history exists; fix forward or restore a verified backup.'); $this->retained();
    }

    public function test_outer_transactions_are_refused_by_actual_module_adapter_and_standalone_disable(): void
    {
        $module = app(ModuleRegistry::class)->find(self::CODE); $this->assertNotNull($module);
        DB::beginTransaction();
        try {
            $this->refused(fn () => (new PluginLifecycleAdapter(app(PluginManager::class)))->execute($module, ModuleLifecycleOperation::DISABLE), 'PROVIDER_OUTER_TRANSACTION_UNSUPPORTED');
            $this->refused(fn () => app(FixtureLifecycleService::class)->disable('fixture_provider', 'fixture_outer'), 'PROVIDER_OUTER_TRANSACTION_UNSUPPORTED');
        } finally { DB::rollBack(); }
        $this->assertTrue(Plugin::where('code', self::CODE)->value('is_enabled')); $this->assertSame(0, FixturePlugin::$cleanupCount);
    }

    public function test_collation_aliases_cannot_bypass_the_reserved_manager_boundary(): void
    {
        foreach (['CREDENTIAL_PROVIDER_FIXTURE', self::CODE.' ', 'crédential_provider_fixture', self::CODE."\n"] as $code) {
            foreach (['disable', 'uninstall', 'enable', 'delete', 'update'] as $method) {
                $this->refused(fn () => app(PluginManager::class)->$method($code), 'Invalid plugin code');
            }
        }
        $this->assertTrue(Plugin::where('code', self::CODE)->value('is_enabled'));
        $this->assertTrue((bool) DB::table('tx_credential_provider_shadow_registration')->value('enabled'));
        $this->assertSame(0, FixturePlugin::$cleanupCount); $this->retained();
    }

    public function test_damaged_schema_constraint_is_refused_without_php_cleanup(): void
    {
        // InnoDB requires an index for the retained FK; corrupt uniqueness while retaining its supporting index.
        Schema::table(FixturePluginBinding::TABLE, fn ($table) => $table->index('plugin_id', 'tx_cp_plugin_nonunique_fixture'));
        Schema::table(FixturePluginBinding::TABLE, fn ($table) => $table->dropUnique('tx_cp_plugin_install_uq'));
        $this->refused(fn () => app(PluginManager::class)->disable(self::CODE), 'PROVIDER_PLUGIN_SCHEMA_UNKNOWN');
        $this->assertTrue(Plugin::where('code', self::CODE)->value('is_enabled'));
        $this->assertTrue((bool) DB::table('tx_credential_provider_shadow_registration')->value('enabled'));
        $this->assertSame(0, FixturePlugin::$cleanupCount);
    }

    public function test_unavailable_runtime_can_be_fenced_but_never_reported_as_cleaned(): void
    {
        $this->issue($this->subject());
        DB::table('tx_credential_provider_shadow_registration')->update(['script_digest' => str_repeat('0', 64)]);
        app(PluginManager::class)->disable(self::CODE);
        $this->assertFalse((bool) DB::table('tx_credential_provider_shadow_registration')->value('enabled'));
        $this->refused(fn () => $this->gate()->cleanup(1, time()+5), 'RUNTIME_UNAVAILABLE');
        $this->assertFalse($this->gate()->status()['fixture_cleanup_complete']); $this->retained();
    }

    public function test_current_issue_grant_revocation_blocks_worker_boot_without_rebinding(): void
    {
        DB::table('tx_credential_provider_shadow_registration')->update(['operation_grants' => '["query","revoke"]']);
        $manager = app(PluginManager::class); $manager->initializeEnabledPlugins();
        $this->assertSame(0, FixturePlugin::$bootCount); $this->assertSame([], $manager->getEnabledPlugins());
        $this->assertSame(1, DB::table(FixturePluginBinding::TABLE)->count());
    }

    public function test_lost_binding_with_existing_operations_cannot_enroll_a_new_disable_generation(): void
    {
        $this->issue($this->subject()); DB::table(FixturePluginBinding::TABLE)->delete();
        $this->refused(fn () => app(FixturePluginBinding::class)->bind('fixture_provider'), 'PROVIDER_PLUGIN_BINDING_UNKNOWN');
        $this->refused(fn () => app(PluginManager::class)->disable(self::CODE), 'PROVIDER_PLUGIN_BINDING_UNKNOWN');
        $this->assertTrue((bool) DB::table('tx_credential_provider_shadow_registration')->value('enabled'));
        $this->assertSame(0, FixturePlugin::$cleanupCount);
    }

    public function test_ordinary_v1_disable_and_uninstall_do_not_query_any_fixture_table(): void
    {
        Plugin::create(['code' => 'ordinary_v1_plugin', 'name' => 'Ordinary v1', 'version' => '1.0.0', 'is_enabled' => true]);
        DB::connection()->enableQueryLog();
        $manager = app(PluginManager::class); $this->assertTrue($manager->disable('ordinary_v1_plugin')); $this->assertTrue($manager->uninstall('ordinary_v1_plugin'));
        $queries = DB::connection()->getQueryLog(); DB::connection()->disableQueryLog();
        foreach ($queries as $query) { $this->assertStringNotContainsString('tx_credential_provider_', $query['query']); }
        $this->assertFalse(Plugin::where('code', 'ordinary_v1_plugin')->exists());
    }

    public function test_real_issue_waits_for_uncommitted_manager_disable_and_then_is_refused(): void
    {
        $this->requireMysqlFork(); $scope = $this->subject(); $directory = $this->barrier(); $connection = DB::getDefaultConnection(); DB::disconnect($connection);
        $pid = pcntl_fork(); if ($pid < 0) { throw new RuntimeException('Fork failed'); }
        if ($pid === 0) {
            DB::purge($connection); $this->waitFile($directory.'/fenced'); touch($directory.'/attempted');
            try { $this->issue($scope); $code = 'UNSAFE_ISSUE'; } catch (DomainException $e) { $code = $e->getMessage(); }
            file_put_contents($directory.'/result', $code); exit(0);
        }
        $observed = false;
        try {
            DB::listen(function ($query) use ($directory, &$observed) {
                if (!$observed && str_starts_with($query->sql, 'update') && str_contains($query->sql, 'tx_plugins')) {
                    $observed = true; touch($directory.'/fenced'); $this->waitFile($directory.'/attempted'); usleep(100000);
                    $this->assertFileDoesNotExist($directory.'/result');
                }
            });
            app(PluginManager::class)->disable(self::CODE); pcntl_waitpid($pid, $status); $pid = 0;
            $this->assertTrue($observed); $this->assertSame(0, pcntl_wexitstatus($status));
            $this->assertSame('PROVIDER_DISABLED', file_get_contents($directory.'/result'));
            $this->assertSame(0, DB::table('tx_credential_provider_shadow_operation')->count());
        } finally { $this->join($pid); File::deleteDirectory($directory); DB::purge($connection); }
    }

    public function test_sigkill_after_fence_commit_retains_both_disabled_states_and_recovers_cleanup(): void
    {
        $this->requireMysqlFork(); $scope = $this->subject(); $this->issue($scope); $directory = $this->barrier(); $connection = DB::getDefaultConnection(); DB::disconnect($connection);
        $pid = pcntl_fork(); if ($pid < 0) { throw new RuntimeException('Fork failed'); }
        if ($pid === 0) {
            DB::purge($connection);
            DB::listen(function ($query) use ($directory) {
                if (str_starts_with($query->sql, 'update') && str_contains($query->sql, 'tx_plugins')) {
                    DB::afterCommit(function () use ($directory) { touch($directory.'/committed'); sleep(20); });
                }
            });
            app(PluginManager::class)->disable(self::CODE); exit(2);
        }
        try {
            $this->waitFile($directory.'/committed'); posix_kill($pid, SIGKILL); pcntl_waitpid($pid, $status); $pid = 0; DB::purge($connection);
            $this->assertTrue(pcntl_wifsignaled($status)); $this->assertSame(SIGKILL, pcntl_wtermsig($status));
            $this->assertFalse(Plugin::where('code', self::CODE)->value('is_enabled'));
            $this->assertFalse((bool) DB::table('tx_credential_provider_shadow_registration')->value('enabled'));
            $operation = $this->gate()->status()['operation_ref'];
            app(PluginManager::class)->disable(self::CODE); $this->assertSame($operation, $this->gate()->status()['operation_ref']);
            $this->assertTrue($this->gate()->cleanup(1, time()+5)['fixture_cleanup_complete']); $this->retained();
        } finally { $this->join($pid); File::deleteDirectory($directory); DB::purge($connection); }
    }

    private function subject(): FixtureScope
    {
        $sub = DB::table('tx_subscription')->insertGetId(['user_id' => 1, 'status' => 'active', 'starts_at' => 1, 'expires_at' => time()+3600, 'created_at' => 1, 'updated_at' => 1]);
        $terms = DB::table('tx_subscription_terms')->insertGetId(['subscription_id' => $sub, 'version_no' => 1, 'effective_from' => 1,
            'quota_bytes' => 1000, 'billing_timezone' => 'UTC', 'reset_policy' => '{"method":1}', 'content_hash' => str_repeat('a', 64), 'created_at' => 1]);
        DB::table('tx_subscription')->where('id', $sub)->update(['current_terms_id' => $terms]);
        DB::table('tx_usage_period')->insert(['subscription_id' => $sub, 'terms_id' => $terms, 'period_no' => 1,
            'quota_bytes' => 1000, 'upload_bytes' => 17, 'download_bytes' => 29, 'billable_bytes' => 46,
            'status' => 'shadow', 'boundary_kind' => 'legacy_boundary_unknown', 'created_at' => 1, 'updated_at' => 1]);
        $id = DB::table('tx_access_subject')->insertGetId(['subscription_id' => $sub, 'provider_id' => 'fixture_provider',
            'provider_subject_ref' => 'core_pending_'.bin2hex(random_bytes(8)), 'status' => 'pending', 'auth_version' => 1, 'created_at' => 1, 'updated_at' => 1]);
        return new FixtureScope('fixture_provider', (string) $id);
    }
    private function issue(FixtureScope $scope, string $key = 'fixture_issue'): array { return app(FixtureProviderService::class)->issue($scope, $key,
        ['expected_subject_version' => '1', 'credential_profile_id' => 'synthetic-v1', 'not_before_at' => time()+5, 'credential_expires_at' => time()+300], time()+5); }
    private function gate(): FixturePluginLifecycleGate { return app(FixturePluginLifecycleGate::class); }
    private function history(): array { return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), ['tx_subscription', 'tx_access_subject', 'tx_usage_period']); }
    private function retained(): void { $this->assertSame('{"keep":"original"}', Plugin::where('code', self::CODE)->value('config'));
        $this->assertSame('retained fixture asset', File::get(public_path('plugins/'.self::CODE.'/keep.txt')));
        $this->assertFileExists(base_path('plugins/CredentialProviderFixture/Plugin.php')); $this->assertSame(1, DB::table(FixturePluginBinding::TABLE)->count()); }
    private function refused(callable $operation, string $code): void { try { $operation(); $this->fail('Unsafe boundary accepted'); } catch (DomainException|RuntimeException|\InvalidArgumentException $error) { $this->assertSame($code, $error->getMessage()); } }
    private function dropDedicatedTables(): void { if (DB::connection()->getDatabaseName() !== 'txboard_provider_test') { throw new RuntimeException('PROVIDER_PLUGIN_DEDICATED_DB_REQUIRED'); }
        Schema::disableForeignKeyConstraints(); try { foreach (self::TABLES as $table) { Schema::dropIfExists($table); } } finally { Schema::enableForeignKeyConstraints(); } }
    private function requireMysqlFork(): void { if (DB::getDriverName() !== 'mysql' || !function_exists('pcntl_fork')) { $this->markTestSkipped('Requires dedicated MariaDB and separate sessions.'); } }
    private function barrier(): string { $path = sys_get_temp_dir().'/txboard-plugin-barrier-'.bin2hex(random_bytes(8)); mkdir($path, 0700); return $path; }
    private function waitFile(string $path): void { $until = microtime(true)+10; while (!is_file($path) && microtime(true)<$until) { usleep(1000); } if (!is_file($path)) { throw new RuntimeException('FIXTURE_BARRIER_TIMEOUT'); } }
    private function join(int $pid): void { if ($pid > 0) { posix_kill($pid, SIGTERM); pcntl_waitpid($pid, $status); } }
}
