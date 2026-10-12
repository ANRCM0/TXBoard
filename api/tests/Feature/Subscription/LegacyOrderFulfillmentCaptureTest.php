<?php

namespace Tests\Feature\Subscription;

use App\Domains\Billing\WalletRechargeService;
use App\Jobs\OrderHandleJob;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Plugin;
use App\Models\User;
use App\Services\OrderService;
use App\Services\Plugin\HookManager;
use App\Services\Plugin\PluginManager;
use App\Services\Subscription\LegacyCaptureShadowException;
use App\Services\Subscription\LegacySubscriptionSnapshot;
use App\Services\Subscription\LegacyUserMutationCapture;
use App\Services\Subscription\SubscriptionShadowBackfill;
use Carbon\Carbon;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Tests\TestCase;

class LegacyOrderFulfillmentCaptureTest extends TestCase
{
    private int $counter = 0;
    private Plan $plan;
    private Payment $payment;
    private TestHandler $log;

    protected function setUp(): void
    {
        parent::setUp();
        $connection = $this->mysql() ? 'order_capture_mysql_testing' : 'order_capture_sqlite_testing';
        config(['database.default' => $connection, 'database.connections.'.$connection => $this->mysql()
            ? config('database.connections.mysql') : array_replace(config('database.connections.sqlite'),
                ['database' => ':memory:', 'foreign_key_constraints' => true])]);
        DB::purge($connection);
        if ($this->mysql()) { $this->clearMysql(); }
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        config(['core_shadow.isolated_copy' => true, 'legacy_capture_shadow.enabled' => true,
            'legacy_capture_shadow.run_key' => 'order-capture-fixture',
            'legacy_capture_shadow.billing_timezone' => 'UTC', 'legacy_capture_shadow.currency' => 'USD',
            'cache.stores.redis' => ['driver' => 'array']]);
        Bus::fake();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        Plugin::create(['name' => 'EPay', 'code' => 'epay', 'type' => 'payment',
            'version' => '1.0.0', 'is_enabled' => true, 'config' => '{}']);
        app()->forgetInstance(PluginManager::class); HookManager::reset();
        $this->plan = $this->plan();
        $this->payment = Payment::create(['uuid' => 'order-capture-payment-uuid', 'payment' => 'EPay',
            'name' => 'Order capture EPay', 'enable' => true,
            'config' => ['pid' => 'order-capture-merchant', 'key' => 'order-fixture-provider-secret',
                'url' => 'https://payment.invalid']]);
        $this->log = new TestHandler();
        $this->log->setFormatter(new LineFormatter(null, null, true, true, true));
        Log::swap(new \Illuminate\Log\Logger(new Logger('capture-order', [$this->log])));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if ($this->mysql()) { $this->clearMysql(); }
        DB::purge($this->mysql() ? 'order_capture_mysql_testing' : 'order_capture_sqlite_testing');
        parent::tearDown();
    }

    public function test_actual_signed_native_callback_ack_precedes_transaction_only_renewal_capture(): void
    {
        $user = $this->user(['balance' => 1234, 'commission_balance' => 567]); $this->backfill($user);
        $order = $this->order($user, ['status' => Order::STATUS_PENDING, 'payment_id' => $this->payment->id,
            'total_amount' => 1000, 'handling_amount' => 130, 'balance_amount' => 37]);
        $before = $this->source($user); $projection = $this->projection(); $params = $this->signature($order);
        $this->assertSame('success', $this->notifyOrder($params)->assertOk()->getContent());
        $this->assertSame(Order::STATUS_PROCESSING, (int) $order->fresh()->status);
        $this->assertSame($before, $this->source($user));
        $this->assertSame(0, DB::table('tx_legacy_capture_shadow_event')->count());
        Bus::assertDispatchedTimes(OrderHandleJob::class, 1);
        DB::enableQueryLog(); DB::flushQueryLog();
        $this->fulfill($order);
        $event = DB::table('tx_legacy_capture_shadow_event')->first();
        $after = json_decode($event->after_snapshot, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($before, json_decode($event->before_snapshot, true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame('1234', $after['balance']); $this->assertSame('567', $after['commission_balance']);
        $this->assertSame('2147483648', $after['transfer_enable']);
        $this->assertSame('10', $after['u']); $this->assertSame('20', $after['d']); $this->assertSame('0', $after['reset_count']);
        $this->assertSame((string) Carbon::createFromTimestamp((int) $before['expired_at'])->addMonth()->timestamp, $after['expired_at']);
        $this->assertSame('5', $after['speed_limit']); $this->assertSame('3', $after['device_limit']);
        $this->assertSame($after, $this->source($user));
        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->fresh()->status);
        $this->assertSame([1000, 130, 37], [(int) $order->fresh()->total_amount,
            (int) $order->fresh()->handling_amount, (int) $order->fresh()->balance_amount]);
        $this->assertSame($projection, $this->projection());
        $report = $this->capture()->verify($user->id); $summary = $report['events'][0];
        $this->assertTrue($report['page_ok']); $this->assertSame('admin-user-update-evidence', $report['scope']);
        $this->assertSame('order.fulfill', $summary['source_kind']);
        $this->assertSame('order-fulfillment-capture/v1', $summary['source_version']);
        $this->assertSame('system_order_fulfillment', $summary['actor_domain']);
        $this->assertSame((string) $order->id, $summary['actor_id']);
        $this->assertSame('transaction-only', $summary['capture_boundary']);
        $this->assertFalse($report['projection_applied']); $this->assertFalse($report['global_coverage_complete']);
        $this->assertFalse($report['migration_gate_open']);
        $bindings = array_values(array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'tx_legacy_capture_shadow')));
        $this->assertNoSecrets($user, $order, json_encode([$event, $bindings, $report, $this->log->getRecords()], JSON_THROW_ON_ERROR), $params);
        $state = $this->state();
        $this->assertSame('success', $this->notifyOrder($params)->assertOk()->getContent());
        $this->fulfill($order); $this->assertSame($state, $this->state());
    }

