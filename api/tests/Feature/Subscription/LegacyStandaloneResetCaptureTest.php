<?php

namespace Tests\Feature\Subscription;

use App\Jobs\OrderHandleJob;
use App\Models\GiftCardCode;
use App\Models\GiftCardTemplate;
use App\Models\Order;
use App\Models\Plan;
use App\Models\TrafficResetLog;
use App\Models\User;
use App\Services\GiftCardService;
use App\Services\Plugin\HookManager;
use App\Services\Subscription\LegacySubscriptionSnapshot;
use App\Services\Subscription\LegacyUserMutationCapture;
use App\Services\Subscription\SubscriptionShadowBackfill;
use App\Services\TrafficResetService;
use App\Services\UserService;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Tests\TestCase;

class LegacyStandaloneResetCaptureTest extends TestCase
{
    private int $counter = 0;
    private TestHandler $log;

    protected function setUp(): void
    {
        parent::setUp(); $connection = $this->connection();
        config(['database.default' => $connection, 'database.connections.'.$connection => $this->mysql()
            ? config('database.connections.mysql') : array_replace(config('database.connections.sqlite'),
                ['database' => ':memory:', 'foreign_key_constraints' => true])]);
        DB::purge($connection); if ($this->mysql()) $this->clearMysql();
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        config(['core_shadow.isolated_copy' => true, 'legacy_capture_shadow.enabled' => true,
            'legacy_capture_shadow.run_key' => 'standalone-reset-fixture', 'legacy_capture_shadow.billing_timezone' => 'UTC',
            'legacy_capture_shadow.currency' => 'USD', 'legacy_observer_capture.enabled' => false,
            'legacy_standalone_reset_capture.enabled' => true, 'cache.stores.redis' => ['driver' => 'array']]);
        Bus::fake(); HookManager::reset(); admin_setting(['secure_path' => 'standalone_reset_fixture', 'renew_order_event_id' => 0]);
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->log = new TestHandler(); Log::swap(new \Illuminate\Log\Logger(new Logger('capture-standalone-reset', [$this->log])));
    }

    protected function tearDown(): void
    {
        HookManager::reset(); if ($this->mysql()) $this->clearMysql();
        DB::purge($this->connection()); parent::tearDown();
    }

    public function test_trusted_standalone_sources_preserve_exact_native_endpoints_logs_and_projection(): void
    {
        $plan = $this->plan();
        foreach (['manual', 'auto', 'api', 'user_access', 'order', 'gift_card', 'cron'] as $source) {
            $user = $this->user(['plan_id' => $plan->id]); $this->backfill($user); $before = $this->source($user); $projection = $this->projection();
            $this->assertTrue($this->service()->performReset($user, $source, ['reason' => 'private-reset-reason', 'admin_id' => 123]));
            $event = DB::table('tx_legacy_capture_shadow_event')->where('legacy_user_id', $user->id)->first();
            $this->assertSame($before, json_decode($event->before_snapshot, true));
            $this->assertSame($this->source($user), json_decode($event->after_snapshot, true));
            $this->assertSame($projection, $this->projection()); $this->assertSame([0, 0, 1], $this->counters($user));
            $log = TrafficResetLog::where('user_id', $user->id)->firstOrFail();
            $this->assertSame([$source, 10, 20, 30], [$log->trigger_source, (int) $log->old_upload, (int) $log->old_download, (int) $log->old_total]);
            $this->assertSame('private-reset-reason', $log->metadata['reason']);
            $page = $this->capture()->verify($user->id); $this->assertTrue($page['page_ok']); $summary = $page['events'][0];
            $this->assertSame(['traffic.reset.standalone', 'standalone-reset-capture/v1', 'system_legacy_traffic_reset', (string) $user->id],
                [$summary['source_kind'], $summary['source_version'], $summary['actor_domain'], $summary['actor_id']]);
            $this->assertSame('source_changed', DB::table('tx_legacy_subscription_map')->where('legacy_user_id', $user->id)->value('state'));
            foreach ([$user->email, $user->password, $user->uuid, $user->token, 'private-reset-reason'] as $secret)
                $this->assertStringNotContainsString($secret, json_encode([$event, $page]));
        }
        $this->assertSame(7, TrafficResetLog::count()); $this->assertSame([], $this->log->getRecords());
    }

