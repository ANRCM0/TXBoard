<?php

namespace Tests\Feature\Subscription;

use App\Domains\Billing\WalletRechargeService;
use App\Http\Controllers\Txapi\CommissionController;
use App\Jobs\OrderHandleJob;
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
use Illuminate\Http\Request;
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

class LegacyCommissionTransferCaptureTest extends TestCase
{
    private const URL = '/txapi/billing/commission-transfer';
    private int $counter = 0;
    private Plan $plan;
    private TestHandler $log;

    protected function setUp(): void
    {
        parent::setUp();
        $connection = $this->connection();
        config(['database.default' => $connection, 'database.connections.'.$connection => $this->mysql()
            ? config('database.connections.mysql') : array_replace(config('database.connections.sqlite'),
                ['database' => ':memory:', 'foreign_key_constraints' => true])]);
        DB::purge($connection);
        if ($this->mysql()) { $this->clearMysql(); }
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        config(['core_shadow.isolated_copy' => true, 'legacy_capture_shadow.enabled' => true,
            'legacy_capture_shadow.run_key' => 'commission-transfer-fixture',
            'legacy_capture_shadow.billing_timezone' => 'UTC', 'legacy_capture_shadow.currency' => 'USD',
            'cache.stores.redis' => ['driver' => 'array']]);
        Bus::fake(); HookManager::reset();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        admin_setting(['commission_transfer_limit' => 0, 'commission_withdraw_limit' => 0]);
        $this->plan = Plan::create(['name' => 'capture transfer', 'group_id' => 1, 'transfer_enable' => 2,
            'show' => true, 'sell' => true, 'renew' => true, 'speed_limit' => 5, 'device_limit' => 3,
            'prices' => ['monthly' => 10], 'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY]);
        $this->log = new TestHandler();
        $this->log->setFormatter(new LineFormatter(null, null, true, true, true));
        Log::swap(new \Illuminate\Log\Logger(new Logger('capture-commission-transfer', [$this->log])));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(); HookManager::reset();
        if ($this->mysql()) { $this->clearMysql(); }
        DB::purge($this->connection());
        parent::tearDown();
    }

    public function test_actual_http_transfer_captures_raw_endpoints_and_invalidates_only_mapping(): void
    {
        $user = $this->user(['balance' => 120, 'commission_balance' => 400]); $this->backfill($user);
        $before = $this->source($user); $projection = $this->projection();
        $map = DB::table('tx_legacy_subscription_map')->first(); Sanctum::actingAs($user);
        DB::enableQueryLog(); DB::flushQueryLog();
        $this->postJson(self::URL, ['transfer_amount' => 150, 'ignored_secret' => 'transfer-fixture-request-secret'])
            ->assertOk()->assertJsonPath('data', true);
        $this->assertSame([270, 250], $this->wallets($user));
        $this->assertSame($projection, $this->projection());
        $currentMap = DB::table('tx_legacy_subscription_map')->first();
        $this->assertSame(['source_changed', 'LEGACY_CAPTURE_PENDING', (int) $map->row_version + 1],
            [$currentMap->state, $currentMap->error_code, (int) $currentMap->row_version]);
        $this->assertSame([$map->subscription_id, $map->terms_id, $map->usage_period_id, $map->source_hash],
            [$currentMap->subscription_id, $currentMap->terms_id, $currentMap->usage_period_id, $currentMap->source_hash]);
        $event = DB::table('tx_legacy_capture_shadow_event')->first();
        $this->assertSame($before, json_decode($event->before_snapshot, true));
        $this->assertSame($this->source($user), json_decode($event->after_snapshot, true));
        $summary = $this->capture()->verify($user->id)['events'][0];
        $this->assertSame(['commission.transfer', 'commission-transfer-capture/v1', 'authenticated_user', (string) $user->id, 'transaction-only'],
            [$summary['source_kind'], $summary['source_version'], $summary['actor_domain'], $summary['actor_id'], $summary['capture_boundary']]);
        $this->assertNoSecrets($user, json_encode([$event, $summary, $this->captureQueries(), $this->log->getRecords()]));
        $this->assertSame(0, Order::count()); $this->assertSame(0, DB::table('idempotency_keys')->count());
    }

    public function test_actual_user_auth_owner_and_validation_remain_native_before_capture(): void
    {
        $this->postJson(self::URL, ['transfer_amount' => 1])->assertUnauthorized();
        $banned = $this->user(['banned' => true, 'commission_balance' => 100]); Sanctum::actingAs($banned);
        $this->postJson(self::URL, ['transfer_amount' => 1])->assertForbidden();
        $other = $this->user(['commission_balance' => 100]);
        $admin = $this->user(['is_admin' => true, 'commission_balance' => 100]); Sanctum::actingAs($admin);
        config(['legacy_capture_shadow.enabled' => 'invalid']); DB::enableQueryLog(); DB::flushQueryLog();
        foreach ([null, 0, -1, 1.5, '1e2', 'bad'] as $amount) {
            $this->postJson(self::URL, ['transfer_amount' => $amount])->assertUnprocessable();
        }
        $this->assertCaptureSqlAbsent(); config(['legacy_capture_shadow.enabled' => true]);
        $this->postJson(self::URL, ['transfer_amount' => 40, 'user_id' => $other->id, 'actor_id' => $other->id,
            'actor_domain' => 'admin_user'])->assertOk();
        $this->assertSame([40, 60], $this->wallets($admin)); $this->assertSame([0, 100], $this->wallets($other));
        $summary = $this->capture()->verify($admin->id)['events'][0];
        $this->assertSame('authenticated_user', $summary['actor_domain']); $this->assertSame((string) $admin->id, $summary['actor_id']);
    }

    public function test_minimum_inheritance_explicit_zero_and_original_float_round_are_preserved(): void
    {
        foreach ([null, '', 'not-numeric'] as $inherit) {
            admin_setting(['commission_withdraw_limit' => 2.505, 'commission_transfer_limit' => $inherit]);
            $user = $this->user(['commission_balance' => 1000]); Sanctum::actingAs($user);
            $minimum = (int) round(admin_transfer_minimum() * 100);
            DB::enableQueryLog(); DB::flushQueryLog();
            $this->postJson(self::URL, ['transfer_amount' => $minimum - 1])->assertUnprocessable()->assertJsonPath('error.code', 'MINIMUM_NOT_MET');
            $this->assertCaptureSqlAbsent();
            $this->postJson(self::URL, ['transfer_amount' => $minimum])->assertOk();
            $this->assertSame([$minimum, 1000 - $minimum], $this->wallets($user));
        }
        admin_setting(['commission_transfer_limit' => 0, 'commission_withdraw_limit' => 250]);
        $user = $this->user(['commission_balance' => 1]); Sanctum::actingAs($user);
        $this->postJson(self::URL, ['transfer_amount' => 1])->assertOk();
        $this->assertSame([1, 0], $this->wallets($user));
    }

    public function test_native_rejections_skip_invalid_guard_and_missing_capture_tables(): void
    {
        $user = $this->user(['commission_balance' => 100]); Sanctum::actingAs($user);
        config(['legacy_capture_shadow.enabled' => 'invalid', 'legacy_capture_shadow.currency' => 'JPY']);
        Schema::drop('tx_legacy_capture_shadow_event'); Schema::drop('tx_legacy_capture_shadow_stream');
        DB::enableQueryLog(); DB::flushQueryLog();
        $this->postJson(self::URL, ['transfer_amount' => 101])->assertUnprocessable()->assertJsonPath('error.code', 'INSUFFICIENT_COMMISSION');
        admin_setting(['commission_transfer_limit' => 5]);
        $this->postJson(self::URL, ['transfer_amount' => 1])->assertUnprocessable()->assertJsonPath('error.code', 'MINIMUM_NOT_MET');
        $this->assertCaptureSqlAbsent(); $this->assertSame([0, 100], $this->wallets($user));
        $this->assertSame([], $this->log->getRecords());
    }

    public function test_same_ignored_key_and_body_are_two_transfers_when_funds_allow_then_native_rejection(): void
    {
        $user = $this->user(['balance' => 25, 'commission_balance' => 200]); Sanctum::actingAs($user);
        $this->withHeader('Idempotency-Key', 'transfer-fixture-key-secret');
        foreach ([1, 2] as $sequence) {
            $this->postJson(self::URL, ['transfer_amount' => 100])->assertOk();
            $this->assertSame([25 + 100 * $sequence, 200 - 100 * $sequence], $this->wallets($user));
        }
        DB::enableQueryLog(); DB::flushQueryLog();
        $this->postJson(self::URL, ['transfer_amount' => 100])->assertUnprocessable()->assertJsonPath('error.code', 'INSUFFICIENT_COMMISSION');
        $this->assertCaptureSqlAbsent(); $this->assertSame(2, DB::table('tx_legacy_capture_shadow_event')->count());
        $this->assertSame(0, DB::table('idempotency_keys')->count());
        $this->assertNoSecrets($user, DB::table('tx_legacy_capture_shadow_event')->get()->toJson());
    }

    public function test_default_off_production_keeps_original_single_user_read_and_zero_capture_sql(): void
    {
        config(['legacy_capture_shadow.enabled' => false, 'core_shadow.isolated_copy' => false]); app()->instance('env', 'production');
        $user = $this->user(['balance' => 100, 'commission_balance' => 300]); Sanctum::actingAs($user);
        DB::enableQueryLog(); DB::flushQueryLog(); $this->postJson(self::URL, ['transfer_amount' => 200])->assertOk();
        $this->assertCaptureSqlAbsent();
        $this->assertCount(1, array_filter(DB::getQueryLog(), fn ($q) => preg_match('/^select.*tx_user/i', $q['query'])));
        $this->assertSame([300, 100], $this->wallets($user));
        $this->assertNull($this->capture()->beginCommissionTransfer(0)); $this->capture()->appendCommissionTransfer(null);
    }

    public function test_strict_context_and_negative_raw_wallet_reject_atomically(): void
    {
        $user = $this->user(['commission_balance' => 100]); Sanctum::actingAs($user);
        foreach ([['legacy_capture_shadow.enabled' => '1'], ['core_shadow.isolated_copy' => false],
            ['legacy_capture_shadow.currency' => 'JPY'], ['legacy_capture_shadow.billing_timezone' => 'unknown']] as $invalid) {
            $state = $this->state(); config($invalid);
            $this->postJson(self::URL, ['transfer_amount' => 1])->assertStatus(409)->assertJsonPath('error.code', 'CONFLICT');
            $this->assertSame($state, $this->state());
            config(['legacy_capture_shadow.enabled' => true, 'core_shadow.isolated_copy' => true,
                'legacy_capture_shadow.currency' => 'USD', 'legacy_capture_shadow.billing_timezone' => 'UTC']);
        }
        app()->instance('env', 'production'); $state = $this->state();
        $this->postJson(self::URL, ['transfer_amount' => 1])->assertStatus(409); $this->assertSame($state, $this->state());
        app()->instance('env', 'testing'); DB::table('tx_user')->where('id', $user->id)->update(['balance' => -1]); $state = $this->state();
        $this->postJson(self::URL, ['transfer_amount' => 1])->assertStatus(409); $this->assertSame($state, $this->state());
        DB::table('tx_user')->where('id', $user->id)->update(['commission_balance' => -1]); DB::enableQueryLog(); DB::flushQueryLog();
        $this->postJson(self::URL, ['transfer_amount' => 1])->assertUnprocessable()->assertJsonPath('error.code', 'INSUFFICIENT_COMMISSION');
        $this->assertCaptureSqlAbsent(); $this->assertSame([], $this->log->getRecords());
    }

    public function test_raw_int_max_is_exact_and_overflow_cannot_leave_partial_transfer(): void
    {
        $user = $this->user(['balance' => 0, 'commission_balance' => 2147483647]);
        $this->assertSame(200, $this->transfer($user, 2147483647));
        $event = DB::table('tx_legacy_capture_shadow_event')->first();
        $this->assertSame('2147483647', json_decode($event->before_snapshot, true)['commission_balance']);
        $this->assertSame('2147483647', json_decode($event->after_snapshot, true)['balance']);
        $overflow = $this->user(['balance' => 2147483647, 'commission_balance' => 1]); $before = $this->state();
        try { $this->transfer($overflow, 1); $this->fail('Accepted native INT overflow.'); }
        catch (LegacyCaptureShadowException $exception) {
            $this->assertFalse($this->mysql()); $this->assertSame('CAPTURE_SOURCE_INVALID', $exception->getMessage());
        } catch (\Illuminate\Database\QueryException $exception) { $this->assertTrue($this->mysql()); }
        $this->assertSame($before, $this->state());
    }

    public function test_context_drift_and_unknown_writer_gap_never_overwrite_chain_or_apply_projection(): void
    {
        $user = $this->user(['commission_balance' => 100]); $this->assertSame(200, $this->transfer($user, 10));
        $before = $this->state(); config(['legacy_capture_shadow.currency' => 'CNY']);
        $this->assertFailure('CAPTURE_CONTEXT_DRIFT', fn () => $this->transfer($user, 10)); $this->assertSame($before, $this->state());
        config(['legacy_capture_shadow.currency' => 'USD']); DB::table('tx_user')->where('id', $user->id)->update(['u' => 99]); $before = $this->state();
        $this->assertFailure('CAPTURE_SOURCE_GAP', fn () => $this->transfer($user, 10)); $this->assertSame($before, $this->state());
        $report = $this->capture()->verify($user->id); $this->assertFalse($report['page_ok']);
        $this->assertContains('CAPTURE_SOURCE_GAP', $report['issues']); $this->assertFalse($report['global_coverage_complete']);
    }

    public function test_actual_bearer_http_args_enabled_storage_failure_rolls_back_and_logs_no_credentials(): void
    {
        $user = $this->user(['balance' => 100, 'commission_balance' => 200]); $this->backfill($user);
        $bearer = $user->createToken('transfer-fixture')->plainTextToken; $before = $this->state(); $armed = true;
        DB::listen(function ($query) use (&$armed): void {
            if ($armed && str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'tx_legacy_subscription_map')) {
                $armed = false; throw new \RuntimeException('transfer-fixture-storage-secret');
            }
        });
        auth()->forgetGuards(); $old = ini_set('zend.exception_ignore_args', '0');
        try {
            $this->assertSame('0', ini_get('zend.exception_ignore_args')); DB::enableQueryLog(); DB::flushQueryLog();
            $response = $this->withHeader('Authorization', 'Bearer '.$bearer)->postJson(self::URL,
                ['transfer_amount' => 100, 'ignored_secret' => 'transfer-fixture-request-secret'])->assertStatus(409)
                ->assertJsonPath('error.code', 'CONFLICT')->assertJsonPath('error.message', 'Resource conflict');
            $this->assertSame($before, $this->state()); $this->assertSame([], $this->log->getRecords());
            $queries = $this->captureQueries(); $this->assertNotEmpty($queries);
            $safe = json_encode([$response->json(), $queries, $this->log->getRecords()]);
            $this->assertNoSecrets($user, $safe); $this->assertStringNotContainsString($bearer, $safe);
            $this->postJson(self::URL, ['transfer_amount' => 100])->assertOk();
            $this->assertSame([200, 100], $this->wallets($user)); $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count());
        } finally { ini_set('zend.exception_ignore_args', $old); }
    }

