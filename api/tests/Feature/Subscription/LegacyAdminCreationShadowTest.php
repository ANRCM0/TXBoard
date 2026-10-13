<?php

namespace Tests\Feature\Subscription;

use App\Models\LegacyUserCreationShadow;
use App\Models\Plan;
use App\Models\User;
use App\Services\Subscription\LegacyAdminCreationShadow;
use App\Services\Subscription\LegacyCaptureShadowException;
use App\Services\Subscription\LegacyUserMutationCapture;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LegacyAdminCreationShadowTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $connection = $this->connection();
        config(['database.default' => $connection, 'database.connections.'.$connection => $this->mysql()
            ? config('database.connections.mysql') : array_replace(config('database.connections.sqlite'), ['database' => ':memory:'])]);
        DB::purge($connection);
        if ($this->mysql()) $this->clearMysql();
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        Bus::fake();
        config(['core_shadow.isolated_copy' => true, 'legacy_admin_creation_shadow.enabled' => true,
            'legacy_capture_shadow.enabled' => true, 'legacy_capture_shadow.run_key' => 'admin-creation-fixture',
            'legacy_capture_shadow.billing_timezone' => 'UTC', 'legacy_capture_shadow.currency' => 'USD',
            'legacy_observer_capture.enabled' => false]);
        admin_setting(['secure_path' => 'creation_admin', 'try_out_plan_id' => 0]);
        $this->admin = User::withoutEvents(fn () => User::create(['email' => 'creation-admin@example.test',
            'password' => 'synthetic-admin-password', 'uuid' => 'a0000000-0000-4000-8000-000000000001',
            'token' => 'synthetic-admin-token', 'is_admin' => true]));
        Sanctum::actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        if ($this->mysql()) $this->clearMysql();
        DB::purge($this->connection());
        parent::tearDown();
    }

    public function test_native_insert_persists_exact_nonsecret_initial_state_and_no_invented_mutations(): void
    {
        $response = $this->createAccount()->assertCreated();
        $id = $response->json('data.id'); $user = User::findOrFail($id); $row = $this->evidence($id);
        $snapshot = json_decode($row->initial_snapshot, true, flags: JSON_THROW_ON_ERROR);
        $this->assertEqualsCanonicalizing(LegacyUserMutationCapture::FIELDS, array_keys($snapshot));
        $this->assertSame($this->source($id), $this->sort($snapshot));
        $this->assertSame('0', $snapshot['balance']); $this->assertSame('0', $snapshot['u']);
        $this->assertSame('0', $snapshot['commission_balance']); $this->assertFalse($snapshot['banned']);
        $this->assertNull($snapshot['plan_id']); $this->assertNull($snapshot['next_reset_at']);
        $this->assertSame($this->admin->id, (int) $row->actor_id);
        $this->assertSame('admin.user.create', $row->source_kind);
        $this->assertTrue(Hash::check('CreationFixturePassword77', $user->password));
        foreach ([$user->email, $user->password, $user->uuid, $user->token, 'CreationFixturePassword77'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($row));
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        foreach (['tx_legacy_capture_shadow_stream', 'tx_legacy_capture_shadow_event', 'tx_legacy_subscription_map',
            'tx_subscription', 'tx_subscription_terms', 'tx_access_subject'] as $table) $this->assertSame(0, DB::table($table)->count());
    }

    public function test_plan_gib_is_copied_from_actual_insert_and_after_commit_schedule_change_remains_visible(): void
    {
        $plan = $this->plan(); $id = $this->createAccount(['plan_id' => $plan->id, 'expired_at' => time()+86400])->assertCreated()->json('data.id');
        $snapshot = json_decode($this->evidence($id)->initial_snapshot, true);
        $this->assertSame('2147483648', $snapshot['transfer_enable']);
        $this->assertSame((string) $plan->id, $snapshot['plan_id']); $this->assertSame('1', $snapshot['group_id']);
        $this->assertSame('23', $snapshot['speed_limit']);
        // Native create does not copy device_limit, so evidence must not invent it.
        $this->assertNull($snapshot['device_limit']); $this->assertNull($snapshot['next_reset_at']);
        $this->assertNotNull(User::findOrFail($id)->next_reset_at);
        $report = $this->service()->verify($id);
        $this->assertSame(['CREATION_SHADOW_SOURCE_CHANGED'], $report['issues']);
        $this->assertNull($report['first_mutation_before_matches_initial']);
        $this->assertFalse($report['migration_gate_open']);
    }

    public function test_after_commit_captured_schedule_has_a_valid_first_before_state_without_certifying_chain(): void
    {
        config(['legacy_observer_capture.enabled' => true]); $plan = $this->plan();
        $id = $this->createAccount(['plan_id' => $plan->id, 'expired_at' => time()+86400])->assertCreated()->json('data.id');
        $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count());
        $this->assertSame('user.schedule.observer', DB::table('tx_legacy_capture_shadow_event')->value('source_kind'));
        $report = $this->service()->verify($id);
        $this->assertTrue($report['first_mutation_before_matches_initial']); $this->assertFalse($report['mutation_chain_verified']);
        $this->assertFalse($report['source_matches_initial']);
        $this->assertTrue(app(LegacyUserMutationCapture::class)->verify($id)['page_ok']);
    }

    public function test_later_native_edit_anchors_to_initial_state_and_verification_issues_only_selects(): void
    {
        $id = $this->createAccount()->assertCreated()->json('data.id'); $initial = $this->evidence($id)->content_hash;
        $this->postJson('/txapi/admin/creation_admin/users/'.$id.'/update', ['speed_limit' => 17])->assertOk();
        DB::enableQueryLog(); DB::flushQueryLog(); $report = $this->service()->verify($id);
        foreach (DB::getQueryLog() as $query) $this->assertMatchesRegularExpression('/^select/i', $query['query']);
        $this->assertTrue($report['first_mutation_before_matches_initial']); $this->assertFalse($report['source_matches_initial']);
        $this->assertFalse($report['global_coverage_complete']); $this->assertFalse($report['projection_applied']);
        $this->assertSame($initial, $this->evidence($id)->content_hash);
    }

    public function test_default_off_in_production_does_not_query_missing_shadow_storage_or_validate_capture_context(): void
    {
        config(['legacy_admin_creation_shadow.enabled' => false, 'legacy_capture_shadow.enabled' => 'invalid',
            'core_shadow.isolated_copy' => false]); $this->app['env'] = 'production';
        Schema::drop('tx_legacy_user_creation_shadow'); DB::enableQueryLog(); DB::flushQueryLog();
        $id = $this->createAccount()->assertCreated()->json('data.id');
        $this->assertNotNull(User::find($id));
        $this->assertSame([], array_values(array_filter(DB::getQueryLog(), fn ($q) => preg_match('/tx_legacy_user_creation_shadow|tx_legacy_capture_shadow/', $q['query']))));
    }

    public function test_native_permissions_validation_and_duplicate_email_remain_authoritative(): void
    {
        $ordinary = User::withoutEvents(fn () => User::create(['email' => 'ordinary@example.test', 'password' => 'synthetic',
            'uuid' => 'a0000000-0000-4000-8000-000000000002', 'token' => 'synthetic-staff-token', 'is_staff' => true]));
        Sanctum::actingAs($ordinary); $this->createAccount()->assertStatus(403); Sanctum::actingAs($this->admin);
        $this->postJson('/txapi/admin/wrong/users', $this->input())->assertStatus(404);
        $this->createAccount(['generate_count' => 2])->assertStatus(422);
        $this->createAccount(['plan_id' => 2147483647])->assertStatus(422);
        $this->assertSame(0, DB::table('tx_legacy_user_creation_shadow')->count());
        $this->createAccount()->assertCreated(); $history = DB::table('tx_legacy_user_creation_shadow')->get()->toJson();
        // Duplicate check precedes evidence context and does not create an idempotent new account.
        config(['legacy_admin_creation_shadow.enabled' => 'invalid']); $this->createAccount()->assertStatus(422);
        $this->assertSame($history, DB::table('tx_legacy_user_creation_shadow')->get()->toJson());
    }

    public function test_strict_switch_and_isolated_context_refuse_before_persistent_insert(): void
    {
        $count = User::count();
        foreach ([1, '1', null] as $flag) { config(['legacy_admin_creation_shadow.enabled' => $flag]); $this->createAccount()->assertStatus(409); }
        config(['legacy_admin_creation_shadow.enabled' => true, 'legacy_capture_shadow.enabled' => false]); $this->createAccount()->assertStatus(409);
        config(['legacy_capture_shadow.enabled' => true, 'core_shadow.isolated_copy' => false]); $this->createAccount()->assertStatus(409);
        config(['core_shadow.isolated_copy' => true]); $this->app['env'] = 'production'; $this->createAccount()->assertStatus(409);
        $this->app['env'] = 'testing'; config(['legacy_capture_shadow.currency' => 'JPY']); $this->createAccount()->assertStatus(409);
        $this->assertSame($count, User::count()); $this->assertSame(0, DB::table('tx_legacy_user_creation_shadow')->count());
    }

    public function test_missing_evidence_table_rolls_back_actual_user_insert_and_safe_error_has_no_sql(): void
    {
        $count = User::count(); Schema::drop('tx_legacy_user_creation_shadow');
        $response = $this->createAccount()->assertStatus(409);
        $this->assertSame($count, User::count()); $this->assertSame(0, DB::transactionLevel());
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('CreationFixturePassword77', $response->getContent());
    }

    public function test_failure_after_evidence_insert_rolls_back_both_rows_and_does_not_dispatch_created_sync(): void
    {
        $active = true; $count = User::count();
        DB::listen(function ($query) use (&$active): void {
            if ($active && preg_match('/^insert.*tx_legacy_user_creation_shadow/i', $query->sql)) throw new \RuntimeException('private-creation-storage-detail');
        });
        try { $response = $this->createAccount()->assertStatus(409); } finally { $active = false; }
        $this->assertSame($count, User::count()); $this->assertSame(0, DB::table('tx_legacy_user_creation_shadow')->count());
        $this->assertStringNotContainsString('private-creation-storage-detail', $response->getContent()); Bus::assertNothingDispatched();
    }

    public function test_context_change_after_native_insert_refuses_and_rolls_back_account(): void
    {
        $active = true; $count = User::count();
        Event::listen('eloquent.created: '.User::class, function () use (&$active): void {
            if ($active) config(['legacy_capture_shadow.run_key' => 'changed-context']);
        });
        try { $this->createAccount()->assertStatus(409); } finally { $active = false; }
        $this->assertSame($count, User::count()); $this->assertSame(0, DB::table('tx_legacy_user_creation_shadow')->count());
    }

    public function test_bounded_cli_requires_explicit_user_and_marker_and_never_changes_business_rows(): void
    {
        $id = $this->createAccount()->assertCreated()->json('data.id'); $state = $this->state();
        $this->assertSame(1, Artisan::call('core:admin-creation-shadow-verify', ['--user' => (string) $id]));
        $this->assertSame(1, Artisan::call('core:admin-creation-shadow-verify', ['--user' => '01', '--isolated-copy' => true]));
        $this->assertSame(0, Artisan::call('core:admin-creation-shadow-verify', ['--user' => (string) $id, '--isolated-copy' => true]));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($report['source_matches_initial']); $this->assertNull($report['first_mutation_before_matches_initial']);
        $this->assertSame($state, $this->state());
        DB::table('tx_user')->where('id', $id)->update(['u' => 1]);
        $this->assertSame(1, Artisan::call('core:admin-creation-shadow-verify', ['--user' => (string) $id, '--isolated-copy' => true]));
        $this->assertStringContainsString('CREATION_SHADOW_SOURCE_CHANGED', Artisan::output());
    }

    public function test_immutable_model_additive_ddl_and_populated_down_preserve_initial_history(): void
    {
        $id = $this->createAccount()->assertCreated()->json('data.id'); $state = $this->state();
        $model = LegacyUserCreationShadow::where('legacy_user_id', $id)->firstOrFail();
        foreach (['save', 'delete'] as $method) {
            try { $model->actor_id = 999; $model->$method(); $this->fail('immutable history was changed'); }
            catch (\LogicException $exception) { $this->assertStringContainsString('history', $exception->getMessage()); }
        }
        $migration = require database_path('migrations/2026_10_13_000002_create_legacy_user_creation_shadow.php');
        $migration->up(); $this->assertSame($state, $this->state());
        try { $migration->down(); $this->fail('populated table was dropped'); }
        catch (\RuntimeException $exception) { $this->assertSame('CREATION_SHADOW_POPULATED_ROLLBACK_REFUSED', $exception->getMessage()); }
        $this->assertSame($state, $this->state());
    }

    public function test_native_user_deletion_keeps_creation_evidence_and_reports_missing_source(): void
    {
        $id = $this->createAccount()->assertCreated()->json('data.id'); $hash = $this->evidence($id)->content_hash;
        User::withoutEvents(fn () => User::findOrFail($id)->delete());
        $report = $this->service()->verify($id);
        $this->assertSame(['CREATION_SHADOW_SOURCE_MISSING'], $report['issues']);
        $this->assertSame($hash, $this->evidence($id)->content_hash); $this->assertTrue($report['evidence_valid']);
    }

    public function test_json_member_order_is_not_a_value_change_and_extra_snapshot_fields_refuse_even_with_updated_hashes(): void
    {
        $id = $this->createAccount()->assertCreated()->json('data.id'); $row = $this->evidence($id);
        $snapshot = json_decode($row->initial_snapshot, true); $context = json_decode($row->context, true);
        DB::table('tx_legacy_user_creation_shadow')->where('legacy_user_id', $id)->update([
            'initial_snapshot' => json_encode(array_reverse($snapshot, true)), 'context' => json_encode(array_reverse($context, true))]);
        $this->assertTrue($this->service()->verify($id)['source_matches_initial']);
        $snapshot['email'] = 'should-not-be-retained@example.test';
        $payload = (array) $row; unset($payload['id'], $payload['content_hash']);
        foreach (['legacy_user_id', 'actor_id', 'captured_at'] as $key) $payload[$key] = (string) $payload[$key];
        $payload['context'] = $context; $payload['initial_snapshot'] = $snapshot; $payload['initial_hash'] = $this->hash($snapshot);
        DB::table('tx_legacy_user_creation_shadow')->where('legacy_user_id', $id)->update([
            'initial_snapshot' => json_encode($snapshot), 'initial_hash' => $payload['initial_hash'], 'content_hash' => $this->hash($payload)]);
        $this->expectException(LegacyCaptureShadowException::class); $this->expectExceptionMessage('CAPTURE_SOURCE_INVALID');
        $this->service()->verify($id);
    }

    public function test_first_mutation_content_tampering_and_context_drift_refuse(): void
    {
        $id = $this->createAccount()->assertCreated()->json('data.id');
        $this->postJson('/txapi/admin/creation_admin/users/'.$id.'/update', ['speed_limit' => 17])->assertOk();
        DB::table('tx_legacy_capture_shadow_event')->where('legacy_user_id', $id)->update(['before_hash' => $this->evidence($id)->initial_hash]);
        config(['legacy_capture_shadow.run_key' => 'wrong-current-context']);
        try { $this->service()->verify($id); $this->fail('context drift was accepted'); }
        catch (LegacyCaptureShadowException $exception) { $this->assertSame('CREATION_SHADOW_EVIDENCE_CHANGED', $exception->getMessage()); }
        config(['legacy_capture_shadow.run_key' => 'admin-creation-fixture']);
        DB::table('tx_legacy_capture_shadow_event')->where('legacy_user_id', $id)->update(['content_hash' => str_repeat('0', 64)]);
        $this->expectException(LegacyCaptureShadowException::class); $this->expectExceptionMessage('CAPTURE_EVENT_TAMPERED');
        $this->service()->verify($id);
    }

    public function test_real_mysql_uncommitted_user_and_evidence_are_invisible_and_rollback_together(): void
    {
        if (!$this->mysql()) $this->markTestSkipped('Requires dedicated MySQL independent session visibility.');
        config(['database.connections.creation_observer' => config('database.connections.'.$this->connection())]);
        DB::purge('creation_observer'); $other = DB::connection('creation_observer');
        $count = User::count();
        DB::beginTransaction();
        try {
            $ticket = $this->service()->begin($this->admin->id);
            $user = User::withoutEvents(fn () => User::create(['email' => 'uncommitted@example.test', 'password' => 'synthetic',
                'uuid' => 'a0000000-0000-4000-8000-000000000003', 'token' => 'synthetic-uncommitted-token']));
            $this->service()->append($ticket, $user->id);
            $this->assertSame(0, $other->table('tx_user')->where('id', $user->id)->count());
            $this->assertSame(0, $other->table('tx_legacy_user_creation_shadow')->where('legacy_user_id', $user->id)->count());
        } finally { DB::rollBack(); }
        $this->assertSame($count, User::count()); $this->assertSame(0, DB::table('tx_legacy_user_creation_shadow')->count());
        $id = $this->createAccount()->assertCreated()->json('data.id');
        $this->assertSame(1, $other->table('tx_user')->where('id', $id)->count());
        $this->assertSame(1, $other->table('tx_legacy_user_creation_shadow')->where('legacy_user_id', $id)->count());
        DB::purge('creation_observer');
    }

    public function test_creation_tickets_require_original_transaction_policy_and_unique_initial_anchor(): void
    {
        try { $this->service()->begin($this->admin->id); $this->fail('capture outside transaction accepted'); }
        catch (LegacyCaptureShadowException $exception) { $this->assertSame('CAPTURE_TRANSACTION_REQUIRED', $exception->getMessage()); }
        $id = $this->createAccount()->assertCreated()->json('data.id'); $state = $this->state();
        DB::transaction(function () use ($id): void {
            $ticket = $this->service()->begin($this->admin->id); $ticket['source_kind'] = 'admin.user.update';
            try { $this->service()->append($ticket, $id); $this->fail('wrong policy accepted'); }
            catch (LegacyCaptureShadowException $exception) { $this->assertSame('CREATION_SHADOW_TICKET_INVALID', $exception->getMessage()); }
        });
        try {
            DB::transaction(function () use ($id): void {
                DB::table('tx_user')->where('id', $id)->update(['balance' => 100]);
                $this->service()->append($this->service()->begin($this->admin->id), $id);
            });
            $this->fail('duplicate initial anchor was accepted');
        } catch (LegacyCaptureShadowException $exception) {
            $this->assertSame('CREATION_SHADOW_STORAGE_OR_EVIDENCE_FAILED', $exception->getMessage());
        }
        $this->assertSame($state, $this->state());
        DB::transaction(function () use ($id): void {
            try { $this->service()->verify($id); $this->fail('outer snapshot accepted'); }
            catch (LegacyCaptureShadowException $exception) { $this->assertSame('CREATION_SHADOW_OUTER_TRANSACTION_UNSUPPORTED', $exception->getMessage()); }
        });
    }

    public function test_oversized_and_altered_content_evidence_are_rejected_with_fixed_errors(): void
    {
        $id = $this->createAccount()->assertCreated()->json('data.id'); $row = $this->evidence($id);
        DB::table('tx_legacy_user_creation_shadow')->where('legacy_user_id', $id)->update(['initial_snapshot' => json_encode(['large' => str_repeat('x', 9000)])]);
        try { $this->service()->verify($id); $this->fail('oversized snapshot accepted'); }
        catch (LegacyCaptureShadowException $exception) { $this->assertSame('CREATION_SHADOW_EVIDENCE_MISSING_OR_INVALID', $exception->getMessage()); }
        DB::table('tx_legacy_user_creation_shadow')->where('legacy_user_id', $id)->update(['initial_snapshot' => $row->initial_snapshot, 'actor_id' => 0]);
        $this->expectException(LegacyCaptureShadowException::class); $this->expectExceptionMessage('CREATION_SHADOW_EVIDENCE_CHANGED');
        $this->service()->verify($id);
    }

    public function test_additive_schema_rejects_incompatible_column_without_replacing_history(): void
    {
        Schema::drop('tx_legacy_user_creation_shadow');
        Schema::create('tx_legacy_user_creation_shadow', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->bigIncrements('id'); $table->string('legacy_user_id');
        });
        DB::table('tx_legacy_user_creation_shadow')->insert(['legacy_user_id' => 'preserved-incompatible-history']);
        $migration = require database_path('migrations/2026_10_13_000002_create_legacy_user_creation_shadow.php');
        try { $migration->up(); $this->fail('incompatible schema was overwritten'); }
        catch (\RuntimeException) { $this->assertSame('preserved-incompatible-history', DB::table('tx_legacy_user_creation_shadow')->value('legacy_user_id')); }
    }

    private function input(): array
    { return ['email_prefix' => 'new-account', 'email_suffix' => 'example.test', 'password' => 'CreationFixturePassword77', 'return_credentials' => true]; }
    private function createAccount(array $fields = []): \Illuminate\Testing\TestResponse
    { return $this->postJson('/txapi/admin/creation_admin/users', array_replace($this->input(), $fields)); }
    private function plan(): Plan
    { return Plan::create(['name' => 'Creation plan', 'group_id' => 1, 'transfer_enable' => 2, 'speed_limit' => 23,
        'device_limit' => 7, 'prices' => ['monthly' => 1], 'reset_traffic_method' => Plan::RESET_TRAFFIC_FIRST_DAY_MONTH]); }
    private function service(): LegacyAdminCreationShadow { return app(LegacyAdminCreationShadow::class); }
    private function evidence(int $id): object { return DB::table('tx_legacy_user_creation_shadow')->where('legacy_user_id', $id)->firstOrFail(); }
    private function source(int $id): array
    {
        $row = (array) DB::table('tx_user')->where('id', $id)->select(LegacyUserMutationCapture::FIELDS)->first();
        foreach ($row as $key => $value) $row[$key] = $key === 'banned' ? (bool) $value : ($value === null ? null : (string) $value);
        return $this->sort($row);
    }
    private function sort(array $row): array { ksort($row, SORT_STRING); return $row; }
    private function hash(array $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (!is_array($item)) return $item;
            if (!array_is_list($item)) ksort($item, SORT_STRING);
            return array_map($normalize, $item);
        };
        return hash('sha256', json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    private function state(): array
    {
        $out = [];
        foreach (['tx_user', 'tx_legacy_user_creation_shadow', 'tx_legacy_capture_shadow_stream', 'tx_legacy_capture_shadow_event'] as $table)
            $out[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        return $out;
    }
    private function mysql(): bool { return getenv('CORE_ADMIN_CREATION_TEST_MYSQL') === '1'; }
    private function connection(): string { return $this->mysql() ? 'creation_mysql_testing' : 'creation_sqlite_testing'; }
    private function clearMysql(): void
    {
        if (DB::connection()->getDatabaseName() !== 'txboard_admin_creation_test') throw new \RuntimeException('Creation evidence requires its dedicated test database.');
        Schema::disableForeignKeyConstraints();
        try { foreach (Schema::getTableListing() as $table) Schema::drop($table); }
        finally { Schema::enableForeignKeyConstraints(); }
    }
}
