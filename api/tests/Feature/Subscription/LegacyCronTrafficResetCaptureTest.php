<?php

namespace Tests\Feature\Subscription;

use App\Domains\Billing\WalletRechargeService;
use App\Http\Controllers\Txapi\CommissionController;
use App\Jobs\NodeUserSyncJob;
use App\Jobs\OrderHandleJob;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\TrafficResetLog;
use App\Models\User;
use App\Services\OrderService;
use App\Services\Plugin\HookManager;
use App\Services\Subscription\LegacyCaptureShadowException;
use App\Services\Subscription\LegacySubscriptionSnapshot;
use App\Services\Subscription\LegacyUserMutationCapture;
use App\Services\Subscription\SubscriptionShadowBackfill;
use App\Services\Subscription\SubscriptionTermsTimeline;
use App\Services\TrafficResetService;
use Carbon\Carbon;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Tests\TestCase;

class LegacyCronTrafficResetCaptureTest extends TestCase
{
    private int $counter = 0;
    private Plan $plan;
    private TestHandler $log;

    protected function setUp(): void
    {
        parent::setUp(); $connection = $this->connection();
        config(['database.default' => $connection, 'database.connections.'.$connection => $this->mysql()
            ? config('database.connections.mysql') : array_replace(config('database.connections.sqlite'),
                ['database' => ':memory:', 'foreign_key_constraints' => true])]);
        DB::purge($connection); if ($this->mysql()) { $this->clearMysql(); }
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        config(['core_shadow.isolated_copy' => true, 'legacy_capture_shadow.enabled' => true,
            'legacy_capture_shadow.run_key' => 'cron-reset-fixture', 'legacy_capture_shadow.billing_timezone' => 'UTC',
            'legacy_capture_shadow.currency' => 'USD', 'cache.stores.redis' => ['driver' => 'array']]);
        Bus::fake(); HookManager::reset(); $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        admin_setting(['commission_transfer_limit' => 0]);
        $this->plan = Plan::create(['name' => 'cron reset capture', 'group_id' => 1, 'transfer_enable' => 2,
            'show' => true, 'sell' => true, 'renew' => true, 'speed_limit' => 5, 'device_limit' => 3,
            'prices' => ['monthly' => 10, 'reset_traffic' => 1], 'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY]);
        $this->log = new TestHandler(); $this->log->setFormatter(new LineFormatter(null, null, true, true, true));
        Log::swap(new \Illuminate\Log\Logger(new Logger('capture-cron-reset', [$this->log])));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(); HookManager::reset();
        if ($this->mysql()) { $this->clearMysql(); }
        DB::purge($this->connection()); parent::tearDown();
    }

    public function test_actual_cron_command_captures_selected_raw_endpoints_and_preserves_native_logs_and_queue(): void
    {
        $first = $this->user(['u' => 70, 'd' => 90]); $second = $this->user(['u' => 30, 'd' => 40]);
        $this->backfill($first); $projection = $this->projection(); $sources = [$this->source($first), $this->source($second)];
        $ineligible = [$this->user(['banned' => true]), $this->user(['expired_at' => time() - 1]),
            $this->user(['plan_id' => null]), $this->user(['next_reset_at' => null]), $this->user(['next_reset_at' => time() + 3600])];
        $unchanged = array_map(fn ($user) => $this->source($user), $ineligible);
        $expected = [$this->service()->calculateNextResetTime($first)->timestamp, $this->service()->calculateNextResetTime($second)->timestamp];
        foreach ([$first, $second] as $user) { Cache::put('user_traffic_'.$user->id, 'cached'); }
        DB::enableQueryLog(); DB::flushQueryLog(); $this->assertSame(0, Artisan::call('reset:traffic'));
        $this->assertStringContainsString('2', Artisan::output());
        foreach ([$first, $second] as $index => $user) {
            $fresh = $user->fresh(); $this->assertSame([0, 0, 1, $expected[$index]],
                [(int) $fresh->u, (int) $fresh->d, (int) $fresh->reset_count, (int) $fresh->next_reset_at]);
            $event = DB::table('tx_legacy_capture_shadow_event')->where('legacy_user_id', $user->id)->first();
            $this->assertSame($sources[$index], json_decode($event->before_snapshot, true));
            $this->assertSame($this->source($user), json_decode($event->after_snapshot, true));
            $native = TrafficResetLog::where('user_id', $user->id)->firstOrFail();
            $this->assertSame([TrafficResetLog::SOURCE_CRON, (int) $sources[$index]['u'], (int) $sources[$index]['d']],
                [$native->trigger_source, (int) $native->old_upload, (int) $native->old_download]);
            $summary = $this->capture()->verify($user->id)['events'][0];
            $this->assertSame(['traffic.reset.cron', 'traffic-cron-reset-capture/v1', 'system_traffic_reset', (string) $user->id, 'transaction-only'],
                [$summary['source_kind'], $summary['source_version'], $summary['actor_domain'], $summary['actor_id'], $summary['capture_boundary']]);
            $this->assertNull(Cache::get('user_traffic_'.$user->id));
            $this->assertNoSecrets($user, json_encode([$event, $summary, $this->captureQueries(), $this->log->getRecords()]));
        }
        $this->assertSame($unchanged, array_map(fn ($user) => $this->source($user), $ineligible));
        $this->assertSame($projection, $this->projection()); $this->assertSame('source_changed', DB::table('tx_legacy_subscription_map')->value('state'));
        $this->assertSame('LEGACY_CAPTURE_PENDING', DB::table('tx_legacy_subscription_map')->value('error_code'));
        $this->assertSame(2, TrafficResetLog::count()); $this->assertSame(2, DB::table('tx_legacy_capture_shadow_event')->count());
        Bus::assertDispatched(NodeUserSyncJob::class); $this->assertSame([], $this->log->getRecords());
    }

    public function test_original_calendar_timezone_and_null_never_follow_policy_remain_native(): void
    {
        config(['app.timezone' => 'Asia/Shanghai']);
        foreach ([Plan::RESET_TRAFFIC_MONTHLY, Plan::RESET_TRAFFIC_YEARLY, Plan::RESET_TRAFFIC_FIRST_DAY_MONTH,
            Plan::RESET_TRAFFIC_FIRST_DAY_YEAR, Plan::RESET_TRAFFIC_NEVER, Plan::RESET_TRAFFIC_FOLLOW_SYSTEM] as $method) {
            $plan = Plan::create(['name' => 'native reset calendar', 'group_id' => 1, 'transfer_enable' => 1,
                'show' => true, 'sell' => true, 'renew' => true, 'prices' => [], 'reset_traffic_method' => $method]);
            admin_setting(['reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY]);
            $user = $this->user(['plan_id' => $plan->id]); $expected = $this->service()->calculateNextResetTime($user)?->timestamp;
            $this->assertTrue($this->reset($user)); $this->assertSame($expected, $user->fresh()->next_reset_at);
            $this->assertSame($method === Plan::RESET_TRAFFIC_NEVER ? TrafficResetLog::TYPE_MANUAL : match ($method) {
                Plan::RESET_TRAFFIC_YEARLY => TrafficResetLog::TYPE_YEARLY,
                Plan::RESET_TRAFFIC_FIRST_DAY_MONTH => TrafficResetLog::TYPE_FIRST_DAY_MONTH,
                Plan::RESET_TRAFFIC_FIRST_DAY_YEAR => TrafficResetLog::TYPE_FIRST_DAY_YEAR,
                default => TrafficResetLog::TYPE_MONTHLY,
            }, TrafficResetLog::where('user_id', $user->id)->value('reset_type'));
        }
        $nullExpiry = $this->user(['expired_at' => null]); $this->assertTrue($this->reset($nullExpiry));
        $this->assertNull($nullExpiry->fresh()->next_reset_at);
    }

    public function test_disabled_production_keeps_native_user_query_count_and_zero_capture_sql(): void
    {
        config(['legacy_capture_shadow.enabled' => false, 'core_shadow.isolated_copy' => false]); app()->instance('env', 'production');
        $user = $this->user(); DB::enableQueryLog(); DB::flushQueryLog(); $this->assertTrue($this->reset($user));
        $this->assertCaptureSqlAbsent();
        $this->assertCount(1, array_filter(DB::getQueryLog(), fn ($q) => preg_match('/^select.*tx_user/i', $q['query'])));
        $this->assertSame(1, (int) $user->fresh()->reset_count); $this->assertSame(1, TrafficResetLog::count());
        $this->assertNull($this->capture()->beginCronTrafficReset(0)); $this->capture()->appendCronTrafficReset(null);
    }

    public function test_locked_no_longer_due_and_precheck_rejections_bypass_invalid_guard_and_missing_schema(): void
    {
        $stale = $this->user(); DB::table('tx_user')->where('id', $stale->id)->update(['next_reset_at' => time() + 3600]);
        config(['legacy_capture_shadow.enabled' => 'invalid', 'legacy_capture_shadow.currency' => 'JPY']);
        Schema::drop('tx_legacy_capture_shadow_event'); Schema::drop('tx_legacy_capture_shadow_stream');
        DB::enableQueryLog(); DB::flushQueryLog(); $this->assertFalse($this->service()->checkAndReset($stale, TrafficResetLog::SOURCE_CRON));
        $this->assertFalse($this->reset($stale)); $this->assertCaptureSqlAbsent();
        DB::flushQueryLog(); $this->assertFalse($this->service()->checkAndReset($stale->fresh(), TrafficResetLog::SOURCE_CRON));
        $this->assertCaptureSqlAbsent(); $this->assertSame(0, TrafficResetLog::count()); $this->assertSame([], $this->log->getRecords());
    }

    public function test_other_sources_forced_cron_and_nested_cron_keep_zero_capture_sql_with_invalid_context(): void
    {
        config(['legacy_capture_shadow.enabled' => 'invalid']);
        foreach ([TrafficResetLog::SOURCE_MANUAL, TrafficResetLog::SOURCE_AUTO, TrafficResetLog::SOURCE_API,
            TrafficResetLog::SOURCE_USER_ACCESS, TrafficResetLog::SOURCE_ORDER, TrafficResetLog::SOURCE_GIFT_CARD] as $source) {
            $user = $this->user(); DB::enableQueryLog(); DB::flushQueryLog();
            $this->assertTrue($this->service()->performReset($user, $source)); $this->assertCaptureSqlAbsent();
        }
        $forced = $this->user(); DB::flushQueryLog();
        $this->assertTrue($this->service()->performReset($forced, TrafficResetLog::SOURCE_CRON, [], false)); $this->assertCaptureSqlAbsent();
        $nested = $this->user(); DB::flushQueryLog(); DB::beginTransaction();
        try { $this->assertTrue($this->reset($nested)); $this->assertCaptureSqlAbsent(); DB::commit(); }
        finally { if (DB::transactionLevel()) { DB::rollBack(); } }
        $this->assertSame(8, TrafficResetLog::count()); $this->assertSame(0, DB::table('tx_legacy_capture_shadow_event')->count());
    }

    public function test_manual_http_and_fix_force_command_scheduling_remain_outside_capture(): void
    {
        $admin = $this->user(['is_admin' => true]); $user = $this->user();
        admin_setting(['secure_path' => 'cron_capture_admin']); Sanctum::actingAs($admin);
        config(['legacy_capture_shadow.enabled' => 'invalid']); DB::enableQueryLog(); DB::flushQueryLog();
        $this->postJson('/txapi/admin/cron_capture_admin/traffic-resets/users/'.$user->id.'/reset',
            ['reason' => 'cron-fixture-request-secret'])->assertOk(); $this->assertCaptureSqlAbsent();
        $null = $this->user(['next_reset_at' => null]); DB::flushQueryLog();
        $this->assertSame(0, Artisan::call('reset:traffic', ['--fix-null' => true])); $this->assertCaptureSqlAbsent();
        $this->assertNotNull($null->fresh()->next_reset_at); DB::flushQueryLog();
        $this->assertSame(0, Artisan::call('reset:traffic', ['--force' => true])); $this->assertCaptureSqlAbsent();
        $this->assertSame(1, TrafficResetLog::count()); $this->assertSame(0, DB::table('tx_legacy_capture_shadow_event')->count());
    }

    public function test_strict_context_source_failure_retains_false_and_command_original_partial_completion(): void
    {
        $first = $this->user(); $second = $this->user();
        HookManager::register('traffic.reset.after', function (User $user) use ($first): void {
            if ((int) $user->id === (int) $first->id) { config(['legacy_capture_shadow.currency' => 'JPY']); }
        });
        $before = $this->state(); $this->assertSame(0, Artisan::call('reset:traffic'));
        $this->assertSame($before, $this->state()); $this->assertCount(2, $this->log->getRecords());
        foreach ($this->log->getRecords() as $record) { $this->assertSafeRecord($record); }
        $this->assertStringContainsString('无错误发生', Artisan::output()); HookManager::reset();
        config(['legacy_capture_shadow.currency' => 'USD']); $this->assertTrue($this->reset($first));
        config(['legacy_capture_shadow.enabled' => 'invalid']); $secondBefore = $this->source($second);
        $this->assertFalse($this->reset($second)); $this->assertSame($secondBefore, $this->source($second));
        $this->assertSame(1, TrafficResetLog::count());
    }

    public function test_invalid_guards_and_negative_raw_source_roll_back_without_secret_context(): void
    {
        $user = $this->user();
        foreach ([['legacy_capture_shadow.enabled' => '1'], ['core_shadow.isolated_copy' => false],
            ['legacy_capture_shadow.billing_timezone' => 'invalid'], ['legacy_capture_shadow.currency' => 'JPY']] as $invalid) {
            config($invalid); $before = $this->state(); $this->assertFalse($this->reset($user)); $this->assertSame($before, $this->state());
            config(['legacy_capture_shadow.enabled' => true, 'core_shadow.isolated_copy' => true,
                'legacy_capture_shadow.billing_timezone' => 'UTC', 'legacy_capture_shadow.currency' => 'USD']);
        }
        app()->instance('env', 'production'); $before = $this->state();
        $this->assertFalse($this->reset($user)); $this->assertSame($before, $this->state()); app()->instance('env', 'testing');
        DB::table('tx_user')->where('id', $user->id)->update(['balance' => -1]); $before = $this->state();
        $this->assertFalse($this->reset($user)); $this->assertSame($before, $this->state());
        foreach ($this->log->getRecords() as $record) { $this->assertSafeRecord($record); }
    }

    public function test_args_enabled_storage_failure_rolls_back_sql_but_preserves_native_cache_invalidation(): void
    {
        $user = $this->user(); $this->backfill($user); $before = $this->state(); $armed = true;
        Cache::put('user_traffic_'.$user->id, 'cached'); $hookCalls = 0;
        HookManager::register('traffic.reset.after', function () use (&$hookCalls): void { $hookCalls++; });
        DB::listen(function ($query) use (&$armed): void {
            if ($armed && str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'tx_legacy_subscription_map')) {
                $armed = false; throw new \RuntimeException('cron-fixture-storage-secret');
            }
        });
        $old = ini_set('zend.exception_ignore_args', '0');
        try {
            $this->assertSame('0', ini_get('zend.exception_ignore_args')); DB::enableQueryLog(); DB::flushQueryLog();
            $this->assertFalse($this->service()->performReset($user, TrafficResetLog::SOURCE_CRON,
                ['reason' => 'cron-fixture-request-secret', 'token' => 'cron-fixture-metadata-secret'], true));
            $this->assertSame($before, $this->state()); $this->assertSame(1, $hookCalls);
            $this->assertNull(Cache::get('user_traffic_'.$user->id));
            $this->assertCount(1, $this->log->getRecords()); $this->assertSafeRecord($this->log->getRecords()[0]);
            $this->assertNoSecrets($user, json_encode([$this->captureQueries(), $this->log->getRecords()]));
            $this->assertTrue($this->reset($user)); $this->assertSame(2, $hookCalls);
            $this->assertSame(1, TrafficResetLog::count()); $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count());
        } finally { ini_set('zend.exception_ignore_args', $old); }
    }

    public function test_in_transaction_same_user_hook_endpoint_is_captured_without_extra_terms_or_period(): void
    {
        $user = $this->user(); $projection = $this->projection();
        HookManager::register('traffic.reset.after', function (User $current): void {
            DB::table('tx_user')->where('id', $current->id)->update(['speed_limit' => 7]);
        });
        $this->assertTrue($this->reset($user)); $after = json_decode(DB::table('tx_legacy_capture_shadow_event')->value('after_snapshot'), true);
        $this->assertSame('7', $after['speed_limit']); $this->assertSame($this->source($user), $after);
        $this->assertTrue($this->capture()->verify($user->id)['page_ok']); $this->assertSame($projection, $this->projection());
    }

    public function test_reentrant_nested_cron_reset_retains_one_outer_capture_for_both_native_resets(): void
    {
        $user = $this->user(); $entered = false;
        HookManager::register('traffic.reset.after', function (User $current) use (&$entered): void {
            if ($entered) { return; } $entered = true;
            DB::table('tx_user')->where('id', $current->id)->update(['next_reset_at' => time() - 1]);
            $this->assertTrue($this->reset($current));
        });
        $this->assertTrue($this->reset($user)); $this->assertSame(2, (int) $user->fresh()->reset_count);
        $this->assertSame(2, TrafficResetLog::count()); $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count());
        $after = json_decode(DB::table('tx_legacy_capture_shadow_event')->value('after_snapshot'), true);
        $this->assertSame('2', $after['reset_count']); $this->assertTrue($this->capture()->verify($user->id)['page_ok']);
    }

    public function test_other_captured_same_user_hook_writer_collision_rolls_back_without_rebase(): void
    {
        foreach ([false, true] as $priorStream) {
            $user = $this->user(['commission_balance' => 100]);
            if ($priorStream) { $this->transfer($user, 1); }
            $before = $this->state();
            HookManager::register('traffic.reset.after', function (User $current): void { $this->transfer($current, 1); });
            $this->assertFalse($this->reset($user)); $this->assertSame($before, $this->state()); HookManager::reset();
        }
        foreach ($this->log->getRecords() as $record) { $this->assertSafeRecord($record); }
    }

    public function test_actual_order_nested_reset_keeps_only_fulfillment_ticket_and_postcommit_gap_is_not_hidden(): void
    {
        $user = $this->user(['next_reset_at' => time() + 3600]);
        $order = Order::create(['user_id' => $user->id, 'plan_id' => $this->plan->id, 'trade_no' => 'cron-order-secret',
            'period' => Plan::PERIOD_RESET_TRAFFIC, 'type' => Order::TYPE_RESET_TRAFFIC, 'status' => Order::STATUS_PROCESSING, 'total_amount' => 100]);
        (new OrderHandleJob($order->trade_no))->handle();
        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->fresh()->status);
        $this->assertSame(['order.fulfill'], DB::table('tx_legacy_capture_shadow_event')->pluck('source_kind')->all());
        $this->assertSame(1, TrafficResetLog::count()); $this->assertTrue($this->capture()->verify($user->id)['page_ok']);
        config(['legacy_capture_shadow.enabled' => 'invalid']); DB::enableQueryLog(); DB::flushQueryLog();
        $this->assertFalse($this->reset($user)); $this->assertCaptureSqlAbsent();
        config(['legacy_capture_shadow.enabled' => true]);
        HookManager::register('traffic.reset.after', function (User $current): void {
            $current->update(['expired_at' => $current->expired_at + 86400]);
        });
        $gap = $this->user(); $this->assertTrue($this->reset($gap));
        $this->assertContains('CAPTURE_SOURCE_GAP', $this->capture()->verify($gap->id)['issues']);
    }