    public function test_noop_ticket_requires_transaction_but_never_creates_event_or_mapping_change(): void
    {
        $user = $this->user(['commission_balance' => 1]); $this->backfill($user); $before = $this->state();
        $this->assertFailure('CAPTURE_TRANSACTION_REQUIRED', fn () => $this->capture()->beginCommissionTransfer($user->id));
        DB::transaction(function () use ($user): void {
            User::query()->lockForUpdate()->findOrFail($user->id);
            $ticket = $this->capture()->beginCommissionTransfer($user->id);
            DB::table('tx_user')->where('id', $user->id)->update(['remarks' => 'metadata only']);
            $this->capture()->appendCommissionTransfer($ticket);
        });
        $this->assertSame(0, DB::table('tx_legacy_capture_shadow_event')->count());
        $this->assertSame(0, DB::table('tx_legacy_capture_shadow_stream')->count());
        unset($before['tx_user']); $after = $this->state(); unset($after['tx_user']); $this->assertSame($before, $after);
    }

    public function test_writer_tickets_cannot_cross_use_and_owner_equality_is_enforced(): void
    {
        $user = $this->user(['commission_balance' => 100]); DB::beginTransaction();
        try {
            $ticket = $this->capture()->beginCommissionTransfer($user->id);
            foreach ([$this->capture()->begin($user->id), $this->capture()->beginVerifiedRecharge($user->id, 1),
                $this->capture()->beginOrderFulfillment($user->id, 1), $this->capture()->beginOrderCreation($user->id),
                $this->capture()->beginOrderCancellationRefund($user->id, 1, 1)] as $other) {
                $this->assertFailure('CAPTURE_WRITER_TICKET_INVALID', fn () => $this->capture()->appendCommissionTransfer($other));
            }
            foreach ([fn () => $this->capture()->append($ticket, $user->id), fn () => $this->capture()->appendVerifiedRecharge($ticket),
                fn () => $this->capture()->appendOrderFulfillment($ticket), fn () => $this->capture()->appendOrderCreation($ticket),
                fn () => $this->capture()->appendOrderCancellationRefund($ticket)] as $callback) {
                $this->assertFailure('CAPTURE_WRITER_TICKET_INVALID', $callback);
            }
            $ticket['actor_id'] = $user->id + 1;
            $this->assertFailure('CAPTURE_WRITER_TICKET_INVALID', fn () => $this->capture()->appendCommissionTransfer($ticket));
        } finally { DB::rollBack(); }
    }