    public function test_free_checkout_and_manual_paid_keep_system_fulfillment_actor_without_payment_or_admin_promotion(): void
    {
        $owner = $this->user(); $free = $this->order($owner, ['status' => Order::STATUS_PENDING, 'total_amount' => 0]);
        Sanctum::actingAs($owner);
        $this->postJson('/txapi/orders/'.$free->trade_no.'/checkout')->assertOk();
        $this->assertSame(Order::STATUS_PROCESSING, (int) $free->fresh()->status);
        $this->fulfill($free);
        $admin = $this->user(['is_admin' => true]); $manual = $this->order($owner, ['status' => Order::STATUS_PENDING]);
        admin_setting(['secure_path' => 'order_capture_admin']); Sanctum::actingAs($admin);
        $this->postJson('/txapi/admin/order_capture_admin/orders/'.$manual->trade_no.'/paid')->assertOk();
        $this->fulfill($manual);
        $report = $this->capture()->verify($owner->id);
        $this->assertTrue($report['page_ok']);
        $this->assertSame(['system_order_fulfillment', 'system_order_fulfillment'], array_column($report['events'], 'actor_domain'));
        $this->assertSame([(string) $free->id, (string) $manual->id], array_column($report['events'], 'actor_id'));
        $this->assertSame(0, DB::table('tx_commerce_shadow_money_journal')->count());
        $this->assertNull($free->fresh()->payment_id); $this->assertNull($manual->fresh()->payment_id);
    }

    public function test_actual_after_commit_observer_writes_remain_source_gap_and_completed_retry_does_not_recapture(): void
    {
        $user = $this->user(['plan_id' => null, 'group_id' => null, 'expired_at' => 0, 'transfer_enable' => 0]);
        $order = $this->order($user, ['type' => Order::TYPE_NEW_PURCHASE]);
        $this->fulfill($order);
        $event = DB::table('tx_legacy_capture_shadow_event')->first();
        $after = json_decode($event->after_snapshot, true, flags: JSON_THROW_ON_ERROR);
        $this->assertNull($after['next_reset_at']);
        $this->assertNotNull($user->fresh()->next_reset_at);
        $this->assertSame('0', $after['u']); $this->assertSame('0', $after['d']);
        $this->assertSame('1', $after['reset_count']);
        $this->assertSame(1, DB::table('tx_traffic_reset_logs')->count());
        $report = $this->capture()->verify($user->id); $this->assertFalse($report['page_ok']);
        $this->assertContains('CAPTURE_SOURCE_GAP', $report['issues']);
        $empty = $this->capture()->verify($user->id, 1); $this->assertFalse($empty['page_ok']);
        $this->assertContains('CAPTURE_SOURCE_GAP', $empty['issues']);
        $before = $this->state(); config(['legacy_capture_shadow.enabled' => 'invalid']);
        DB::enableQueryLog(); DB::flushQueryLog(); $this->fulfill($order);
        $this->assertCaptureSqlAbsent(); $this->assertSame($before, $this->state());
        config(['legacy_capture_shadow.enabled' => true]);
        $next = $this->order($user); $before = $this->state();
        $this->assertFailure('CAPTURE_SOURCE_GAP', fn () => $this->fulfill($next));
        $this->assertSame($before, $this->state());
    }

