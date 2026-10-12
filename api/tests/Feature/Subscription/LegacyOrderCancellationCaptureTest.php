<?php

namespace Tests\Feature\Subscription;

use App\Domains\Billing\WalletRechargeService;
use App\Jobs\OrderHandleJob;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderService;
use App\Services\Plugin\HookManager;
use App\Services\Subscription\LegacyCaptureShadowException;
use App\Services\Subscription\LegacySubscriptionSnapshot;
use App\Services\Subscription\LegacyUserMutationCapture;
use App\Services\Subscription\SubscriptionShadowBackfill;
use App\Services\Subscription\SubscriptionTermsTimeline;
use Carbon\Carbon;
use Illuminate\Database\Events\TransactionCommitted;
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

class LegacyOrderCancellationCaptureTest extends TestCase
{
    private int $counter = 0;
    private Plan $plan;
    private TestHandler $log;

    protected function setUp(): void
    {
        parent::setUp();
        $connection = $this->mysql() ? 'order_cancellation_capture_mysql_testing' : 'order_cancellation_capture_sqlite_testing';
        config(['database.default' => $connection, 'database.connections.'.$connection => $this->mysql()
            ? config('database.connections.mysql') : array_replace(config('database.connections.sqlite'),
                ['database' => ':memory:', 'foreign_key_constraints' => true])]);
        DB::purge($connection);
        if ($this->mysql()) { $this->clearMysql(); }
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        config(['core_shadow.isolated_copy' => true, 'legacy_capture_shadow.enabled' => true,
            'legacy_capture_shadow.run_key' => 'order-cancellation-fixture',
            'legacy_capture_shadow.billing_timezone' => 'UTC', 'legacy_capture_shadow.currency' => 'USD',
            'cache.stores.redis' => ['driver' => 'array']]);
        Bus::fake(); HookManager::reset();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->plan = Plan::create(['name' => 'capture cancellation', 'group_id' => 1, 'transfer_enable' => 2,
            'show' => true, 'sell' => true, 'renew' => true, 'speed_limit' => 5, 'device_limit' => 3,
            'prices' => ['monthly' => 10], 'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY]);
        $this->log = new TestHandler();
        $this->log->setFormatter(new LineFormatter(null, null, true, true, true));
        Log::swap(new \Illuminate\Log\Logger(new Logger('capture-order-cancellation', [$this->log])));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(); HookManager::reset();
        if ($this->mysql()) { $this->clearMysql(); }
        DB::purge($this->mysql() ? 'order_cancellation_capture_mysql_testing' : 'order_cancellation_capture_sqlite_testing');
        parent::tearDown();
    }

    public function test_actual_http_partial_and_full_wallet_refunds_preserve_coupon_and_native_amounts_once(): void
    {
        $coupon = $this->coupon();
        foreach ([100, 2000] as $balance) {
            $user = $this->user(['balance' => $balance, 'commission_balance' => 123]);
            Sanctum::actingAs($user);
            $response = $this->postJson('/txapi/orders', ['plan_id' => $this->plan->id, 'period' => 'monthly', 'coupon_code' => $coupon->code])->assertCreated();
            $order = Order::where('trade_no', $response->json('data.trade_no'))->firstOrFail();
            $this->assertSame(min($balance, 800), (int) $order->balance_amount);
            // Existing fees, principal and discount are not recomputed or refunded.
            $order->handling_amount = 77; $order->save();
            $amounts = [$order->total_amount, $order->balance_amount, $order->handling_amount, $order->discount_amount];
            $before = $this->source($user); $prior = DB::table('tx_legacy_capture_shadow_event')->where('legacy_user_id', $user->id)->get()->toJson();
            DB::enableQueryLog(); DB::flushQueryLog();
            $this->postJson($this->url($order))->assertOk()->assertJsonPath('data.ok', true);
            $this->assertSame($balance, (int) $user->fresh()->balance);
            $order = $order->fresh(); $this->assertSame(Order::STATUS_CANCELLED, (int) $order->status);
            $this->assertSame($amounts, [$order->total_amount, $order->balance_amount, $order->handling_amount, $order->discount_amount]);
            $this->assertSame($prior, DB::table('tx_legacy_capture_shadow_event')->where('legacy_user_id', $user->id)->where('sequence', 1)->get()->toJson());
            $row = DB::table('tx_legacy_capture_shadow_event')->where('legacy_user_id', $user->id)->where('sequence', 2)->first();
            $this->assertSame($before, json_decode($row->before_snapshot, true));
            $this->assertSame($this->source($user), json_decode($row->after_snapshot, true));
            $this->assertSame('123', json_decode($row->after_snapshot, true)['commission_balance']);
            $summary = $this->capture()->verify($user->id)['events'][1];
            $this->assertSame(['order.cancel.refund', 'order-cancel-refund-capture/v1', 'system_order_cancellation', (string) $order->id, 'transaction-only'],
                [$summary['source_kind'], $summary['source_version'], $summary['actor_domain'], $summary['actor_id'], $summary['capture_boundary']]);
            $queries = array_values(array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'tx_legacy_capture_shadow')));
            $evidence = json_encode([$row, $summary, $queries, $this->log->getRecords()]);
            $this->assertNoSecrets($user, $evidence); $this->assertStringNotContainsString($order->trade_no, $evidence);
            $this->postJson($this->url($order))->assertStatus(409)->assertJsonPath('error.code', 'ORDER_CONFLICT');
            $this->assertSame($balance, (int) $user->fresh()->balance);
        }
        $this->assertSame(5, (int) $coupon->fresh()->limit_use);
        $this->assertSame(4, DB::table('tx_legacy_capture_shadow_event')->count());
    }