    public function test_real_admin_auth_path_native_metadata_and_repeat_manual_resets_are_preserved(): void
    {
        $plan = $this->plan(); $user = $this->user(['plan_id' => $plan->id]); $admin = $this->user(['is_admin' => true]);
        $url = '/txapi/admin/standalone_reset_fixture/traffic-resets/users/'.$user->id.'/reset';
        $this->postJson($url)->assertForbidden(); Sanctum::actingAs($user); $this->postJson($url)->assertForbidden();
        Sanctum::actingAs($admin); $this->postJson(str_replace('standalone_reset_fixture', 'guessed', $url))->assertNotFound();
        foreach ([1, 2] as $count) {
            $this->postJson($url, ['reason' => 'manual-fixture-reason', 'admin_id' => $user->id])->assertOk()->assertJsonPath('data.user_id', $user->id);
            $this->assertSame([0, 0, $count], $this->counters($user));
        }
        $log = TrafficResetLog::orderByDesc('id')->first(); $this->assertSame($admin->id, $log->metadata['admin_id']);
        $page = $this->capture()->verify($user->id); $this->assertTrue($page['page_ok']); $this->assertCount(2, $page['events']);
        $this->assertSame([(string) $user->id, (string) $user->id], array_column($page['events'], 'actor_id'));
    }