    public function test_original_postcommit_open_event_reset_is_preserved_and_reported_as_uncaptured_gap(): void
    {
        $user = $this->user(); $order = $this->order($user);
        admin_setting(['renew_order_event_id' => 1]); $this->fulfill($order);
        $after = json_decode(DB::table('tx_legacy_capture_shadow_event')->value('after_snapshot'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('10', $after['u']); $this->assertSame('20', $after['d']); $this->assertSame('0', $after['reset_count']);
        $this->assertSame(0, (int) $user->fresh()->u); $this->assertSame(0, (int) $user->fresh()->d);
        $this->assertSame(1, (int) $user->fresh()->reset_count);
        $this->assertSame(1, DB::table('tx_traffic_reset_logs')->count());
        $this->assertContains('CAPTURE_SOURCE_GAP', $this->capture()->verify($user->id)['issues']);
        $before = $this->state(); $this->fulfill($order); $this->assertSame($before, $this->state());
    }

    public function test_upgrade_preserves_actual_surplus_credit_discounting_and_counter_behavior(): void
    {
        $user = $this->user(['balance' => 123]); $prior = $this->order($user, ['status' => Order::STATUS_COMPLETED]);
        $target = $this->plan(['transfer_enable' => 7, 'speed_limit' => 9, 'device_limit' => 4]);
        $order = $this->order($user, ['plan_id' => $target->id, 'type' => Order::TYPE_UPGRADE,
            'surplus_credit' => 75, 'surplus_amount' => 1075, 'surplus_order_ids' => [$prior->id],
            'total_amount' => 0, 'handling_amount' => 42]);
        $this->fulfill($order);
        $after = json_decode(DB::table('tx_legacy_capture_shadow_event')->value('after_snapshot'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('198', $after['balance']); $this->assertSame('7516192768', $after['transfer_enable']);
        $this->assertSame('10', $after['u']); $this->assertSame('20', $after['d']); $this->assertSame('0', $after['reset_count']);
        $this->assertSame((string) $target->id, $after['plan_id']); $this->assertSame('9', $after['speed_limit']);
        $this->assertSame(Order::STATUS_DISCOUNTED, (int) $prior->fresh()->status);
        $this->assertSame([75, 1075, 0, 42], [(int) $order->fresh()->surplus_credit,
            (int) $order->fresh()->surplus_amount, (int) $order->fresh()->total_amount, (int) $order->fresh()->handling_amount]);
        $this->assertSame(0, DB::table('tx_traffic_reset_logs')->count());
    }

    public function test_onetime_and_reset_packages_keep_native_nested_reset_values_without_shadow_projection(): void
    {
        $oneTime = $this->user(); $one = $this->order($oneTime, ['period' => Plan::PERIOD_ONETIME]);
        $resetUser = $this->user(); $reset = $this->order($resetUser,
            ['period' => Plan::PERIOD_RESET_TRAFFIC, 'type' => Order::TYPE_RESET_TRAFFIC]);
        $projection = $this->projection(); $this->fulfill($one); $this->fulfill($reset);
        $rows = DB::table('tx_legacy_capture_shadow_event')->orderBy('id')->get();
        foreach ($rows as $row) {
            $after = json_decode($row->after_snapshot, true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('0', $after['u']); $this->assertSame('0', $after['d']); $this->assertSame('1', $after['reset_count']);
        }
        $this->assertNull($oneTime->fresh()->expired_at);
        $this->assertSame(2, DB::table('tx_traffic_reset_logs')->count());
        $this->assertContains('CAPTURE_SOURCE_GAP', $this->capture()->verify($oneTime->id)['issues']);
        $this->assertTrue($this->capture()->verify($resetUser->id)['page_ok']);
        $this->assertSame($projection, $this->projection());
    }

    public function test_capture_storage_failure_rolls_back_native_fulfillment_reset_discount_and_map_atomically(): void
    {
        $user = $this->user(); $this->backfill($user);
        $prior = $this->order($user, ['status' => Order::STATUS_COMPLETED]);
        $order = $this->order($user, ['type' => Order::TYPE_NEW_PURCHASE,
            'surplus_credit' => 75, 'surplus_order_ids' => [$prior->id]]);
        $before = $this->state(); $armed = true;
        DB::listen(function ($query) use (&$armed): void {
            if ($armed && str_starts_with(strtolower($query->sql), 'update')
                && str_contains($query->sql, 'tx_legacy_subscription_map')) {
                $armed = false; throw new \RuntimeException('order-fixture-provider-secret');
            }
        });
        $this->assertFailure('CAPTURE_STORAGE_OR_EVIDENCE_FAILED', fn () => $this->fulfill($order));
        $this->assertSame($before, $this->state());
        $this->assertSame(Order::STATUS_PROCESSING, (int) $order->fresh()->status);
        $this->fulfill($order); $this->assertSame(Order::STATUS_COMPLETED, (int) $order->fresh()->status);
        $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count());
    }

    public function test_disabled_production_fulfillment_keeps_original_rules_and_performs_zero_capture_sql(): void
    {
        $user = $this->user(); $order = $this->order($user);
        config(['legacy_capture_shadow.enabled' => false, 'core_shadow.isolated_copy' => false]);
        app()->instance('env', 'production'); DB::enableQueryLog(); DB::flushQueryLog();
        $this->fulfill($order); $this->assertCaptureSqlAbsent();
        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->fresh()->status);
        $this->assertSame(10, (int) $user->fresh()->u); $this->assertSame(20, (int) $user->fresh()->d);
    }

    public function test_strict_activation_context_drift_and_missing_plan_preserve_original_retry_state(): void
    {
        $user = $this->user(); $order = $this->order($user); $before = $this->state();
        config(['legacy_capture_shadow.enabled' => '1']);
        $this->assertFailure('CAPTURE_ISOLATED_COPY_REQUIRED', fn () => $this->fulfill($order));
        $this->assertSame($before, $this->state());
        config(['legacy_capture_shadow.enabled' => true]); $this->fulfill($order);
        $next = $this->order($user); $before = $this->state();
        config(['legacy_capture_shadow.currency' => 'CNY']);
        $this->assertFailure('CAPTURE_CONTEXT_DRIFT', fn () => $this->fulfill($next));
        $this->assertSame($before, $this->state());
        config(['legacy_capture_shadow.currency' => 'USD']);
        $missing = $this->order($user, ['plan_id' => 2147483647]);
        try { $this->fulfill($missing); $this->fail('Missing plan was silently fulfilled.'); }
        catch (\RuntimeException $exception) { $this->assertSame('订阅不存在', $exception->getMessage()); }
        $this->assertSame(Order::STATUS_PROCESSING, (int) $missing->fresh()->status);
        $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count());
    }

    public function test_actual_uncaptured_order_creation_wallet_predebit_blocks_fulfillment_without_undoing_predebit(): void
    {
        $user = $this->user(['balance' => 2000]); $admin = $this->user(['is_admin' => true]);
        $this->adminUpdate($admin, $user, ['speed_limit' => 1]);
        Sanctum::actingAs($user);
        $response = $this->postJson('/txapi/orders', ['plan_id' => $this->plan->id, 'period' => 'monthly'])->assertCreated();
        $order = Order::query()->where('trade_no', $response->json('data.trade_no'))->firstOrFail();
        $this->assertSame(1000, (int) $user->fresh()->balance); $this->assertSame(1000, (int) $order->balance_amount);
        $this->postJson('/txapi/orders/'.$order->trade_no.'/checkout')->assertOk();
        $before = $this->state(); $this->assertFailure('CAPTURE_SOURCE_GAP', fn () => $this->fulfill($order));
        $this->assertSame($before, $this->state());
        $this->assertSame(1000, (int) $user->fresh()->balance);
        $this->assertSame(Order::STATUS_PROCESSING, (int) $order->fresh()->status);
        $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count());
    }

    public function test_mixed_existing_writer_hashes_remain_unchanged_and_bounded_recovery_is_select_only(): void
    {
        $user = $this->user(); $this->backfill($user); $admin = $this->user(['is_admin' => true]);
        $this->adminUpdate($admin, $user, ['speed_limit' => 1]);
        $recharge = app(WalletRechargeService::class)->create($user->id, 1000, $this->payment->id,
            (string) \Illuminate\Support\Str::uuid());
        $this->assertTrue(app(WalletRechargeService::class)->settleVerified($recharge->trade_no,
            $this->payment->id, 'fixture-native-recharge-receipt', 1000));
        $oldRows = DB::table('tx_legacy_capture_shadow_event')->orderBy('id')->get()->toJson();
        $oldContext = DB::table('tx_legacy_capture_shadow_stream')->value('context_hash');
        $order = $this->order($user); $this->fulfill($order);
        $this->assertSame($oldRows, DB::table('tx_legacy_capture_shadow_event')->where('sequence', '<=', 2)->orderBy('id')->get()->toJson());
        $this->assertSame($oldContext, DB::table('tx_legacy_capture_shadow_stream')->value('context_hash'));
        $before = $this->state(); DB::enableQueryLog(); DB::flushQueryLog();
        $pages = [$this->capture()->verify($user->id, 0, 1), $this->capture()->verify($user->id, 1, 1),
            $this->capture()->verify($user->id, 2, 1), $this->capture()->verify($user->id, 3, 1)];
        foreach ($pages as $page) {
            $this->assertTrue($page['read_only']); $this->assertTrue($page['page_ok']);
            $this->assertFalse($page['projection_applied']); $this->assertFalse($page['migration_gate_open']);
            $this->assertFalse($page['global_coverage_complete']);
        }
        $this->assertSame('admin_user', $pages[0]['events'][0]['actor_domain']);
        $this->assertSame('verified_payment_method', $pages[1]['events'][0]['actor_domain']);
        $this->assertArrayNotHasKey('capture_boundary', $pages[0]['events'][0]);
        $this->assertArrayNotHasKey('capture_boundary', $pages[1]['events'][0]);
        $this->assertSame('transaction-only', $pages[2]['events'][0]['capture_boundary']);
        $this->assertTrue($pages[0]['has_more']); $this->assertTrue($pages[1]['has_more']);
        $this->assertFalse($pages[2]['has_more']); $this->assertSame([], $pages[3]['events']);
        foreach (DB::getQueryLog() as $query) { $this->assertMatchesRegularExpression('/^select/i', $query['query']); }
        $this->assertSame($before, $this->state());
    }

    public function test_all_three_writer_tickets_reject_cross_use_and_invalid_fulfillment_reference(): void
    {
        $user = $this->user(); $order = $this->order($user); DB::beginTransaction();
        try {
            Order::query()->whereKey($order->id)->lockForUpdate()->first();
            User::query()->whereKey($user->id)->lockForUpdate()->first();
            $admin = $this->capture()->begin($user->id);
            $recharge = $this->capture()->beginVerifiedRecharge($user->id, $this->payment->id);
            $fulfillment = $this->capture()->beginOrderFulfillment($user->id, $order->id);
            foreach ([$admin, $recharge] as $ticket) {
                $this->assertFailure('CAPTURE_WRITER_TICKET_INVALID', fn () => $this->capture()->appendOrderFulfillment($ticket));
            }
            $this->assertFailure('CAPTURE_WRITER_TICKET_INVALID', fn () => $this->capture()->append($fulfillment, $user->id));
            $this->assertFailure('CAPTURE_WRITER_TICKET_INVALID', fn () => $this->capture()->appendVerifiedRecharge($fulfillment));
            $this->assertFailure('CAPTURE_SOURCE_INVALID', fn () => $this->capture()->beginOrderFulfillment($user->id, 0));
            $fulfillment['source_version'] = 'unknown/v1';
            $this->assertFailure('CAPTURE_WRITER_TICKET_INVALID', fn () => $this->capture()->appendOrderFulfillment($fulfillment));
            $this->assertSame(0, DB::table('tx_legacy_capture_shadow_event')->count());
        } finally { DB::rollBack(); }
    }

    public function test_unknown_fulfillment_policy_or_version_is_rejected_even_after_rehashing_tail(): void
    {
        $user = $this->user(); $this->fulfill($this->order($user));
        $row = (array) DB::table('tx_legacy_capture_shadow_event')->first();
        foreach ([['source_kind' => 'order.unapproved'], ['source_version' => 'unapproved/v1']] as $change) {
            $payload = array_replace($row, $change); unset($payload['id'], $payload['content_hash']);
            foreach (['stream_id', 'legacy_user_id', 'sequence', 'actor_id', 'captured_at'] as $key) { $payload[$key] = (string) $payload[$key]; }
            foreach (['before_snapshot', 'after_snapshot'] as $key) { $payload[$key] = json_decode($payload[$key], true); }
            $hash = \App\Services\Subscription\SubscriptionTermsTimeline::hash($payload);
            DB::table('tx_legacy_capture_shadow_event')->where('id', $row['id'])->update(array_replace($row, $change, ['content_hash' => $hash]));
            DB::table('tx_legacy_capture_shadow_stream')->update(['last_event_hash' => $hash]);
            $this->assertFailure('CAPTURE_EVENT_TAMPERED', fn () => $this->capture()->verify($user->id, 1));
        }
    }

    public function test_real_redis_worker_keeps_three_retries_and_fixed_logs_with_exception_arguments_enabled(): void
    {
        $user = $this->user(); $order = $this->order($user); $before = $this->state();
        config(['legacy_capture_shadow.enabled' => 'invalid']);
        $queue = app('queue')->connection('redis'); $name = 'legacy_order_capture_fixture'; $queue->clear($name);
        $fake = Bus::getFacadeRoot(); Bus::swap($fake->dispatcher);
        $old = ini_set('zend.exception_ignore_args', '0'); $this->assertSame('0', ini_get('zend.exception_ignore_args'));
        $failed = [];
        Event::listen(JobFailed::class, function ($event) use (&$failed): void {
            $failed[] = $event;
            (new \Illuminate\Queue\Failed\DatabaseFailedJobProvider(app('db'), DB::getDefaultConnection(),
                config('queue.failed.table')))->log($event->connectionName,
                    $event->job->getQueue(), $event->job->getRawBody(), $event->exception);
        });
        try {
            $job = new OrderHandleJob($order->trade_no);
            $this->assertSame(3, $job->tries); $this->assertSame(30, $job->timeout);
            $this->assertSame([10, 30, 60], $job->backoff());
            $queue->push($job, '', $name);
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                app('queue.worker')->runNextJob('redis', $name, new WorkerOptions(sleep: 0, maxTries: 3, force: true));
                $this->assertSame($before, $this->state());
                $this->assertCount($attempt === 3 ? 1 : 0, $failed);
                Carbon::setTestNow(Carbon::now()->addSeconds(61));
            }
            $this->assertSame(0, $queue->size($name));
            $this->assertInstanceOf(LegacyCaptureShadowException::class, $failed[0]->exception);
            $this->assertNull($failed[0]->exception->getPrevious());
            $archive = DB::table(config('queue.failed.table'))->first();
            $this->assertNotNull($archive);
            $this->assertStringContainsString($order->trade_no, $archive->payload);
            foreach ([$user->email, $user->password, $user->token, $user->uuid,
                $this->payment->uuid, 'order-fixture-provider-secret'] as $secret) {
                $this->assertStringNotContainsString($secret, $archive->exception.$archive->payload);
            }
            $records = $this->log->getRecords(); $this->assertCount(4, $records);
            $this->assertSame(['LEGACY_ORDER_FULFILLMENT_CAPTURE_FAILED', 'LEGACY_ORDER_FULFILLMENT_CAPTURE_FAILED',
                'LEGACY_ORDER_FULFILLMENT_CAPTURE_FAILED', 'LEGACY_ORDER_FULFILLMENT_CAPTURE_RETRIES_EXHAUSTED'],
                array_map(fn ($record) => $record->message, $records));
            foreach ($records as $record) {
                $this->assertSame([], $record->context);
                $this->assertStringNotContainsString('stacktrace', $record->formatted);
                $this->assertNoSecrets($user, $order, $record->formatted);
            }
        } finally { $queue->clear($name); Bus::swap($fake); Carbon::setTestNow(); ini_set('zend.exception_ignore_args', $old); }
    }