    public function test_unknown_counter_writer_and_context_drift_are_blockers_not_reset_repairs(): void
    {
        $user = $this->user(['commission_balance' => 100]); $this->transfer($user, 1);
        config(['legacy_capture_shadow.currency' => 'CNY']); $before = $this->state();
        $this->assertFalse($this->reset($user)); $this->assertSame($before, $this->state());
        config(['legacy_capture_shadow.currency' => 'USD']); DB::table('tx_user')->where('id', $user->id)->increment('u', 90); $before = $this->state();
        $this->assertFalse($this->reset($user)); $this->assertSame($before, $this->state());
        $this->assertContains('CAPTURE_SOURCE_GAP', $this->capture()->verify($user->id)['issues']);
        $this->assertSame(0, TrafficResetLog::count());
    }

    public function test_reset_counter_int_headroom_cannot_partially_commit_and_native_errors_stay_distinct(): void
    {
        $user = $this->user(['reset_count' => 2147483646]); $this->assertTrue($this->reset($user));
        $after = json_decode(DB::table('tx_legacy_capture_shadow_event')->value('after_snapshot'), true);
        $this->assertSame('2147483647', $after['reset_count']);
        $overflow = $this->user(['reset_count' => 2147483647]); $before = $this->state();
        $this->assertFalse($this->reset($overflow)); $this->assertSame($before, $this->state());
        $last = $this->log->getRecords()[0];
        if ($this->mysql()) { $this->assertArrayHasKey('user_id', $last['context']); }
        else { $this->assertSafeRecord($last); }
        // Native save overflow precedes append on MariaDB; this fixture makes no native-log secrecy claim.
    }

