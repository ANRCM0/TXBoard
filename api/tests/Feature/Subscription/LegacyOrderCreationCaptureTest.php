<?php

namespace Tests\Feature\Subscription;

use App\Domains\Billing\OrderIdempotency;
use App\Domains\Billing\WalletRechargeService;
use App\Jobs\OrderHandleJob;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderService;
use App\Services\Plugin\HookManager;
use App\Services\Subscription\CommerceShadowService;
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

class LegacyOrderCreationCaptureTest extends TestCase
{
    private int $counter = 0;
    private Plan $plan;
    private TestHandler $log;

    protected function setUp(): void
    {
        parent::setUp();
        $connection = $this->mysql() ? 'order_creation_capture_mysql_testing' : 'order_creation_capture_sqlite_testing';
        config(['database.default' => $connection, 'database.connections.'.$connection => $this->mysql()
            ? config('database.connections.mysql') : array_replace(config('database.connections.sqlite'),
                ['database' => ':memory:', 'foreign_key_constraints' => true])]);
        DB::purge($connection);
        if ($this->mysql()) { $this->clearMysql(); }
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        config(['core_shadow.isolated_copy' => true, 'legacy_capture_shadow.enabled' => true,
            'legacy_capture_shadow.run_key' => 'order-creation-fixture',
            'legacy_capture_shadow.billing_timezone' => 'UTC', 'legacy_capture_shadow.currency' => 'USD',
            'cache.stores.redis' => ['driver' => 'array']]);
        Bus::fake(); HookManager::reset();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->plan = Plan::create(['name' => 'capture creation', 'group_id' => 1, 'transfer_enable' => 2,
            'show' => true, 'sell' => true, 'renew' => true, 'speed_limit' => 5, 'device_limit' => 3,
            'prices' => ['monthly' => 10, 'quarterly' => 28], 'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY]);
        $this->log = new TestHandler();
        $this->log->setFormatter(new LineFormatter(null, null, true, true, true));
        Log::swap(new \Illuminate\Log\Logger(new Logger('capture-order-creation', [$this->log])));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(); HookManager::reset();
        if ($this->mysql()) { $this->clearMysql(); }
        DB::purge($this->mysql() ? 'order_creation_capture_mysql_testing' : 'order_creation_capture_sqlite_testing');
        parent::tearDown();
    }

    public function test_real_http_partial_full_wallet_coupon_and_vip_keep_native_amounts_and_exact_raw_endpoints(): void
    {
        $coupon = $this->coupon();
        foreach ([100 => [0, 100, 600], 2000 => [1300, 700, 0]] as $balance => $expected) {
            $user = $this->user(['balance' => $balance, 'discount' => 10, 'commission_balance' => 123]);
            $before = $this->source($user); Sanctum::actingAs($user);
            DB::enableQueryLog(); DB::flushQueryLog();
            $order = $this->postOrder('quote-'.$user->id, ['coupon_code' => $coupon->code]);
            $this->assertSame($expected, [(int) $user->fresh()->balance, (int) $order->balance_amount, (int) $order->total_amount]);
            $this->assertSame(300, (int) $order->discount_amount);
            $this->assertSame(Order::TYPE_RENEWAL, (int) $order->type);
            $row = DB::table('tx_legacy_capture_shadow_event')->where('legacy_user_id', $user->id)->first();
            $this->assertSame($before, json_decode($row->before_snapshot, true));
            $this->assertSame($this->source($user), json_decode($row->after_snapshot, true));
            $this->assertSame('123', json_decode($row->after_snapshot, true)['commission_balance']);
            $summary = $this->capture()->verify($user->id)['events'][0];
            $this->assertSame(['order.create', 'order-create-capture/v1', 'authenticated_user', (string) $user->id, 'transaction-only'],
                [$summary['source_kind'], $summary['source_version'], $summary['actor_domain'], $summary['actor_id'], $summary['capture_boundary']]);
            $bindings = array_values(array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'tx_legacy_capture_shadow')));
            $evidence = json_encode([$row, $summary, $bindings, $this->log->getRecords()]);
            $this->assertNoSecrets($user, $evidence);
            $this->assertStringNotContainsString($order->trade_no, $evidence);
            $this->assertStringNotContainsString('quote-'.$user->id, $evidence);
        }
        $this->assertSame(3, (int) $coupon->fresh()->limit_use);
    }

