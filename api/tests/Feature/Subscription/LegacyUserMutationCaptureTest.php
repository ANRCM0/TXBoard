<?php

namespace Tests\Feature\Subscription;

use App\Models\LegacyCaptureShadowEvent;
use App\Models\Plan;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Services\Subscription\LegacyCaptureShadowException;
use App\Services\Subscription\LegacySubscriptionSnapshot;
use App\Services\Subscription\LegacyUserMutationCapture;
use App\Services\Subscription\SubscriptionShadowBackfill;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LegacyUserMutationCaptureTest extends TestCase
{
    private int $counter = 0;
    private User $admin;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $connection = $this->mysql() ? 'capture_mysql_testing' : 'capture_sqlite_testing';
        config(['database.default' => $connection, 'database.connections.'.$connection => $this->mysql()
            ? config('database.connections.mysql') : array_replace(config('database.connections.sqlite'),
                ['database' => ':memory:', 'foreign_key_constraints' => true])]);
        DB::purge($connection);
        if ($this->mysql()) { $this->clearMysql(); }
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        config(['core_shadow.isolated_copy' => true, 'legacy_capture_shadow.enabled' => true,
            'legacy_capture_shadow.run_key' => 'capture-fixture', 'legacy_capture_shadow.billing_timezone' => 'UTC',
            'legacy_capture_shadow.currency' => 'USD', 'cache.stores.redis' => ['driver' => 'array']]);
        admin_setting(['secure_path' => 'capture_admin']);
        $this->plan = Plan::create(['name' => 'capture plan', 'group_id' => 1, 'transfer_enable' => 1,
            'prices' => ['monthly' => 1], 'reset_traffic_method' => 0]);
        $this->admin = $this->user(['is_admin' => true]);
        Sanctum::actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        if ($this->mysql()) { $this->clearMysql(); }
        DB::purge($this->mysql() ? 'capture_mysql_testing' : 'capture_sqlite_testing');
        parent::tearDown();
    }

    public function test_real_admin_request_captures_exact_raw_source_and_atomically_invalidates_map(): void
    {
        $user = $this->user(['u' => 9007199254740993]); $this->backfill($user);
        $before = $this->projectionState();
        $this->edit($user, ['u' => '9007199254740995', 'expected_u' => '9007199254740993',
            'balance' => '15.25', 'expected_balance_minor' => 0, 'commission_balance' => '0.01',
            'expected_commission_balance_minor' => 0, 'remarks' => 'capture-fixture-secret-marker'])->assertOk()
            ->assertJsonPath('data.ok', true)->assertJsonPath('data.id', $user->id);
        $event = DB::table('tx_legacy_capture_shadow_event')->first();
        $rawBefore = json_decode($event->before_snapshot, true, flags: JSON_THROW_ON_ERROR);
        $rawAfter = json_decode($event->after_snapshot, true, flags: JSON_THROW_ON_ERROR);
        $this->assertEqualsCanonicalizing(LegacyUserMutationCapture::FIELDS, array_keys($rawAfter));
        $this->assertSame('9007199254740993', $rawBefore['u']);
        $this->assertSame('9007199254740995', $rawAfter['u']);
        $this->assertSame('1525', $rawAfter['balance']); $this->assertSame('1', $rawAfter['commission_balance']);
        $this->assertFalse($rawAfter['banned']);
        foreach ([$user->email, $user->token, $user->uuid, $user->password, 'capture-fixture-secret-marker'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($event));
        }
        $map = DB::table('tx_legacy_subscription_map')->where('legacy_user_id', $user->id)->first();
        $this->assertSame('source_changed', $map->state); $this->assertSame('LEGACY_CAPTURE_PENDING', $map->error_code);
        $this->assertSame(2, (int) $map->row_version);
        $this->assertSame($before, $this->projectionState());
        $this->assertTrue($this->capture()->verify($user->id)['page_ok']);
    }

    public function test_disabled_is_zero_capture_sql_and_keeps_native_editor_behavior(): void
    {
        $user = $this->user(); config(['legacy_capture_shadow.enabled' => false]);
        app()->instance('env', 'production'); config(['core_shadow.isolated_copy' => false]);
        DB::enableQueryLog(); DB::flushQueryLog();
        $this->edit($user, ['balance' => '1.01', 'expected_balance_minor' => 0])->assertOk();
        $this->assertSame([], array_values(array_filter(DB::getQueryLog(), fn ($q) =>
            preg_match('/tx_legacy_capture_shadow|tx_legacy_subscription_map/', $q['query']))));
        $this->assertSame(101, (int) $user->fresh()->balance);
    }

    public function test_metadata_and_identical_source_do_not_create_event_or_invalidate_map(): void
    {
        $user = $this->user(); $this->backfill($user);
        $map = DB::table('tx_legacy_subscription_map')->get()->toJson();
        $this->edit($user, ['remarks' => 'metadata only', 'discount' => 1])->assertOk();
        $this->edit($user, ['u' => 10, 'expected_u' => 10, 'balance' => '0.00', 'expected_balance_minor' => 0])->assertOk();
        $this->assertSame(0, DB::table('tx_legacy_capture_shadow_event')->count());
        $this->assertSame(0, DB::table('tx_legacy_capture_shadow_stream')->count());
        $this->assertSame($map, DB::table('tx_legacy_subscription_map')->get()->toJson());
        $this->edit($user, ['speed_limit' => 2])->assertOk();
        $this->edit($user, ['speed_limit' => 2])->assertOk();
        $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count());
    }

    public function test_auth_and_cas_are_preserved_and_failures_do_not_append(): void
    {
        $user = $this->user(); Sanctum::actingAs($user);
        $this->edit($user, ['speed_limit' => 2])->assertStatus(403);
        Sanctum::actingAs($this->admin);
        $this->postJson('/txapi/admin/wrong/users/'.$user->id.'/update', ['speed_limit' => 2])->assertStatus(404);
        $this->edit($user, ['is_admin' => true])->assertStatus(422);
        $this->edit($user, ['u' => 15])->assertStatus(422);
        $this->edit($user, ['u' => 15, 'expected_u' => 9])->assertStatus(422);
        $this->edit($user, ['balance' => '1.001', 'expected_balance_minor' => 0])->assertStatus(422);
        $this->edit($user, ['plan_id' => 999999, 'expected_plan_id' => $this->plan->id])->assertStatus(404);
        $this->assertSame(0, DB::table('tx_legacy_capture_shadow_event')->count());
        $this->assertSame(10, (int) $user->fresh()->u);
    }

    public function test_strict_config_and_context_drift_abort_without_exposing_input(): void
    {
        $user = $this->user();
        foreach (['1', 1, null] as $invalid) {
            config(['legacy_capture_shadow.enabled' => $invalid]);
            $this->edit($user, ['speed_limit' => 2])->assertStatus(409)->assertJsonPath('error.code', 'CONFLICT');
        }
        config(['legacy_capture_shadow.enabled' => true, 'core_shadow.isolated_copy' => false]);
        $this->edit($user, ['speed_limit' => 2])->assertStatus(409);
        config(['core_shadow.isolated_copy' => true, 'legacy_capture_shadow.currency' => 'JPY']);
        $this->edit($user, ['speed_limit' => 2])->assertStatus(409);
        config(['legacy_capture_shadow.currency' => 'USD']);
        app()->instance('env', 'production'); $this->edit($user, ['speed_limit' => 2])->assertStatus(409);
        app()->instance('env', 'testing'); $this->edit($user, ['speed_limit' => 2])->assertOk();
        config(['legacy_capture_shadow.currency' => 'CNY']);
        $response = $this->edit($user, ['speed_limit' => 3])->assertStatus(409);
        $this->assertStringNotContainsString($user->email, $response->getContent());
        $this->assertSame(2, (int) $user->fresh()->speed_limit);
        $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count());
    }

    public function test_capture_storage_failure_rolls_back_saved_user_event_stream_and_mapping(): void
    {
        $user = $this->user(); $this->backfill($user); $before = $this->state();
        // Failure after event INSERT, before map commit, without a production fault switch.
        DB::listen(function ($query): void {
            if (str_starts_with(strtolower($query->sql), 'insert into')
                && str_contains($query->sql, 'tx_legacy_capture_shadow_event')) {
                throw new \RuntimeException('must-never-leak-fixture-input@example.test');
            }
        });
        $response = $this->edit($user, ['balance' => '1.25', 'expected_balance_minor' => 0])->assertStatus(409);
        $this->assertStringNotContainsString('must-never-leak', $response->getContent());
        $this->assertSame($before, $this->state());
    }

    public function test_actual_in_transaction_hook_effect_is_captured_but_post_commit_writer_remains_uncovered(): void
    {
        $user = $this->user();
        HookManager::register('admin.user.update.before', function (array $event): void {
            DB::table('tx_user')->where('id', $event['user']->id)->update(['d' => 88]);
        });
        HookManager::register('admin.user.update.after', function (array $event): void {
            DB::table('tx_user')->where('id', $event['user']->id)->update(['u' => 99]);
        });
        $this->edit($user, ['speed_limit' => 2])->assertOk();
        $after = json_decode(DB::table('tx_legacy_capture_shadow_event')->value('after_snapshot'), true);
        $this->assertSame('88', $after['d']); $this->assertSame('10', $after['u']);
        $this->assertContains('CAPTURE_SOURCE_GAP', $this->capture()->verify($user->id)['issues']);
    }

    public function test_unknown_writer_endpoint_gap_blocks_next_event_and_never_opens_global_gate(): void
    {
        $user = $this->user(); $this->edit($user, ['speed_limit' => 2])->assertOk();
        DB::table('tx_user')->where('id', $user->id)->increment('u');
        $this->edit($user, ['speed_limit' => 3])->assertStatus(409);
        $this->assertSame(2, (int) $user->fresh()->speed_limit);
        $report = $this->capture()->verify($user->id);
        $this->assertFalse($report['page_ok']); $this->assertFalse($report['global_coverage_complete']);
        $this->assertFalse($report['projection_applied']); $this->assertFalse($report['migration_gate_open']);
        $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count());
    }

    public function test_chain_pages_and_empty_tail_are_bounded_select_only_and_unapplied(): void
    {
        $user = $this->user(); $this->backfill($user);
        foreach ([1, 2, 3] as $speed) { $this->edit($user, ['speed_limit' => $speed])->assertOk(); }
        $before = $this->state(); DB::enableQueryLog(); DB::flushQueryLog();
        $one = $this->capture()->verify($user->id, 0, 1);
        $two = $this->capture()->verify($user->id, 1, 1);
        $three = $this->capture()->verify($user->id, 2, 1);
        $empty = $this->capture()->verify($user->id, 3, 1);
        foreach ([$one, $two, $three, $empty] as $report) {
            $this->assertTrue($report['page_ok']); $this->assertTrue($report['read_only']);
            $this->assertFalse($report['projection_applied']); $this->assertSame('3', $report['high_water']);
            $this->assertContains('CAPTURE_PROJECTION_UNAPPLIED', $report['projection_issues']);
        }
        $this->assertTrue($one['has_more']); $this->assertTrue($two['has_more']);
        $this->assertFalse($three['has_more']); $this->assertFalse($empty['has_more']);
        $this->assertSame('3', $empty['next_cursor']); $this->assertSame([], $empty['events']);
        foreach (DB::getQueryLog() as $query) { $this->assertMatchesRegularExpression('/^select/i', $query['query']); }
        $this->assertSame($before, $this->state());
    }

    public function test_drift_after_a_verified_page_is_detected_on_following_empty_page(): void
    {
        $user = $this->user(); $this->edit($user, ['speed_limit' => 1])->assertOk();
        $this->assertTrue($this->capture()->verify($user->id, 0, 1)['page_ok']);
        DB::table('tx_user')->where('id', $user->id)->update(['balance' => 1]);
        $this->assertContains('CAPTURE_SOURCE_GAP', $this->capture()->verify($user->id, 1, 1)['issues']);
    }

    public function test_missing_stream_mapping_and_unknown_period_do_not_become_applied_or_clean_gate(): void
    {
        $user = $this->user();
        $this->assertSame(['CAPTURE_STREAM_MISSING'], $this->capture()->verify($user->id)['issues']);
        $this->edit($user, ['speed_limit' => 1])->assertOk();
        $this->assertContains('CAPTURE_MAPPING_MISSING', $this->capture()->verify($user->id)['projection_issues']);
        $this->backfill($user);
        $this->assertContains('CAPTURE_MAPPING_NOT_INVALIDATED', $this->capture()->verify($user->id)['projection_issues']);
        $this->edit($user, ['speed_limit' => 2])->assertOk();
        $this->assertContains('CAPTURE_PERIOD_BOUNDARY_UNPROVEN', $this->capture()->verify($user->id)['projection_issues']);
    }

    public function test_tampered_payload_and_stream_tail_are_rejected_even_on_empty_page(): void
    {
        $user = $this->user(); $this->edit($user, ['speed_limit' => 1])->assertOk();
        DB::table('tx_legacy_capture_shadow_event')->update(['actor_id' => $user->id]);
        $this->assertFailure('CAPTURE_EVENT_TAMPERED', fn () => $this->capture()->verify($user->id, 1));
    }

    public function test_tail_pointer_and_deleted_middle_sequence_cannot_pass_bounded_pages(): void
    {
        $user = $this->user(); foreach ([1, 2, 3] as $speed) { $this->edit($user, ['speed_limit' => $speed])->assertOk(); }
        DB::table('tx_legacy_capture_shadow_stream')->update(['last_after_hash' => str_repeat('0', 64)]);
        $this->assertFailure('CAPTURE_STREAM_TAMPERED', fn () => $this->capture()->verify($user->id, 3));
        $tail = DB::table('tx_legacy_capture_shadow_event')->where('sequence', 3)->first();
        DB::table('tx_legacy_capture_shadow_stream')->update(['last_after_hash' => $tail->after_hash]);
        DB::table('tx_legacy_capture_shadow_event')->where('sequence', 2)->delete();
        $this->assertFailure('CAPTURE_CHAIN_GAP', fn () => $this->capture()->verify($user->id, 1, 1));
        $this->assertFailure('CAPTURE_CURSOR_AHEAD', fn () => $this->capture()->verify($user->id, 4));
    }

    public function test_pending_and_settled_b3_evidence_are_readonly_projection_blockers(): void
    {
        $user = $this->user(); $this->backfill($user); $this->edit($user, ['speed_limit' => 1])->assertOk();
        $map = DB::table('tx_legacy_subscription_map')->where('legacy_user_id', $user->id)->first();
        $source = DB::table('tx_node_usage_source')->insertGetId(['node_id' => 1, 'source_epoch' => 1,
            'installation_ref' => 'capture-synthetic-source', 'core_instance_id' => 'fixture',
            'receipt_generation' => 1, 'created_at' => time()]);
        $entries = [['subscription_id' => (string) $map->subscription_id]];
        $batch = DB::table('tx_usage_batch')->insertGetId(['source_id' => $source, 'node_id' => 1,
            'source_epoch' => 1, 'batch_id' => 'capture-synthetic-batch', 'core_instance_id' => 'fixture',
            'receipt_generation' => 1, 'receipt_id' => 'capture-synthetic-receipt', 'payload_digest' => str_repeat('0', 71),
            'canonical_payload' => '{}', 'frozen_entries' => json_encode($entries),
            'frozen_hash' => hash('sha256', json_encode($entries)), 'started_at' => 1, 'ended_at' => 2,
            'accepted_at' => time(), 'updated_at' => time()]);
        $before = $this->state(); DB::enableQueryLog(); DB::flushQueryLog();
        $this->assertContains('CAPTURE_PENDING_USAGE', $this->capture()->verify($user->id)['projection_issues']);
        foreach (DB::getQueryLog() as $query) {
            if (str_contains($query['query'], 'tx_usage_batch') || str_contains($query['query'], 'tx_usage_entry')) {
                $this->assertStringNotContainsString('for update', strtolower($query['query']));
                $this->assertStringNotContainsString('lock in share mode', strtolower($query['query']));
            }
        }
        $this->assertSame($before, $this->state());
        $tariff = DB::table('tx_tariff_revision')->insertGetId(['node_id' => 1, 'numerator' => 1, 'denominator' => 1,
            'effective_from' => 1, 'effective_until' => 100, 'content_hash' => str_repeat('1', 64), 'created_at' => time()]);
        $auth = DB::table('tx_auth_snapshot')->insertGetId(['source_id' => $source, 'user_id' => $user->id,
            'access_subject_id' => $map->access_subject_id, 'subscription_id' => $map->subscription_id,
            'terms_id' => $map->terms_id, 'usage_period_id' => $map->usage_period_id, 'tariff_revision_id' => $tariff,
            'auth_version' => 1, 'revocation_version' => 0, 'issued_at' => 1, 'lease_expires_at' => 100,
            'content_hash' => str_repeat('2', 64), 'created_at' => time()]);
        DB::table('tx_usage_batch')->where('id', $batch)->update(['status' => 'settled']);
        DB::table('tx_usage_entry')->insert(['batch_id' => $batch, 'entry_no' => 1, 'subscription_id' => $map->subscription_id,
            'usage_period_id' => $map->usage_period_id, 'access_subject_id' => $map->access_subject_id,
            'auth_snapshot_id' => $auth, 'tariff_revision_id' => $tariff, 'terms_id' => $map->terms_id,
            'raw_up_bytes' => 1, 'raw_down_bytes' => 1, 'billed_up_bytes' => 1, 'billed_down_bytes' => 1,
            'numerator' => 1, 'denominator' => 1, 'rounding_revision' => 'floor-per-direction-v1', 'created_at' => time()]);
        $before = $this->state();
        $this->assertContains('CAPTURE_ACCEPTED_USAGE', $this->capture()->verify($user->id)['projection_issues']);
        $this->assertSame($before, $this->state());
        DB::table('tx_usage_batch')->where('id', $batch)->update(['status' => 'pending',
            'frozen_entries' => json_encode([['subscription_id' => (string) $map->subscription_id,
                'fixture_padding' => str_repeat('x', 262144)]])]);
        DB::enableQueryLog(); DB::flushQueryLog();
        $this->assertContains('CAPTURE_USAGE_SCOPE_LIMIT', $this->capture()->verify($user->id)['projection_issues']);
        $payloadReads = array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'tx_usage_batch')
            && !str_contains(strtolower($q['query']), 'length(frozen_entries)'));
        $this->assertSame([], array_values($payloadReads), 'Oversized evidence was loaded before its byte guard.');
    }

    public function test_missing_event_table_is_restored_but_incompatible_schema_is_never_replaced(): void
    {
        Schema::drop('tx_legacy_capture_shadow_event'); $this->migration()->up();
        $this->assertTrue(Schema::hasTable('tx_legacy_capture_shadow_event'));
        Schema::table('tx_legacy_capture_shadow_event', fn ($t) => $t->dropColumn('source_version'));
        $this->expectExceptionMessage('Shadow schema mismatch: tx_legacy_capture_shadow_event.source_version');
        $this->migration()->up();
    }

    public function test_duplicate_sequence_database_constraint_and_immutable_model_protect_history(): void
    {
        $user = $this->user(); $this->edit($user, ['speed_limit' => 1])->assertOk();
        $event = LegacyCaptureShadowEvent::query()->firstOrFail();
        try { $event->actor_id = $user->id; $event->save(); $this->fail('Mutable event.'); }
        catch (\LogicException) { $this->assertTrue(true); }
        try { $event->delete(); $this->fail('Deleted history.'); }
        catch (\LogicException) { $this->assertTrue(true); }
        $row = (array) DB::table('tx_legacy_capture_shadow_event')->first(); unset($row['id']);
        try { DB::table('tx_legacy_capture_shadow_event')->insert($row); $this->fail('Duplicate sequence.'); }
        catch (\Illuminate\Database\QueryException) { $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count()); }
    }

    public function test_additive_migration_replay_preserves_history_and_refuses_populated_rollback(): void
    {
        $user = $this->user(); $this->edit($user, ['speed_limit' => 1])->assertOk();
        $before = $this->state(); $this->migration()->up(); $this->assertSame($before, $this->state());
        $this->expectExceptionMessage('CAPTURE_POPULATED_ROLLBACK_REFUSED'); $this->migration()->down();
    }

    public function test_native_legacy_user_deletion_is_not_blocked_by_capture_foreign_keys(): void
    {
        $user = $this->user(); $this->edit($user, ['speed_limit' => 1])->assertOk();
        $this->assertSame(1, DB::table('tx_user')->where('id', $user->id)->delete());
        $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count());
        $this->assertFailure('CAPTURE_SOURCE_MISSING', fn () => $this->capture()->verify($user->id));
    }

    public function test_source_integer_limits_reject_invalid_values_without_floats(): void
    {
        $user = $this->user();
        DB::table('tx_user')->where('id', $user->id)->update(['u' => -1]);
        $this->edit($user, ['speed_limit' => 1])->assertStatus(409);
        $this->assertSame(0, DB::table('tx_legacy_capture_shadow_event')->count());
        DB::table('tx_user')->where('id', $user->id)->update(['u' => '9223372036854775807']);
        $this->edit($user, ['speed_limit' => 1])->assertOk();
        $after = json_decode(DB::table('tx_legacy_capture_shadow_event')->value('after_snapshot'), true);
        $this->assertSame('9223372036854775807', $after['u']);
    }

    public function test_current_terms_slot_period_conflicts_are_projection_blockers_without_writes(): void
    {
        $user = $this->user(); $this->backfill($user); $this->edit($user, ['speed_limit' => 1])->assertOk();
        DB::table('tx_subscription')->where('user_id', $user->id)->update(['current_terms_id' => null]);
        DB::table('tx_subscription_slot')->where('user_id', $user->id)->update(['subscription_id' => null]);
        $before = $this->state(); $report = $this->capture()->verify($user->id);
        $this->assertContains('CAPTURE_TERMS_CONFLICT', $report['projection_issues']);
        $this->assertContains('CAPTURE_SLOT_CONFLICT', $report['projection_issues']);
        $this->assertSame($before, $this->state());
    }

    public function test_cli_has_explicit_isolated_flag_and_success_is_only_capture_page_validation(): void
    {
        $user = $this->user(); $this->edit($user, ['speed_limit' => 1])->assertOk();
        $options = ['--user' => (string) $user->id, '--limit' => '1'];
        $this->assertSame(1, Artisan::call('core:legacy-capture-shadow', $options));
        $this->assertSame(0, Artisan::call('core:legacy-capture-shadow', $options + ['--isolated-copy' => true]));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($report['page_ok']); $this->assertFalse($report['migration_gate_open']);
        $this->assertSame(1, Artisan::call('core:legacy-capture-shadow', array_replace($options,
            ['--isolated-copy' => true, '--after' => '01'])));
        DB::beginTransaction();
        try { $this->assertFailure('CAPTURE_OUTER_TRANSACTION_UNSUPPORTED', fn () => $this->capture()->verify($user->id)); }
        finally { DB::rollBack(); }
    }

    public function test_independent_mysql_reader_waits_for_commit_and_verifies_atomic_endpoint(): void
    {
        $this->requireMysqlFork();
        $user = $this->user(); $this->edit($user, ['speed_limit' => 1])->assertOk();
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        DB::purge(); $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($parent); stream_set_timeout($child, 10);
            if (fread($child, 1) !== 'S') { exit(2); }
            fwrite($child, 'R');
            fwrite($child, json_encode($this->capture()->verify($user->id), JSON_THROW_ON_ERROR));
            fclose($child); DB::disconnect(); exit(0);
        }
        $this->assertGreaterThan(0, $pid); fclose($child); stream_set_timeout($parent, 10);
        try {
            DB::beginTransaction();
            $this->edit($user, ['speed_limit' => 2])->assertOk();
            fwrite($parent, 'S'); $this->assertSame('R', fread($parent, 1));
            $read = [$parent]; $write = []; $except = [];
            $this->assertSame(0, stream_select($read, $write, $except, 0, 250000));
            DB::commit();
            $report = json_decode(stream_get_contents($parent, 8192), true, flags: JSON_THROW_ON_ERROR);
            $this->assertTrue($report['page_ok']); $this->assertSame('2', $report['high_water']);
        } finally {
            if (DB::transactionLevel()) { DB::rollBack(); }
            fclose($parent); pcntl_waitpid($pid, $status);
        }
        $this->assertSame(0, pcntl_wexitstatus($status));
    }

    public function test_independent_mysql_same_cas_writers_produce_only_one_event(): void
    {
        $this->requireMysqlFork(); $user = $this->user();
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        DB::purge(); $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($parent); stream_set_timeout($child, 10); fread($child, 1); fwrite($child, 'R');
            $response = $this->edit($user, ['u' => 20, 'expected_u' => 10]);
            fwrite($child, (string) $response->status()); fclose($child); DB::disconnect(); exit(0);
        }
        $this->assertGreaterThan(0, $pid); fclose($child); stream_set_timeout($parent, 10);
        try {
            DB::beginTransaction(); $this->edit($user, ['u' => 15, 'expected_u' => 10])->assertOk();
            fwrite($parent, 'S'); $this->assertSame('R', fread($parent, 1));
            $read = [$parent]; $write = []; $except = [];
            $this->assertSame(0, stream_select($read, $write, $except, 0, 250000));
            DB::commit(); $this->assertSame('422', stream_get_contents($parent, 16));
        } finally {
            if (DB::transactionLevel()) { DB::rollBack(); }
            fclose($parent); pcntl_waitpid($pid, $status);
        }
        $this->assertSame(15, (int) $user->fresh()->u);
        $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count());
        $this->assertSame(0, pcntl_wexitstatus($status));
    }

    public function test_mysql_process_death_before_commit_rolls_back_all_and_after_commit_recovers_readonly(): void
    {
        $this->requireMysqlFork(); $user = $this->user(); $this->backfill($user);
        $before = $this->state();
        $this->killWriter($user, false);
        $this->assertSame($before, $this->state());
        $this->killWriter($user, true);
        $this->assertSame(2, (int) $user->fresh()->speed_limit);
        $this->assertSame('source_changed', DB::table('tx_legacy_subscription_map')->where('legacy_user_id', $user->id)->value('state'));
        $committed = $this->state(); $report = $this->capture()->verify($user->id);
        $this->assertTrue($report['page_ok']); $this->assertSame('1', $report['high_water']);
        $this->assertFalse($report['projection_applied']); $this->assertSame($committed, $this->state());
    }

    public function test_stale_mysql_outer_snapshot_is_rejected_and_producer_uses_current_stream(): void
    {
        if (!$this->mysql()) { $this->markTestSkipped('Requires independent MariaDB sessions.'); }
        $user = $this->user(); $this->edit($user, ['speed_limit' => 1])->assertOk();
        $primary = DB::getDefaultConnection();
        config(['database.connections.capture_peer' => config('database.connections.'.$primary)]);
        DB::beginTransaction();
        try {
            $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count());
            DB::setDefaultConnection('capture_peer');
            try { $this->edit($user, ['speed_limit' => 2])->assertOk(); }
            finally { DB::setDefaultConnection($primary); }
            $this->assertFailure('CAPTURE_OUTER_TRANSACTION_UNSUPPORTED', fn () => $this->capture()->verify($user->id));
            $this->edit($user, ['speed_limit' => 3])->assertOk();
            $this->assertSame(3, DB::table('tx_legacy_capture_shadow_event')->sharedLock()->get()->count());
            DB::commit();
        } finally { if (DB::transactionLevel()) { DB::rollBack(); } DB::purge('capture_peer'); }
        $this->assertTrue($this->capture()->verify($user->id)['page_ok']);
    }

    private function killWriter(User $user, bool $afterCommit): void
    {
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        DB::purge(); $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($parent);
            $barrier = function () use ($child): void { fwrite($child, 'B'); fflush($child); fread($child, 1); };
            if ($afterCommit) {
                HookManager::register('admin.user.update.after', $barrier);
            } else {
                DB::listen(function ($query) use ($barrier): void {
                    if (str_starts_with(strtolower($query->sql), 'update')
                        && str_contains($query->sql, 'tx_legacy_subscription_map')) { $barrier(); }
                });
            }
            $this->edit($user, ['speed_limit' => 2]); exit(3);
        }
        $this->assertGreaterThan(0, $pid); fclose($child); stream_set_timeout($parent, 10);
        try {
            $this->assertSame('B', fread($parent, 1));
            $this->assertTrue(posix_kill($pid, SIGKILL)); pcntl_waitpid($pid, $status);
            $this->assertTrue(pcntl_wifsignaled($status)); $this->assertSame(SIGKILL, pcntl_wtermsig($status));
        } finally { fclose($parent); }
    }

    private function user(array $overrides = []): User
    {
        $id = ++$this->counter;
        return User::create(array_replace(['email' => 'capture-'.$id.'@example.test',
            'password' => 'capture-fixture-password', 'uuid' => sprintf('00000000-0000-4000-8000-%012d', $id),
            'token' => sprintf('%032d', $id), 'plan_id' => $this->plan->id, 'group_id' => 1,
            'transfer_enable' => 1000, 'u' => 10, 'd' => 20, 'expired_at' => time() + 86400], $overrides));
    }
    private function edit(User $user, array $fields): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/txapi/admin/capture_admin/users/'.$user->id.'/update', $fields);
    }
    private function backfill(User $user): void
    {
        app(SubscriptionShadowBackfill::class)->run('capture-map-'.$user->id, 100,
            app(LegacySubscriptionSnapshot::class)->context('UTC', 'USD'));
    }
    private function capture(): LegacyUserMutationCapture { return app(LegacyUserMutationCapture::class); }
    private function mysql(): bool { return getenv('CORE_CAPTURE_TEST_MYSQL') === '1'; }
    private function migration(): \Illuminate\Database\Migrations\Migration
    {
        return require database_path('migrations/2026_10_12_000010_create_legacy_capture_shadow_tables.php');
    }
    private function assertFailure(string $code, callable $callback): void
    {
        try { $callback(); $this->fail('Accepted invalid capture state.'); }
        catch (LegacyCaptureShadowException $e) { $this->assertSame($code, $e->getMessage()); $this->assertNull($e->getPrevious()); }
    }
    private function projectionState(): array
    {
        $result = [];
        foreach (['tx_subscription', 'tx_subscription_terms', 'tx_subscription_terms_transition',
            'tx_subscription_slot', 'tx_usage_period', 'tx_usage_adjustment', 'tx_access_subject'] as $table) {
            $result[$table] = DB::table($table)->orderBy($table === 'tx_subscription_slot' ? 'user_id' : 'id')->get()->toJson();
        }
        return $result;
    }
    private function state(): array
    {
        $result = $this->projectionState();
        foreach (['tx_user', 'tx_legacy_capture_shadow_stream', 'tx_legacy_capture_shadow_event', 'tx_legacy_subscription_map'] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        return $result;
    }
    private function requireMysqlFork(): void
    {
        if (!$this->mysql() || !function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('Requires independent MariaDB sessions and process control.');
        }
    }
    private function clearMysql(): void
    {
        if (DB::connection()->getDatabaseName() !== 'txboard_concurrency_test') {
            throw new \RuntimeException('Capture fixture requires its dedicated database.');
        }
        Schema::disableForeignKeyConstraints();
        try { foreach (Schema::getTableListing() as $table) { Schema::drop($table); } }
        finally { Schema::enableForeignKeyConstraints(); }
    }
}