    public function test_rehashed_actor_and_kind_version_tampering_are_rejected_by_read_verification(): void
    {
        $user = $this->user(['commission_balance' => 100]); $this->assertSame(200, $this->transfer($user, 1));
        $original = (array) DB::table('tx_legacy_capture_shadow_event')->first();
        foreach ([['actor_id' => $user->id + 1], ['source_kind' => 'unknown.writer'], ['source_version' => 'invented-transfer/v1']] as $change) {
            $row = array_replace($original, $change); $payload = $row; unset($payload['id'], $payload['content_hash']);
            foreach (['stream_id', 'legacy_user_id', 'sequence', 'actor_id', 'captured_at'] as $key) { $payload[$key] = (string) $payload[$key]; }
            foreach (['before_snapshot', 'after_snapshot'] as $key) { $payload[$key] = json_decode($payload[$key], true); }
            $hash = SubscriptionTermsTimeline::hash($payload);
            DB::table('tx_legacy_capture_shadow_event')->update($change + ['content_hash' => $hash]);
            DB::table('tx_legacy_capture_shadow_stream')->update(['last_event_hash' => $hash]);
            $this->assertFailure('CAPTURE_EVENT_TAMPERED', fn () => $this->capture()->verify($user->id));
            DB::table('tx_legacy_capture_shadow_event')->update($original);
        }
    }