    public function test_independent_mysql_duplicate_processing_order_waits_and_only_fulfills_once(): void
    {
        $this->requireFork(); $user = $this->user(); $order = $this->order($user);
        $this->concurrentFulfillments($order, $order);
        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->fresh()->status);
        $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count());
        $this->assertTrue($this->capture()->verify($user->id)['page_ok']);
        $this->assertSame(0, (int) $user->fresh()->reset_count);
    }

    public function test_independent_mysql_distinct_order_locks_serialize_current_user_and_contiguous_stream(): void
    {
        $this->requireFork(); $user = $this->user(); $before = (int) $user->expired_at;
        $one = $this->order($user); $two = $this->order($user); $this->concurrentFulfillments($one, $two);
        $this->assertSame(2, Order::query()->where('status', Order::STATUS_COMPLETED)->count());
        $this->assertSame(Carbon::createFromTimestamp($before)->addMonths(2)->timestamp, (int) $user->fresh()->expired_at);
        $report = $this->capture()->verify($user->id); $this->assertTrue($report['page_ok']);
        $this->assertSame(['1', '2'], array_column($report['events'], 'sequence'));
    }

    public function test_independent_mysql_stale_outer_snapshot_cannot_drive_capture_source_or_tail(): void
    {
        $this->requireFork(); $user = $this->user(); $one = $this->order($user); $two = $this->order($user);
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        DB::purge(); $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($parent); stream_set_timeout($child, 10);
            DB::beginTransaction(); DB::table('tx_user')->where('id', $user->id)->first();
            DB::table('tx_legacy_capture_shadow_stream')->get(); fwrite($child, 'R'); fread($child, 1);
            try { $this->fulfill($two); DB::commit(); fwrite($child, 'OK'); }
            catch (\Throwable) { DB::rollBack(); fwrite($child, 'FAIL'); }
            fclose($child); DB::disconnect(); exit(0);
        }
        $this->assertGreaterThan(0, $pid); fclose($child); stream_set_timeout($parent, 10);
        try {
            $this->assertSame('R', fread($parent, 1)); $this->fulfill($one); fwrite($parent, 'S');
            $this->assertSame('OK', stream_get_contents($parent, 32));
        } finally { fclose($parent); pcntl_waitpid($pid, $status); }
        $this->assertSame(0, pcntl_wexitstatus($status));
        $report = $this->capture()->verify($user->id); $this->assertTrue($report['page_ok']);
        $this->assertSame(['1', '2'], array_column($report['events'], 'sequence'));
    }

    public function test_independent_mysql_recharge_and_fulfillment_keep_native_locks_and_one_user_stream(): void
    {
        $this->requireFork(); $user = $this->user(); $order = $this->order($user);
        $recharge = app(WalletRechargeService::class)->create($user->id, 1000, $this->payment->id,
            (string) \Illuminate\Support\Str::uuid());
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        DB::purge(); $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($parent); stream_set_timeout($child, 10); fread($child, 1); fwrite($child, 'R');
            try { $this->fulfill($order); fwrite($child, 'OK'); }
            catch (\Throwable) { fwrite($child, 'FAIL'); }
            fclose($child); DB::disconnect(); exit(0);
        }
        $this->assertGreaterThan(0, $pid); fclose($child); stream_set_timeout($parent, 10);
        try {
            DB::beginTransaction();
            $this->assertTrue(app(WalletRechargeService::class)->settleVerified($recharge->trade_no,
                $this->payment->id, 'concurrent-native-recharge', 1000));
            fwrite($parent, 'S'); $this->assertSame('R', fread($parent, 1)); $this->assertWaiting($parent);
            DB::commit(); $this->assertSame('OK', stream_get_contents($parent, 32));
        } finally {
            if (DB::transactionLevel()) { DB::rollBack(); }
            fclose($parent); pcntl_waitpid($pid, $status);
        }
        $this->assertSame(0, pcntl_wexitstatus($status));
        $this->assertSame(1000, (int) $user->fresh()->balance);
        $report = $this->capture()->verify($user->id); $this->assertTrue($report['page_ok']);
        $this->assertSame(['verified_payment_method', 'system_order_fulfillment'], array_column($report['events'], 'actor_domain'));
    }

    public function test_mysql_process_death_before_and_after_commit_keeps_fulfillment_atomic_and_retry_exactly_once(): void
    {
        $this->requireFork(); $user = $this->user(); $this->backfill($user); $order = $this->order($user);
        $before = $this->state(); $this->killFulfillment($order, false); $this->assertSame($before, $this->state());
        $this->killFulfillment($order, true);
        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->fresh()->status);
        $this->assertSame('source_changed', DB::table('tx_legacy_subscription_map')->where('legacy_user_id', $user->id)->value('state'));
        $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count());
        $this->assertTrue($this->capture()->verify($user->id)['page_ok']);
        $committed = $this->state(); $this->fulfill($order); $this->assertSame($committed, $this->state());
    }

    private function concurrentFulfillments(Order $one, Order $two): void
    {
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        DB::purge(); $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($parent); stream_set_timeout($child, 10); fread($child, 1); fwrite($child, 'R');
            try { $this->fulfill($two); fwrite($child, 'OK'); }
            catch (\Throwable) { fwrite($child, 'FAIL'); }
            fclose($child); DB::disconnect(); exit(0);
        }
        $this->assertGreaterThan(0, $pid); fclose($child); stream_set_timeout($parent, 10);
        try {
            DB::beginTransaction(); $this->fulfill($one);
            fwrite($parent, 'S'); $this->assertSame('R', fread($parent, 1)); $this->assertWaiting($parent);
            DB::commit(); $this->assertSame('OK', stream_get_contents($parent, 32));
        } finally {
            if (DB::transactionLevel()) { DB::rollBack(); }
            fclose($parent); pcntl_waitpid($pid, $status);
        }
        $this->assertSame(0, pcntl_wexitstatus($status));
    }

    private function killFulfillment(Order $order, bool $afterCommit): void
    {
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        DB::purge(); $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($parent);
            $barrier = function () use ($child): void { fwrite($child, 'B'); fflush($child); fread($child, 1); };
            if ($afterCommit) {
                Event::listen(TransactionCommitted::class, function ($event) use ($barrier): void {
                    if ($event->connection->transactionLevel() === 0) { $barrier(); }
                });
            } else {
                DB::listen(function ($query) use ($barrier): void {
                    if (str_starts_with(strtolower($query->sql), 'update')
                        && str_contains($query->sql, 'tx_legacy_subscription_map')) { $barrier(); }
                });
            }
            $this->fulfill($order); exit(3);
        }
        $this->assertGreaterThan(0, $pid); fclose($child); stream_set_timeout($parent, 10);
        try {
            $this->assertSame('B', fread($parent, 1)); $this->assertTrue(posix_kill($pid, SIGKILL));
            pcntl_waitpid($pid, $status); $this->assertTrue(pcntl_wifsignaled($status));
            $this->assertSame(SIGKILL, pcntl_wtermsig($status));
        } finally { fclose($parent); }
    }

    private function assertWaiting($pipe): void
    {
        $read = [$pipe]; $write = []; $except = [];
        $this->assertSame(0, stream_select($read, $write, $except, 0, 250000), 'Writer escaped the uncommitted native row fence.');
    }
    private function assertCaptureSqlAbsent(): void
    {
        $this->assertSame([], array_values(array_filter(DB::getQueryLog(), fn ($q) =>
            preg_match('/tx_legacy_capture_shadow|tx_legacy_subscription_map/', $q['query']))));
    }
    private function assertNoSecrets(User $user, Order $order, string $text, array $params = []): void
    {
        foreach ([$user->email, $user->password, $user->token, $user->uuid, $order->trade_no,
            $this->payment->uuid, $params['trade_no'] ?? 'fixture-native-provider-receipt',
            $params['sign'] ?? 'fixture-signature-not-in-evidence', 'order-fixture-provider-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }
    }
    private function plan(array $overrides = []): Plan
    {
        return Plan::create(array_replace(['name' => 'order capture', 'group_id' => 1, 'transfer_enable' => 2,
            'show' => true, 'sell' => true, 'renew' => true, 'speed_limit' => 5, 'device_limit' => 3,
            'prices' => ['monthly' => 10, 'onetime' => 10, 'reset_traffic' => 1],
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY], $overrides));
    }
    private function user(array $overrides = []): User
    {
        $id = ++$this->counter;
        $anchor = Carbon::now('UTC')->startOfMonth()->addMonths(2)->day(15)->setTime(12, 0)->timestamp;
        return User::create(array_replace(['email' => 'order-capture-'.$id.'@example.test',
            'password' => 'order-fixture-password', 'uuid' => sprintf('00000000-0000-4000-8000-%012d', $id),
            'token' => sprintf('%032d', $id), 'plan_id' => $this->plan->id, 'group_id' => 1,
            'transfer_enable' => 1000, 'u' => 10, 'd' => 20, 'expired_at' => $anchor], $overrides));
    }
    private function order(User $user, array $overrides = []): Order
    {
        return Order::create(array_replace(['user_id' => $user->id, 'plan_id' => $this->plan->id,
            'trade_no' => 'order-capture-trade-'.(++$this->counter), 'period' => Plan::PERIOD_MONTHLY,
            'type' => Order::TYPE_RENEWAL, 'status' => Order::STATUS_PROCESSING,
            'total_amount' => 1000, 'balance_amount' => 0], $overrides));
    }
    private function signature(Order $order): array
    {
        $params = ['pid' => 'order-capture-merchant', 'out_trade_no' => $order->trade_no,
            'trade_no' => 'fixture-native-provider-receipt', 'money' => '11.30', 'trade_status' => 'TRADE_SUCCESS'];
        ksort($params); $params['sign'] = md5(stripslashes(urldecode(http_build_query($params))).'order-fixture-provider-secret');
        $params['sign_type'] = 'MD5'; return $params;
    }
    private function notifyOrder(array $params): \Illuminate\Testing\TestResponse
    {
        return $this->post('/txapi/payment/webhook/EPay/'.$this->payment->uuid, $params);
    }
    private function fulfill(Order $order): void { (new OrderHandleJob($order->trade_no))->handle(); }
    private function adminUpdate(User $admin, User $user, array $fields): void
    {
        admin_setting(['secure_path' => 'order_capture_admin']); Sanctum::actingAs($admin);
        $this->postJson('/txapi/admin/order_capture_admin/users/'.$user->id.'/update', $fields)->assertOk();
    }
    private function backfill(User $user): void
    {
        app(SubscriptionShadowBackfill::class)->run('order-capture-map-'.$user->id, 100,
            app(LegacySubscriptionSnapshot::class)->context('UTC', 'USD'));
    }
    private function source(User $user): array
    {
        $row = (array) DB::table('tx_user')->where('id', $user->id)->select(LegacyUserMutationCapture::FIELDS)->first();
        foreach ($row as $field => $value) { $row[$field] = $field === 'banned' ? (bool) $value : ($value === null ? null : (string) $value); }
        ksort($row, SORT_STRING);
        return $row;
    }
    private function capture(): LegacyUserMutationCapture { return app(LegacyUserMutationCapture::class); }
    private function assertFailure(string $code, callable $callback): void
    {
        try { $callback(); $this->fail('Accepted invalid writer evidence.'); }
        catch (LegacyCaptureShadowException $e) { $this->assertSame($code, $e->getMessage()); $this->assertNull($e->getPrevious()); }
    }
    private function projection(): array
    {
        $result = [];
        foreach (['tx_subscription', 'tx_subscription_terms', 'tx_subscription_terms_transition', 'tx_usage_period',
            'tx_usage_adjustment', 'tx_access_subject', 'tx_commerce_shadow_money_journal', 'tx_commerce_shadow_money_posting'] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        return $result;
    }
    private function state(): array
    {
        $result = $this->projection();
        foreach (['tx_user', 'tx_order', 'tx_traffic_reset_logs', 'tx_wallet_recharge', 'tx_legacy_capture_shadow_stream',
            'tx_legacy_capture_shadow_event', 'tx_legacy_subscription_map'] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        return $result;
    }
    private function mysql(): bool { return getenv('CORE_CAPTURE_TEST_MYSQL') === '1'; }
    private function requireFork(): void
    {
        if (!$this->mysql() || !function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('Requires independent MariaDB connections and process control.');
        }
    }
    private function clearMysql(): void
    {
        if (DB::connection()->getDatabaseName() !== 'txboard_concurrency_test') {
            throw new \RuntimeException('Order capture fixture requires its dedicated database.');
        }
        Schema::disableForeignKeyConstraints();
        try { foreach (Schema::getTableListing() as $table) { Schema::drop($table); } }
        finally { Schema::enableForeignKeyConstraints(); }
    }
}
