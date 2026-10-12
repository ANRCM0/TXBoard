<?php

namespace Tests\Feature\Subscription;

use App\Models\Plan;
use App\Models\SubscriptionTerms;
use App\Models\SubscriptionTermsTransition;
use App\Models\UsageAdjustment;
use App\Models\User;
use App\Services\Subscription\SubscriptionTermsShadowService;
use App\Services\Subscription\SubscriptionTermsTimeline;
use App\Services\TrafficResetService;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class SubscriptionTermsShadowTest extends TestCase
{
    private const TABLES = ['tx_usage_entry', 'tx_usage_batch', 'tx_subscription_terms_shadow_operation',
        'tx_usage_adjustment', 'tx_access_subject', 'tx_legacy_subscription_map', 'tx_legacy_subscription_exception',
        'tx_migration_checkpoint', 'tx_subscription_slot', 'tx_usage_period', 'tx_subscription_terms_transition',
        'tx_subscription_terms', 'tx_subscription', 'tx_user'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['core_shadow.isolated_copy' => true]);
        if (getenv('CORE_TERMS_TEST_MYSQL') === '1') {
            config(['database.default' => 'terms_shadow_mysql_testing',
                'database.connections.terms_shadow_mysql_testing' => config('database.connections.mysql')]);
            DB::purge('terms_shadow_mysql_testing');
            $this->clearFixtures();
        } else {
            config(['database.default' => 'terms_shadow_testing', 'database.connections.terms_shadow_testing' =>
                array_merge(config('database.connections.sqlite'), ['database' => ':memory:', 'foreign_key_constraints' => true])]);
            DB::purge('terms_shadow_testing');
        }
        Schema::create('tx_user', function (Blueprint $table) {
            $table->integer('id', true);
            $table->boolean('banned')->default(false);
            $table->unsignedBigInteger('u')->default(0);
            $table->unsignedBigInteger('d')->default(0);
            $table->unsignedBigInteger('transfer_enable')->default(1000);
            $table->unsignedBigInteger('expired_at')->nullable();
            $table->string('uuid')->nullable();
            $table->unsignedBigInteger('created_at');
            $table->unsignedBigInteger('updated_at');
        });
        (require database_path('migrations/2026_10_12_000001_create_subscription_shadow_tables.php'))->up();
        $this->migration()->up();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if (getenv('CORE_TERMS_TEST_MYSQL') === '1') {
            $this->clearFixtures();
            DB::purge('terms_shadow_mysql_testing');
        }
        DB::purge('terms_shadow_testing');
        parent::tearDown();
    }

    public function test_timeline_caps_without_rewriting_terms_and_uses_half_open_intervals(): void
    {
        $f = $this->fixture();
        $original = DB::table('tx_subscription_terms')->first();
        $result = $this->advance($f, 'quota', ['quota_bytes' => '2000']);
        $segments = $this->timeline()->resolve($f['sub']);
        $this->assertSame([100, 200], array_column($segments, 'effective_from'));
        $this->assertSame([200, 1000], array_column($segments, 'effective_until'));
        $this->assertSame($f['terms'], $this->timeline()->at($f['sub'], 199)['terms_id']);
        $this->assertSame($result['terms_id'], $this->timeline()->at($f['sub'], 200)['terms_id']);
        $this->assertNull($this->timeline()->at($f['sub'], 1000));
        $this->assertTrue($this->timeline()->covers($f['sub'], $f['terms'], 100, 200, true));
        $this->assertFalse($this->timeline()->covers($f['sub'], $f['terms'], 100, 201, true));
        $this->assertFalse($this->timeline()->covers($f['sub'], $f['terms'], 200, 200));
        $this->assertEquals($original, DB::table('tx_subscription_terms')->where('id', $f['terms'])->first());
    }

    public function test_timeline_preserves_an_existing_gap_and_rejects_graph_corruption(): void
    {
        $terms = [ $this->termRow('1', '1', 100, 150), $this->termRow('2', '2', 200, 300) ];
        $edge = $this->edgeRow('1', '1', '2', 200);
        $segments = $this->timeline()->fromRows('1', $terms, [$edge], '2');
        $this->assertSame(150, $segments[0]['effective_until']);
        $bad = [
            [[$terms[0], $terms[1] + ['x' => true]], [], '2'],
            [[$terms[0], array_replace($terms[1], ['subscription_id' => '2'])], [$edge], '2'],
            [[$terms[0], array_replace($terms[1], ['version_no' => '1'])], [$edge], '2'],
            [[$terms[0], $terms[1]], [$edge], '1'],
            [[$terms[0], $terms[1]], [array_replace($edge, ['effective_at' => 199])], '2'],
            [[$terms[0], $terms[1], $this->termRow('3', '3', 210, 300)], [$edge, $this->edgeRow('2', '1', '3', 210)], '3'],
            [[$terms[0], $terms[1]], [$edge, $this->edgeRow('2', '2', '1', 100)], '2'],
            [[$this->termRow('1', '1', 100, 100)], [], '1'],
        ];
        foreach ($bad as [$nodes, $edges, $tip]) {
            try {
                $this->timeline()->fromRows('1', $nodes, $edges, $tip);
                $this->fail('Damaged timeline accepted');
            } catch (DomainException $error) {
                $this->assertStringStartsWith('TERMS_TIMELINE_', $error->getMessage());
            }
        }
    }

    public function test_decimal_values_reject_floats_signs_leading_zero_and_overflow(): void
    {
        foreach ([1.0, '-1', '+1', '01', '1e3', '9223372036854775808', null, true, ''] as $value) {
            try {
                SubscriptionTermsTimeline::decimal($value);
                $this->fail('Noncanonical number accepted');
            } catch (DomainException $error) {
                $this->assertSame('TERMS_DECIMAL_INVALID', $error->getMessage());
            }
        }
        $this->assertSame('9223372036854775807', SubscriptionTermsTimeline::decimal('9223372036854775807'));
        $this->expectExceptionMessage('TERMS_TIME_INVALID');
        SubscriptionTermsTimeline::timestamp('9007199254740992');
    }

    public function test_limits_price_and_admin_changes_preserve_period_counters_anchors_and_legacy(): void
    {
        $f = $this->fixture();
        $user = DB::table('tx_user')->first();
        $old = DB::table('tx_usage_period')->first();
        $slot = DB::table('tx_subscription_slot')->first();
        $limits = $this->advance($f, 'limits', ['speed_limit_mbps' => '20', 'device_limit' => null]);
        $terms = DB::table('tx_subscription_terms')->where('id', $limits['terms_id'])->first();
        $this->assertSame('20', (string) $terms->speed_limit_mbps);
        $this->assertNull($terms->device_limit);
        $quote = ['currency' => 'USD', 'price_minor' => '1200', 'offer_code' => 'monthly', 'catalog_snapshot_hash' => str_repeat('b', 64)];
        $price = $this->advance($f, 'price_evidence', ['quote_evidence' => $quote], 201, 'price', '2', '2');
        $this->assertEquals($quote, json_decode(DB::table('tx_subscription_terms')->where('id', $price['terms_id'])->value('legacy_snapshot'), true)['shadow_change']['quote_evidence']);
        $admin = $this->advance($f, 'admin', ['group_id' => '6', 'expires_at' => 1100], 202, 'admin', '3', '3');
        $period = DB::table('tx_usage_period')->first();
        foreach (['terms_id', 'starts_at', 'ends_at', 'upload_bytes', 'download_bytes', 'billable_bytes', 'last_reset_at', 'next_reset_at', 'legacy_reset_count'] as $field) {
            $this->assertEquals($old->$field, $period->$field, $field);
        }
        $this->assertSame('1100', (string) $admin['expires_at']);
        $this->assertSame(0, DB::table('tx_usage_adjustment')->count());
        $this->assertEquals($user, DB::table('tx_user')->first());
        $this->assertEquals($slot, DB::table('tx_subscription_slot')->first());
        $this->assertNull(DB::table('tx_subscription_terms')->where('id', $admin['terms_id'])->value('plan_revision_id'));
    }

    public function test_quota_changes_append_signed_adjustments_even_when_already_overused(): void
    {
        $f = $this->fixture(['billable_bytes' => 2500]);
        $this->advance($f, 'quota', ['quota_bytes' => '2000']);
        $result = $this->advance($f, 'quota', ['quota_bytes' => '400'], 201, 'decrease', '2', '2');
        $this->assertSame(['1000', '-1600'], DB::table('tx_usage_adjustment')->orderBy('id')->pluck('quota_delta_bytes')->map(fn ($v) => (string) $v)->all());
        $this->assertSame('400', (string) DB::table('tx_usage_period')->value('quota_bytes'));
        $this->assertSame('2500', (string) DB::table('tx_usage_period')->value('billable_bytes'));
        $this->assertSame($f['period'], $result['usage_period_id']);
        $this->assertSame(1, DB::table('tx_usage_period')->count());
        $this->assertSame(2, DB::table('tx_usage_adjustment')->count());
    }

    public function test_renew_uses_actual_calendar_overflow_and_never_resets_allowance(): void
    {
        $expiry = Carbon::parse('2028-01-31 12:34:56', 'UTC')->timestamp;
        $f = $this->fixture([], ['expires_at' => $expiry]);
        $old = DB::table('tx_usage_period')->first();
        $result = $this->advance($f, 'renew', ['calendar_months' => 1]);
        $this->assertSame(Carbon::createFromTimestamp($expiry, 'UTC')->addMonths(1)->timestamp, $result['expires_at']);
        $period = DB::table('tx_usage_period')->first();
        foreach (['ends_at', 'next_reset_at', 'upload_bytes', 'download_bytes', 'billable_bytes', 'terms_id', 'quota_bytes'] as $field) {
            $this->assertEquals($old->$field, $period->$field, $field);
        }
        $this->assertSame(1, DB::table('tx_usage_period')->count());
        $this->assertSame(0, DB::table('tx_usage_adjustment')->count());
    }

    public function test_idempotent_retry_at_later_clock_returns_original_result_without_rewrites(): void
    {
        $f = $this->fixture();
        $request = $this->request('quota', ['quota_bytes' => '2000']);
        $first = $this->service()->advance($f['user'], $f['sub'], 'retry', $request, 200);
        $before = $this->aggregate();
        $this->assertSame($first, $this->service()->advance($f['user'], $f['sub'], 'retry', $request, 250));
        $this->assertEquals($before, $this->aggregate());
        $request['changes']['quota_bytes'] = '3000';
        $this->expectExceptionMessage('TERMS_IDEMPOTENCY_CONFLICT');
        $this->service()->advance($f['user'], $f['sub'], 'retry', $request, 250);
    }

    public function test_expected_versions_and_immediate_boundary_are_required_before_any_change(): void
    {
        $f = $this->fixture();
        foreach ([['expected_subscription_version' => '2'], ['expected_period_version' => '2'], ['effective_at' => 199]] as $change) {
            $before = $this->aggregate();
            $request = array_replace($this->request('quota', ['quota_bytes' => '2000']), $change);
            try {
                $this->service()->advance($f['user'], $f['sub'], 'version', $request, 200);
                $this->fail('Stale version or backdated boundary accepted');
            } catch (DomainException) {
                $this->assertEquals($before, $this->aggregate());
            }
        }
    }

    public function test_guard_policy_and_unknown_projection_refuse_mutation(): void
    {
        $f = $this->fixture();
        config(['core_shadow.isolated_copy' => false]);
        $this->assertRejected($f, 'ISOLATED_COPY_REQUIRED');
        config(['core_shadow.isolated_copy' => true]);
        DB::table('tx_usage_period')->update(['boundary_kind' => 'legacy_boundary_unknown']);
        $this->assertRejected($f, 'TERMS_PERIOD_BOUNDARY_UNKNOWN');
        DB::table('tx_usage_period')->update(['boundary_kind' => 'known', 'quota_bytes' => null]);
        $this->assertRejected($f, 'TERMS_DECIMAL_INVALID');
        DB::table('tx_usage_period')->update(['quota_bytes' => 900]);
        $this->assertRejected($f, 'TERMS_QUOTA_PROJECTION_UNKNOWN');
        DB::table('tx_usage_period')->update(['quota_bytes' => 1000]);
        $request = $this->request('quota', ['quota_bytes' => '2000']);
        $request['policy_version'] = 'invented-policy/v1';
        $this->expectExceptionMessage('TERMS_POLICY_UNSUPPORTED');
        $this->service()->advance($f['user'], $f['sub'], 'bad', $request, 200);
    }

    public function test_outer_transactions_are_refused_for_mutation_but_timeline_reads_can_be_reused(): void
    {
        $f = $this->fixture();
        DB::beginTransaction();
        try {
            DB::table('tx_subscription')->where('id', $f['sub'])->lockForUpdate()->first();
            $this->assertTrue($this->timeline()->covers($f['sub'], $f['terms'], 100, 200, true));
            $this->assertRejected($f, 'TERMS_OUTER_TRANSACTION_UNSUPPORTED');
        } finally {
            DB::rollBack();
        }
    }

    public function test_long_period_history_fails_closed_before_timeline_or_projection_work(): void
    {
        $f = $this->fixture();
        $rows = [];
        for ($number = 2; $number <= 1001; $number++) {
            $rows[] = ['subscription_id' => $f['sub'], 'terms_id' => $f['terms'], 'period_no' => $number,
                'starts_at' => 0, 'ends_at' => 1, 'quota_bytes' => 1000, 'status' => 'closed',
                'boundary_kind' => 'known', 'created_at' => 1, 'updated_at' => 1];
        }
        DB::table('tx_usage_period')->insert($rows);
        $this->assertRejected($f, 'TERMS_PERIOD_HISTORY_LIMIT');
        $this->assertSame(1001, DB::table('tx_usage_period')->count());
    }

    public function test_original_reset_replays_with_over_limit_history_but_new_business_key_is_refused(): void
    {
        $f = $this->fixture();
        $request = $this->request('reset', ['trigger' => 'manual', 'verified_legacy_rule' => 'legacy-performReset/manual/v1']);
        $first = $this->service()->reset($f['user'], $f['sub'], 'reset-replay', $request, 200);
        $rows = [];
        for ($number = 3; $number <= 1001; $number++) {
            $rows[] = ['subscription_id' => $f['sub'], 'terms_id' => $f['terms'], 'period_no' => $number,
                'starts_at' => 0, 'ends_at' => 1, 'quota_bytes' => 1000, 'status' => 'closed',
                'boundary_kind' => 'known', 'created_at' => 1, 'updated_at' => 1];
        }
        DB::table('tx_usage_period')->insert($rows);
        $before = $this->aggregate();
        $this->assertSame($first, $this->service()->reset($f['user'], $f['sub'], 'reset-replay', $request, 250));
        $this->assertEquals($before, $this->aggregate());
        try {
            $this->service()->reset($f['user'], $f['sub'], 'new-reset', $request, 250);
            $this->fail('New mutation bypassed the history limit');
        } catch (DomainException $error) {
            $this->assertSame('TERMS_PERIOD_HISTORY_LIMIT', $error->getMessage());
        }
        $this->assertEquals($before, $this->aggregate());
    }

    public function test_pending_processing_retry_quarantined_and_settled_evidence_blocks_reassignment(): void
    {
        $f = $this->fixture();
        $this->evidenceSchema();
        foreach (['pending', 'processing', 'retry_wait', 'quarantined', 'settled'] as $status) {
            $batch = $this->batch($f['sub'], 201, $status);
            $this->assertRejected($f, 'TERMS_ACCEPTED_USAGE_WOULD_BE_REASSIGNED');
            DB::table('tx_usage_batch')->where('id', $batch)->delete();
        }
        $batch = $this->batch('999', 201, 'settled');
        DB::table('tx_usage_entry')->insert(['batch_id' => $batch, 'subscription_id' => $f['sub']]);
        $this->assertRejected($f, 'TERMS_ACCEPTED_USAGE_WOULD_BE_REASSIGNED');
    }

    public function test_evidence_ending_exactly_at_transition_allows_change_but_corruption_blocks(): void
    {
        $f = $this->fixture();
        $this->evidenceSchema();
        $this->batch($f['sub'], 200);
        $this->advance($f, 'quota', ['quota_bytes' => '2000']);
        $batch = $this->batch('999', 202);
        DB::table('tx_usage_batch')->where('id', $batch)->update(['frozen_hash' => str_repeat('0', 64)]);
        $request = $this->request('quota', ['quota_bytes' => '3000'], 201, '2', '2');
        try {
            $this->service()->advance($f['user'], $f['sub'], 'evidence', $request, 201);
            $this->fail('Corrupt accepted evidence ignored');
        } catch (DomainException $error) {
            $this->assertSame('TERMS_USAGE_EVIDENCE_CORRUPT', $error->getMessage());
        }
        $this->assertSame(2, DB::table('tx_subscription_terms')->count());
    }

    public function test_partial_evidence_schema_blocks_instead_of_guessing(): void
    {
        $f = $this->fixture();
        $this->evidenceSchema();
        Schema::drop('tx_usage_entry');
        $this->assertRejected($f, 'TERMS_USAGE_EVIDENCE_UNAVAILABLE');
    }

    public function test_explicit_reset_preserves_old_counters_and_creation_terms_and_opens_new_period(): void
    {
        $f = $this->fixture();
        $old = DB::table('tx_usage_period')->first();
        $user = DB::table('tx_user')->first();
        $request = $this->request('reset', ['trigger' => 'manual', 'verified_legacy_rule' => 'legacy-performReset/manual/v1']);
        $result = $this->service()->reset($f['user'], $f['sub'], 'reset', $request, 200);
        $closed = DB::table('tx_usage_period')->where('id', $f['period'])->first();
        foreach (['terms_id', 'starts_at', 'quota_bytes', 'upload_bytes', 'download_bytes', 'billable_bytes', 'last_reset_at', 'next_reset_at', 'legacy_reset_count'] as $field) {
            $this->assertEquals($old->$field, $closed->$field, $field);
        }
        $this->assertSame('closed', $closed->status);
        $this->assertSame(200, (int) $closed->ends_at);
        $new = DB::table('tx_usage_period')->where('id', $result['usage_period_id'])->first();
        $this->assertSame([0, 0, 0], [(int) $new->upload_bytes, (int) $new->download_bytes, (int) $new->billable_bytes]);
        $this->assertSame(4, (int) $new->legacy_reset_count);
        $this->assertSame(200, (int) $new->starts_at);
        $this->assertSame(1, DB::table('tx_subscription_terms')->count());
        $this->assertSame(0, DB::table('tx_subscription_terms_transition')->count());
        $this->assertEquals($user, DB::table('tx_user')->first());
        $this->assertSame($result, $this->service()->reset($f['user'], $f['sub'], 'reset', $request, 250));
    }

    public function test_scheduled_reset_only_occurs_at_the_exact_proven_due_boundary(): void
    {
        $f = $this->fixture(['ends_at' => 200, 'next_reset_at' => 200]);
        $request = $this->request('reset', ['trigger' => 'scheduled', 'verified_legacy_rule' => 'legacy-performReset/scheduled/v1']);
        $this->service()->reset($f['user'], $f['sub'], 'scheduled', $request, 200);
        $this->assertSame(2, DB::table('tx_usage_period')->count());
        $this->assertSame('closed', DB::table('tx_usage_period')->where('id', $f['period'])->value('status'));
    }

    public function test_reset_calendar_boundaries_match_the_existing_runtime_for_month_and_leap_year(): void
    {
        $cases = [
            [1, '2029-01-31 12:34:56', '2028-02-01 09:00:00'],
            [1, '2029-01-31 12:34:56', '2028-02-29 12:34:56'],
            [4, '2032-02-29 12:34:56', '2029-02-01 09:00:00'],
            [0, '2029-12-31 12:34:56', '2028-01-31 09:00:00'],
            [3, '2029-12-31 12:34:56', '2028-07-01 09:00:00'],
            [2, '2029-12-31 12:34:56', '2028-07-01 09:00:00'],
        ];
        config(['app.timezone' => 'Asia/Shanghai']);
        foreach ($cases as $index => [$method, $expiryString, $nowString]) {
            $expiry = Carbon::parse($expiryString, 'Asia/Shanghai')->timestamp;
            $now = Carbon::parse($nowString, 'Asia/Shanghai')->timestamp;
            $f = $this->fixture(['starts_at' => $now - 100, 'ends_at' => $now + 100, 'next_reset_at' => $now + 100],
                ['expires_at' => $expiry], ['effective_from' => $now - 100, 'billing_timezone' => 'Asia/Shanghai',
                    'reset_policy' => SubscriptionTermsTimeline::json(['method' => $method])]);
            $legacyUser = new User(['expired_at' => $expiry]);
            $legacyUser->setRelation('plan', new Plan(['reset_traffic_method' => $method]));
            Carbon::setTestNow(Carbon::createFromTimestamp($now, 'Asia/Shanghai'));
            $expected = (new TrafficResetService())->calculateNextResetTime($legacyUser)?->timestamp;
            $request = $this->request('reset', ['trigger' => 'manual', 'verified_legacy_rule' => 'legacy-performReset/manual/v1'], $now);
            $result = $this->service()->reset($f['user'], $f['sub'], 'calendar-'.$index, $request, $now);
            $actual = DB::table('tx_usage_period')->where('id', $result['usage_period_id'])->value('next_reset_at');
            $this->assertSame($expected, $actual === null ? null : (int) $actual, $nowString.' method '.$method);
        }
    }

    public function test_late_unproven_reset_and_perpetual_renewal_are_rejected(): void
    {
        $f = $this->fixture(['ends_at' => 199]);
        $request = $this->request('reset', ['trigger' => 'manual', 'verified_legacy_rule' => 'legacy-performReset/manual/v1']);
        try {
            $this->service()->reset($f['user'], $f['sub'], 'late', $request, 200);
            $this->fail('Past boundary reset accepted');
        } catch (DomainException $error) {
            $this->assertSame('TERMS_PERIOD_BOUNDARY_UNKNOWN', $error->getMessage());
        }
        DB::table('tx_usage_period')->where('id', $f['period'])->update(['ends_at' => 800]);
        DB::table('tx_subscription')->where('id', $f['sub'])->update(['expires_at' => null]);
        $this->expectExceptionMessage('TERMS_PERPETUAL_RENEWAL_UNSUPPORTED');
        $this->advance($f, 'renew', ['calendar_months' => 1]);
    }

    public function test_final_operation_failure_rolls_back_all_terms_adjustment_period_and_pointer_changes(): void
    {
        $f = $this->fixture();
        $before = $this->aggregate();
        $trigger = DB::getDriverName() === 'mysql'
            ? "CREATE TRIGGER terms_operation_fail BEFORE INSERT ON tx_subscription_terms_shadow_operation FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected operation failure'"
            : "CREATE TRIGGER terms_operation_fail BEFORE INSERT ON tx_subscription_terms_shadow_operation BEGIN SELECT RAISE(ABORT, 'injected operation failure'); END";
        DB::unprepared($trigger);
        try {
            $this->advance($f, 'quota', ['quota_bytes' => '2000']);
            $this->fail('Injected failure did not abort');
        } catch (QueryException $error) {
            $this->assertStringContainsString('injected operation failure', $error->getMessage());
        }
        $this->assertEquals($before, $this->aggregate());
        $request = $this->request('reset', ['trigger' => 'manual', 'verified_legacy_rule' => 'legacy-performReset/manual/v1']);
        try {
            $this->service()->reset($f['user'], $f['sub'], 'reset-fail', $request, 200);
            $this->fail('Reset failure did not abort');
        } catch (QueryException) {
            $this->assertEquals($before, $this->aggregate());
        }
    }

    public function test_immutable_models_reject_changes_and_operation_corruption_refuses_replay(): void
    {
        $f = $this->fixture();
        $request = $this->request('quota', ['quota_bytes' => '2000']);
        $this->service()->advance($f['user'], $f['sub'], 'immutable', $request, 200);
        foreach ([SubscriptionTerms::query()->first(), SubscriptionTermsTransition::query()->first(), UsageAdjustment::query()->first()] as $model) {
            try {
                $model->delete();
                $this->fail('Immutable history deleted');
            } catch (LogicException) {
                $this->assertTrue(true);
            }
        }
        DB::table('tx_subscription_terms_shadow_operation')->update(['content_hash' => str_repeat('0', 64)]);
        $this->expectExceptionMessage('TERMS_OPERATION_DAMAGED');
        $this->service()->advance($f['user'], $f['sub'], 'immutable', $request, 250);
    }

    public function test_schema_replay_preserves_operations_and_rollback_refuses_history(): void
    {
        $f = $this->fixture();
        $this->advance($f, 'quota', ['quota_bytes' => '2000']);
        $before = $this->aggregate();
        $this->migration()->up();
        $this->assertEquals($before, $this->aggregate());
        try {
            $this->migration()->down();
            $this->fail('Operation history removed');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('history exists', $error->getMessage());
        }
        $this->assertSame(1, DB::table('tx_subscription_terms_shadow_operation')->count());
    }

    public function test_legacy_user_can_be_deleted_without_restrictive_shadow_fk(): void
    {
        $f = $this->fixture();
        $this->advance($f, 'quota', ['quota_bytes' => '2000']);
        DB::table('tx_user')->where('id', $f['user'])->delete();
        $this->assertSame(0, DB::table('tx_user')->count());
        $this->assertSame(1, DB::table('tx_subscription_terms_shadow_operation')->count());
    }

    public function test_real_sessions_with_same_expected_version_allow_exactly_one_change(): void
    {
        $this->requireMysqlFork();
        $f = $this->fixture();
        $results = $this->race(8, function (int $worker) use ($f) {
            return $this->advance($f, 'quota', ['quota_bytes' => (string) (2000 + $worker)], 200, 'version-race-'.$worker);
        });
        $this->assertCount(1, array_filter($results, fn ($r) => isset($r['result'])));
        $this->assertCount(7, array_filter($results, fn ($r) => ($r['error'] ?? null) === 'TERMS_SUBSCRIPTION_VERSION_CONFLICT'));
        $this->assertSame(2, DB::table('tx_subscription_terms')->count());
        $this->assertSame(1, DB::table('tx_subscription_terms_transition')->count());
        $this->assertSame(1, DB::table('tx_usage_adjustment')->count());
        $this->assertSame(1, DB::table('tx_subscription_terms_shadow_operation')->count());
        $this->assertSame('333', (string) DB::table('tx_usage_period')->value('billable_bytes'));
    }

    public function test_real_sessions_same_business_key_replay_exactly_one_transition_and_adjustment(): void
    {
        $this->requireMysqlFork();
        $f = $this->fixture();
        $results = $this->race(8, fn () => $this->advance($f, 'quota', ['quota_bytes' => '2000'], 200, 'same-key'));
        $this->assertCount(8, array_filter($results, fn ($r) => isset($r['result'])), json_encode($results));
        foreach ($results as $result) {
            $this->assertSame($results[0]['result'], $result['result']);
        }
        $this->assertSame(2, DB::table('tx_subscription_terms')->count());
        $this->assertSame(1, DB::table('tx_subscription_terms_transition')->count());
        $this->assertSame(1, DB::table('tx_usage_adjustment')->count());
        $this->assertSame(1, DB::table('tx_subscription_terms_shadow_operation')->count());
        $this->assertSame('2000', (string) DB::table('tx_usage_period')->value('quota_bytes'));
    }

    public function test_timeline_current_read_sees_committed_successor_inside_an_older_outer_snapshot(): void
    {
        $this->requireMysqlFork();
        $f = $this->fixture();
        $connection = DB::getDefaultConnection();
        $directory = $this->temporaryDirectory('timeline');
        DB::disconnect($connection);
        $pid = pcntl_fork();
        if ($pid === -1) { throw new RuntimeException('Timeline worker fork failed'); }
        if ($pid === 0) {
            try {
                DB::purge($connection);
                DB::beginTransaction();
                $old = $this->timeline()->at($f['sub'], 200);
                touch($directory.'/ready');
                $this->awaitFile($directory.'/go');
                // B3 already holds the subscription lock; the timeline only adds current reads.
                DB::table('tx_subscription')->where('id', $f['sub'])->lockForUpdate()->first();
                $stale = $this->timeline()->at($f['sub'], 200);
                $current = $this->timeline()->at($f['sub'], 200, true);
                $covered = $this->timeline()->covers($f['sub'], $f['terms'], 100, 201, true);
                file_put_contents($directory.'/result', json_encode(compact('old', 'stale', 'current', 'covered'), JSON_THROW_ON_ERROR));
                DB::commit();
                exit(0);
            } catch (\Throwable $error) {
                file_put_contents($directory.'/result', json_encode(['error' => $error->getMessage()]));
                exit(2);
            }
        }
        try {
            $this->awaitFile($directory.'/ready');
            DB::purge($connection);
            $new = $this->advance($f, 'quota', ['quota_bytes' => '2000']);
            touch($directory.'/go');
            pcntl_waitpid($pid, $status);
            $pid = null;
            DB::purge($connection);
            $result = json_decode(file_get_contents($directory.'/result'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(0, pcntl_wexitstatus($status), json_encode($result));
            $this->assertSame($f['terms'], $result['old']['terms_id']);
            $this->assertSame($f['terms'], $result['stale']['terms_id'], 'Fixture must reproduce the old consistent view.');
            $this->assertSame($new['terms_id'], $result['current']['terms_id']);
            $this->assertFalse($result['covered']);
        } finally {
            if ($pid !== null) { posix_kill($pid, SIGTERM); pcntl_waitpid($pid, $status); }
            $this->removeTemporaryDirectory($directory);
            DB::purge($connection);
        }
    }

    public function test_mutation_waiting_on_user_lock_sees_new_pending_evidence_and_refuses_boundary_change(): void
    {
        $this->requireMysqlFork();
        $f = $this->fixture();
        $this->evidenceSchema();
        $connection = DB::getDefaultConnection();
        $directory = $this->temporaryDirectory('pending');
        DB::disconnect($connection);
        $pid = pcntl_fork();
        if ($pid === -1) { throw new RuntimeException('Pending worker fork failed'); }
        if ($pid === 0) {
            try {
                DB::purge($connection);
                file_put_contents($directory.'/connection', (string) DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
                touch($directory.'/ready');
                $this->awaitFile($directory.'/go');
                touch($directory.'/calling');
                $this->advance($f, 'quota', ['quota_bytes' => '2000']);
                file_put_contents($directory.'/result', 'unexpected-success');
                exit(2);
            } catch (\Throwable $error) {
                file_put_contents($directory.'/result', $error->getMessage());
                exit($error->getMessage() === 'TERMS_ACCEPTED_USAGE_WOULD_BE_REASSIGNED' ? 0 : 2);
            }
        }
        try {
            $this->awaitFile($directory.'/ready');
            DB::purge($connection);
            DB::beginTransaction();
            DB::table('tx_user')->where('id', $f['user'])->lockForUpdate()->first();
            touch($directory.'/go');
            $this->awaitFile($directory.'/calling');
            $thread = file_get_contents($directory.'/connection');
            $deadline = microtime(true) + 20;
            do {
                $info = DB::table('information_schema.PROCESSLIST')->where('ID', $thread)->value('INFO');
                $waiting = is_string($info) && str_contains($info, '`tx_user`') && str_contains(strtolower($info), 'for update');
                if (!$waiting) { usleep(10000); }
            } while (!$waiting && microtime(true) < $deadline);
            $this->assertTrue($waiting, 'The child must be blocked in its User locking read before B3 evidence is committed.');
            // Parent mimics a B3 accept under its aggregate locks, before mutation can own User.
            DB::table('tx_subscription')->where('id', $f['sub'])->lockForUpdate()->first();
            $this->batch($f['sub'], 201);
            DB::commit();
            pcntl_waitpid($pid, $status);
            $pid = null;
            DB::purge($connection);
            $this->assertSame(0, pcntl_wexitstatus($status), file_get_contents($directory.'/result'));
            $this->assertSame('TERMS_ACCEPTED_USAGE_WOULD_BE_REASSIGNED', file_get_contents($directory.'/result'));
            $this->assertSame(1, DB::table('tx_subscription_terms')->count());
            $this->assertSame(0, DB::table('tx_subscription_terms_shadow_operation')->count());
        } finally {
            if (DB::transactionLevel() > 0) { DB::rollBack(); }
            if ($pid !== null) { posix_kill($pid, SIGTERM); pcntl_waitpid($pid, $status); }
            $this->removeTemporaryDirectory($directory);
            DB::purge($connection);
        }
    }

    private function race(int $workers, callable $operation): array
    {
        $connection = DB::getDefaultConnection();
        $directory = $this->temporaryDirectory('race');
        $children = [];
        try {
            DB::disconnect($connection);
            for ($worker = 0; $worker < $workers; $worker++) {
                $pid = pcntl_fork();
                if ($pid === -1) { throw new RuntimeException('Terms worker fork failed'); }
                if ($pid === 0) {
                    DB::purge($connection);
                    touch($directory.'/ready-'.$worker);
                    $this->awaitFile($directory.'/go');
                    try { $result = ['result' => $operation($worker)]; }
                    catch (\Throwable $error) { $result = ['error' => $error->getMessage()]; }
                    file_put_contents($directory.'/worker-'.$worker, json_encode($result, JSON_THROW_ON_ERROR));
                    exit(0);
                }
                $children[] = $pid;
            }
            $deadline = microtime(true) + 20;
            while (count(glob($directory.'/ready-*') ?: []) !== $workers && microtime(true) < $deadline) { usleep(10000); }
            $this->assertCount($workers, glob($directory.'/ready-*') ?: []);
            touch($directory.'/go');
            foreach ($children as $pid) { pcntl_waitpid($pid, $status); $this->assertSame(0, pcntl_wexitstatus($status)); }
            $children = [];
            DB::purge($connection);
            $results = [];
            for ($worker = 0; $worker < $workers; $worker++) {
                $results[] = json_decode(file_get_contents($directory.'/worker-'.$worker), true, flags: JSON_THROW_ON_ERROR);
            }
            return $results;
        } finally {
            foreach ($children as $pid) { posix_kill($pid, SIGTERM); pcntl_waitpid($pid, $status); }
            $this->removeTemporaryDirectory($directory);
            DB::purge($connection);
        }
    }

    private function requireMysqlFork(): void
    {
        if (DB::getDriverName() !== 'mysql' || !function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires leased dedicated MariaDB and independent pcntl sessions.');
        }
    }

    private function temporaryDirectory(string $suffix): string
    {
        $directory = sys_get_temp_dir().'/txboard-terms-'.$suffix.'-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        return $directory;
    }

    private function awaitFile(string $file): void
    {
        $deadline = microtime(true) + 20;
        while (!is_file($file) && microtime(true) < $deadline) { usleep(10000); }
        if (!is_file($file)) { throw new RuntimeException('Terms session barrier timed out'); }
    }

    private function removeTemporaryDirectory(string $directory): void
    {
        foreach (glob($directory.'/*') ?: [] as $file) { unlink($file); }
        rmdir($directory);
    }

    private function fixture(array $period = [], array $sub = [], array $terms = []): array
    {
        $user = (string) DB::table('tx_user')->insertGetId(['u' => 111, 'd' => 222, 'uuid' => 'legacy-opaque-value', 'created_at' => 1, 'updated_at' => 1]);
        $subId = (string) DB::table('tx_subscription')->insertGetId($sub + ['user_id' => $user, 'status' => 'active',
            'starts_at' => 100, 'expires_at' => 1000, 'row_version' => 1, 'created_at' => 100, 'updated_at' => 100]);
        $termsId = (string) DB::table('tx_subscription_terms')->insertGetId($terms + ['subscription_id' => $subId,
            'version_no' => 1, 'effective_from' => 100, 'effective_until' => null, 'quota_bytes' => 1000, 'group_id' => 3,
            'speed_limit_mbps' => 10, 'device_limit' => 2, 'billing_timezone' => 'UTC', 'reset_policy' => '{"method":1}',
            'legacy_snapshot' => '{"provenance":"legacy_snapshot_unknown"}', 'content_hash' => str_repeat('a', 64), 'created_at' => 100]);
        DB::table('tx_subscription')->where('id', $subId)->update(['current_terms_id' => $termsId]);
        DB::table('tx_subscription_slot')->insert(['user_id' => $user, 'slot_no' => 1, 'subscription_id' => $subId,
            'reserved_at' => 100, 'row_version' => 1, 'created_at' => 100, 'updated_at' => 100]);
        $periodId = (string) DB::table('tx_usage_period')->insertGetId($period + ['subscription_id' => $subId, 'terms_id' => $termsId,
            'period_no' => 1, 'starts_at' => 100, 'ends_at' => 800, 'quota_bytes' => 1000, 'upload_bytes' => 111,
            'download_bytes' => 222, 'billable_bytes' => 333, 'status' => 'shadow', 'boundary_kind' => 'known',
            'last_reset_at' => 100, 'next_reset_at' => 800, 'legacy_reset_count' => 3, 'row_version' => 1, 'created_at' => 100, 'updated_at' => 100]);
        return ['user' => $user, 'sub' => $subId, 'terms' => $termsId, 'period' => $periodId];
    }

    private function request(string $kind, array $changes, int $now = 200, string $subVersion = '1', string $periodVersion = '1'): array
    {
        $policy = match ($kind) { 'reset' => 'legacy-reset-explicit/v1', 'admin' => 'shadow-admin-explicit/v1',
            default => 'shadow-'.str_replace('_', '-', $kind).'-preserve/v1' };
        return ['kind' => $kind, 'policy_version' => $policy, 'effective_at' => $now,
            'expected_subscription_version' => $subVersion, 'expected_period_version' => $periodVersion, 'changes' => $changes];
    }

    private function advance(array $f, string $kind, array $changes, int $now = 200, string $key = 'change', string $sv = '1', string $pv = '1'): array
    {
        return $this->service()->advance($f['user'], $f['sub'], $key, $this->request($kind, $changes, $now, $sv, $pv), $now);
    }

    private function assertRejected(array $f, string $code): void
    {
        $before = $this->aggregate();
        try {
            $this->advance($f, 'quota', ['quota_bytes' => '2000']);
            $this->fail('Unsafe operation accepted');
        } catch (DomainException $error) {
            $this->assertSame($code, $error->getMessage());
        }
        $this->assertEquals($before, $this->aggregate());
    }

    private function aggregate(): array
    {
        $data = [];
        foreach (['tx_user', 'tx_subscription', 'tx_subscription_slot', 'tx_subscription_terms', 'tx_subscription_terms_transition',
            'tx_usage_period', 'tx_usage_adjustment', 'tx_subscription_terms_shadow_operation'] as $table) {
            $data[$table] = DB::table($table)->get()->all();
        }
        return $data;
    }

    private function evidenceSchema(): void
    {
        Schema::create('tx_usage_batch', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->unsignedBigInteger('ended_at'); $t->string('status');
            $t->json('frozen_entries'); $t->char('frozen_hash', 64);
        });
        Schema::create('tx_usage_entry', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->unsignedBigInteger('batch_id'); $t->unsignedBigInteger('subscription_id');
        });
    }

    private function batch(string $sub, int $end, string $status = 'pending'): int
    {
        $entries = [['subscription_id' => $sub]];
        return DB::table('tx_usage_batch')->insertGetId(['ended_at' => $end, 'status' => $status,
            'frozen_entries' => SubscriptionTermsTimeline::json($entries), 'frozen_hash' => SubscriptionTermsTimeline::hash($entries)]);
    }

    private function termRow(string $id, string $version, int $from, ?int $end): array
    {
        return ['id' => $id, 'subscription_id' => '1', 'version_no' => $version, 'effective_from' => $from, 'effective_until' => $end];
    }

    private function edgeRow(string $id, string $previous, string $next, int $at): array
    {
        return ['id' => $id, 'subscription_id' => '1', 'previous_terms_id' => $previous, 'next_terms_id' => $next, 'effective_at' => $at];
    }

    private function service(): SubscriptionTermsShadowService { return app(SubscriptionTermsShadowService::class); }
    private function timeline(): SubscriptionTermsTimeline { return app(SubscriptionTermsTimeline::class); }
    private function migration(): object { return require database_path('migrations/2026_10_12_000005_create_subscription_terms_shadow_operations.php'); }

    private function clearFixtures(): void
    {
        if (DB::connection()->getDatabaseName() !== 'txboard_concurrency_test') {
            throw new RuntimeException('TERMS_TEST_DEDICATED_DATABASE_REQUIRED');
        }
        Schema::disableForeignKeyConstraints();
        try {
            foreach (self::TABLES as $table) { Schema::dropIfExists($table); }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }
}