    public function test_real_admin_user_role_stays_user_principal_and_banned_or_unauthenticated_requests_cannot_create(): void
    {
        $admin = $this->user(['is_admin' => true, 'balance' => 2000]); Sanctum::actingAs($admin);
        $this->postOrder('admin-as-user');
        $this->assertSame('authenticated_user', $this->capture()->verify($admin->id)['events'][0]['actor_domain']);
        $banned = $this->user(['banned' => true, 'balance' => 2000]); Sanctum::actingAs($banned);
        $this->postJson('/txapi/orders', $this->body())->assertForbidden();
        auth()->forgetGuards();
        $this->postJson('/txapi/orders', $this->body())->assertUnauthorized();
        $this->assertSame(1, Order::count());
    }

    public function test_native_upgrade_surplus_and_wallet_reservation_match_default_off_creation(): void
    {
        Carbon::setTestNow(Carbon::now('UTC')->startOfMinute());
        $target = Plan::create(['name' => 'upgrade target', 'group_id' => 1, 'transfer_enable' => 4,
            'show' => true, 'sell' => true, 'renew' => true, 'capacity_limit' => null, 'prices' => ['monthly' => 30]]);
        $results = [];
        foreach ([false, true] as $enabled) {
            $user = $this->user(['balance' => 5000]);
            $prior = Order::create(['user_id' => $user->id, 'plan_id' => $this->plan->id,
                'trade_no' => 'prior-upgrade-'.$user->id, 'period' => 'monthly', 'type' => Order::TYPE_NEW_PURCHASE,
                'status' => Order::STATUS_COMPLETED, 'total_amount' => 1000]);
            config(['legacy_capture_shadow.enabled' => $enabled]); Sanctum::actingAs($user);
            $order = $this->postOrder('upgrade-'.$user->id, ['plan_id' => $target->id]);
            $results[] = [(int) $order->type, (int) $order->surplus_amount, (int) $order->surplus_credit,
                (int) $order->balance_amount, (int) $order->total_amount, (int) $user->fresh()->balance];
            $this->assertSame([$prior->id], $order->surplus_order_ids);
            $this->assertSame(Order::STATUS_COMPLETED, (int) $prior->fresh()->status);
        }
        $this->assertSame($results[0], $results[1]);
        $this->assertSame([Order::TYPE_UPGRADE, 1000, 0, 2000, 0, 3000], $results[1]);
        $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count());
    }

    public function test_free_zero_wallet_and_metadata_only_hook_never_fabricate_user_event_or_clean_commerce_order_gate(): void
    {
        $zero = $this->user();
        $this->backfill($zero);
        $map = DB::table('tx_legacy_subscription_map')->get()->toJson();
        $commerce = app(CommerceShadowService::class);
        $commerce->run('creation-commerce', 100, ['currency' => 'USD', 'snapshot_key' => 'creation-snapshot']);
        HookManager::register('order.after_create', function ($order): void {
            DB::table('tx_user')->where('id', $order->user_id)->update(['remind_expire' => 0]);
        });
        Sanctum::actingAs($zero); $this->postOrder('zero-wallet');
        $free = $this->user(['balance' => 2000]); $this->plan->update(['prices' => ['monthly' => 0]]);
        Sanctum::actingAs($free); $this->postOrder('free');
        $this->assertSame(2000, (int) $free->fresh()->balance);
        $this->assertSame(0, DB::table('tx_legacy_capture_shadow_event')->count());
        $this->assertSame(0, DB::table('tx_legacy_capture_shadow_stream')->count());
        $this->assertSame($map, DB::table('tx_legacy_subscription_map')->get()->toJson());
        $this->assertSame(0, DB::table('tx_commerce_shadow_order')->count());
        $report = $commerce->reconcile('creation-commerce', 'order', '0', 100);
        $this->assertFalse($report['page_ok']); $this->assertNotEmpty($report['global_issues']);
    }

    public function test_existing_recharge_admin_create_and_paid_renewal_chain_preserves_old_hashes_and_never_applies_projection(): void
    {
        $user = $this->user(['balance' => 2000]); $admin = $this->user(['is_admin' => true]); $this->backfill($user);
        admin_setting(['secure_path' => 'creation_capture_admin']); Sanctum::actingAs($admin);
        $this->postJson('/txapi/admin/creation_capture_admin/users/'.$user->id.'/update', ['speed_limit' => 1])->assertOk();
        $payment = Payment::create(['uuid' => 'creation-payment', 'payment' => 'EPay', 'name' => 'fixture', 'enable' => true, 'config' => []]);
        $service = app(WalletRechargeService::class);
        $recharge = $service->create($user->id, 1000, $payment->id, (string) \Illuminate\Support\Str::uuid());
        $this->assertTrue($service->settleVerified($recharge->trade_no, $payment->id, 'native-receipt', 1000));
        $old = DB::table('tx_legacy_capture_shadow_event')->orderBy('id')->get()->toJson();
        $contextHash = DB::table('tx_legacy_capture_shadow_stream')->value('context_hash');
        $projection = $this->projection(); Sanctum::actingAs($user);
        $order = $this->postOrder('mixed-chain');
        $this->postJson('/txapi/orders/'.$order->trade_no.'/checkout')->assertOk();
        (new OrderHandleJob($order->trade_no))->handle();
        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->fresh()->status);
        $this->assertSame($old, DB::table('tx_legacy_capture_shadow_event')->where('sequence', '<=', 2)->orderBy('id')->get()->toJson());
        $this->assertSame($contextHash, DB::table('tx_legacy_capture_shadow_stream')->value('context_hash'));
        $this->assertSame($projection, $this->projection());
        $before = $this->state(); DB::enableQueryLog(); DB::flushQueryLog();
        $summaries = [];
        for ($cursor = 0; $cursor < 4; $cursor++) {
            $page = $this->capture()->verify($user->id, $cursor, 1); $this->assertTrue($page['page_ok']);
            $this->assertFalse($page['projection_applied']); $this->assertFalse($page['migration_gate_open']);
            $this->assertFalse($page['global_coverage_complete']); $summaries[] = $page['events'][0];
        }
        $this->assertSame(['admin_user', 'verified_payment_method', 'authenticated_user', 'system_order_fulfillment'], array_column($summaries, 'actor_domain'));
        $this->assertArrayNotHasKey('capture_boundary', $summaries[0]); $this->assertArrayNotHasKey('capture_boundary', $summaries[1]);
        foreach (DB::getQueryLog() as $query) { $this->assertMatchesRegularExpression('/^select/i', $query['query']); }
        $this->assertSame($before, $this->state());
    }

    public function test_one_hundred_success_replays_skip_hooks_capture_guard_and_missing_schema_even_after_cancel_or_completion(): void
    {
        $user = $this->user(['balance' => 2000]); Sanctum::actingAs($user); $calls = 0;
        HookManager::register('order.create.before', function () use (&$calls): void { $calls++; });
        $order = $this->postOrder('replay-original'); $state = $this->state();
        config(['legacy_capture_shadow.currency' => 'CNY', 'legacy_capture_shadow.enabled' => 'invalid']);
        DB::enableQueryLog(); DB::flushQueryLog();
        for ($i = 0; $i < 100; $i++) { $this->keyedPost('replay-original')->assertCreated()->assertJsonPath('data.trade_no', $order->trade_no); }
        $this->assertCaptureSqlAbsent(); $this->assertSame(1, $calls); $this->assertSame($state, $this->state());
        config(['legacy_capture_shadow.currency' => 'USD', 'legacy_capture_shadow.enabled' => true]);
        $this->postJson('/txapi/orders/'.$order->trade_no.'/cancel')->assertOk();
        config(['legacy_capture_shadow.currency' => 'CNY', 'legacy_capture_shadow.enabled' => 'invalid']);
        DB::table('tx_order')->where('id', $order->id)->update(['status' => Order::STATUS_COMPLETED]);
        Schema::drop('tx_legacy_capture_shadow_event'); Schema::drop('tx_legacy_capture_shadow_stream');
        DB::flushQueryLog(); $this->keyedPost('replay-original')->assertCreated()->assertJsonPath('data.trade_no', $order->trade_no);
        $this->assertCaptureSqlAbsent(); $this->assertSame(1, $calls);
    }

    public function test_native_conflict_preflight_unkeyed_and_fresh_inprogress_remain_before_capture_guard(): void
    {
        $user = $this->user(['balance' => 2000]); Sanctum::actingAs($user); $this->postOrder('original');
        config(['legacy_capture_shadow.enabled' => 'invalid']); DB::enableQueryLog(); DB::flushQueryLog();
        $this->keyedPost('original', ['period' => 'quarterly'])->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        $this->keyedPost('different')->assertStatus(409)->assertJsonPath('error.code', 'ORDER_REJECTED');
        $this->withoutHeader('Idempotency-Key')->postJson('/txapi/orders', $this->body())->assertStatus(409)->assertJsonPath('error.code', 'ORDER_REJECTED');
        $this->claim($user, 'inprogress');
        $this->keyedPost('inprogress')->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_IN_PROGRESS')->assertHeader('Retry-After', '2');
        $this->assertCaptureSqlAbsent(); $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count());
    }

    public function test_stale_and_expired_native_claims_keep_original_recovery_and_new_capture_once(): void
    {
        foreach (['stale', 'expired'] as $kind) {
            $user = $this->user(['balance' => 2000]); Sanctum::actingAs($user);
            $this->claim($user, $kind, $kind === 'stale' ? ['updated_at' => time() - 1800] : ['expires_at' => time() - 1]);
            $order = $this->postOrder($kind);
            DB::enableQueryLog(); DB::flushQueryLog();
            $this->keyedPost($kind)->assertCreated()->assertJsonPath('data.trade_no', $order->trade_no);
            $this->assertCaptureSqlAbsent();
            $this->assertSame(1000, (int) $user->fresh()->balance);
        }
        $this->assertSame(2, Order::count()); $this->assertSame(2, DB::table('idempotency_keys')->count());
        $this->assertSame(2, DB::table('tx_legacy_capture_shadow_event')->count());
    }

    public function test_outer_http_capture_failure_with_exception_args_enabled_rolls_back_order_wallet_coupon_claim_and_map_without_secret_logs(): void
    {
        $user = $this->user(['balance' => 2000]); $this->backfill($user); $coupon = $this->coupon();
        $bearer = $user->createToken('creation-fixture')->plainTextToken;
        auth()->forgetGuards(); $before = $this->state(); $armed = true; $hooks = [];
        HookManager::register('order.create.after', function () use (&$hooks): void { $hooks[] = 'create.after'; });
        HookManager::register('order.after_create', function () use (&$hooks): void { $hooks[] = 'after_create'; });
        DB::listen(function ($query) use (&$armed): void {
            if ($armed && str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'tx_legacy_subscription_map')) {
                $armed = false; throw new \RuntimeException('creation-fixture-storage-secret');
            }
        });
        $old = ini_set('zend.exception_ignore_args', '0');
        try {
            $this->assertSame('0', ini_get('zend.exception_ignore_args'));
            DB::enableQueryLog(); DB::flushQueryLog();
            $response = $this->withHeader('Authorization', 'Bearer '.$bearer)
                ->keyedPost('creation-fixture-idempotency-secret', ['coupon_code' => $coupon->code])->assertStatus(409)
                ->assertJsonPath('error.code', 'CONFLICT')->assertJsonPath('error.message', 'Resource conflict');
            $this->assertSame(['create.after', 'after_create'], $hooks);
            $shadowQueries = array_values(array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'tx_legacy_capture_shadow')));
            $this->assertNotEmpty($shadowQueries);
            $safeOutput = json_encode([$response->json(), $shadowQueries, $this->log->getRecords()]);
            $this->assertNoSecrets($user, $safeOutput);
            $this->assertStringNotContainsString($bearer, $safeOutput);
            $this->assertSame([], $this->log->getRecords()); $this->assertSame($before, $this->state());
            $this->postOrder('creation-fixture-idempotency-secret', ['coupon_code' => $coupon->code]);
            $this->assertSame(1, Order::count()); $this->assertSame(1, DB::table('idempotency_keys')->count());
            $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count());
        } finally { ini_set('zend.exception_ignore_args', $old); }
    }

    public function test_disabled_production_creation_uses_native_deduction_with_zero_capture_sql(): void
    {
        $user = $this->user(['balance' => 2000]); Sanctum::actingAs($user);
        config(['legacy_capture_shadow.enabled' => false, 'core_shadow.isolated_copy' => false]);
        app()->instance('env', 'production'); DB::enableQueryLog(); DB::flushQueryLog();
        $this->postOrder('default-off'); $this->assertCaptureSqlAbsent();
        $this->assertSame(1000, (int) $user->fresh()->balance);
    }

    public function test_strict_guard_invalid_source_and_context_drift_roll_back_new_intents(): void
    {
        $user = $this->user(['balance' => 2000]); $before = $this->state();
        config(['legacy_capture_shadow.enabled' => '1']);
        $this->assertFailure('CAPTURE_ISOLATED_COPY_REQUIRED', fn () => $this->create($user, 'guard')); $this->assertSame($before, $this->state());
        config(['legacy_capture_shadow.enabled' => true]); DB::table('tx_user')->where('id', $user->id)->update(['balance' => -1]);
        $before = $this->state(); $this->assertFailure('CAPTURE_SOURCE_INVALID', fn () => $this->create($user, 'negative'));
        $this->assertSame($before, $this->state());
        DB::table('tx_user')->where('id', $user->id)->update(['balance' => 2000]); $this->create($user, 'context');
        // Existing completed order is outside the native pending-order preflight.
        DB::table('tx_order')->update(['status' => Order::STATUS_COMPLETED]);
        config(['legacy_capture_shadow.currency' => 'CNY']); $before = $this->state();
        $this->assertFailure('CAPTURE_CONTEXT_DRIFT', fn () => $this->create($user, 'new-context')); $this->assertSame($before, $this->state());
    }

    public function test_selected_user_raw_before_and_after_hooks_are_captured_and_real_observer_followup_remains_a_gap(): void
    {
        $user = $this->user(['balance' => 2000]); Sanctum::actingAs($user); $before = $this->source($user);
        HookManager::register('order.create.before', function ($payload): void { DB::table('tx_user')->where('id', $payload[0]->id)->update(['speed_limit' => 7]); });
        HookManager::register('order.create.after', function ($order): void { DB::table('tx_user')->where('id', $order->user_id)->update(['device_limit' => 4]); });
        HookManager::register('order.after_create', function ($order): void { DB::table('tx_user')->where('id', $order->user_id)->update(['commission_balance' => 42]); });
        $this->postOrder('hooks'); $row = DB::table('tx_legacy_capture_shadow_event')->first();
        $this->assertSame($before, json_decode($row->before_snapshot, true));
        $this->assertSame($this->source($user), json_decode($row->after_snapshot, true));
        $after = json_decode($row->after_snapshot, true);
        $this->assertSame(['7', '4', '42'], [$after['speed_limit'], $after['device_limit'], $after['commission_balance']]);
        HookManager::reset(); $other = $this->user(['balance' => 2000]); Sanctum::actingAs($other);
        HookManager::register('order.create.before', function ($payload): void {
            User::findOrFail($payload[0]->id)->update(['expired_at' => time() + 172800, 'next_reset_at' => null]);
        });
        $this->postOrder('observer');
        $after = json_decode(DB::table('tx_legacy_capture_shadow_event')->where('legacy_user_id', $other->id)->value('after_snapshot'), true);
        $this->assertNull($after['next_reset_at']); $this->assertNotNull($other->fresh()->next_reset_at);
        $this->assertContains('CAPTURE_SOURCE_GAP', $this->capture()->verify($other->id)['issues']);
    }

    public function test_creation_ticket_cross_use_and_rehashed_wrong_actor_are_rejected(): void
    {
        $user = $this->user(['balance' => 2000]); DB::beginTransaction();
        try {
            User::whereKey($user->id)->lockForUpdate()->first();
            $create = $this->capture()->beginOrderCreation($user->id);
            $admin = $this->capture()->begin($user->id); $payment = $this->capture()->beginVerifiedRecharge($user->id, 1);
            $fulfill = $this->capture()->beginOrderFulfillment($user->id, 1);
            foreach ([$admin, $payment, $fulfill] as $ticket) { $this->assertFailure('CAPTURE_WRITER_TICKET_INVALID', fn () => $this->capture()->appendOrderCreation($ticket)); }
            $this->assertFailure('CAPTURE_WRITER_TICKET_INVALID', fn () => $this->capture()->append($create, $user->id));
            $this->assertFailure('CAPTURE_WRITER_TICKET_INVALID', fn () => $this->capture()->appendVerifiedRecharge($create));
            $this->assertFailure('CAPTURE_WRITER_TICKET_INVALID', fn () => $this->capture()->appendOrderFulfillment($create));
            $create['actor_id'] = $user->id + 1;
            $this->assertFailure('CAPTURE_WRITER_TICKET_INVALID', fn () => $this->capture()->appendOrderCreation($create));
        } finally { DB::rollBack(); }
        $this->create($user, 'tamper'); $row = (array) DB::table('tx_legacy_capture_shadow_event')->first();
        $payload = $row; unset($payload['id'], $payload['content_hash']);
        foreach (['stream_id', 'legacy_user_id', 'sequence', 'actor_id', 'captured_at'] as $key) { $payload[$key] = (string) $payload[$key]; }
        foreach (['before_snapshot', 'after_snapshot'] as $key) { $payload[$key] = json_decode($payload[$key], true); }
        $payload['actor_id'] = (string) ($user->id + 1); $hash = SubscriptionTermsTimeline::hash($payload);
        DB::table('tx_legacy_capture_shadow_event')->update(['actor_id' => $user->id + 1, 'content_hash' => $hash]);
        DB::table('tx_legacy_capture_shadow_stream')->update(['last_event_hash' => $hash]);
        $this->assertFailure('CAPTURE_EVENT_TAMPERED', fn () => $this->capture()->verify($user->id));
    }

    public function test_independent_mysql_same_key_waits_and_commits_one_wallet_coupon_order_claim_and_event(): void
    {
        $this->requireFork(); $user = $this->user(['balance' => 2000]); $coupon = $this->coupon();
        $result = $this->concurrentCreate($user, 'same', 'same', ['coupon_code' => $coupon->code]);
        $this->assertSame('SAME', $result);
        $this->assertSame(1200, (int) $user->fresh()->balance); $this->assertSame(4, (int) $coupon->fresh()->limit_use);
        $this->assertSame(1, Order::count()); $this->assertSame(1, DB::table('idempotency_keys')->count());
        $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count());
    }

    public function test_independent_mysql_distinct_intents_keep_native_user_preflight_and_do_not_double_reserve(): void
    {
        $this->requireFork(); $user = $this->user(['balance' => 2000]);
        $this->assertSame('REJECTED', $this->concurrentCreate($user, 'one', 'two'));
        $this->assertSame(1000, (int) $user->fresh()->balance); $this->assertSame(1, Order::count());
        $this->assertSame(1, DB::table('idempotency_keys')->count()); $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count());
    }

    public function test_independent_mysql_stale_outer_snapshot_creation_uses_current_recharge_endpoint_and_stream_tail(): void
    {
        $this->requireFork(); $user = $this->user(['balance' => 500]);
        $payment = Payment::create(['uuid' => 'creation-current-payment', 'payment' => 'EPay', 'name' => 'fixture', 'enable' => true, 'config' => []]);
        $recharge = app(WalletRechargeService::class)->create($user->id, 1000, $payment->id, (string) \Illuminate\Support\Str::uuid());
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        DB::purge(); $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($parent); stream_set_timeout($child, 10); DB::beginTransaction();
            DB::table('tx_user')->where('id', $user->id)->first(); DB::table('tx_legacy_capture_shadow_stream')->get();
            fwrite($child, 'R'); fread($child, 1);
            try { $this->create($user, 'current-source'); DB::commit(); fwrite($child, 'OK'); }
            catch (\Throwable) { DB::rollBack(); fwrite($child, 'FAIL'); }
            fclose($child); DB::disconnect(); exit(0);
        }
        $this->assertGreaterThan(0, $pid); fclose($child); stream_set_timeout($parent, 10);
        try {
            $this->assertSame('R', fread($parent, 1)); DB::beginTransaction();
            $this->assertTrue(app(WalletRechargeService::class)->settleVerified($recharge->trade_no, $payment->id, 'recharge-receipt', 1000));
            fwrite($parent, 'S'); $this->assertWaiting($parent); DB::commit();
            $this->assertSame('OK', stream_get_contents($parent, 32));
        } finally { if (DB::transactionLevel()) { DB::rollBack(); } fclose($parent); pcntl_waitpid($pid, $status); }
        $this->assertSame(0, pcntl_wexitstatus($status)); $this->assertSame(500, (int) $user->fresh()->balance);
        $report = $this->capture()->verify($user->id); $this->assertTrue($report['page_ok']);
        $this->assertSame(['wallet.recharge.settle', 'order.create'], array_column($report['events'], 'source_kind'));
        $row = DB::table('tx_legacy_capture_shadow_event')->where('sequence', 2)->first();
        $this->assertSame('1500', json_decode($row->before_snapshot, true)['balance']);
    }

    public function test_mysql_process_death_before_and_after_commit_recovers_original_same_key_without_second_debit_or_capture(): void
    {
        $this->requireFork(); $user = $this->user(['balance' => 2000]); $this->backfill($user); $coupon = $this->coupon();
        $before = $this->state(); $this->killCreation($user, $coupon, false); $this->assertSame($before, $this->state());
        $this->killCreation($user, $coupon, true);
        $this->assertSame(1200, (int) $user->fresh()->balance); $this->assertSame(4, (int) $coupon->fresh()->limit_use);
        $this->assertSame(1, Order::count()); $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count());
        $this->assertSame('succeeded', DB::table('idempotency_keys')->value('status'));
        $committed = $this->state(); $order = Order::first();
        $this->assertSame($order->trade_no, $this->create($user, 'killed-intent', ['coupon_code' => $coupon->code])->trade_no);
        $this->assertSame($committed, $this->state()); $this->assertTrue($this->capture()->verify($user->id)['page_ok']);
    }

    private function concurrentCreate(User $user, string $one, string $two, array $extra = []): string
    {
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        DB::purge(); $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($parent); stream_set_timeout($child, 10); $trade = trim(fgets($child)); fwrite($child, 'R');
            try { $result = $this->create($user, $two, $extra); fwrite($child, $result->trade_no === $trade ? 'SAME' : 'DIFFERENT'); }
            catch (\App\Exceptions\ApiException) { fwrite($child, 'REJECTED'); }
            catch (\Throwable) { fwrite($child, 'FAIL'); }
            fclose($child); DB::disconnect(); exit(0);
        }
        $this->assertGreaterThan(0, $pid); fclose($child); stream_set_timeout($parent, 10);
        try {
            DB::beginTransaction(); $order = $this->create($user, $one, $extra); fwrite($parent, $order->trade_no."\n");
            $this->assertSame('R', fread($parent, 1)); $this->assertWaiting($parent); DB::commit();
            $result = stream_get_contents($parent, 32);
        } finally { if (DB::transactionLevel()) { DB::rollBack(); } fclose($parent); pcntl_waitpid($pid, $status); }
        $this->assertSame(0, pcntl_wexitstatus($status)); return $result;
    }

    private function killCreation(User $user, Coupon $coupon, bool $afterCommit): void
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
            $this->create($user, 'killed-intent', ['coupon_code' => $coupon->code]); exit(3);
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
        $this->assertSame(0, stream_select($read, $write, $except, 0, 250000), 'Writer escaped the uncommitted native User fence.');
    }
    private function body(array $extra = []): array { return array_replace(['plan_id' => $this->plan->id, 'period' => 'monthly'], $extra); }
    private function keyedPost(string $key, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('Idempotency-Key', $key)->postJson('/txapi/orders', $this->body($extra));
    }
    private function postOrder(string $key, array $extra = []): Order
    {
        $response = $this->keyedPost($key, $extra)->assertCreated();
        return Order::query()->where('trade_no', $response->json('data.trade_no'))->firstOrFail();
    }
    private function create(User $user, string $key, array $extra = []): Order
    {
        return OrderService::createFromRequest($user, $this->plan, $extra['period'] ?? 'monthly', $extra['coupon_code'] ?? null, $key);
    }
    private function coupon(): Coupon
    {
        return Coupon::create(['code' => 'creation-fixture-coupon-secret', 'name' => 'creation coupon',
            'type' => 1, 'value' => 200, 'show' => true, 'limit_use' => 5]);
    }
    private function claim(User $user, string $key, array $extra = []): void
    {
        DB::table('idempotency_keys')->insert(array_replace(['user_id' => $user->id, 'operation' => OrderIdempotency::OPERATION_CREATE,
            'key_hash' => OrderIdempotency::keyHash($user->id, OrderIdempotency::OPERATION_CREATE, $key),
            'request_hash' => OrderIdempotency::requestHash(['plan_id' => (int) $this->plan->id, 'period' => 'monthly', 'coupon_code' => null]),
            'status' => 'in_progress', 'trade_no' => null, 'result_snapshot' => null,
            'created_at' => time(), 'updated_at' => time(), 'expires_at' => time() + 86400], $extra));
    }
    private function user(array $extra = []): User
    {
        $id = ++$this->counter;
        $anchor = Carbon::now('UTC')->startOfMonth()->addMonths(2)->day(15)->setTime(12, 0)->timestamp;
        return User::create(array_replace(['email' => 'creation-capture-'.$id.'@example.test', 'password' => 'creation-fixture-password',
            'uuid' => sprintf('10000000-0000-4000-8000-%012d', $id), 'token' => sprintf('%032d', $id),
            'plan_id' => $this->plan->id, 'group_id' => 1, 'transfer_enable' => 1000, 'u' => 10, 'd' => 20, 'expired_at' => $anchor], $extra));
    }
    private function backfill(User $user): void
    {
        app(SubscriptionShadowBackfill::class)->run('creation-map-'.$user->id, 100, app(LegacySubscriptionSnapshot::class)->context('UTC', 'USD'));
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
        try { $callback(); $this->fail('Accepted invalid creation evidence.'); }
        catch (LegacyCaptureShadowException $exception) { $this->assertSame($code, $exception->getMessage()); $this->assertNull($exception->getPrevious()); }
    }
    private function assertCaptureSqlAbsent(): void
    {
        $this->assertSame([], array_values(array_filter(DB::getQueryLog(), fn ($q) => preg_match('/tx_legacy_capture_shadow|tx_legacy_subscription_map/', $q['query']))));
    }
    private function assertNoSecrets(User $user, string $text): void
    {
        foreach ([$user->email, $user->password, $user->token, $user->uuid, 'creation-fixture-bearer-secret',
            'creation-fixture-idempotency-secret', 'creation-fixture-coupon-secret', 'creation-fixture-storage-secret'] as $secret) {
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
        if (DB::connection()->getDatabaseName() !== 'txboard_concurrency_test') { throw new \RuntimeException('Creation capture needs its dedicated database.'); }
        Schema::disableForeignKeyConstraints();
        try { foreach (Schema::getTableListing() as $table) { Schema::drop($table); } }
        finally { Schema::enableForeignKeyConstraints(); }
    }
}