    public function test_real_admin_user_and_expired_system_job_share_work_item_policy_without_borrowing_auth(): void
    {
        $admin = $this->user(['is_admin' => true]); admin_setting(['secure_path' => 'cancellation_capture_admin']);
        $user = $this->user(); $order = $this->order($user, ['balance_amount' => 200]);
        Sanctum::actingAs($admin);
        $this->postJson('/txapi/admin/cancellation_capture_admin/orders/'.$order->trade_no.'/cancel')->assertOk();
        $systemUser = $this->user(); $system = $this->order($systemUser, ['balance_amount' => 300, 'created_at' => time() - 7201]);
        auth()->forgetGuards(); (new OrderHandleJob($system->trade_no))->handle();
        $this->assertSame(Order::STATUS_CANCELLED, (int) $system->fresh()->status);
        foreach ([[$user, $order, 200], [$systemUser, $system, 300]] as [$owner, $workItem, $amount]) {
            $summary = $this->capture()->verify($owner->id)['events'][0];
            $this->assertSame('system_order_cancellation', $summary['actor_domain']);
            $this->assertSame((string) $workItem->id, $summary['actor_id']);
            $this->assertSame($amount, (int) $owner->fresh()->balance);
        }
        $young = $this->order($this->user(), ['created_at' => time(), 'balance_amount' => 100]);
        (new OrderHandleJob($young->trade_no))->handle();
        $this->assertSame(Order::STATUS_PENDING, (int) $young->fresh()->status);
        $this->assertSame(2, DB::table('tx_legacy_capture_shadow_event')->count());
    }

    public function test_actual_user_admin_authorization_and_owner_lookup_remain_before_capture(): void
    {
        $owner = $this->user(); $order = $this->order($owner, ['balance_amount' => 200]);
        $other = $this->user(); Sanctum::actingAs($other);
        $this->postJson($this->url($order))->assertNotFound();
        $this->postJson('/txapi/orders/not-found/cancel')->assertNotFound();
        admin_setting(['secure_path' => 'cancellation_capture_admin']);
        $this->postJson('/txapi/admin/cancellation_capture_admin/orders/'.$order->trade_no.'/cancel')->assertForbidden();
        Sanctum::actingAs($this->user(['is_admin' => true]));
        $this->postJson('/txapi/admin/wrong_path/orders/'.$order->trade_no.'/cancel')->assertNotFound();
        Sanctum::actingAs($this->user(['banned' => true]));
        $this->postJson($this->url($order))->assertForbidden();
        auth()->forgetGuards(); $this->postJson($this->url($order))->assertUnauthorized();
        $this->assertSame(Order::STATUS_PENDING, (int) $order->fresh()->status);
        $this->assertSame(0, DB::table('tx_legacy_capture_shadow_event')->count());
    }

    public function test_zero_and_null_refunds_release_coupon_without_user_lock_guard_or_fake_event(): void
    {
        $coupon = $this->coupon();
        foreach ([0, null] as $amount) {
            $user = $this->user(['balance' => 30]); $this->backfill($user);
            $order = $this->order($user, ['balance_amount' => $amount, 'coupon_id' => $coupon->id]);
            $source = $this->source($user); $map = DB::table('tx_legacy_subscription_map')->get()->toJson();
            config(['legacy_capture_shadow.enabled' => 'invalid']); DB::enableQueryLog(); DB::flushQueryLog();
            $this->assertTrue((new OrderService($order))->cancel()); $this->assertCaptureSqlAbsent();
            $this->assertSame([], array_values(array_filter(DB::getQueryLog(), fn ($q) => preg_match('/^select.*tx_user/i', $q['query']))));
            $this->assertSame($source, $this->source($user));
            $this->assertSame($map, DB::table('tx_legacy_subscription_map')->get()->toJson());
        }
        $this->assertSame(7, (int) $coupon->fresh()->limit_use);
        $this->assertSame(0, DB::table('tx_legacy_capture_shadow_stream')->count());
    }