    public function test_cron_ticket_transaction_owner_and_cross_writer_policy_are_enforced(): void
    {
        $user = $this->user(); $this->assertFailure('CAPTURE_TRANSACTION_REQUIRED', fn () => $this->capture()->beginCronTrafficReset($user->id));
        DB::beginTransaction();
        try {
            $ticket = $this->capture()->beginCronTrafficReset($user->id);
            foreach ([$this->capture()->begin($user->id), $this->capture()->beginVerifiedRecharge($user->id, 1),
                $this->capture()->beginOrderFulfillment($user->id, 1), $this->capture()->beginOrderCreation($user->id),
                $this->capture()->beginOrderCancellationRefund($user->id, 1, 1), $this->capture()->beginCommissionTransfer($user->id)] as $other) {
                $this->assertFailure('CAPTURE_WRITER_TICKET_INVALID', fn () => $this->capture()->appendCronTrafficReset($other));
            }
            foreach ([fn () => $this->capture()->append($ticket, $user->id), fn () => $this->capture()->appendVerifiedRecharge($ticket),
                fn () => $this->capture()->appendOrderFulfillment($ticket), fn () => $this->capture()->appendOrderCreation($ticket),
                fn () => $this->capture()->appendOrderCancellationRefund($ticket), fn () => $this->capture()->appendCommissionTransfer($ticket)] as $other) {
                $this->assertFailure('CAPTURE_WRITER_TICKET_INVALID', $other);
            }
            $ticket['actor_id'] = $user->id + 1;
            $this->assertFailure('CAPTURE_WRITER_TICKET_INVALID', fn () => $this->capture()->appendCronTrafficReset($ticket));
        } finally { DB::rollBack(); }
    }