    public function test_actual_user_access_due_helper_captures_once_then_skips_before_invalid_guards(): void
    {
        $plan = $this->plan(); $user = $this->user(['plan_id' => $plan->id]); $user->updateQuietly(['next_reset_at' => time()-1]);
        $info = app(UserService::class)->getUserTrafficInfo($user);
        $this->assertSame([0, 0, 1], $this->counters($user)); $this->assertSame(0, $info['total_used']);
        $this->assertSame(TrafficResetLog::SOURCE_USER_ACCESS, TrafficResetLog::first()->trigger_source);
        config(['legacy_standalone_reset_capture.enabled' => 'invalid']); DB::enableQueryLog(); DB::flushQueryLog();
        $this->assertFalse($this->service()->checkAndReset($user->fresh(), TrafficResetLog::SOURCE_USER_ACCESS));
        $this->assertNoCaptureQueries(); $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count());
    }

    public function test_native_manual_ineligible_and_locked_due_rejections_skip_capture(): void
    {
        $plan = $this->plan(); $active = $this->user(['plan_id' => $plan->id]); $blocked = $this->user(['banned' => true, 'plan_id' => $plan->id]);
        config(['legacy_standalone_reset_capture.enabled' => 'invalid']); DB::enableQueryLog(); DB::flushQueryLog();
        $this->assertFalse($this->service()->manualReset($blocked));
        $this->assertFalse($this->service()->performReset($blocked, TrafficResetLog::SOURCE_MANUAL));
        $this->assertFalse($this->service()->performReset($active, TrafficResetLog::SOURCE_AUTO, [], true));
        $this->assertNoCaptureQueries(); $this->assertSame(0, TrafficResetLog::count());
    }

    public function test_strict_flags_unknown_source_and_context_fail_with_fixed_false_and_rollback(): void
    {
        $plan = $this->plan(); $user = $this->user(['plan_id' => $plan->id]); $before = $this->source($user);
        foreach (['true', 1, null] as $flag) {
            config(['legacy_standalone_reset_capture.enabled' => $flag]); $this->assertFalse($this->service()->manualReset($user));
            $this->assertSame($before, $this->source($user));
        }
        config(['legacy_standalone_reset_capture.enabled' => true]); $this->assertFalse($this->service()->performReset($user, 'unknown-private-source'));
        config(['legacy_capture_shadow.enabled' => false]); $this->assertFalse($this->service()->manualReset($user));
        config(['legacy_capture_shadow.enabled' => true, 'core_shadow.isolated_copy' => false]); $this->assertFalse($this->service()->manualReset($user));
        $this->assertSame($before, $this->source($user)); $this->assertSame(0, TrafficResetLog::count());
        foreach ($this->log->getRecords() as $record) {
            $this->assertSame('LEGACY_STANDALONE_TRAFFIC_RESET_CAPTURE_FAILED', $record['message']); $this->assertSame([], $record['context']);
        }
    }

    public function test_default_off_production_keeps_original_reset_without_capture_sql(): void
    {
        $plan = $this->plan(); $user = $this->user(['plan_id' => $plan->id]);
        config(['legacy_standalone_reset_capture.enabled' => false, 'legacy_capture_shadow.enabled' => 'invalid', 'core_shadow.isolated_copy' => false]);
        $this->app['env'] = 'production'; Schema::drop('tx_legacy_capture_shadow_event'); Schema::drop('tx_legacy_capture_shadow_stream');
        DB::enableQueryLog(); DB::flushQueryLog(); $this->assertTrue($this->service()->manualReset($user));
        $this->assertNoCaptureQueries(); $this->assertSame([0, 0, 1], $this->counters($user));
    }

    public function test_storage_failure_rolls_back_user_native_log_and_map_and_preserves_safe_http_error(): void
    {
        $plan = $this->plan(); $user = $this->user(['plan_id' => $plan->id]); $admin = $this->user(['is_admin' => true]);
        $this->backfill($user); $before = $this->source($user); $map = DB::table('tx_legacy_subscription_map')->orderBy('id')->get()->toJson();
        $active = true; DB::listen(function ($query) use (&$active): void {
            if ($active && preg_match('/^insert.*tx_legacy_capture_shadow_event/i', $query->sql)) throw new \RuntimeException('private-standalone-storage-detail');
        });
        Sanctum::actingAs($admin);
        $response = $this->postJson('/txapi/admin/standalone_reset_fixture/traffic-resets/users/'.$user->id.'/reset');
        $response->assertStatus(409)->assertJsonPath('error.code', 'TRAFFIC_RESET_FAILED'); $active = false;
        $this->assertStringNotContainsString('private-standalone-storage-detail', $response->getContent());
        $this->assertSame($before, $this->source($user)); $this->assertSame($map, DB::table('tx_legacy_subscription_map')->orderBy('id')->get()->toJson());
        $this->assertSame(0, TrafficResetLog::count()); $this->assertSame(0, DB::table('tx_legacy_capture_shadow_event')->count());
        $this->assertCount(1, $this->log->getRecords()); $this->assertSame([], $this->log->getRecords()[0]['context']);
    }

    public function test_due_cron_keeps_original_policy_and_nested_gift_and_hook_keep_outer_ticket(): void
    {
        $plan = $this->plan(); $cron = $this->user(['plan_id' => $plan->id]); $cron->updateQuietly(['next_reset_at' => time()-1]);
        config(['legacy_standalone_reset_capture.enabled' => 'invalid']); $this->assertTrue($this->service()->checkAndReset($cron, TrafficResetLog::SOURCE_CRON));
        $this->assertSame('traffic.reset.cron', $this->capture()->verify($cron->id)['events'][0]['source_kind']);
        config(['legacy_standalone_reset_capture.enabled' => true]); $gift = $this->user(['plan_id' => $plan->id]);
        $template = GiftCardTemplate::create(['name' => 'nested-reset', 'type' => GiftCardTemplate::TYPE_GENERAL,
            'status' => true, 'admin_id' => 1, 'rewards' => ['reset_package' => true]]);
        $code = GiftCardCode::create(['template_id' => $template->id, 'code' => 'NESTEDRESETFIXTURE', 'status' => 0, 'max_usage' => 1]);
        (new GiftCardService($code->code))->setUser($gift)->redeem();
        $this->assertSame(['gift.reward.apply'], array_column($this->capture()->verify($gift->id)['events'], 'source_kind'));
        $hookUser = $this->user(['plan_id' => $plan->id]); $entered = false;
        HookManager::register('traffic.reset.after', function (User $current) use (&$entered): void {
            if (!$entered) { $entered = true; $this->assertTrue($this->service()->performReset($current, TrafficResetLog::SOURCE_API)); }
        });
        $this->assertTrue($this->service()->manualReset($hookUser)); HookManager::reset();
        $this->assertSame([0, 0, 2], $this->counters($hookUser));
        $this->assertCount(1, $this->capture()->verify($hookUser->id)['events']);
    }

    public function test_order_postcommit_reset_is_separate_from_fulfillment_and_observer_schedule(): void
    {
        $plan = $this->plan(); $user = $this->user(['plan_id' => $plan->id]);
        $order = Order::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'type' => Order::TYPE_RENEWAL,
            'status' => Order::STATUS_PROCESSING, 'period' => Plan::PERIOD_MONTHLY, 'total_amount' => 1000,
            'balance_amount' => 0, 'trade_no' => 'standalone-private-order']);
        admin_setting(['renew_order_event_id' => 1]); config(['legacy_observer_capture.enabled' => true]);
        (new OrderHandleJob($order->trade_no))->handle();
        $page = $this->capture()->verify($user->id); $this->assertTrue($page['page_ok']);
        $kinds = array_column($page['events'], 'source_kind'); $this->assertSame('order.fulfill', $kinds[0]);
        $this->assertContains('traffic.reset.standalone', $kinds); $this->assertSame([0, 0, 1], $this->counters($user));
        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->fresh()->status);
    }

    public function test_source_gap_refuses_reset_without_rebase_and_verification_stays_readonly(): void
    {
        $plan = $this->plan(); $user = $this->user(['plan_id' => $plan->id]); $this->assertTrue($this->service()->manualReset($user));
        DB::table('tx_user')->where('id', $user->id)->increment('u'); $before = $this->source($user);
        $this->assertFalse($this->service()->manualReset($user)); $this->assertSame($before, $this->source($user));
        $this->assertSame(1, TrafficResetLog::count()); DB::enableQueryLog(); DB::flushQueryLog(); $page = $this->capture()->verify($user->id);
        foreach (DB::getQueryLog() as $q) $this->assertMatchesRegularExpression('/^select/i', $q['query']);
        $this->assertContains('CAPTURE_SOURCE_GAP', $page['issues']);
    }

    public function test_independent_mysql_repeated_manual_resets_remain_two_commits(): void
    {
        $this->requireFork(); $plan = $this->plan(); $user = $this->user(['plan_id' => $plan->id]);
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP); DB::purge(); $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($parent); stream_set_timeout($child, 10); fwrite($child, 'R'); fread($child, 1);
            try { fwrite($child, $this->service()->manualReset($user) ? 'OK' : 'FAIL'); } catch (\Throwable) { fwrite($child, 'ERR'); }
            fclose($child); DB::disconnect(); exit(0);
        }
        $this->assertGreaterThan(0, $pid); fclose($child); stream_set_timeout($parent, 10);
        try {
            $this->assertSame('R', fread($parent, 1)); DB::beginTransaction();
            // A native outer caller holds the current User fence; nested reset has no new ticket.
            $current = User::lockForUpdate()->findOrFail($user->id); $current->updateQuietly(['u' => 70]);
            fwrite($parent, 'S'); $read = [$parent]; $write = []; $except = [];
            $this->assertSame(0, stream_select($read, $write, $except, 0, 250000)); DB::commit();
            $this->assertSame('OK', stream_get_contents($parent, 32));
        } finally { if (DB::transactionLevel()) DB::rollBack(); fclose($parent); pcntl_waitpid($pid, $status); }
        $this->assertSame(0, pcntl_wexitstatus($status)); $this->assertTrue($this->service()->manualReset($user));
        $this->assertSame([0, 0, 2], $this->counters($user)); $this->assertSame(2, TrafficResetLog::count());
        $page = $this->capture()->verify($user->id); $this->assertTrue($page['page_ok']); $this->assertCount(2, $page['events']);
    }

    public function test_mysql_sigkill_before_and_after_commit_and_unknown_manual_replay_is_another_reset(): void
    {
        $this->requireFork(); $plan = $this->plan(); $user = $this->user(['plan_id' => $plan->id]); $this->backfill($user);
        $before = $this->source($user); $this->killReset($user, false); $this->assertSame($before, $this->source($user));
        $this->assertSame(0, TrafficResetLog::count()); $this->assertSame(0, DB::table('tx_legacy_capture_shadow_event')->count());
        $this->killReset($user, true); $this->assertSame([0, 0, 1], $this->counters($user));
        $this->assertSame(1, TrafficResetLog::count()); $this->assertTrue($this->capture()->verify($user->id)['page_ok']);
        $this->assertTrue($this->service()->manualReset($user)); $this->assertSame([0, 0, 2], $this->counters($user));
        $this->assertCount(2, $this->capture()->verify($user->id)['events']);
    }

    private function killReset(User $user, bool $afterCommit): void
    {
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP); DB::purge(); $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($parent); $barrier = function () use ($child): void { fwrite($child, 'K'); while (true) usleep(10000); };
            if ($afterCommit) Event::listen(TransactionCommitted::class, function ($event) use ($barrier): void {
                if ($event->connection->transactionLevel() === 0) $barrier();
            });
            else DB::listen(function ($query) use ($barrier): void {
                if (preg_match('/^update.*tx_legacy_subscription_map/i', $query->sql)) $barrier();
            });
            $this->service()->manualReset($user); exit(2);
        }
        $this->assertGreaterThan(0, $pid); fclose($child); stream_set_timeout($parent, 10);
        try { $this->assertSame('K', fread($parent, 1)); }
        finally { posix_kill($pid, SIGKILL); pcntl_waitpid($pid, $status); fclose($parent);
            $this->assertTrue(pcntl_wifsignaled($status)); $this->assertSame(SIGKILL, pcntl_wtermsig($status)); }
    }

    private function service(): TrafficResetService { return app(TrafficResetService::class); }
    private function user(array $override = []): User
    {
        $id = ++$this->counter;
        return User::create(array_replace(['email' => 'standalone-'.$id.'@example.test', 'password' => 'standalone-private-password',
            'uuid' => sprintf('90000000-0000-4000-8000-%012d', $id), 'token' => sprintf('%032d', $id),
            'transfer_enable' => 1000, 'u' => 10, 'd' => 20, 'balance' => 100, 'commission_balance' => 20,
            'expired_at' => time()+86400], $override));
    }
    private function plan(): Plan
    { return Plan::create(['name' => 'standalone-plan', 'group_id' => 1, 'transfer_enable' => 10,
        'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY, 'prices' => ['monthly' => 10], 'show' => 1, 'sell' => 1]); }
    private function counters(User $user): array { $user->refresh(); return [(int) $user->u, (int) $user->d, (int) $user->reset_count]; }
    private function capture(): LegacyUserMutationCapture { return app(LegacyUserMutationCapture::class); }
    private function backfill(User $user): void
    { app(SubscriptionShadowBackfill::class)->run('standalone-map-'.$user->id, 100, app(LegacySubscriptionSnapshot::class)->context('UTC', 'USD')); }
    private function source(User $user): array
    {
        $row = (array) DB::table('tx_user')->where('id', $user->id)->select(LegacyUserMutationCapture::FIELDS)->first();
        foreach ($row as $key => $value) $row[$key] = $key === 'banned' ? (bool) $value : ($value === null ? null : (string) $value);
        ksort($row, SORT_STRING); return $row;
    }
    private function projection(): array
    {
        $out = []; foreach (['tx_subscription', 'tx_subscription_terms', 'tx_usage_period', 'tx_usage_adjustment',
            'tx_access_subject', 'tx_commerce_shadow_money_journal', 'tx_commerce_shadow_money_posting'] as $name)
            $out[$name] = DB::table($name)->orderBy('id')->get()->toJson(); return $out;
    }
    private function assertNoCaptureQueries(): void
    { $this->assertSame([], array_values(array_filter(DB::getQueryLog(), fn ($q) => preg_match('/tx_legacy_capture_shadow|tx_legacy_subscription_map/', $q['query'])))); }
    private function mysql(): bool { return getenv('CORE_CAPTURE_TEST_MYSQL') === '1'; }
    private function connection(): string { return $this->mysql() ? 'standalone_capture_mysql_testing' : 'standalone_capture_sqlite_testing'; }
    private function requireFork(): void
    { if (!$this->mysql() || !function_exists('pcntl_fork') || !function_exists('posix_kill')) $this->markTestSkipped('Requires local independent MariaDB sessions and process control.'); }
    private function clearMysql(): void
    {
        if (DB::connection()->getDatabaseName() !== 'txboard_concurrency_test') throw new \RuntimeException('Standalone reset capture needs its dedicated database.');
        Schema::disableForeignKeyConstraints(); try { foreach (Schema::getTableListing() as $table) Schema::drop($table); }
        finally { Schema::enableForeignKeyConstraints(); }
    }
}