    public function test_all_old_writer_hashes_context_and_readonly_pages_survive_transfer_append(): void
    {
        $user = $this->user(['balance' => 2000, 'commission_balance' => 200]); $admin = $this->user(['is_admin' => true]); $this->backfill($user);
        admin_setting(['secure_path' => 'transfer_capture_admin']); Sanctum::actingAs($admin);
        $this->postJson('/txapi/admin/transfer_capture_admin/users/'.$user->id.'/update', ['speed_limit' => 1])->assertOk();
        [$payment, $recharge] = $this->recharge($user);
        $this->assertTrue(app(WalletRechargeService::class)->settleVerified($recharge->trade_no, $payment->id, 'native-receipt', 1000));
        $cancelled = OrderService::createFromRequest($user, $this->plan, 'monthly');
        $this->assertTrue((new OrderService($cancelled))->cancel());
        $renewal = OrderService::createFromRequest($user, $this->plan, 'monthly');
        $this->assertTrue((new OrderService($renewal))->paid('native-order-receipt'));
        (new OrderHandleJob($renewal->trade_no))->handle();
        $this->assertSame(Order::STATUS_COMPLETED, (int) $renewal->fresh()->status);
        $old = DB::table('tx_legacy_capture_shadow_event')->orderBy('id')->get()->toJson();
        $context = DB::table('tx_legacy_capture_shadow_stream')->value('context_hash'); $projection = $this->projection();
        $this->assertSame(200, $this->transfer($user, 100));
        $this->assertSame($old, DB::table('tx_legacy_capture_shadow_event')->where('sequence', '<=', 6)->orderBy('id')->get()->toJson());
        $this->assertSame($context, DB::table('tx_legacy_capture_shadow_stream')->value('context_hash'));
        $this->assertSame($projection, $this->projection()); $before = $this->state(); DB::enableQueryLog(); DB::flushQueryLog();
        $summaries = [];
        for ($cursor = 0; $cursor < 7; $cursor++) {
            $page = $this->capture()->verify($user->id, $cursor, 1); $this->assertTrue($page['page_ok']);
            $this->assertFalse($page['projection_applied']); $this->assertFalse($page['migration_gate_open']);
            $this->assertFalse($page['global_coverage_complete']); $summaries[] = $page['events'][0];
        }
        $this->assertSame(['admin.user.update', 'wallet.recharge.settle', 'order.create', 'order.cancel.refund', 'order.create', 'order.fulfill', 'commission.transfer'],
            array_column($summaries, 'source_kind'));
        $this->assertArrayNotHasKey('capture_boundary', $summaries[0]); $this->assertArrayNotHasKey('capture_boundary', $summaries[1]);
        foreach (DB::getQueryLog() as $query) { $this->assertMatchesRegularExpression('/^select/i', $query['query']); }
        $this->assertSame($before, $this->state());
    }