    public function test_rehashed_wrong_cron_actor_kind_or_version_never_passes_read_verification(): void
    {
        $user = $this->user(); $this->assertTrue($this->reset($user)); $original = (array) DB::table('tx_legacy_capture_shadow_event')->first();
        foreach ([['actor_id' => $user->id + 1], ['source_kind' => 'unknown.cron'], ['source_version' => 'invented-reset/v1']] as $change) {
            $payload = array_replace($original, $change); unset($payload['id'], $payload['content_hash']);
            foreach (['stream_id', 'legacy_user_id', 'sequence', 'actor_id', 'captured_at'] as $key) { $payload[$key] = (string) $payload[$key]; }
            foreach (['before_snapshot', 'after_snapshot'] as $key) { $payload[$key] = json_decode($payload[$key], true); }
            $hash = SubscriptionTermsTimeline::hash($payload);
            DB::table('tx_legacy_capture_shadow_event')->update($change + ['content_hash' => $hash]);
            DB::table('tx_legacy_capture_shadow_stream')->update(['last_event_hash' => $hash]);
            $this->assertFailure('CAPTURE_EVENT_TAMPERED', fn () => $this->capture()->verify($user->id));
            DB::table('tx_legacy_capture_shadow_event')->update($original);
        }
    }

    public function test_mixed_native_writer_chain_keeps_old_hashes_context_and_bounded_recovery_select_only(): void
    {
        $user = $this->user(['balance' => 2000, 'commission_balance' => 100]); $admin = $this->user(['is_admin' => true, 'next_reset_at' => null]);
        $this->backfill($user); admin_setting(['secure_path' => 'cron_capture_admin']); Sanctum::actingAs($admin);
        $this->postJson('/txapi/admin/cron_capture_admin/users/'.$user->id.'/update', ['speed_limit' => 1])->assertOk();
        [$payment, $recharge] = $this->recharge($user);
        $this->assertTrue(app(WalletRechargeService::class)->settleVerified($recharge->trade_no, $payment->id, 'native-receipt', 1000));
        $order = OrderService::createFromRequest($user, $this->plan, 'monthly'); $this->assertTrue((new OrderService($order))->cancel());
        $this->transfer($user, 1); $prior = DB::table('tx_legacy_capture_shadow_event')->orderBy('id')->get()->toJson();
        $context = DB::table('tx_legacy_capture_shadow_stream')->value('context_hash'); $projection = $this->projection();
        $this->assertTrue($this->reset($user));
        $this->assertSame($prior, DB::table('tx_legacy_capture_shadow_event')->where('sequence', '<=', 5)->orderBy('id')->get()->toJson());
        $this->assertSame($context, DB::table('tx_legacy_capture_shadow_stream')->value('context_hash'));
        $this->assertSame($projection, $this->projection()); $before = $this->state(); DB::enableQueryLog(); DB::flushQueryLog();
        $summaries = [];
        for ($cursor = 0; $cursor < 6; $cursor++) {
            $page = $this->capture()->verify($user->id, $cursor, 1); $this->assertTrue($page['page_ok']);
            $this->assertFalse($page['projection_applied']); $this->assertFalse($page['migration_gate_open']);
            $this->assertFalse($page['global_coverage_complete']); $summaries[] = $page['events'][0];
        }
        $this->assertSame(['admin.user.update', 'wallet.recharge.settle', 'order.create', 'order.cancel.refund', 'commission.transfer', 'traffic.reset.cron'],
            array_column($summaries, 'source_kind'));
        foreach (DB::getQueryLog() as $query) { $this->assertMatchesRegularExpression('/^select/i', $query['query']); }
        $this->assertSame($before, $this->state());
    }