    public function test_disabled_production_refund_preserves_native_sql_and_negative_legacy_algorithm(): void
    {
        config(['legacy_capture_shadow.enabled' => false, 'core_shadow.isolated_copy' => false]);
        app()->instance('env', 'production');
        foreach ([200, -100] as $amount) {
            $user = $this->user(['balance' => 500]); $order = $this->order($user, ['balance_amount' => $amount]);
            DB::enableQueryLog(); DB::flushQueryLog();
            $this->assertTrue((new OrderService($order))->cancel()); $this->assertCaptureSqlAbsent();
            $this->assertCount(1, array_filter(DB::getQueryLog(), fn ($q) => preg_match('/^select.*tx_user/i', $q['query'])));
            $this->assertSame(500 + $amount, (int) $user->fresh()->balance);
        }
        $this->assertNull($this->capture()->beginOrderCancellationRefund(0, 0, 'invalid'));
        $this->capture()->appendOrderCancellationRefund(null);
    }

    public function test_nonpending_duplicates_skip_invalid_guard_missing_capture_schema_and_job_recovery(): void
    {
        $user = $this->user(); $order = $this->order($user, ['balance_amount' => 200, 'created_at' => time() - 7201]);
        Sanctum::actingAs($user); $this->postJson($this->url($order))->assertOk();
        config(['legacy_capture_shadow.enabled' => 'invalid', 'legacy_capture_shadow.currency' => 'CNY']);
        Schema::drop('tx_legacy_capture_shadow_event'); Schema::drop('tx_legacy_capture_shadow_stream');
        DB::enableQueryLog(); DB::flushQueryLog();
        $this->assertFalse((new OrderService($order))->cancel());
        $this->postJson($this->url($order))->assertStatus(409)->assertJsonPath('error.code', 'ORDER_CONFLICT');
        (new OrderHandleJob($order->trade_no))->handle(); $this->assertCaptureSqlAbsent();
        $this->assertSame(200, (int) $user->fresh()->balance); $this->assertSame([], $this->log->getRecords());
    }

    public function test_strict_guard_and_invalid_source_fail_closed_and_leave_native_transaction_unchanged(): void
    {
        $user = $this->user(); $order = $this->order($user, ['balance_amount' => 200]);
        foreach ([['legacy_capture_shadow.enabled' => '1'], ['core_shadow.isolated_copy' => false],
            ['legacy_capture_shadow.currency' => 'JPY']] as $invalid) {
            $before = $this->state(); config($invalid);
            $this->assertFalse((new OrderService($order))->cancel()); $this->assertSame($before, $this->state());
            config(['legacy_capture_shadow.enabled' => true, 'core_shadow.isolated_copy' => true, 'legacy_capture_shadow.currency' => 'USD']);
        }
        app()->instance('env', 'production'); $before = $this->state();
        $this->assertFalse((new OrderService($order))->cancel()); $this->assertSame($before, $this->state());
        app()->instance('env', 'testing');
        DB::table('tx_user')->where('id', $user->id)->update(['commission_balance' => -1]); $before = $this->state();
        $this->assertFalse((new OrderService($order))->cancel()); $this->assertSame($before, $this->state());
        foreach ($this->log->getRecords() as $record) { $this->assertSafeFailureRecord($record); }
    }

    public function test_refund_ticket_requires_transaction_positive_canonical_native_int_and_valid_work_item(): void
    {
        $user = $this->user();
        $this->assertFailure('CAPTURE_TRANSACTION_REQUIRED', fn () => $this->capture()->beginOrderCancellationRefund($user->id, 1, 1));
        DB::beginTransaction();
        try {
            foreach ([0, -1, 1.0, true, null, '01', '+1', '1e2', '1.0', '2147483648'] as $amount) {
                $this->assertFailure('CAPTURE_SOURCE_INVALID', fn () => $this->capture()->beginOrderCancellationRefund($user->id, 1, $amount));
            }
            foreach ([1, '2147483647'] as $amount) {
                $ticket = $this->capture()->beginOrderCancellationRefund($user->id, 1, $amount);
                $this->assertSame('order.cancel.refund', $ticket['source_kind']);
            }
            $this->assertFailure('CAPTURE_SOURCE_INVALID', fn () => $this->capture()->beginOrderCancellationRefund($user->id, 0, 1));
        } finally { DB::rollBack(); }
        $negative = $this->order($user, ['balance_amount' => -1]); $before = $this->state();
        $this->assertFalse((new OrderService($negative))->cancel()); $this->assertSame($before, $this->state());
    }