    public function test_independent_mysql_insufficient_combined_funds_has_one_native_winner(): void
    {
        $this->requireFork(); $user = $this->user(['commission_balance' => 100]);
        $this->assertSame('422', $this->compete($user, 100));
        $this->assertSame([100, 0], $this->wallets($user)); $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count());
        $this->assertTrue($this->capture()->verify($user->id)['page_ok']);
    }

    public function test_independent_mysql_sufficient_combined_funds_has_two_native_winners_without_idempotency(): void
    {
        $this->requireFork(); $user = $this->user(['commission_balance' => 200]);
        $this->assertSame('200', $this->compete($user, 100));
        $this->assertSame([200, 0], $this->wallets($user)); $this->assertSame(2, DB::table('tx_legacy_capture_shadow_event')->count());
        $this->assertSame(0, DB::table('idempotency_keys')->count()); $this->assertTrue($this->capture()->verify($user->id)['page_ok']);
    }

    public function test_independent_mysql_stale_outer_mvcc_transfer_uses_locked_current_recharge_endpoint(): void
    {
        $this->requireFork(); $user = $this->user(['balance' => 500, 'commission_balance' => 200]); [$payment, $recharge] = $this->recharge($user);
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        DB::purge(); $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($parent); stream_set_timeout($child, 10); DB::beginTransaction();
            DB::table('tx_user')->where('id', $user->id)->first(); DB::table('tx_legacy_capture_shadow_stream')->get();
            fwrite($child, 'R'); fread($child, 1);
            try { $code = $this->transfer($user, 100); DB::commit(); fwrite($child, (string) $code); }
            catch (\Throwable) { DB::rollBack(); fwrite($child, 'FAIL'); }
            fclose($child); DB::disconnect(); exit(0);
        }
        $this->assertGreaterThan(0, $pid); fclose($child); stream_set_timeout($parent, 10);
        try {
            $this->assertSame('R', fread($parent, 1)); DB::beginTransaction();
            $this->assertTrue(app(WalletRechargeService::class)->settleVerified($recharge->trade_no, $payment->id, 'native-recharge-reference', 1000));
            fwrite($parent, 'S'); $this->assertWaiting($parent); DB::commit(); $this->assertSame('200', stream_get_contents($parent, 32));
        } finally { if (DB::transactionLevel()) { DB::rollBack(); } fclose($parent); pcntl_waitpid($pid, $status); }
        $this->assertSame(0, pcntl_wexitstatus($status)); $this->assertSame([1600, 100], $this->wallets($user));
        $report = $this->capture()->verify($user->id); $this->assertTrue($report['page_ok']);
        $this->assertSame(['wallet.recharge.settle', 'commission.transfer'], array_column($report['events'], 'source_kind'));
        $row = DB::table('tx_legacy_capture_shadow_event')->where('sequence', 2)->first();
        $this->assertSame('1500', json_decode($row->before_snapshot, true)['balance']);
        DB::beginTransaction(); DB::enableQueryLog(); DB::flushQueryLog();
        try {
            User::query()->lockForUpdate()->findOrFail($user->id); DB::flushQueryLog();
            $this->capture()->beginCommissionTransfer($user->id);
            $this->assertStringContainsString('lock in share mode', strtolower(DB::getQueryLog()[0]['query']));
        } finally { DB::rollBack(); }
    }

    public function test_mysql_process_death_before_and_after_commit_preserves_unkeyed_repeat_as_new_transfer(): void
    {
        $this->requireFork(); $user = $this->user(['balance' => 25, 'commission_balance' => 200]); $this->backfill($user);
        $before = $this->state(); $this->killTransfer($user, false); $this->assertSame($before, $this->state());
        $this->killTransfer($user, true); $this->assertSame([125, 100], $this->wallets($user));
        $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count());
        // The request lost its response; this native operation has no persisted success receipt.
        $this->assertSame(200, $this->transfer($user, 100)); $this->assertSame([225, 0], $this->wallets($user));
        $this->assertSame(2, DB::table('tx_legacy_capture_shadow_event')->count());
        $this->assertSame(422, $this->transfer($user, 100)); $this->assertSame(0, DB::table('idempotency_keys')->count());
        $this->assertTrue($this->capture()->verify($user->id)['page_ok']);
    }

    private function transfer(User $user, int $amount): int
    {
        Sanctum::actingAs($user);
        $request = Request::create(self::URL, 'POST', ['transfer_amount' => $amount]);
        $request->headers->set('Idempotency-Key', 'transfer-fixture-key-secret');
        return app(CommissionController::class)->transfer($request)->getStatusCode();
    }

    private function compete(User $user, int $amount): string
    {
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        DB::purge(); $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($parent); stream_set_timeout($child, 10); fread($child, 1); fwrite($child, 'R');
            try { fwrite($child, (string) $this->transfer($user, $amount)); }
            catch (\Throwable) { fwrite($child, 'FAIL'); }
            fclose($child); DB::disconnect(); exit(0);
        }
        $this->assertGreaterThan(0, $pid); fclose($child); stream_set_timeout($parent, 10);
        try {
            DB::beginTransaction(); $this->assertSame(200, $this->transfer($user, $amount)); fwrite($parent, 'S');
            $this->assertSame('R', fread($parent, 1)); $this->assertWaiting($parent); DB::commit(); $result = stream_get_contents($parent, 32);
        } finally { if (DB::transactionLevel()) { DB::rollBack(); } fclose($parent); pcntl_waitpid($pid, $status); }
        $this->assertSame(0, pcntl_wexitstatus($status)); return $result;
    }

    private function killTransfer(User $user, bool $afterCommit): void
    {
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        DB::purge(); $pid = pcntl_fork();
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
            $this->transfer($user, 100); exit(3);
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
    private function wallets(User $user): array { $fresh = $user->fresh(); return [(int) $fresh->balance, (int) $fresh->commission_balance]; }
    private function recharge(User $user): array
    {
        $payment = Payment::create(['uuid' => 'transfer-payment-'.(++$this->counter), 'payment' => 'EPay', 'name' => 'fixture', 'enable' => true, 'config' => []]);
        return [$payment, app(WalletRechargeService::class)->create($user->id, 1000, $payment->id, (string) \Illuminate\Support\Str::uuid())];
    }
    private function user(array $extra = []): User
    {
        $id = ++$this->counter;
        $anchor = Carbon::now('UTC')->startOfMonth()->addMonths(2)->day(15)->setTime(12, 0)->timestamp;
        return User::create(array_replace(['email' => 'transfer-capture-'.$id.'@example.test', 'password' => 'transfer-fixture-password',
            'uuid' => sprintf('30000000-0000-4000-8000-%012d', $id), 'token' => sprintf('%032d', $id),
            'plan_id' => $this->plan->id, 'group_id' => 1, 'transfer_enable' => 1000, 'u' => 10, 'd' => 20, 'expired_at' => $anchor], $extra));
    }
    private function backfill(User $user): void
    {
        app(SubscriptionShadowBackfill::class)->run('transfer-map-'.$user->id, 100, app(LegacySubscriptionSnapshot::class)->context('UTC', 'USD'));
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
        try { $callback(); $this->fail('Accepted invalid commission transfer evidence.'); }
        catch (LegacyCaptureShadowException $exception) { $this->assertSame($code, $exception->getMessage()); $this->assertNull($exception->getPrevious()); }
    }
    private function captureQueries(): array
    {
        return array_values(array_filter(DB::getQueryLog(), fn ($q) => preg_match('/tx_legacy_capture_shadow|tx_legacy_subscription_map/', $q['query'])));
    }
    private function assertCaptureSqlAbsent(): void { $this->assertSame([], $this->captureQueries()); }
    private function assertNoSecrets(User $user, string $text): void
    {
        foreach ([$user->email, $user->password, $user->token, $user->uuid, 'transfer-fixture-request-secret',
            'transfer-fixture-key-secret', 'transfer-fixture-storage-secret'] as $secret) { $this->assertStringNotContainsString($secret, $text); }
    }
    private function mysql(): bool { return getenv('CORE_CAPTURE_TEST_MYSQL') === '1'; }
    private function connection(): string { return $this->mysql() ? 'commission_transfer_capture_mysql_testing' : 'commission_transfer_capture_sqlite_testing'; }
    private function requireFork(): void
    {
        if (!$this->mysql() || !function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('Requires independent MariaDB sessions and process control.');
        }
    }
    private function clearMysql(): void
    {
        if (DB::connection()->getDatabaseName() !== 'txboard_concurrency_test') { throw new \RuntimeException('Commission transfer capture needs its dedicated database.'); }
        Schema::disableForeignKeyConstraints();
        try { foreach (Schema::getTableListing() as $table) { Schema::drop($table); } }
        finally { Schema::enableForeignKeyConstraints(); }
    }
}