    public function test_native_successful_reset_recheck_skips_invalid_context_and_schema_without_new_event(): void
    {
        $user = $this->user(); $this->assertTrue($this->reset($user)); $source = $this->source($user);
        config(['legacy_capture_shadow.enabled' => 'invalid', 'legacy_capture_shadow.currency' => 'CNY']);
        Schema::drop('tx_legacy_capture_shadow_event'); Schema::drop('tx_legacy_capture_shadow_stream');
        DB::enableQueryLog(); DB::flushQueryLog(); $this->assertFalse($this->reset($user)); $this->assertCaptureSqlAbsent();
        $this->assertSame($source, $this->source($user)); $this->assertSame(1, TrafficResetLog::count());
    }

    public function test_independent_mysql_stale_due_requests_have_one_native_reset_winner(): void
    {
        $this->requireFork(); $user = $this->user();
        $this->assertSame('NO', $this->compete(fn () => $this->reset($user), fn () => $this->reset($user)));
        $this->assertSame(1, (int) $user->fresh()->reset_count); $this->assertSame(1, TrafficResetLog::count());
        $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count()); $this->assertTrue($this->capture()->verify($user->id)['page_ok']);
    }

    public function test_independent_mysql_recharge_then_standalone_reset_uses_current_source_and_tail(): void
    {
        $this->requireFork(); $user = $this->user(); [$payment, $recharge] = $this->recharge($user);
        $this->assertSame('OK', $this->compete(
            fn () => app(WalletRechargeService::class)->settleVerified($recharge->trade_no, $payment->id, 'native-receipt', 1000),
            fn () => $this->reset($user)));
        $row = DB::table('tx_legacy_capture_shadow_event')->where('sequence', 2)->first();
        $this->assertSame('1100', json_decode($row->before_snapshot, true)['balance']);
        $report = $this->capture()->verify($user->id); $this->assertTrue($report['page_ok']);
        $this->assertSame(['wallet.recharge.settle', 'traffic.reset.cron'], array_column($report['events'], 'source_kind'));
        DB::beginTransaction(); DB::enableQueryLog(); DB::flushQueryLog();
        try {
            User::query()->lockForUpdate()->findOrFail($user->id); DB::flushQueryLog(); $this->capture()->beginCronTrafficReset($user->id);
            $this->assertStringContainsString('lock in share mode', strtolower(DB::getQueryLog()[0]['query']));
        } finally { DB::rollBack(); }
    }

    public function test_independent_mysql_reset_then_transfer_keeps_native_lock_order_and_one_endpoint_chain(): void
    {
        $this->requireFork(); $user = $this->user(['commission_balance' => 100]);
        $this->assertSame('OK', $this->compete(fn () => $this->reset($user), function () use ($user): bool { $this->transfer($user, 1); return true; }));
        $report = $this->capture()->verify($user->id); $this->assertTrue($report['page_ok']);
        $this->assertSame(['traffic.reset.cron', 'commission.transfer'], array_column($report['events'], 'source_kind'));
        $this->assertSame(101, (int) $user->fresh()->balance);
    }

    public function test_mysql_outer_mvcc_scope_is_excluded_instead_of_manufacturing_standalone_capture(): void
    {
        $this->requireFork(); $user = $this->user(); [$payment, $recharge] = $this->recharge($user);
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP); DB::purge(); $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($parent); stream_set_timeout($child, 10); DB::beginTransaction();
            DB::table('tx_user')->where('id', $user->id)->first(); DB::table('tx_legacy_capture_shadow_stream')->get(); fwrite($child, 'R'); fread($child, 1);
            try { $okay = $this->reset($user); DB::commit(); fwrite($child, $okay ? 'OK' : 'NO'); }
            catch (\Throwable) { DB::rollBack(); fwrite($child, 'FAIL'); }
            fclose($child); DB::disconnect(); exit(0);
        }
        $this->assertGreaterThan(0, $pid); fclose($child); stream_set_timeout($parent, 10);
        try {
            $this->assertSame('R', fread($parent, 1)); DB::beginTransaction();
            $this->assertTrue(app(WalletRechargeService::class)->settleVerified($recharge->trade_no, $payment->id, 'native-receipt', 1000));
            fwrite($parent, 'S'); $this->assertWaiting($parent); DB::commit(); $this->assertSame('OK', stream_get_contents($parent, 32));
        } finally { if (DB::transactionLevel()) { DB::rollBack(); } fclose($parent); pcntl_waitpid($pid, $status); }
        $this->assertSame(0, pcntl_wexitstatus($status));
        $this->assertSame(['wallet.recharge.settle'], DB::table('tx_legacy_capture_shadow_event')->pluck('source_kind')->all());
        $this->assertSame(1, TrafficResetLog::count()); $this->assertContains('CAPTURE_SOURCE_GAP', $this->capture()->verify($user->id)['issues']);
    }

    public function test_mysql_process_death_before_and_after_commit_retains_due_retry_without_notification_claim(): void
    {
        $this->requireFork(); $user = $this->user(); $this->backfill($user); $before = $this->state();
        $this->killReset($user, false); $this->assertSame($before, $this->state());
        $this->killReset($user, true); $this->assertSame(1, (int) $user->fresh()->reset_count);
        $this->assertSame(1, TrafficResetLog::count()); $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count());
        $committed = $this->state(); config(['legacy_capture_shadow.enabled' => 'invalid']);
        $this->assertFalse($this->reset($user)); $this->assertSame($committed, $this->state());
        config(['legacy_capture_shadow.enabled' => true]); $this->assertTrue($this->capture()->verify($user->id)['page_ok']);
    }

    private function compete(callable $winner, callable $waiting): string
    {
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP); DB::purge(); $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($parent); stream_set_timeout($child, 10); fread($child, 1); fwrite($child, 'R');
            try { fwrite($child, $waiting() ? 'OK' : 'NO'); } catch (\Throwable) { fwrite($child, 'FAIL'); }
            fclose($child); DB::disconnect(); exit(0);
        }
        $this->assertGreaterThan(0, $pid); fclose($child); stream_set_timeout($parent, 10);
        $armed = true;
        DB::listen(function ($query) use (&$armed, $parent): void {
            if ($armed && str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'tx_legacy_capture_shadow_stream')) {
                $armed = false; fwrite($parent, 'S');
                $this->assertSame('R', fread($parent, 1)); $this->assertWaiting($parent);
            }
        });
        try {
            // Do not add an outer transaction: the winner must enter the actual standalone scope.
            $this->assertTrue($winner()); $this->assertFalse($armed); $result = stream_get_contents($parent, 32);
        } finally { if (DB::transactionLevel()) { DB::rollBack(); } fclose($parent); pcntl_waitpid($pid, $status); }
        $this->assertSame(0, pcntl_wexitstatus($status)); return $result;
    }

    private function killReset(User $user, bool $afterCommit): void
    {
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP); DB::purge(); $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($parent); $barrier = function () use ($child): void { fwrite($child, 'B'); fflush($child); fread($child, 1); };
            if ($afterCommit) {
                Event::listen(TransactionCommitted::class, function ($event) use ($barrier): void {
                    if ($event->connection->transactionLevel() === 0) { $barrier(); }
                });
            } else {
                DB::listen(function ($query) use ($barrier): void {
                    if (str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'tx_legacy_subscription_map')) { $barrier(); }
                });
            }
            $this->reset($user); exit(3);
        }
        $this->assertGreaterThan(0, $pid); fclose($child); stream_set_timeout($parent, 10);
        try {
            $this->assertSame('B', fread($parent, 1)); $this->assertTrue(posix_kill($pid, SIGKILL));
            pcntl_waitpid($pid, $status); $this->assertTrue(pcntl_wifsignaled($status)); $this->assertSame(SIGKILL, pcntl_wtermsig($status));
        } finally { fclose($parent); }
    }

    private function reset(User $user): bool { return $this->service()->performReset($user, TrafficResetLog::SOURCE_CRON, [], true); }
    private function service(): TrafficResetService { return app(TrafficResetService::class); }
    private function transfer(User $user, int $amount): void
    {
        Sanctum::actingAs($user);
        $response = app(CommissionController::class)->transfer(Request::create('/txapi/billing/commission-transfer', 'POST', ['transfer_amount' => $amount]));
        $this->assertSame(200, $response->getStatusCode());
    }
    private function recharge(User $user): array
    {
        $payment = Payment::create(['uuid' => 'cron-payment-'.(++$this->counter), 'payment' => 'EPay', 'name' => 'fixture', 'enable' => true, 'config' => []]);
        return [$payment, app(WalletRechargeService::class)->create($user->id, 1000, $payment->id, (string) \Illuminate\Support\Str::uuid())];
    }
    private function user(array $extra = []): User
    {
        $id = ++$this->counter; $next = array_key_exists('next_reset_at', $extra) ? $extra['next_reset_at'] : time() - 1;
        $anchor = Carbon::now('UTC')->startOfMonth()->addMonths(2)->day(15)->setTime(12, 0)->timestamp;
        $user = User::create(array_replace(['email' => 'cron-capture-'.$id.'@example.test', 'password' => 'cron-fixture-password',
            'uuid' => sprintf('40000000-0000-4000-8000-%012d', $id), 'token' => sprintf('%032d', $id),
            'plan_id' => $this->plan->id, 'group_id' => 1, 'transfer_enable' => 1000, 'u' => 10, 'd' => 20,
            'balance' => 100, 'expired_at' => $anchor], $extra));
        // Fixture setup intentionally follows the actual creation Observer's schedule calculation.
        DB::table('tx_user')->where('id', $user->id)->update(['next_reset_at' => $next]); return $user->fresh();
    }
    private function backfill(User $user): void
    {
        app(SubscriptionShadowBackfill::class)->run('cron-map-'.$user->id, 100, app(LegacySubscriptionSnapshot::class)->context('UTC', 'USD'));
    }
    private function source(User $user): array
    {
        $row = (array) DB::table('tx_user')->where('id', $user->id)->select(LegacyUserMutationCapture::FIELDS)->first();
        foreach ($row as $key => $value) { $row[$key] = $key === 'banned' ? (bool) $value : ($value === null ? null : (string) $value); }
        ksort($row, SORT_STRING); return $row;
    }
    private function projection(): array
    {
        $out = [];
        foreach (['tx_subscription', 'tx_subscription_terms', 'tx_subscription_terms_transition', 'tx_usage_period',
            'tx_usage_adjustment', 'tx_access_subject', 'tx_commerce_shadow_money_journal', 'tx_commerce_shadow_money_posting'] as $table) {
            $out[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        return $out;
    }
    private function state(): array
    {
        $out = $this->projection();
        foreach (['tx_user', 'tx_order', 'tx_coupon', 'idempotency_keys', 'tx_traffic_reset_logs', 'tx_legacy_capture_shadow_stream',
            'tx_legacy_capture_shadow_event', 'tx_legacy_subscription_map'] as $table) { $out[$table] = DB::table($table)->orderBy('id')->get()->toJson(); }
        return $out;
    }
    private function capture(): LegacyUserMutationCapture { return app(LegacyUserMutationCapture::class); }
    private function assertFailure(string $code, callable $callback): void
    {
        try { $callback(); $this->fail('Accepted invalid cron reset evidence.'); }
        catch (LegacyCaptureShadowException $exception) { $this->assertSame($code, $exception->getMessage()); $this->assertNull($exception->getPrevious()); }
    }
    private function assertWaiting($pipe): void
    {
        $read = [$pipe]; $write = []; $except = [];
        $this->assertSame(0, stream_select($read, $write, $except, 0, 250000), 'Writer escaped the uncommitted native User fence.');
    }
    private function assertSafeRecord($record): void
    {
        $this->assertSame('LEGACY_CRON_TRAFFIC_RESET_CAPTURE_FAILED', $record['message']); $this->assertSame([], $record['context']);
    }
    private function captureQueries(): array
    {
        return array_values(array_filter(DB::getQueryLog(), fn ($q) => preg_match('/tx_legacy_capture_shadow|tx_legacy_subscription_map/', $q['query'])));
    }
    private function assertCaptureSqlAbsent(): void { $this->assertSame([], $this->captureQueries()); }
    private function assertNoSecrets(User $user, string $text): void
    {
        foreach ([$user->email, $user->password, $user->token, $user->uuid, 'cron-fixture-request-secret',
            'cron-fixture-metadata-secret', 'cron-fixture-storage-secret'] as $secret) { $this->assertStringNotContainsString($secret, $text); }
    }
    private function mysql(): bool { return getenv('CORE_CAPTURE_TEST_MYSQL') === '1'; }
    private function connection(): string { return $this->mysql() ? 'cron_reset_capture_mysql_testing' : 'cron_reset_capture_sqlite_testing'; }
    private function requireFork(): void
    {
        if (!$this->mysql() || !function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('Requires independent MariaDB sessions and process control.');
        }
    }
    private function clearMysql(): void
    {
        if (DB::connection()->getDatabaseName() !== 'txboard_concurrency_test') { throw new \RuntimeException('Cron capture needs its dedicated database.'); }
        Schema::disableForeignKeyConstraints();
        try { foreach (Schema::getTableListing() as $table) { Schema::drop($table); } }
        finally { Schema::enableForeignKeyConstraints(); }
    }
}
