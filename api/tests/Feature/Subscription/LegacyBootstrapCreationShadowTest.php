<?php

namespace Tests\Feature\Subscription;

use App\Console\Commands\TxboardInstall;
use App\Models\Plan;
use App\Models\User;
use App\Services\Subscription\LegacyAdminCreationShadow;
use App\Services\Subscription\LegacyBootstrapCreationShadow;
use App\Services\Subscription\LegacyCaptureShadowException;
use App\Services\Subscription\LegacyRegistrationCreationShadow;
use App\Services\Subscription\LegacyUserMutationCapture;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LegacyBootstrapCreationShadowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp(); $connection = $this->connection();
        config(['database.default' => $connection, 'database.connections.'.$connection => $this->mysql()
            ? config('database.connections.mysql') : array_replace(config('database.connections.sqlite'), ['database' => ':memory:'])]);
        DB::purge($connection); if ($this->mysql()) $this->clearMysql();
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true])); Bus::fake();
        config(['core_shadow.isolated_copy' => true, 'legacy_bootstrap_creation_shadow.enabled' => true,
            'legacy_admin_creation_shadow.enabled' => false, 'legacy_registration_creation_shadow.enabled' => false,
            'legacy_capture_shadow.enabled' => true, 'legacy_capture_shadow.run_key' => 'bootstrap-fixture',
            'legacy_capture_shadow.billing_timezone' => 'UTC', 'legacy_capture_shadow.currency' => 'USD',
            'legacy_observer_capture.enabled' => false]);
    }
    protected function tearDown(): void
    { if ($this->mysql()) $this->clearMysql(); DB::purge($this->connection()); parent::tearDown(); }

    public function test_actual_register_admin_keeps_native_credentials_role_and_closed_self_bound_initial_evidence(): void
    {
        $this->assertTrue($this->createAdmin()); $user = $this->admin(); $row = $this->evidence($user->id);
        $this->assertTrue((bool) $user->is_admin); $this->assertTrue(password_verify('BootstrapFixturePassword77', $user->password));
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', $user->uuid); $this->assertSame(32, strlen($user->token));
        $snapshot = json_decode($row->initial_snapshot, true, flags: JSON_THROW_ON_ERROR);
        $this->assertEqualsCanonicalizing(LegacyUserMutationCapture::FIELDS, array_keys($snapshot));
        $this->assertSame($this->source($user->id), $this->sort($snapshot));
        $this->assertSame('install.admin.create', $row->source_kind); $this->assertSame('bootstrap-creation-shadow/v1', $row->source_version);
        $this->assertSame($user->id, (int) $row->actor_id); $this->assertSame('0', $snapshot['balance']);
        $this->assertNull($snapshot['plan_id']); $this->assertNull($snapshot['next_reset_at']);
        foreach ([$user->email, $user->password, $user->uuid, $user->token, 'BootstrapFixturePassword77'] as $secret)
            $this->assertStringNotContainsString($secret, json_encode($row));
        foreach (['tx_subscription_terms','tx_access_subject','tx_legacy_subscription_map','tx_legacy_capture_shadow_event','personal_access_tokens'] as $table)
            $this->assertSame(0, DB::table($table)->count());
        $this->assertTrue($this->service()->verify($user->id)['source_matches_initial']);
    }

    public function test_trial_settings_are_not_invented_for_original_installer_method_and_settings_do_not_change(): void
    {
        $plan = Plan::create(['name' => 'Ignored bootstrap trial', 'group_id' => 1, 'transfer_enable' => 4, 'prices' => ['monthly' => 1]]);
        admin_setting(['try_out_plan_id' => $plan->id, 'try_out_hour' => 3]); $settings = DB::table('tx_settings')->orderBy('id')->get()->toJson();
        $this->assertTrue($this->createAdmin()); $snapshot = json_decode($this->evidence($this->admin()->id)->initial_snapshot, true);
        $this->assertNull($snapshot['plan_id']); $this->assertSame('0', $snapshot['transfer_enable']);
        $this->assertSame($settings, DB::table('tx_settings')->orderBy('id')->get()->toJson());
    }

    public function test_default_off_production_uses_native_save_without_transaction_or_evidence_queries(): void
    {
        config(['legacy_bootstrap_creation_shadow.enabled' => false, 'legacy_capture_shadow.enabled' => 'invalid', 'core_shadow.isolated_copy' => false]);
        $this->app['env'] = 'production'; Schema::drop('tx_legacy_user_creation_shadow'); $level = null;
        Event::listen('eloquent.created: '.User::class, function () use (&$level): void { $level = DB::transactionLevel(); });
        DB::enableQueryLog(); DB::flushQueryLog(); $this->assertTrue($this->createAdmin()); $this->assertSame(0, $level);
        $this->assertSame([], array_values(array_filter(DB::getQueryLog(), fn ($q) => preg_match('/tx_legacy_user_creation_shadow|tx_legacy_capture_shadow/', $q['query']))));
        $this->assertTrue((bool) $this->admin()->is_admin);
    }

    public function test_native_short_password_validation_and_cancelled_saving_do_not_invent_initial_evidence(): void
    {
        try { TxboardInstall::registerAdmin('bootstrap@example.test', 'short'); $this->fail('short native password accepted'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) { $this->assertSame(500, $exception->getStatusCode()); }
        Event::listen('eloquent.saving: '.User::class, fn () => false);
        $this->assertFalse($this->createAdmin()); $this->assertSame(0, User::count());
        $this->assertSame(0, DB::table('tx_legacy_user_creation_shadow')->count()); Bus::assertNothingDispatched();
    }

    public function test_strict_independent_gates_and_invalid_context_refuse_before_persistence(): void
    {
        foreach ([1,'1',null] as $flag) {
            config(['legacy_bootstrap_creation_shadow.enabled' => $flag]); $this->refused(fn () => $this->createAdmin(), 'CREATION_SHADOW_ISOLATED_REQUIRED');
        }
        config(['legacy_bootstrap_creation_shadow.enabled' => true, 'legacy_capture_shadow.enabled' => false]);
        $this->refused(fn () => $this->createAdmin(), 'CAPTURE_ISOLATED_COPY_REQUIRED');
        config(['legacy_capture_shadow.enabled' => true, 'core_shadow.isolated_copy' => false]);
        $this->refused(fn () => $this->createAdmin(), 'CAPTURE_ISOLATED_COPY_REQUIRED');
        config(['core_shadow.isolated_copy' => true]); $this->app['env'] = 'production';
        $this->refused(fn () => $this->createAdmin(), 'CAPTURE_ISOLATED_COPY_REQUIRED');
        $this->app['env'] = 'testing'; config(['legacy_capture_shadow.currency' => 'JPY']);
        $this->refused(fn () => $this->createAdmin(), 'CAPTURE_CONTEXT_INVALID'); $this->assertSame(0, User::count());
    }

    public function test_storage_failure_after_insert_rolls_back_user_and_anchor_without_node_sync(): void
    {
        $active = true;
        DB::listen(function ($query) use (&$active): void {
            if ($active && preg_match('/^insert.*tx_legacy_user_creation_shadow/i', $query->sql)) throw new \RuntimeException('private-bootstrap-evidence-detail');
        });
        try { $this->refused(fn () => $this->createAdmin(), 'CREATION_SHADOW_STORAGE_OR_EVIDENCE_FAILED'); } finally { $active = false; }
        $this->assertSame(0, User::count()); $this->assertSame(0, DB::table('tx_legacy_user_creation_shadow')->count()); Bus::assertNothingDispatched();
    }

    public function test_missing_table_and_context_change_after_native_insert_roll_back_new_administrator(): void
    {
        Schema::drop('tx_legacy_user_creation_shadow'); $this->refused(fn () => $this->createAdmin(), 'CREATION_SHADOW_STORAGE_OR_EVIDENCE_FAILED');
        $this->assertSame(0, User::count());
        $migration = require database_path('migrations/2026_10_13_000002_create_legacy_user_creation_shadow.php'); $migration->up();
        Event::listen('eloquent.created: '.User::class, fn () => config(['legacy_capture_shadow.run_key' => 'changed-context']));
        $this->refused(fn () => $this->createAdmin(), 'CREATION_SHADOW_TICKET_INVALID'); $this->assertSame(0, User::count());
    }

    public function test_duplicate_email_does_not_regenerate_existing_credentials_or_initial_anchor(): void
    {
        $this->assertTrue($this->createAdmin()); $state = $this->state();
        $this->refused(fn () => $this->createAdmin(), 'BOOTSTRAP_SHADOW_STORAGE_OR_EVIDENCE_FAILED');
        $this->assertSame($state, $this->state());
    }

    public function test_policy_verifiers_reject_bootstrap_as_registration_or_authenticated_admin_creation(): void
    {
        $this->assertTrue($this->createAdmin()); $user = $this->admin();
        config(['legacy_registration_creation_shadow.enabled' => true,'legacy_admin_creation_shadow.enabled' => true]);
        $this->refused(fn () => app(LegacyRegistrationCreationShadow::class)->verify($user->id), 'CREATION_SHADOW_EVIDENCE_CHANGED');
        $this->refused(fn () => app(LegacyAdminCreationShadow::class)->verify($user->id), 'CREATION_SHADOW_EVIDENCE_CHANGED');
        $this->assertTrue($this->service()->verify($user->id)['source_matches_initial']);
    }

    public function test_cli_select_only_changes_and_missing_source_are_explicit_without_opening_a_migration_gate(): void
    {
        $this->assertTrue($this->createAdmin()); $user = $this->admin(); $state = $this->state();
        $this->assertSame(1, Artisan::call('core:bootstrap-creation-shadow-verify', ['--user' => (string) $user->id]));
        DB::enableQueryLog(); DB::flushQueryLog();
        $this->assertSame(0, Artisan::call('core:bootstrap-creation-shadow-verify', ['--user' => (string) $user->id,'--isolated-copy' => true]));
        foreach (DB::getQueryLog() as $query) $this->assertMatchesRegularExpression('/^select/i', $query['query']);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR); $this->assertFalse($report['migration_gate_open']);
        $this->assertSame($state, $this->state()); DB::table('tx_user')->where('id', $user->id)->update(['u' => 1]);
        $this->assertSame(['CREATION_SHADOW_SOURCE_CHANGED'], $this->service()->verify($user->id)['issues']);
        DB::table('tx_user')->where('id', $user->id)->delete();
        $this->assertSame(['CREATION_SHADOW_SOURCE_MISSING'], $this->service()->verify($user->id)['issues']);
    }

    public function test_outer_transaction_or_existing_user_cannot_be_recaptured_as_installer_creation(): void
    {
        DB::transaction(function (): void { $this->refused(fn () => $this->createAdmin(), 'BOOTSTRAP_SHADOW_OUTER_TRANSACTION_UNSUPPORTED'); });
        $this->assertTrue($this->createAdmin());
        $this->refused(fn () => $this->service()->persist($this->admin()), 'BOOTSTRAP_SHADOW_NEW_USER_REQUIRED');
        $this->assertSame(1, DB::table('tx_legacy_user_creation_shadow')->count());
    }

    public function test_real_mysql_independent_session_observes_bootstrap_user_and_evidence_only_after_commit(): void
    {
        if (!$this->mysql()) $this->markTestSkipped('Requires dedicated MySQL independent session visibility.');
        config(['database.connections.bootstrap_observer' => config('database.connections.'.$this->connection())]);
        DB::purge('bootstrap_observer'); $other = DB::connection('bootstrap_observer'); $checks = 0;
        DB::listen(function ($query) use ($other,&$checks): void {
            if (preg_match('/^insert.*tx_legacy_user_creation_shadow/i', $query->sql)) {
                $this->assertSame(0,$other->table('tx_user')->count()); $this->assertSame(0,$other->table('tx_legacy_user_creation_shadow')->count()); ++$checks;
            }
        });
        $this->assertTrue($this->createAdmin()); $this->assertSame(1,$checks); $user = $this->admin();
        $this->assertSame(1,$other->table('tx_user')->where('id',$user->id)->count());
        $this->assertSame(1,$other->table('tx_legacy_user_creation_shadow')->where('legacy_user_id',$user->id)->count());
        DB::purge('bootstrap_observer');
    }

    private function createAdmin(): bool { return TxboardInstall::registerAdmin('bootstrap@example.test','BootstrapFixturePassword77'); }
    private function admin(): User { return User::byEmail('bootstrap@example.test')->firstOrFail(); }
    private function service(): LegacyBootstrapCreationShadow { return app(LegacyBootstrapCreationShadow::class); }
    private function evidence(int $id): object { return DB::table('tx_legacy_user_creation_shadow')->where('legacy_user_id',$id)->firstOrFail(); }
    private function refused(callable $operation,string $code): void
    {
        try { $operation(); $this->fail('Expected capture refusal'); }
        catch (LegacyCaptureShadowException $exception) { $this->assertSame($code,$exception->getMessage()); $this->assertNull($exception->getPrevious()); }
    }
    private function sort(array $row): array { ksort($row,SORT_STRING); return $row; }
    private function source(int $id): array
    {
        $row=(array)DB::table('tx_user')->where('id',$id)->select(LegacyUserMutationCapture::FIELDS)->first();
        foreach ($row as $key=>$value) $row[$key]=$key==='banned' ? (bool)$value : ($value===null ? null : (string)$value);
        return $this->sort($row);
    }
    private function state(): array
    { return [DB::table('tx_user')->get()->toJson(),DB::table('tx_legacy_user_creation_shadow')->get()->toJson()]; }
    private function mysql(): bool { return getenv('CORE_BOOTSTRAP_CREATION_TEST_MYSQL')==='1'; }
    private function connection(): string { return $this->mysql() ? 'bootstrap_mysql_testing' : 'bootstrap_sqlite_testing'; }
    private function clearMysql(): void
    {
        if (DB::connection()->getDatabaseName()!=='txboard_bootstrap_creation_test') throw new \RuntimeException('Bootstrap evidence requires its dedicated test database.');
        Schema::disableForeignKeyConstraints();try { foreach (Schema::getTableListing() as $table) Schema::drop($table); }finally { Schema::enableForeignKeyConstraints(); }
    }
}
