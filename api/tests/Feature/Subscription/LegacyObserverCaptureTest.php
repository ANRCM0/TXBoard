<?php

namespace Tests\Feature\Subscription;

use App\Jobs\NodeUserSyncJob;
use App\Jobs\OrderHandleJob;
use App\Models\GiftCardCode;
use App\Models\GiftCardTemplate;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Observers\UserObserver;
use App\Services\GiftCardService;
use App\Services\Plugin\HookManager;
use App\Services\Subscription\LegacyCaptureShadowException;
use App\Services\Subscription\LegacySubscriptionSnapshot;
use App\Services\Subscription\LegacyUserMutationCapture;
use App\Services\Subscription\SubscriptionShadowBackfill;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LegacyObserverCaptureTest extends TestCase
{
    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp(); $connection = $this->connection();
        config(['database.default' => $connection, 'database.connections.'.$connection => $this->mysql()
            ? config('database.connections.mysql') : array_replace(config('database.connections.sqlite'),
                ['database' => ':memory:', 'foreign_key_constraints' => true])]);
        DB::purge($connection); if ($this->mysql()) $this->clearMysql();
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        config(['core_shadow.isolated_copy' => true, 'legacy_capture_shadow.enabled' => true,
            'legacy_capture_shadow.run_key' => 'observer-fixture', 'legacy_capture_shadow.billing_timezone' => 'UTC',
            'legacy_capture_shadow.currency' => 'USD', 'legacy_observer_capture.enabled' => false]);
        Bus::fake(); HookManager::reset(); admin_setting(['renew_order_event_id' => 0]);
    }

    protected function tearDown(): void
    {
        HookManager::reset(); if ($this->mysql()) $this->clearMysql();
        DB::purge($this->connection()); parent::tearDown();
    }

    public function test_gift_actual_after_commit_schedule_forms_second_contiguous_transaction_endpoint(): void
    {
        $plan = $this->plan(); $user = $this->user(['plan_id' => $plan->id]); $this->backfill($user);
        $projection = $this->projection(); $before = $this->source($user); $code = $this->code(['expire_days' => 2]);
        config(['legacy_observer_capture.enabled' => true]); Bus::fake(); $this->redeem($code, $user);
        $rows = DB::table('tx_legacy_capture_shadow_event')->orderBy('sequence')->get(); $this->assertCount(2, $rows);
        $this->assertSame($before, json_decode($rows[0]->before_snapshot, true));
        $this->assertSame(json_decode($rows[0]->after_snapshot, true), json_decode($rows[1]->before_snapshot, true));
        $this->assertSame($this->source($user), json_decode($rows[1]->after_snapshot, true));
        $observerBefore = json_decode($rows[1]->before_snapshot, true); $observerAfter = json_decode($rows[1]->after_snapshot, true);
        $this->assertNotSame($observerBefore['next_reset_at'], $observerAfter['next_reset_at']);
        $observerBefore['next_reset_at'] = $observerAfter['next_reset_at']; $this->assertSame($observerBefore, $observerAfter);
        $this->assertSame($projection, $this->projection()); $this->assertSame(1, (int) $code->fresh()->usage_count);
        DB::enableQueryLog(); DB::flushQueryLog(); $page = $this->capture()->verify($user->id);
        foreach (DB::getQueryLog() as $q) $this->assertMatchesRegularExpression('/^select/i', $q['query']);
        $this->assertTrue($page['page_ok']);
        $this->assertSame(['gift.reward.apply', 'user.schedule.observer'], array_column($page['events'], 'source_kind'));
        $this->assertSame(['observer-schedule-capture/v1', 'system_user_schedule_observer', (string) $user->id, 'transaction-only'],
            [$page['events'][1]['source_version'], $page['events'][1]['actor_domain'], $page['events'][1]['actor_id'], $page['events'][1]['capture_boundary']]);
        Bus::assertDispatched(NodeUserSyncJob::class);
        foreach ([$user->email, $user->password, $user->uuid, $user->token, $code->code] as $secret)
            $this->assertStringNotContainsString($secret, json_encode([$rows, $page]));
        foreach (['global_coverage_complete', 'projection_applied', 'migration_gate_open'] as $flag) $this->assertFalse($page[$flag]);
        // A later captured business writer now starts from the complete observed endpoint.
        $this->redeem($this->code(['balance' => 10]), $user);
        $this->assertTrue($this->capture()->verify($user->id)['page_ok']);
    }

    public function test_actual_order_fulfillment_observer_and_completed_retry_keep_chain_and_no_recapture(): void
    {
        $plan = $this->plan(); $user = $this->user(['expired_at' => 0, 'transfer_enable' => 0]);
        $order = Order::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'type' => Order::TYPE_NEW_PURCHASE,
            'status' => Order::STATUS_PROCESSING, 'period' => Plan::PERIOD_MONTHLY, 'total_amount' => 1000,
            'balance_amount' => 0, 'trade_no' => 'observer-private-order-trade']);
        config(['legacy_observer_capture.enabled' => true]); (new OrderHandleJob($order->trade_no))->handle();
        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->fresh()->status);
        $page = $this->capture()->verify($user->id); $this->assertTrue($page['page_ok']);
        $this->assertSame(['order.fulfill', 'user.schedule.observer'], array_column($page['events'], 'source_kind'));
        $saved = DB::table('tx_legacy_capture_shadow_event')->orderBy('sequence')->get()->toJson();
        config(['legacy_observer_capture.enabled' => 'invalid', 'legacy_capture_shadow.enabled' => 'invalid']);
        DB::enableQueryLog(); DB::flushQueryLog(); (new OrderHandleJob($order->trade_no))->handle();
        $this->assertNoCaptureQueries(); $this->assertSame($saved, DB::table('tx_legacy_capture_shadow_event')->orderBy('sequence')->get()->toJson());
    }

    public function test_created_observer_captures_only_schedule_not_the_prior_user_insert(): void
    {
        $plan = $this->plan(); config(['legacy_observer_capture.enabled' => true]);
        $user = $this->user(['plan_id' => $plan->id]); $page = $this->capture()->verify($user->id);
        $this->assertTrue($page['page_ok']); $this->assertCount(1, $page['events']);
        $event = DB::table('tx_legacy_capture_shadow_event')->first();
        $before = json_decode($event->before_snapshot, true); $this->assertNull($before['next_reset_at']);
        $this->assertSame((string) $user->id, $before['id']); $this->assertNotNull($user->fresh()->next_reset_at);
        $this->assertSame('user.schedule.observer', $page['events'][0]['source_kind']);
    }

    public function test_noop_schedule_and_updates_without_native_schedule_trigger_do_not_create_event(): void
    {
        $user = $this->user(); config(['legacy_observer_capture.enabled' => true]);
        $this->observer()->created($user); $this->assertSame(0, DB::table('tx_legacy_capture_shadow_event')->count());
        config(['legacy_observer_capture.enabled' => 'invalid']); DB::enableQueryLog(); DB::flushQueryLog();
        $user->update(['speed_limit' => 10]); $this->assertNoCaptureQueries(); $this->assertSame(10, (int) $user->fresh()->speed_limit);
    }

    public function test_default_off_production_preserves_original_schedule_and_zero_capture_sql(): void
    {
        $plan = $this->plan(); $user = $this->user(['plan_id' => $plan->id]);
        $user->updateQuietly(['next_reset_at' => null]);
        config(['legacy_capture_shadow.enabled' => 'invalid', 'core_shadow.isolated_copy' => false]); $this->app['env'] = 'production';
        Schema::drop('tx_legacy_capture_shadow_event'); Schema::drop('tx_legacy_capture_shadow_stream');
        DB::enableQueryLog(); DB::flushQueryLog(); $this->observer()->created($user);
        $this->assertNoCaptureQueries(); $this->assertNotNull($user->fresh()->next_reset_at);
    }

    public function test_strict_flags_and_context_refuse_schedule_without_repairing_it(): void
    {
        $plan = $this->plan(); $user = $this->user(['plan_id' => $plan->id]); $user->updateQuietly(['next_reset_at' => null]);
        foreach (['true', '1', 1, null] as $flag) {
            config(['legacy_observer_capture.enabled' => $flag]);
            $this->failure('CAPTURE_OBSERVER_ISOLATED_REQUIRED', fn () => $this->observer()->created($user));
            $this->assertNull($user->fresh()->next_reset_at);
        }
        config(['legacy_observer_capture.enabled' => true, 'legacy_capture_shadow.enabled' => false]);
        $this->failure('CAPTURE_OBSERVER_ISOLATED_REQUIRED', fn () => $this->observer()->created($user));
        config(['legacy_capture_shadow.enabled' => true, 'core_shadow.isolated_copy' => false]);
        $this->failure('CAPTURE_ISOLATED_COPY_REQUIRED', fn () => $this->observer()->created($user));
        $this->assertNull($user->fresh()->next_reset_at); $this->assertSame(0, DB::table('tx_legacy_capture_shadow_event')->count());
    }

    public function test_manually_nested_observer_refuses_ticket_and_native_rollback_does_not_schedule(): void
    {
        $plan = $this->plan(); $user = $this->user(['plan_id' => $plan->id]); config(['legacy_observer_capture.enabled' => true]);
        DB::transaction(function () use ($user): void {
            $this->failure('CAPTURE_OBSERVER_TRANSACTION_UNSUPPORTED', fn () => $this->observer()->created($user));
        });
        $before = $this->source($user); DB::beginTransaction(); $user->update(['expired_at' => $user->expired_at+172800]); DB::rollBack();
        $this->assertSame($before, $this->source($user)); $this->assertSame(0, DB::table('tx_legacy_capture_shadow_event')->count());
    }

    public function test_failure_in_actual_after_commit_callback_preserves_prior_gift_commit_and_rolls_back_only_schedule(): void
    {
        $plan = $this->plan(); $user = $this->user(['plan_id' => $plan->id]); $code = $this->code(['expire_days' => 2]);
        $before = $this->source($user); config(['legacy_observer_capture.enabled' => true]); $active = true;
        DB::listen(function ($query) use (&$active): void {
            if ($active && preg_match('/^insert.*tx_legacy_capture_shadow_event/i', $query->sql)
                && in_array('user.schedule.observer', $query->bindings, true)) throw new \RuntimeException('private-observer-storage-detail');
        });
        Bus::fake(); $this->failure('CAPTURE_STORAGE_OR_EVIDENCE_FAILED', fn () => $this->redeem($code, $user)); $active = false;
        $this->assertSame(1, (int) $code->fresh()->usage_count); $this->assertSame(1, DB::table('tx_gift_card_usage')->count());
        $this->assertSame((int) $before['expired_at']+172800, (int) $user->fresh()->expired_at);
        $this->assertSame($before['next_reset_at'], $this->source($user)['next_reset_at']);
        $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count());
        $this->assertTrue($this->capture()->verify($user->id)['page_ok']); Bus::assertNotDispatched(NodeUserSyncJob::class);
        // Readonly evidence cannot certify the unscheduled after-commit obligation.
        $this->observer()->created($user); $this->assertCount(2, $this->capture()->verify($user->id)['events']);
    }

    public function test_preexisting_gap_is_not_rebased_by_observer_and_uncaptured_business_has_already_committed(): void
    {
        $plan = $this->plan(); $user = $this->user(['plan_id' => $plan->id]);
        $this->redeem($this->code(['expire_days' => 2]), $user); $this->assertContains('CAPTURE_SOURCE_GAP', $this->capture()->verify($user->id)['issues']);
        config(['legacy_observer_capture.enabled' => true]); $before = $this->source($user);
        // A native no-op schedule returns without creating an event or rebasing the existing gap.
        $this->observer()->created($user); $this->assertSame($before, $this->source($user));
        $this->assertContains('CAPTURE_SOURCE_GAP', $this->capture()->verify($user->id)['issues']);
        $this->failure('CAPTURE_SOURCE_GAP', fn () => $user->fresh()->update(['expired_at' => (int) $user->fresh()->expired_at+86400]));
        $this->assertSame((int) $before['expired_at']+86400, (int) $user->fresh()->expired_at);
        $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count());
    }

    public function test_original_without_events_schedule_save_still_bypasses_model_saving_listener(): void
    {
        $plan = $this->plan(); $user = $this->user(['plan_id' => $plan->id]); $user->updateQuietly(['next_reset_at' => null]);
        config(['legacy_observer_capture.enabled' => true]);
        $name = 'eloquent.saving: '.User::class;
        Event::listen($name, static fn () => false);
        try { $this->observer()->created($user); } finally { Event::forget($name); }
        // withoutEvents intentionally bypasses model listeners, as the original runtime does.
        $this->assertNotNull($user->fresh()->next_reset_at); $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count());
    }

    public function test_independent_mysql_schedule_current_lock_uses_fresh_endpoint_not_stale_passed_model(): void
    {
        $this->requireFork(); $plan = $this->plan(); $user = $this->user(['plan_id' => $plan->id]);
        $stale = $user->fresh(); config(['legacy_observer_capture.enabled' => true]);
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP); DB::purge(); $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($parent); stream_set_timeout($child, 10); fwrite($child, 'R'); fread($child, 1);
            try { $this->observer()->created($stale); fwrite($child, 'OK'); } catch (\Throwable) { fwrite($child, 'FAIL'); }
            fclose($child); DB::disconnect(); exit(0);
        }
        $this->assertGreaterThan(0, $pid); fclose($child); stream_set_timeout($parent, 10);
        try {
            $this->assertSame('R', fread($parent, 1)); DB::beginTransaction();
            $locked = User::lockForUpdate()->findOrFail($user->id); $locked->updateQuietly(['expired_at' => $locked->expired_at+172800]);
            $newExpiry = (int) $locked->expired_at; fwrite($parent, 'S');
            $read = [$parent]; $write = []; $except = []; $this->assertSame(0, stream_select($read, $write, $except, 0, 250000));
            DB::commit(); $this->assertSame('OK', stream_get_contents($parent, 32));
        } finally { if (DB::transactionLevel()) DB::rollBack(); fclose($parent); pcntl_waitpid($pid, $status); }
        $this->assertSame(0, pcntl_wexitstatus($status)); $page = $this->capture()->verify($user->id); $this->assertTrue($page['page_ok']);
        $event = DB::table('tx_legacy_capture_shadow_event')->first(); $this->assertSame((string) $newExpiry, json_decode($event->before_snapshot, true)['expired_at']);
        $this->assertSame($this->source($user), json_decode($event->after_snapshot, true));
    }

    public function test_mysql_sigkill_before_and_after_followup_commit_keeps_prior_business_distinct(): void
    {
        $this->requireFork(); $plan = $this->plan(); $user = $this->user(['plan_id' => $plan->id]);
        $code = $this->code(['expire_days' => 2]); $before = $this->source($user); config(['legacy_observer_capture.enabled' => true]);
        $this->killGiftObserver($user, $code, false);
        $this->assertSame(1, (int) $code->fresh()->usage_count); $this->assertSame((int) $before['expired_at']+172800, (int) $user->fresh()->expired_at);
        $this->assertSame($before['next_reset_at'], $this->source($user)['next_reset_at']);
        $this->assertCount(1, $this->capture()->verify($user->id)['events']);
        $this->observer()->created($user); $this->assertCount(2, $this->capture()->verify($user->id)['events']);
        $next = $this->code(['expire_days' => 2]); $this->killGiftObserver($user, $next, true);
        $this->assertSame(1, (int) $next->fresh()->usage_count); $page = $this->capture()->verify($user->id);
        $this->assertTrue($page['page_ok']); $this->assertCount(4, $page['events']);
        $this->assertSame(2, DB::table('tx_gift_card_usage')->count());
    }

    private function killGiftObserver(User $user, GiftCardCode $code, bool $afterCommit): void
    {
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP); DB::purge(); $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($parent); $barrier = function () use ($child): void { fwrite($child, 'K'); while (true) usleep(10000); };
            if ($afterCommit) {
                $commits = 0; Event::listen(TransactionCommitted::class, function ($event) use (&$commits, $barrier): void {
                    if ($event->connection->transactionLevel() === 0 && ++$commits === 2) $barrier();
                });
            } else DB::listen(function ($query) use ($barrier): void {
                if (preg_match('/^insert.*tx_legacy_capture_shadow_event/i', $query->sql)
                    && in_array('user.schedule.observer', $query->bindings, true)) $barrier();
            });
            $this->redeem($code, $user); exit(2);
        }
        $this->assertGreaterThan(0, $pid); fclose($child); stream_set_timeout($parent, 10);
        try { $this->assertSame('K', fread($parent, 1)); }
        finally { posix_kill($pid, SIGKILL); pcntl_waitpid($pid, $status); fclose($parent);
            $this->assertTrue(pcntl_wifsignaled($status)); $this->assertSame(SIGKILL, pcntl_wtermsig($status)); }
    }

    private function observer(): UserObserver { return app(UserObserver::class); }
    private function user(array $override = []): User
    {
        $id = ++$this->counter;
        return User::create(array_replace(['email' => 'observer-'.$id.'@example.test', 'password' => 'observer-private-password',
            'uuid' => sprintf('80000000-0000-4000-8000-%012d', $id), 'token' => sprintf('%032d', $id),
            'transfer_enable' => 1000, 'u' => 10, 'd' => 20, 'balance' => 100, 'commission_balance' => 20,
            'expired_at' => time()+86400], $override));
    }
    private function plan(): Plan
    { return Plan::create(['name' => 'observer-plan', 'group_id' => 1, 'transfer_enable' => 10, 'speed_limit' => 20,
        'device_limit' => 3, 'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY, 'prices' => ['monthly' => 10], 'show' => 1, 'sell' => 1]); }
    private function code(array $rewards): GiftCardCode
    {
        $model = GiftCardTemplate::create(['name' => 'observer-fixture', 'type' => GiftCardTemplate::TYPE_GENERAL,
            'status' => true, 'admin_id' => 1, 'rewards' => $rewards]);
        return GiftCardCode::create(['template_id' => $model->id, 'code' => 'OBSERVERFIXTURE'.(++$this->counter),
            'status' => GiftCardCode::STATUS_UNUSED, 'max_usage' => 1, 'usage_count' => 0]);
    }
    private function redeem(GiftCardCode $code, User $user): array
    { return (new GiftCardService($code->code))->setUser($user)->redeem(); }
    private function capture(): LegacyUserMutationCapture { return app(LegacyUserMutationCapture::class); }
    private function backfill(User $user): void
    { app(SubscriptionShadowBackfill::class)->run('observer-map-'.$user->id, 100, app(LegacySubscriptionSnapshot::class)->context('UTC', 'USD')); }
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
    private function failure(string $code, callable $operation): void
    {
        try { $operation(); $this->fail('Invalid observer capture accepted.'); }
        catch (LegacyCaptureShadowException $e) { $this->assertSame($code, $e->getMessage()); $this->assertNull($e->getPrevious()); }
    }
    private function mysql(): bool { return getenv('CORE_CAPTURE_TEST_MYSQL') === '1'; }
    private function connection(): string { return $this->mysql() ? 'observer_capture_mysql_testing' : 'observer_capture_sqlite_testing'; }
    private function requireFork(): void
    { if (!$this->mysql() || !function_exists('pcntl_fork') || !function_exists('posix_kill')) $this->markTestSkipped('Requires local independent MariaDB sessions and process control.'); }
    private function clearMysql(): void
    {
        if (DB::connection()->getDatabaseName() !== 'txboard_concurrency_test') throw new \RuntimeException('Observer capture needs its dedicated database.');
        Schema::disableForeignKeyConstraints(); try { foreach (Schema::getTableListing() as $table) Schema::drop($table); }
        finally { Schema::enableForeignKeyConstraints(); }
    }
}
