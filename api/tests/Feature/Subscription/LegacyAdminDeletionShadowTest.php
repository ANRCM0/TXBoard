<?php

namespace Tests\Feature\Subscription;

use App\Models\LegacyUserDeletionShadow;
use App\Models\Order;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Services\Subscription\LegacyAdminCreationShadow;
use App\Services\Subscription\LegacyAdminDeletionShadow;
use App\Services\Subscription\LegacyCaptureShadowException;
use App\Services\Subscription\LegacyUserMutationCapture;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LegacyAdminDeletionShadowTest extends TestCase
{
    private User $admin;
    private int $counter = 0;

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
        config(['core_shadow.isolated_copy' => true, 'legacy_admin_deletion_shadow.enabled' => true,
            'legacy_capture_shadow.enabled' => true, 'legacy_capture_shadow.run_key' => 'admin-deletion-fixture',
            'legacy_capture_shadow.billing_timezone' => 'UTC', 'legacy_capture_shadow.currency' => 'USD',
            'legacy_observer_capture.enabled' => false]);
        admin_setting(['secure_path' => 'deletion_admin', 'try_out_plan_id' => 0]);
        $this->admin = $this->user(['is_admin' => true]);
        Sanctum::actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        if ($this->mysql()) $this->clearMysql();
        DB::purge($this->connection());
        parent::tearDown();
    }

    public function test_actual_native_delete_revokes_only_owned_sessions_and_retains_nonsecret_admission_evidence(): void
    {
        $user = $this->user(['transfer_enable' => 9007199254740993, 'speed_limit' => 19, 'banned' => true]);
        $outside = $this->user(); $user->createToken('private-owned-session'); $outside->createToken('outside-session');
        $source = $this->source($user->id);
        $response = $this->deleteAccount($user->id)->assertOk()->assertJsonPath('data.ok', true);
        $this->assertNull(User::find($user->id)); $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(1, $outside->tokens()->count()); $this->assertNotNull($outside->fresh());
        $row = $this->evidence($user->id);
        $this->assertSame($source, $this->sort(json_decode($row->admitted_snapshot, true, flags: JSON_THROW_ON_ERROR)));
        $this->assertSame($this->admin->id, (int) $row->actor_id); $this->assertSame('admin.user.delete', $row->source_kind);
        $this->assertSame(1, (int) $row->deletion_confirmed);
        foreach ([$user->email, $user->password, $user->uuid, $user->token, 'private-owned-session'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($row));
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        foreach (['tx_legacy_capture_shadow_event', 'tx_legacy_subscription_map', 'tx_subscription_terms', 'tx_access_subject'] as $table)
            $this->assertSame(0, DB::table($table)->count());
        $report = $this->service()->verify($user->id);
        $this->assertTrue($report['source_absent']); $this->assertTrue($report['evidence_valid']);
        $this->assertFalse($report['revocation_converged']); $this->assertFalse($report['migration_gate_open']);
    }

    public function test_current_permissions_path_self_protection_and_missing_account_refuse_before_capture(): void
    {
        $target = $this->user(); $staff = $this->user(['is_staff' => true]); $state = $this->state();
        Sanctum::actingAs($staff); $this->deleteAccount($target->id)->assertStatus(403);
        Sanctum::actingAs($this->admin);
        $this->postJson('/txapi/admin/wrong/users/'.$target->id.'/delete')->assertStatus(404);
        $this->deleteAccount($this->admin->id)->assertStatus(409)->assertJsonPath('error.code', 'ACCOUNT_IN_USE');
        $this->deleteAccount(2147483647)->assertStatus(404);
        $this->assertSame($state, $this->state());
    }

    public function test_native_roles_money_traffic_plan_and_order_history_protection_precedes_evidence_context(): void
    {
        config(['legacy_admin_deletion_shadow.enabled' => 'invalid']);
        $cases = [['is_staff' => true], ['is_admin' => true], ['balance' => 1], ['commission_balance' => 1],
            ['u' => 1], ['d' => 1], ['plan_id' => 999]];
        foreach ($cases as $attributes) {
            $user = $this->user($attributes); $user->createToken('retain-protected');
            $this->deleteAccount($user->id)->assertStatus(409)->assertJsonPath('error.code', 'ACCOUNT_IN_USE');
            $this->assertNotNull($user->fresh()); $this->assertSame(1, $user->tokens()->count());
        }
        $buyer = $this->user();
        Order::create(['user_id' => $buyer->id, 'plan_id' => 0, 'trade_no' => 'DELETE-HISTORY-SYNTHETIC',
            'period' => 'monthly', 'total_amount' => 100, 'status' => Order::STATUS_COMPLETED, 'type' => Order::TYPE_NEW_PURCHASE]);
        $this->deleteAccount($buyer->id)->assertStatus(409); $this->assertNotNull($buyer->fresh());
        $this->assertSame(0, DB::table('tx_legacy_user_deletion_shadow')->count());
    }

    public function test_existing_optional_parent_account_reference_still_protects_the_parent(): void
    {
        $this->assertFalse(Schema::hasColumn('tx_user', 'parent_id'));
        Schema::table('tx_user', fn (\Illuminate\Database\Schema\Blueprint $table) => $table->integer('parent_id')->nullable());
        $parent = $this->user(); $child = $this->user(); $parent->createToken('retain-parent');
        DB::table('tx_user')->where('id', $child->id)->update(['parent_id' => $parent->id]);
        $this->deleteAccount($parent->id)->assertStatus(409)->assertJsonPath('error.code', 'ACCOUNT_IN_USE');
        $this->assertNotNull($parent->fresh()); $this->assertSame(1, $parent->tokens()->count());
        $this->assertSame(0, DB::table('tx_legacy_user_deletion_shadow')->count());
    }

    public function test_disabled_production_retains_native_delete_with_missing_evidence_table_and_zero_capture_sql(): void
    {
        $user = $this->user(); $user->createToken('old');
        config(['legacy_admin_deletion_shadow.enabled' => false, 'legacy_capture_shadow.enabled' => 'invalid', 'core_shadow.isolated_copy' => false]);
        $this->app['env'] = 'production'; Schema::drop('tx_legacy_user_deletion_shadow');
        DB::enableQueryLog(); DB::flushQueryLog(); $this->deleteAccount($user->id)->assertOk();
        $this->assertSame([], array_values(array_filter(DB::getQueryLog(), fn ($q) => preg_match('/tx_legacy_user_deletion_shadow|tx_legacy_capture_shadow/', $q['query']))));
        $this->assertNull(User::find($user->id)); $this->assertSame(0, $user->tokens()->count());
    }

    public function test_strict_switch_isolation_and_context_refuse_without_deleting_user_or_sessions(): void
    {
        $user = $this->user(); $user->createToken('retain'); $state = $this->state();
        foreach ([1, '1', null] as $flag) { config(['legacy_admin_deletion_shadow.enabled' => $flag]); $this->deleteAccount($user->id)->assertStatus(409); }
        config(['legacy_admin_deletion_shadow.enabled' => true, 'legacy_capture_shadow.enabled' => false]); $this->deleteAccount($user->id)->assertStatus(409);
        config(['legacy_capture_shadow.enabled' => true, 'core_shadow.isolated_copy' => false]); $this->deleteAccount($user->id)->assertStatus(409);
        config(['core_shadow.isolated_copy' => true]); $this->app['env'] = 'production'; $this->deleteAccount($user->id)->assertStatus(409);
        $this->app['env'] = 'testing'; config(['legacy_capture_shadow.currency' => 'JPY']); $this->deleteAccount($user->id)->assertStatus(409);
        $this->assertSame($state, $this->state());
    }

    public function test_missing_evidence_table_restores_deleted_user_and_sessions_with_fixed_safe_error(): void
    {
        $user = $this->user(); $user->createToken('retain'); $state = $this->state(); Schema::drop('tx_legacy_user_deletion_shadow');
        $response = $this->deleteAccount($user->id)->assertStatus(409);
        $this->assertSame($state['tx_user'], DB::table('tx_user')->orderBy('id')->get()->toJson());
        $this->assertSame($state['personal_access_tokens'], DB::table('personal_access_tokens')->orderBy('id')->get()->toJson());
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString($user->token, $response->getContent()); Bus::assertNothingDispatched();
    }

    public function test_failure_after_evidence_insert_rolls_back_user_sessions_and_evidence_and_after_hook_does_not_run(): void
    {
        $user = $this->user(); $user->createToken('retain'); $state = $this->state(); $active = true; $after = 0;
        HookManager::register('admin.user.destroy.after', function () use (&$after): void { ++$after; });
        DB::listen(function ($query) use (&$active): void {
            if ($active && preg_match('/^insert.*tx_legacy_user_deletion_shadow/i', $query->sql)) throw new \RuntimeException('private-deletion-storage-detail');
        });
        try { $response = $this->deleteAccount($user->id)->assertStatus(409); } finally { $active = false; }
        $this->assertSame($state, $this->state()); $this->assertSame(0, $after); Bus::assertNothingDispatched();
        $this->assertStringNotContainsString('private-deletion-storage-detail', $response->getContent());
    }

    public function test_cancelled_native_model_delete_preserves_success_response_and_session_removal_without_fake_evidence(): void
    {
        $user = $this->user(); $user->createToken('removed-before-cancel');
        Event::listen('eloquent.deleting: '.User::class, fn (User $model) => $model->id === $user->id ? false : null);
        $this->deleteAccount($user->id)->assertOk()->assertJsonPath('data.ok', true);
        $this->assertNotNull($user->fresh()); $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(0, DB::table('tx_legacy_user_deletion_shadow')->count()); Bus::assertNothingDispatched();
    }

    public function test_trusted_hook_changes_are_not_misrepresented_as_final_predelete_state(): void
    {
        $user = $this->user(['speed_limit' => 3]); $before = $this->source($user->id); $observed = null;
        HookManager::register('admin.user.destroy.before', function (array $payload) use ($user): void {
            DB::table('tx_user')->where('id', $user->id)->update(['speed_limit' => 99]);
        });
        Event::listen('eloquent.deleting: '.User::class, function (User $model) use (&$observed): void {
            $observed = DB::table('tx_user')->where('id', $model->id)->value('speed_limit');
        });
        $this->deleteAccount($user->id)->assertOk();
        $this->assertSame(99, (int) $observed); $this->assertSame($before, $this->sort(json_decode($this->evidence($user->id)->admitted_snapshot, true)));
        $this->assertFalse($this->service()->verify($user->id)['final_predelete_state_verified']);
    }

    public function test_context_drift_in_native_destroy_hook_rolls_back_delete_and_session_removal(): void
    {
        $user = $this->user(); $user->createToken('retain'); $state = $this->state();
        HookManager::register('admin.user.destroy.before', fn () => config(['legacy_capture_shadow.run_key' => 'changed-context']));
        $this->deleteAccount($user->id)->assertStatus(409); $this->assertSame($state, $this->state()); Bus::assertNothingDispatched();
    }

    public function test_native_after_commit_hook_failure_can_hide_a_committed_deletion_and_does_not_retry(): void
    {
        $user = $this->user(); $user->createToken('removed');
        HookManager::register('admin.user.destroy.after', function (): void { throw new \RuntimeException('synthetic-after-commit-failure'); });
        $this->deleteAccount($user->id)->assertStatus(500);
        $this->assertNull(User::find($user->id)); $this->assertSame(0, $user->tokens()->count());
        $this->assertTrue($this->service()->verify($user->id)['source_absent']);
        $this->deleteAccount($user->id)->assertStatus(404); $this->assertSame(1, DB::table('tx_legacy_user_deletion_shadow')->count());
    }

    public function test_creation_and_mutation_evidence_survive_native_deletion_and_are_not_rewritten(): void
    {
        config(['legacy_admin_creation_shadow.enabled' => true]);
        $id = $this->postJson('/txapi/admin/deletion_admin/users', ['email_prefix' => 'created', 'email_suffix' => 'example.test',
            'password' => 'CreationAndDeletionFixture77'])->assertCreated()->json('data.id');
        $this->postJson('/txapi/admin/deletion_admin/users/'.$id.'/update', ['speed_limit' => 13])->assertOk();
        $creation = DB::table('tx_legacy_user_creation_shadow')->get()->toJson();
        $events = DB::table('tx_legacy_capture_shadow_event')->get()->toJson();
        $this->deleteAccount($id)->assertOk();
        $this->assertSame($creation, DB::table('tx_legacy_user_creation_shadow')->get()->toJson());
        $this->assertSame($events, DB::table('tx_legacy_capture_shadow_event')->get()->toJson());
        $report = app(LegacyAdminCreationShadow::class)->verify($id);
        $this->assertSame(['CREATION_SHADOW_SOURCE_MISSING'], $report['issues']);
        $this->assertTrue($report['first_mutation_before_matches_initial']);
        $this->assertTrue($this->service()->verify($id)['source_absent']);
    }

    public function test_cli_is_bounded_select_only_and_requires_explicit_user_and_isolated_marker(): void
    {
        $user = $this->user(); $this->deleteAccount($user->id)->assertOk(); $state = $this->state();
        $this->assertSame(1, Artisan::call('core:admin-deletion-shadow-verify', ['--user' => (string) $user->id]));
        $this->assertSame(1, Artisan::call('core:admin-deletion-shadow-verify', ['--user' => '01', '--isolated-copy' => true]));
        DB::enableQueryLog(); DB::flushQueryLog();
        $this->assertSame(0, Artisan::call('core:admin-deletion-shadow-verify', ['--user' => (string) $user->id, '--isolated-copy' => true]));
        foreach (DB::getQueryLog() as $query) $this->assertMatchesRegularExpression('/^select/i', $query['query']);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($report['source_absent']); $this->assertFalse($report['global_coverage_complete']);
        $this->assertSame($state, $this->state());
    }

    public function test_reappearance_is_reported_and_repeated_delete_does_not_overwrite_unique_history(): void
    {
        $user = $this->user(); $attributes = $user->getAttributes(); $this->deleteAccount($user->id)->assertOk();
        $hash = $this->evidence($user->id)->content_hash; DB::table('tx_user')->insert($attributes);
        $report = $this->service()->verify($user->id);
        $this->assertSame(['DELETION_SHADOW_SOURCE_REAPPEARED'], $report['issues']); $this->assertFalse($report['source_absent']);
        $this->deleteAccount($user->id)->assertStatus(409);
        $this->assertNotNull(User::find($user->id)); $this->assertSame($hash, $this->evidence($user->id)->content_hash);
    }

    public function test_immutable_model_additive_replay_and_populated_down_preserve_history(): void
    {
        $user = $this->user(); $this->deleteAccount($user->id)->assertOk(); $state = $this->state();
        $model = LegacyUserDeletionShadow::where('legacy_user_id', $user->id)->firstOrFail();
        foreach (['save', 'delete'] as $method) {
            try { $model->actor_id = 999; $model->$method(); $this->fail('immutable history changed'); }
            catch (\LogicException $exception) { $this->assertStringContainsString('history', $exception->getMessage()); }
        }
        $migration = require database_path('migrations/2026_10_13_000003_create_legacy_user_deletion_shadow.php');
        $migration->up(); $this->assertSame($state, $this->state());
        try { $migration->down(); $this->fail('populated table dropped'); }
        catch (\RuntimeException $exception) { $this->assertSame('DELETION_SHADOW_POPULATED_ROLLBACK_REFUSED', $exception->getMessage()); }
        $this->assertSame($state, $this->state());
    }

    public function test_snapshot_reordering_is_stable_but_tampered_content_and_oversized_payload_refuse(): void
    {
        $user = $this->user(); $this->deleteAccount($user->id)->assertOk(); $row = $this->evidence($user->id);
        DB::table('tx_legacy_user_deletion_shadow')->where('legacy_user_id', $user->id)->update([
            'admitted_snapshot' => json_encode(array_reverse(json_decode($row->admitted_snapshot, true), true)),
            'context' => json_encode(array_reverse(json_decode($row->context, true), true))]);
        $this->assertTrue($this->service()->verify($user->id)['source_absent']);
        DB::table('tx_legacy_user_deletion_shadow')->where('legacy_user_id', $user->id)->update(['admitted_snapshot' => json_encode(['oversize' => str_repeat('x', 9000)])]);
        try { $this->service()->verify($user->id); $this->fail('oversize accepted'); }
        catch (LegacyCaptureShadowException $exception) { $this->assertSame('DELETION_SHADOW_EVIDENCE_MISSING_OR_INVALID', $exception->getMessage()); }
        DB::table('tx_legacy_user_deletion_shadow')->where('legacy_user_id', $user->id)->update(['admitted_snapshot' => $row->admitted_snapshot, 'deletion_confirmed' => false]);
        $this->expectException(LegacyCaptureShadowException::class); $this->expectExceptionMessage('DELETION_SHADOW_EVIDENCE_CHANGED');
        $this->service()->verify($user->id);
    }

    public function test_transaction_policy_current_context_and_invalid_source_are_checked(): void
    {
        $user = $this->user();
        try { $this->service()->begin($user->id, $this->admin->id); $this->fail('outerless capture accepted'); }
        catch (LegacyCaptureShadowException $exception) { $this->assertSame('CAPTURE_TRANSACTION_REQUIRED', $exception->getMessage()); }
        DB::transaction(function () use ($user): void {
            $ticket = $this->service()->begin($user->id, $this->admin->id); $ticket['source_kind'] = 'admin.user.create';
            try { $this->service()->append($ticket); $this->fail('wrong policy accepted'); }
            catch (LegacyCaptureShadowException $exception) { $this->assertSame('DELETION_SHADOW_TICKET_INVALID', $exception->getMessage()); }
            try { $this->service()->verify($user->id); $this->fail('outer verification accepted'); }
            catch (LegacyCaptureShadowException $exception) { $this->assertSame('DELETION_SHADOW_OUTER_TRANSACTION_UNSUPPORTED', $exception->getMessage()); }
        });
        DB::table('tx_user')->where('id', $user->id)->update(['transfer_enable' => -1]); $state = $this->state();
        $this->deleteAccount($user->id)->assertStatus(409); $this->assertSame($state, $this->state());
    }

    public function test_real_mysql_independent_session_observes_atomic_commit_and_rollback_of_user_sessions_and_evidence(): void
    {
        if (!$this->mysql()) $this->markTestSkipped('Requires dedicated MySQL independent transaction visibility.');
        $user = $this->user(); $user->createToken('retain');
        config(['database.connections.deletion_observer' => config('database.connections.'.$this->connection())]);
        DB::purge('deletion_observer'); $other = DB::connection('deletion_observer'); $state = $this->state();
        DB::beginTransaction();
        try {
            User::lockForUpdate()->findOrFail($user->id); $ticket = $this->service()->begin($user->id, $this->admin->id);
            $user->tokens()->delete(); DB::table('tx_user')->where('id', $user->id)->delete(); $this->service()->append($ticket);
            $this->assertSame(1, $other->table('tx_user')->where('id', $user->id)->count());
            $this->assertSame(1, $other->table('personal_access_tokens')->where('tokenable_id', $user->id)->count());
            $this->assertSame(0, $other->table('tx_legacy_user_deletion_shadow')->where('legacy_user_id', $user->id)->count());
        } finally { DB::rollBack(); }
        $this->assertSame($state, $this->state()); $this->deleteAccount($user->id)->assertOk();
        $this->assertSame(0, $other->table('tx_user')->where('id', $user->id)->count());
        $this->assertSame(0, $other->table('personal_access_tokens')->where('tokenable_id', $user->id)->count());
        $this->assertSame(1, $other->table('tx_legacy_user_deletion_shadow')->where('legacy_user_id', $user->id)->count());
        DB::purge('deletion_observer');
    }

    private function user(array $attributes = []): User
    {
        $n = ++$this->counter;
        return User::withoutEvents(fn () => User::create(array_replace(['email' => 'delete-'.$n.'@example.test',
            'password' => 'synthetic-deletion-password', 'uuid' => sprintf('b0000000-0000-4000-8000-%012d', $n),
            'token' => sprintf('%032d', $n)], $attributes)));
    }
    private function deleteAccount(int $id): \Illuminate\Testing\TestResponse
    { return $this->postJson('/txapi/admin/deletion_admin/users/'.$id.'/delete'); }
    private function service(): LegacyAdminDeletionShadow { return app(LegacyAdminDeletionShadow::class); }
    private function evidence(int $id): object { return DB::table('tx_legacy_user_deletion_shadow')->where('legacy_user_id', $id)->firstOrFail(); }
    private function sort(array $row): array { ksort($row, SORT_STRING); return $row; }
    private function source(int $id): array
    {
        $row = (array) DB::table('tx_user')->where('id', $id)->select(LegacyUserMutationCapture::FIELDS)->first();
        foreach ($row as $key => $value) $row[$key] = $key === 'banned' ? (bool) $value : ($value === null ? null : (string) $value);
        return $this->sort($row);
    }
    private function state(): array
    {
        $out = [];
        foreach (['tx_user', 'personal_access_tokens', 'tx_legacy_user_deletion_shadow', 'tx_legacy_capture_shadow_event'] as $table)
            $out[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        return $out;
    }
    private function mysql(): bool { return getenv('CORE_ADMIN_DELETION_TEST_MYSQL') === '1'; }
    private function connection(): string { return $this->mysql() ? 'deletion_mysql_testing' : 'deletion_sqlite_testing'; }
    private function clearMysql(): void
    {
        if (DB::connection()->getDatabaseName() !== 'txboard_admin_deletion_test') throw new \RuntimeException('Deletion evidence requires its dedicated test database.');
        Schema::disableForeignKeyConstraints();
        try { foreach (Schema::getTableListing() as $table) Schema::drop($table); }
        finally { Schema::enableForeignKeyConstraints(); }
    }
}