    public function test_actual_http_storage_failure_after_coupon_rolls_back_all_with_args_enabled_and_fixed_empty_log(): void
    {
        $user = $this->user(['balance' => 2000]); $coupon = $this->coupon(); $this->backfill($user);
        $order = $this->create($user, $coupon); $bearer = $user->createToken('cancellation-fixture')->plainTextToken;
        $before = $this->state(); $armed = true; $afterCalls = 0;
        HookManager::register('order.cancel.after', function () use (&$afterCalls): void { $afterCalls++; });
        DB::listen(function ($query) use (&$armed): void {
            if ($armed && str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'tx_legacy_subscription_map')) {
                $armed = false; throw new \RuntimeException('cancellation-fixture-storage-secret');
            }
        });
        auth()->forgetGuards(); $old = ini_set('zend.exception_ignore_args', '0');
        try {
            $this->assertSame('0', ini_get('zend.exception_ignore_args'));
            DB::enableQueryLog(); DB::flushQueryLog();
            $response = $this->withHeader('Authorization', 'Bearer '.$bearer)->postJson($this->url($order),
                ['ignored_secret' => 'cancellation-fixture-request-secret'])->assertStatus(409)->assertJsonPath('error.code', 'ORDER_CONFLICT');
            $this->assertSame($before, $this->state()); $this->assertSame(0, $afterCalls);
            $this->assertCount(1, $this->log->getRecords()); $this->assertSafeFailureRecord($this->log->getRecords()[0]);
            $queries = array_values(array_filter(DB::getQueryLog(), fn ($q) => preg_match('/tx_legacy_capture_shadow|tx_legacy_subscription_map/', $q['query'])));
            $this->assertNotEmpty($queries);
            $safe = json_encode([$response->json(), $queries, $this->log->getRecords()]);
            $this->assertNoSecrets($user, $safe); $this->assertStringNotContainsString($bearer, $safe);
            $this->assertStringNotContainsString($order->trade_no, $safe);
            $this->postJson($this->url($order))->assertOk();
            $this->assertSame(1, $afterCalls); $this->assertSame(2000, (int) $user->fresh()->balance);
            $this->assertSame(5, (int) $coupon->fresh()->limit_use);
            $this->assertSame(2, DB::table('tx_legacy_capture_shadow_event')->count());
        } finally { ini_set('zend.exception_ignore_args', $old); }
    }

    public function test_capture_failure_job_still_ignores_false_and_scheduler_can_retry_the_pending_order(): void
    {
        $user = $this->user(); $order = $this->order($user, ['balance_amount' => 200, 'created_at' => time() - 7201]);
        config(['legacy_capture_shadow.enabled' => 'invalid']);
        $job = new OrderHandleJob($order->trade_no); $job->handle();
        $this->assertSame(Order::STATUS_PENDING, (int) $order->fresh()->status);
        $this->assertSame(0, (int) $user->fresh()->balance);
        $this->assertSame(3, $job->tries); $this->assertSame([10, 30, 60], $job->backoff());
        $this->assertSame(0, Artisan::call('check:order'));
        Bus::assertDispatched(OrderHandleJob::class);
        config(['legacy_capture_shadow.enabled' => true]); $job->handle(); $job->handle();
        $this->assertSame(200, (int) $user->fresh()->balance);
        $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count());
    }

    public function test_pre_user_fence_hook_gap_blocks_and_rolls_back_refund_without_claiming_hook_coverage(): void
    {
        $user = $this->user(['balance' => 2000]); $order = $this->create($user);
        HookManager::register('order.cancel.before', function ($order): void {
            DB::table('tx_user')->where('id', $order->user_id)->update(['u' => 99]);
        });
        $before = $this->state(); $this->assertFalse((new OrderService($order))->cancel());
        $this->assertSame($before, $this->state()); $this->assertTrue($this->capture()->verify($user->id)['page_ok']);
        HookManager::reset();
        DB::table('tx_user')->where('id', $user->id)->update(['u' => 99]); $before = $this->state();
        $this->assertFalse((new OrderService($order))->cancel()); $this->assertSame($before, $this->state());
        $this->assertContains('CAPTURE_SOURCE_GAP', $this->capture()->verify($user->id)['issues']);
    }

    public function test_post_transaction_hook_gap_is_visible_and_native_false_after_commit_does_not_refund_again(): void
    {
        $user = $this->user(['balance' => 2000]); $order = $this->create($user);
        HookManager::register('order.cancel.after', function ($order): void {
            DB::table('tx_user')->where('id', $order->user_id)->update(['u' => 99]);
            throw new \RuntimeException('ordinary native after-hook failure');
        });
        $this->assertFalse((new OrderService($order))->cancel());
        $this->assertSame(Order::STATUS_CANCELLED, (int) $order->fresh()->status);
        $this->assertSame(2000, (int) $user->fresh()->balance);
        $this->assertSame(2, DB::table('tx_legacy_capture_shadow_event')->count());
        $this->assertContains('CAPTURE_SOURCE_GAP', $this->capture()->verify($user->id)['issues']);
        $committed = $this->state(); $this->assertFalse((new OrderService($order))->cancel());
        $this->assertSame($committed, $this->state());
    }

    public function test_context_drift_and_native_int_headroom_fail_without_partial_refund_or_event(): void
    {
        $user = $this->user(['balance' => 2000]); $order = $this->create($user); $before = $this->state();
        config(['legacy_capture_shadow.currency' => 'CNY']);
        $this->assertFalse((new OrderService($order))->cancel()); $this->assertSame($before, $this->state());
        config(['legacy_capture_shadow.currency' => 'USD']);
        $maximum = $this->user(['balance' => 2147483647]); $overflow = $this->order($maximum, ['balance_amount' => 1]);
        $before = $this->state(); $this->assertFalse((new OrderService($overflow))->cancel());
        $this->assertSame($before, $this->state());
    }

    public function test_mixed_chain_preserves_older_hashes_and_bounded_recovery_is_select_only_unapplied(): void
    {
        $user = $this->user(['balance' => 2000]); $admin = $this->user(['is_admin' => true]); $this->backfill($user);
        admin_setting(['secure_path' => 'cancellation_capture_admin']); Sanctum::actingAs($admin);
        $this->postJson('/txapi/admin/cancellation_capture_admin/users/'.$user->id.'/update', ['speed_limit' => 1])->assertOk();
        $order = $this->create($user); $old = DB::table('tx_legacy_capture_shadow_event')->orderBy('id')->get()->toJson();
        $context = DB::table('tx_legacy_capture_shadow_stream')->value('context_hash'); $projection = $this->projection();
        $this->assertTrue((new OrderService($order))->cancel());
        $this->assertSame($old, DB::table('tx_legacy_capture_shadow_event')->where('sequence', '<=', 2)->orderBy('id')->get()->toJson());
        $this->assertSame($context, DB::table('tx_legacy_capture_shadow_stream')->value('context_hash'));
        $this->assertSame($projection, $this->projection());
        $state = $this->state(); DB::enableQueryLog(); DB::flushQueryLog(); $summaries = [];
        foreach ([0, 1, 2, 3] as $cursor) {
            $page = $this->capture()->verify($user->id, $cursor, 1);
            $this->assertTrue($page['page_ok']); $this->assertTrue($page['read_only']);
            $this->assertFalse($page['projection_applied']); $this->assertFalse($page['migration_gate_open']);
            $this->assertFalse($page['global_coverage_complete']);
            $summaries = array_merge($summaries, $page['events']);
        }
        foreach (DB::getQueryLog() as $query) { $this->assertMatchesRegularExpression('/^select/i', $query['query']); }
        $this->assertSame($state, $this->state());
        $this->assertSame(['admin_user', 'authenticated_user', 'system_order_cancellation'], array_column($summaries, 'actor_domain'));
        $this->assertArrayNotHasKey('capture_boundary', $summaries[0]);
        $this->assertSame('transaction-only', $summaries[2]['capture_boundary']);
    }

    public function test_cancellation_ticket_cross_use_and_rehashed_unknown_policy_are_rejected(): void
    {
        $user = $this->user(); DB::beginTransaction();
        try {
            $refund = $this->capture()->beginOrderCancellationRefund($user->id, 1, 1);
            foreach ([$this->capture()->begin($user->id), $this->capture()->beginVerifiedRecharge($user->id, 1),
                $this->capture()->beginOrderFulfillment($user->id, 1), $this->capture()->beginOrderCreation($user->id)] as $other) {
                $this->assertFailure('CAPTURE_WRITER_TICKET_INVALID', fn () => $this->capture()->appendOrderCancellationRefund($other));
            }
            $this->assertFailure('CAPTURE_WRITER_TICKET_INVALID', fn () => $this->capture()->append($refund, $user->id));
            $this->assertFailure('CAPTURE_WRITER_TICKET_INVALID', fn () => $this->capture()->appendVerifiedRecharge($refund));
            $this->assertFailure('CAPTURE_WRITER_TICKET_INVALID', fn () => $this->capture()->appendOrderFulfillment($refund));
            $this->assertFailure('CAPTURE_WRITER_TICKET_INVALID', fn () => $this->capture()->appendOrderCreation($refund));
        } finally { DB::rollBack(); }
        $this->assertTrue((new OrderService($this->order($user, ['balance_amount' => 100])))->cancel());
        $payload = (array) DB::table('tx_legacy_capture_shadow_event')->first(); unset($payload['id'], $payload['content_hash']);
        foreach (['stream_id', 'legacy_user_id', 'sequence', 'actor_id', 'captured_at'] as $key) { $payload[$key] = (string) $payload[$key]; }
        foreach (['before_snapshot', 'after_snapshot'] as $key) { $payload[$key] = json_decode($payload[$key], true); }
        foreach ([['order.cancel.refund', 'invented-refund/v1'], ['invented.writer', 'order-cancel-refund-capture/v1']] as [$kind, $version]) {
            $payload['source_kind'] = $kind; $payload['source_version'] = $version; $hash = SubscriptionTermsTimeline::hash($payload);
            DB::table('tx_legacy_capture_shadow_event')->update(['source_kind' => $kind, 'source_version' => $version, 'content_hash' => $hash]);
            DB::table('tx_legacy_capture_shadow_stream')->update(['last_event_hash' => $hash]);
            $this->assertFailure('CAPTURE_EVENT_TAMPERED', fn () => $this->capture()->verify($user->id));
        }
    }

    public function test_independent_mysql_stale_duplicate_cancel_waits_and_refunds_coupon_and_user_once(): void
    {
        $this->requireFork(); $user = $this->user(['balance' => 2000]); $coupon = $this->coupon(); $order = $this->create($user, $coupon);
        $this->assertSame('NO', $this->compete(fn () => (new OrderService($order))->cancel(), fn () => (new OrderService($order))->cancel()));
        $this->assertSame(2000, (int) $user->fresh()->balance); $this->assertSame(5, (int) $coupon->fresh()->limit_use);
        $this->assertSame(Order::STATUS_CANCELLED, (int) $order->fresh()->status);
        $this->assertSame(2, DB::table('tx_legacy_capture_shadow_event')->count());
        $this->assertTrue($this->capture()->verify($user->id)['page_ok']);
    }

    public function test_independent_mysql_cancel_and_paid_current_status_arbitration_retains_both_native_winners(): void
    {
        $this->requireFork();
        foreach ([true, false] as $cancelWins) {
            $user = $this->user(); $order = $this->order($user, ['balance_amount' => 100]);
            $cancel = fn () => (new OrderService($order))->cancel(); $paid = fn () => (new OrderService($order))->paid('native-payment-reference');
            $this->assertSame('NO', $this->compete($cancelWins ? $cancel : $paid, $cancelWins ? $paid : $cancel));
            $this->assertSame($cancelWins ? Order::STATUS_CANCELLED : Order::STATUS_PROCESSING, (int) $order->fresh()->status);
            $this->assertSame($cancelWins ? 100 : 0, (int) $user->fresh()->balance);
            $this->assertSame($cancelWins ? 1 : 0, DB::table('tx_legacy_capture_shadow_event')->where('legacy_user_id', $user->id)->count());
        }
    }

    public function test_independent_mysql_stale_outer_mvcc_refund_uses_exclusive_current_user_and_recharge_tail(): void
    {
        $this->requireFork(); $user = $this->user(['balance' => 500]); $order = $this->order($user, ['balance_amount' => 200]);
        [$payment, $recharge] = $this->recharge($user);
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        DB::purge(); $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($parent); stream_set_timeout($child, 10); DB::beginTransaction();
            DB::table('tx_user')->where('id', $user->id)->first(); DB::table('tx_legacy_capture_shadow_stream')->get();
            fwrite($child, 'R'); fread($child, 1);
            try { $okay = (new OrderService($order))->cancel(); DB::commit(); fwrite($child, $okay ? 'OK' : 'NO'); }
            catch (\Throwable) { DB::rollBack(); fwrite($child, 'FAIL'); }
            fclose($child); DB::disconnect(); exit(0);
        }
        $this->assertGreaterThan(0, $pid); fclose($child); stream_set_timeout($parent, 10);
        try {
            $this->assertSame('R', fread($parent, 1)); DB::beginTransaction();
            $this->assertTrue(app(WalletRechargeService::class)->settleVerified($recharge->trade_no, $payment->id, 'native-recharge-reference', 1000));
            fwrite($parent, 'S'); $this->assertWaiting($parent); DB::commit();
            $this->assertSame('OK', stream_get_contents($parent, 32));
        } finally { if (DB::transactionLevel()) { DB::rollBack(); } fclose($parent); pcntl_waitpid($pid, $status); }
        $this->assertSame(0, pcntl_wexitstatus($status)); $this->assertSame(1700, (int) $user->fresh()->balance);
        $report = $this->capture()->verify($user->id); $this->assertTrue($report['page_ok']);
        $this->assertSame(['wallet.recharge.settle', 'order.cancel.refund'], array_column($report['events'], 'source_kind'));
        $row = DB::table('tx_legacy_capture_shadow_event')->where('sequence', 2)->first();
        $this->assertSame('1500', json_decode($row->before_snapshot, true)['balance']);
        // The selected allowlist snapshot takes X directly; older writers remain shared current reads.
        DB::beginTransaction(); DB::enableQueryLog(); DB::flushQueryLog();
        try {
            $this->capture()->beginOrderCancellationRefund($user->id, $order->id, 200);
            $this->assertStringContainsString('for update', strtolower(DB::getQueryLog()[0]['query']));
            DB::flushQueryLog(); $this->capture()->beginOrderCreation($user->id);
            $this->assertStringContainsString('lock in share mode', strtolower(DB::getQueryLog()[0]['query']));
        } finally { DB::rollBack(); }
    }

    public function test_independent_mysql_cancel_then_recharge_serializes_one_current_endpoint_chain(): void
    {
        $this->requireFork(); $user = $this->user(); $order = $this->order($user, ['balance_amount' => 200]);
        [$payment, $recharge] = $this->recharge($user);
        $this->assertSame('OK', $this->compete(fn () => (new OrderService($order))->cancel(),
            fn () => app(WalletRechargeService::class)->settleVerified($recharge->trade_no, $payment->id, 'native-recharge-reference', 1000)));
        $this->assertSame(1200, (int) $user->fresh()->balance);
        $report = $this->capture()->verify($user->id); $this->assertTrue($report['page_ok']);
        $this->assertSame(['order.cancel.refund', 'wallet.recharge.settle'], array_column($report['events'], 'source_kind'));
    }

    public function test_independent_mysql_cancel_then_new_create_retains_native_coupon_and_wallet_preflight(): void
    {
        $this->requireFork(); $user = $this->user(['balance' => 2000]); $coupon = $this->coupon(); $order = $this->create($user, $coupon);
        $this->assertSame('OK', $this->compete(fn () => (new OrderService($order))->cancel(), function () use ($user, $coupon): bool {
            $this->create($user, $coupon); return true;
        }));
        $this->assertSame(1200, (int) $user->fresh()->balance); $this->assertSame(4, (int) $coupon->fresh()->limit_use);
        $this->assertSame(2, Order::count()); $this->assertSame(1, Order::where('status', Order::STATUS_PENDING)->count());
        $report = $this->capture()->verify($user->id); $this->assertTrue($report['page_ok']);
        $this->assertSame(['order.create', 'order.cancel.refund', 'order.create'], array_column($report['events'], 'source_kind'));
    }

    public function test_mysql_process_death_before_and_after_commit_retains_native_duplicate_false_without_second_refund(): void
    {
        $this->requireFork(); $user = $this->user(['balance' => 2000]); $this->backfill($user); $coupon = $this->coupon();
        $order = $this->create($user, $coupon); $before = $this->state();
        $this->killCancellation($order, false); $this->assertSame($before, $this->state());
        $this->killCancellation($order, true);
        $this->assertSame(Order::STATUS_CANCELLED, (int) $order->fresh()->status);
        $this->assertSame(2000, (int) $user->fresh()->balance); $this->assertSame(5, (int) $coupon->fresh()->limit_use);
        $this->assertSame(2, DB::table('tx_legacy_capture_shadow_event')->count());
        $committed = $this->state(); $this->assertFalse((new OrderService($order))->cancel());
        Sanctum::actingAs($user); $this->postJson($this->url($order))->assertStatus(409)->assertJsonPath('error.code', 'ORDER_CONFLICT');
        $this->assertSame($committed, $this->state()); $this->assertTrue($this->capture()->verify($user->id)['page_ok']);
    }

    private function compete(callable $winner, callable $waiting): string
    {
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        DB::purge(); $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($parent); stream_set_timeout($child, 10); fread($child, 1); fwrite($child, 'R');
            try { fwrite($child, $waiting() ? 'OK' : 'NO'); }
            catch (\Throwable) { fwrite($child, 'FAIL'); }
            fclose($child); DB::disconnect(); exit(0);
        }
        $this->assertGreaterThan(0, $pid); fclose($child); stream_set_timeout($parent, 10);
        try {
            DB::beginTransaction(); $this->assertTrue($winner()); fwrite($parent, 'S');
            $this->assertSame('R', fread($parent, 1)); $this->assertWaiting($parent); DB::commit();
            $result = stream_get_contents($parent, 32);
        } finally { if (DB::transactionLevel()) { DB::rollBack(); } fclose($parent); pcntl_waitpid($pid, $status); }
        $this->assertSame(0, pcntl_wexitstatus($status)); return $result;
    }

    private function killCancellation(Order $order, bool $afterCommit): void
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
                    if (str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'tx_legacy_subscription_map')) { $barrier(); }
                });
            }
            (new OrderService($order))->cancel(); exit(3);
        }
        $this->assertGreaterThan(0, $pid); fclose($child); stream_set_timeout($parent, 10);
        try {
            $this->assertSame('B', fread($parent, 1)); $this->assertTrue(posix_kill($pid, SIGKILL));
            pcntl_waitpid($pid, $status); $this->assertTrue(pcntl_wifsignaled($status)); $this->assertSame(SIGKILL, pcntl_wtermsig($status));
        } finally { fclose($parent); }
    }

    private function assertWaiting($pipe): void
    {
        $read = [$pipe]; $write = []; $except = [];
        $this->assertSame(0, stream_select($read, $write, $except, 0, 250000), 'Writer escaped the uncommitted native fence.');
    }
    private function create(User $user, ?Coupon $coupon = null): Order
    {
        return OrderService::createFromRequest($user, $this->plan, 'monthly', $coupon?->code);
    }
    private function url(Order $order): string { return '/txapi/orders/'.$order->trade_no.'/cancel'; }
    private function coupon(): Coupon
    {
        return Coupon::create(['code' => 'cancellation-fixture-coupon-secret', 'name' => 'cancellation coupon',
            'type' => 1, 'value' => 200, 'show' => true, 'limit_use' => 5]);
    }
    private function order(User $user, array $extra = []): Order
    {
        return Order::create(array_replace(['user_id' => $user->id, 'plan_id' => $this->plan->id,
            'trade_no' => 'cancellation-native-order-'.(++$this->counter), 'period' => 'monthly',
            'type' => Order::TYPE_RENEWAL, 'status' => Order::STATUS_PENDING, 'total_amount' => 1000], $extra));
    }
    private function recharge(User $user): array
    {
        $payment = Payment::create(['uuid' => 'cancellation-payment-'.(++$this->counter), 'payment' => 'EPay', 'name' => 'fixture', 'enable' => true, 'config' => []]);
        return [$payment, app(WalletRechargeService::class)->create($user->id, 1000, $payment->id, (string) \Illuminate\Support\Str::uuid())];
    }
    private function user(array $extra = []): User
    {
        $id = ++$this->counter;
        $anchor = Carbon::now('UTC')->startOfMonth()->addMonths(2)->day(15)->setTime(12, 0)->timestamp;
        return User::create(array_replace(['email' => 'cancellation-capture-'.$id.'@example.test', 'password' => 'cancellation-fixture-password',
            'uuid' => sprintf('20000000-0000-4000-8000-%012d', $id), 'token' => sprintf('%032d', $id),
            'plan_id' => $this->plan->id, 'group_id' => 1, 'transfer_enable' => 1000, 'u' => 10, 'd' => 20, 'expired_at' => $anchor], $extra));
    }
    private function backfill(User $user): void
    {
        app(SubscriptionShadowBackfill::class)->run('cancellation-map-'.$user->id, 100, app(LegacySubscriptionSnapshot::class)->context('UTC', 'USD'));
    }
    private function source(User $user): array
    {
        $row = (array) DB::table('tx_user')->where('id', $user->id)->select(LegacyUserMutationCapture::FIELDS)->first();
        foreach ($row as $key => $value) { $row[$key] = $key === 'banned' ? (bool) $value : ($value === null ? null : (string) $value); }
        ksort($row, SORT_STRING); return $row;
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
        foreach (['tx_user', 'tx_order', 'tx_coupon', 'idempotency_keys', 'tx_legacy_capture_shadow_stream',
            'tx_legacy_capture_shadow_event', 'tx_legacy_subscription_map'] as $table) { $result[$table] = DB::table($table)->orderBy('id')->get()->toJson(); }
        return $result;
    }
    private function capture(): LegacyUserMutationCapture { return app(LegacyUserMutationCapture::class); }
    private function assertFailure(string $code, callable $callback): void
    {
        try { $callback(); $this->fail('Accepted invalid cancellation evidence.'); }
        catch (LegacyCaptureShadowException $exception) { $this->assertSame($code, $exception->getMessage()); $this->assertNull($exception->getPrevious()); }
    }
    private function assertSafeFailureRecord($record): void
    {
        $this->assertSame('LEGACY_ORDER_CANCELLATION_CAPTURE_FAILED', $record['message']);
        $this->assertSame([], $record['context']);
    }
    private function assertCaptureSqlAbsent(): void
    {
        $this->assertSame([], array_values(array_filter(DB::getQueryLog(), fn ($q) => preg_match('/tx_legacy_capture_shadow|tx_legacy_subscription_map/', $q['query']))));
    }
    private function assertNoSecrets(User $user, string $text): void
    {
        foreach ([$user->email, $user->password, $user->token, $user->uuid,
            'cancellation-fixture-request-secret', 'cancellation-fixture-coupon-secret', 'cancellation-fixture-storage-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }
    }
    private function mysql(): bool { return getenv('CORE_CAPTURE_TEST_MYSQL') === '1'; }
    private function requireFork(): void
    {
        if (!$this->mysql() || !function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('Requires independent MariaDB sessions and process control.');
        }
    }
    private function clearMysql(): void
    {
        if (DB::connection()->getDatabaseName() !== 'txboard_concurrency_test') { throw new \RuntimeException('Cancellation capture needs its dedicated database.'); }
        Schema::disableForeignKeyConstraints();
        try { foreach (Schema::getTableListing() as $table) { Schema::drop($table); } }
        finally { Schema::enableForeignKeyConstraints(); }
    }
}
