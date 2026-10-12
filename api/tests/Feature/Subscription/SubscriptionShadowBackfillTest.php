<?php

namespace Tests\Feature\Subscription;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Subscription\LegacySubscriptionSnapshot;
use App\Services\Subscription\SubscriptionShadowBackfill;
use App\Services\Subscription\SubscriptionShadowReconcile;
use App\Services\Subscription\SubscriptionShadowReader;
use App\Services\Subscription\SubscriptionSlotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SubscriptionShadowBackfillTest extends TestCase
{
    use RefreshDatabase;

    private array $context = ['billing_timezone' => 'Asia/Shanghai', 'currency' => 'USD'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['core_shadow.isolated_copy' => true]);
    }

    public function test_current_user_terms_large_bytes_and_overuse_are_preserved_without_changing_legacy_rows(): void
    {
        $user = $this->user(['transfer_enable' => 100, 'u' => 9007199254740993, 'd' => 5,
            'group_id' => 9, 'speed_limit' => 17, 'device_limit' => 3, 'expired_at' => null,
            'last_reset_at' => 1700000000, 'next_reset_at' => 1900000000, 'reset_count' => 7]);
        $before = DB::table('tx_user')->where('id', $user->id)->first();
        $result = $this->backfill();
        $this->assertSame('mapped', $result['results'][0]['status']);
        $this->assertEquals($before, DB::table('tx_user')->where('id', $user->id)->first());
        $period = DB::table('tx_usage_period')->first();
        $this->assertSame('9007199254740993', (string) $period->upload_bytes);
        $this->assertSame('9007199254740998', (string) $period->billable_bytes);
        $terms = DB::table('tx_subscription_terms')->first();
        $this->assertSame(9, (int) $terms->group_id);
        $this->assertSame(17, (int) $terms->speed_limit_mbps);
        $this->assertSame(3, (int) $terms->device_limit);
        $this->assertSame(100, (int) $terms->quota_bytes); // Not the current Plan's GiB value.
        $this->assertNull($terms->plan_revision_id);
        $this->assertNull(DB::table('tx_subscription')->first()->expires_at);
        $this->assertTrue($this->reconcile()['passed']);
        $shadow = app(SubscriptionShadowReader::class)->forLegacyUser($user->id);
        $this->assertSame('9007199254740993', $shadow['upload_bytes']);
        $this->assertSame('0', $shadow['remaining_bytes']);
        $this->assertSame('legacy', $shadow['authority']);
        $this->assertSame('pending', DB::table('tx_access_subject')->first()->status);
        $snapshot = DB::table('tx_legacy_subscription_map')->first()->source_snapshot;
        foreach (['uuid', 'token', 'email', 'password', $user->uuid, $user->token] as $secret) {
            $this->assertStringNotContainsString($secret, $snapshot);
        }
    }

    public function test_bounded_chunks_resume_with_fixed_high_water_and_global_idempotent_mapping(): void
    {
        $first = $this->user();
        $second = $this->user();
        $one = $this->backfill('resume', 1);
        $this->assertSame('backfilling', $one['phase']);
        $this->assertSame((string) $first->id, $one['cursor_user_id']);
        $this->assertFalse($this->reconcile('resume')['passed']);
        $two = $this->backfill('resume', 1);
        $this->assertSame('backfilled', $two['phase']);
        $this->assertSame((string) $second->id, $two['cursor_user_id']);
        $savedIds = DB::table('tx_legacy_subscription_map')->orderBy('id')->pluck('subscription_id')->all();
        $this->backfill('new-run', 100);
        $this->assertSame($savedIds, DB::table('tx_legacy_subscription_map')->orderBy('id')->pluck('subscription_id')->all());
        $this->assertSame(2, DB::table('tx_subscription_terms')->count());
        $this->assertTrue($this->reconcile('resume')['passed']);
        $this->user();
        $this->assertContains('HIGH_WATER_CHANGED', array_column($this->reconcile('resume')['differences'], 'code'));
        $this->assertSame((string) $second->id, $this->backfill('resume')['high_water_user_id']);
    }

    public function test_source_changes_are_detected_and_never_overwrite_immutable_terms_or_usage(): void
    {
        $user = $this->user(['u' => 10]);
        $this->backfill();
        $termsBefore = DB::table('tx_subscription_terms')->first();
        $periodBefore = DB::table('tx_usage_period')->first();
        $user->update(['u' => 19, 'balance' => 321]);
        $result = app(SubscriptionShadowBackfill::class)->run('test', 100, $this->context, $user->id);
        $this->assertSame('source_changed', $result['results'][0]['status']);
        $this->assertEquals($termsBefore, DB::table('tx_subscription_terms')->first());
        $this->assertEquals($periodBefore, DB::table('tx_usage_period')->first());
        $report = $this->reconcile();
        $this->assertFalse($report['passed']);
        $this->assertContains('u', $report['differences'][0]['fields']);
        $this->assertContains('balance', $report['differences'][0]['fields']);
    }

    public function test_missing_plan_is_durable_retryable_exception_and_creates_no_partial_aggregate(): void
    {
        $user = $this->user(['plan_id' => 9999]);
        $result = $this->backfill();
        $this->assertSame('LEGACY_PLAN_MISSING', $result['results'][0]['error_code']);
        $this->assertSame(0, DB::table('tx_subscription')->count());
        $this->assertDatabaseHas('tx_legacy_subscription_exception', ['legacy_user_id' => $user->id, 'state' => 'unresolved']);
        $this->assertFalse($this->reconcile()['passed']);
        $user->update(['plan_id' => $this->plan()->id]);
        $retry = app(SubscriptionShadowBackfill::class)->run('test', 100, $this->context, $user->id);
        $this->assertSame('mapped', $retry['results'][0]['status']);
        $this->assertDatabaseHas('tx_legacy_subscription_exception', ['legacy_user_id' => $user->id, 'state' => 'resolved']);
        $this->assertTrue($this->reconcile()['passed']);
    }

    public function test_slot_conflict_rolls_back_all_new_rows_but_records_failure(): void
    {
        $user = $this->user();
        $occupant = Subscription::create(['user_id' => $user->id, 'plan_id' => $user->plan_id,
            'status' => 'suspended', 'starts_at' => time(), 'expires_at' => null]);
        app(SubscriptionSlotService::class)->reserve($user->id, $occupant->id);
        $result = $this->backfill();
        $this->assertSame('exception', $result['results'][0]['status']);
        $this->assertSame(1, DB::table('tx_subscription')->count());
        foreach (['tx_subscription_terms', 'tx_usage_period', 'tx_access_subject', 'tx_legacy_subscription_map'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
        $this->assertSame($occupant->id, (int) DB::table('tx_subscription_slot')->first()->subscription_id);
    }

    public function test_no_plan_expired_and_banned_accounts_have_explicit_slot_policy_and_unknown_boundaries(): void
    {
        $noPlan = $this->user(['plan_id' => null, 'expired_at' => null]);
        $expired = $this->user(['expired_at' => time() - 10]);
        $banned = $this->user(['banned' => true, 'expired_at' => null]);
        $this->backfill();
        $this->assertDatabaseHas('tx_subscription', ['user_id' => $noPlan->id, 'status' => 'pending']);
        $this->assertDatabaseHas('tx_subscription', ['user_id' => $expired->id, 'status' => 'expired']);
        $this->assertDatabaseHas('tx_subscription', ['user_id' => $banned->id, 'status' => 'suspended']);
        $this->assertSame(1, DB::table('tx_subscription_slot')->whereNotNull('subscription_id')->count());
        $this->assertSame(3, DB::table('tx_usage_period')->whereNull('starts_at')->where('boundary_kind', 'legacy_boundary_unknown')->count());
        $this->assertTrue($this->reconcile()['passed']);
    }

    public function test_reconciliation_is_read_only_paginated_and_detects_tampering_and_deleted_sources(): void
    {
        $first = $this->user();
        $second = $this->user();
        $this->backfill();
        $checkpoint = DB::table('tx_migration_checkpoint')->first();
        $page = $this->reconcile('test', 1);
        $this->assertTrue($page['passed']);
        $this->assertFalse($page['complete']);
        $this->assertSame((string) $first->id, $page['next_cursor']);
        $last = $this->reconcile('test', 1, $first->id);
        $this->assertTrue($last['passed']);
        $this->assertTrue($last['complete']);
        $this->assertEquals($checkpoint, DB::table('tx_migration_checkpoint')->first());
        $periodId = DB::table('tx_legacy_subscription_map')->where('legacy_user_id', $first->id)->value('usage_period_id');
        DB::table('tx_usage_period')->where('id', $periodId)->update(['download_bytes' => 33]);
        $this->assertContains('usage_period.download_bytes', $this->reconcile()['differences'][0]['fields']);
        // Additive shadow data must not block existing account deletion.
        $second->delete();
        $this->assertContains('LEGACY_USER_MISSING', array_column($this->reconcile()['differences'], 'code'));
    }

    public function test_currency_timezone_and_run_context_are_explicit_and_cannot_change_on_resume(): void
    {
        $this->user();
        $this->backfill();
        $this->expectExceptionMessage('MIGRATION_CONTEXT_CHANGED');
        app(SubscriptionShadowBackfill::class)->run('test', 100, ['billing_timezone' => 'UTC', 'currency' => 'EUR']);
    }

    public function test_non_isolated_database_and_invalid_bounds_cannot_be_written(): void
    {
        config(['core_shadow.isolated_copy' => false]);
        $this->artisan('core:subscriptions-backfill', ['--run' => 'blocked', '--isolated-copy' => true,
            '--billing-timezone' => 'UTC', '--currency' => 'USD'])->assertFailed();
        $this->assertSame(0, DB::table('tx_migration_checkpoint')->count());
        config(['core_shadow.isolated_copy' => true]);
        $this->artisan('core:subscriptions-backfill', ['--run' => 'blocked', '--limit' => '1e3',
            '--isolated-copy' => true, '--billing-timezone' => 'UTC', '--currency' => 'USD'])->assertFailed();
        $this->artisan('core:subscriptions-backfill', ['--run' => 'blocked', '--billing-timezone' => 'UTC', '--currency' => 'USD'])->assertFailed();
        $this->assertSame(0, DB::table('tx_migration_checkpoint')->count());
    }

    public function test_overflow_negative_counters_and_invalid_reset_boundaries_fail_without_loss(): void
    {
        $this->user(['u' => PHP_INT_MAX, 'd' => 1]);
        $this->user(['u' => -1]);
        $this->user(['last_reset_at' => 100, 'next_reset_at' => 99]);
        $result = $this->backfill();
        $this->assertSame(['LEGACY_TRAFFIC_OVERFLOW', 'INVALID_LEGACY_U', 'INVALID_LEGACY_RESET_BOUNDARY'],
            array_column($result['results'], 'error_code'));
        $this->assertSame(0, DB::table('tx_subscription')->count());
    }

    public function test_unknown_currency_and_timezone_are_not_guessed(): void
    {
        $this->expectExceptionMessage('INVALID_BILLING_CONTEXT');
        app(LegacySubscriptionSnapshot::class)->context('', '');
    }

    public function test_hashes_survive_database_json_object_key_reordering(): void
    {
        $hashes = app(LegacySubscriptionSnapshot::class);
        $this->assertSame($hashes->hash(['x' => ['a' => '9007199254740993', 'b' => null], 'z' => 0]),
            $hashes->hash(['z' => 0, 'x' => ['b' => null, 'a' => '9007199254740993']]));
        $this->assertNotSame($hashes->hash(['x' => '1']), $hashes->hash(['x' => 1]));
    }

    public function test_existing_http_reads_continue_to_use_legacy_source_after_shadow_backfill(): void
    {
        $user = $this->user(['u' => 12, 'd' => 13]);
        $this->backfill();
        \Laravel\Sanctum\Sanctum::actingAs($user);
        $before = $this->getJson('/txapi/me/subscription')->assertOk();
        DB::table('tx_usage_period')->update(['upload_bytes' => 999]);
        $after = $this->getJson('/txapi/me/subscription')->assertOk();
        $this->assertSame($before->json('data'), $after->json('data'));
        $after->assertJsonPath('data.upload_bytes', 12)->assertJsonPath('data.download_bytes', 13);
    }

    private function backfill(string $run = 'test', int $limit = 100): array
    {
        return app(SubscriptionShadowBackfill::class)->run($run, $limit, $this->context);
    }

    private function reconcile(string $run = 'test', int $limit = 100, int $after = 0): array
    {
        return app(SubscriptionShadowReconcile::class)->run($run, $limit, $after);
    }

    private function plan(): Plan
    {
        return Plan::create(['name' => 'Shadow sample', 'group_id' => 1, 'transfer_enable' => 10,
            'prices' => ['monthly' => 10], 'reset_traffic_method' => 1]);
    }

    private function user(array $attributes = []): User
    {
        return User::withoutEvents(fn () => User::create(array_merge(['email' => bin2hex(random_bytes(6)).'@example.test',
            'password' => 'synthetic-not-a-login', 'uuid' => bin2hex(random_bytes(16)),
            'token' => bin2hex(random_bytes(16)), 'plan_id' => $this->plan()->id, 'group_id' => 1,
            'transfer_enable' => 1000, 'u' => 0, 'd' => 0, 'balance' => 0, 'commission_balance' => 0,
            'reset_count' => 0, 'expired_at' => time() + 10000], $attributes)));
    }
}
