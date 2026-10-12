<?php

namespace Tests\Feature\Subscription;

use App\Domains\Billing\WalletRechargeService;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Plugin;
use App\Models\User;
use App\Models\WalletRecharge;
use App\Services\Plugin\HookManager;
use App\Services\Plugin\PluginManager;
use App\Services\Subscription\LegacyCaptureShadowException;
use App\Services\Subscription\LegacySubscriptionSnapshot;
use App\Services\Subscription\LegacyUserMutationCapture;
use App\Services\Subscription\SubscriptionShadowBackfill;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Tests\TestCase;

class LegacyWalletRechargeCaptureTest extends TestCase
{
    private int $counter = 0;
    private Plan $plan;
    private Payment $payment;
    private TestHandler $log;

    protected function setUp(): void
    {
        parent::setUp();
        $connection = $this->mysql() ? 'recharge_capture_mysql_testing' : 'recharge_capture_sqlite_testing';
        config(['database.default' => $connection, 'database.connections.'.$connection => $this->mysql()
            ? config('database.connections.mysql') : array_replace(config('database.connections.sqlite'),
                ['database' => ':memory:', 'foreign_key_constraints' => true])]);
        DB::purge($connection);
        if ($this->mysql()) { $this->clearMysql(); }
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        config(['core_shadow.isolated_copy' => true, 'legacy_capture_shadow.enabled' => true,
            'legacy_capture_shadow.run_key' => 'recharge-capture-fixture',
            'legacy_capture_shadow.billing_timezone' => 'UTC', 'legacy_capture_shadow.currency' => 'USD',
            'cache.stores.redis' => ['driver' => 'array']]);
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        Plugin::create(['name' => 'EPay', 'code' => 'epay', 'type' => 'payment',
            'version' => '1.0.0', 'is_enabled' => true, 'config' => '{}']);
        app()->forgetInstance(PluginManager::class); HookManager::reset();
        $this->plan = Plan::create(['name' => 'recharge capture', 'group_id' => 1, 'transfer_enable' => 1,
            'prices' => ['monthly' => 1], 'reset_traffic_method' => 0]);
        $this->payment = $this->method();
        $this->log = new TestHandler();
        $this->log->setFormatter(new LineFormatter(null, null, true, true, true));
        Log::swap(new \Illuminate\Log\Logger(new Logger('capture-recharge', [$this->log])));
    }

    protected function tearDown(): void
    {
        if ($this->mysql()) { $this->clearMysql(); }
        DB::purge($this->mysql() ? 'recharge_capture_mysql_testing' : 'recharge_capture_sqlite_testing');
        parent::tearDown();
    }

    public function test_actual_signed_callback_captures_principal_only_raw_minor_values_and_payment_actor(): void
    {
        $user = $this->user(['balance' => 1234, 'commission_balance' => 567]); $this->backfill($user);
        $recharge = $this->recharge($user); $projection = $this->projection();
        DB::enableQueryLog(); DB::flushQueryLog();
        $params = $this->signature($recharge);
        $response = $this->notifyRecharge($params)->assertOk();
        $this->assertSame('success', $response->getContent());
        $event = DB::table('tx_legacy_capture_shadow_event')->first();
        $before = json_decode($event->before_snapshot, true, flags: JSON_THROW_ON_ERROR);
        $after = json_decode($event->after_snapshot, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('1234', $before['balance']); $this->assertSame('2234', $after['balance']);
        $this->assertSame('567', $after['commission_balance']);
        $this->assertSame('wallet.recharge.settle', $event->source_kind);
        $this->assertSame('wallet-recharge-capture/v1', $event->source_version);
        $this->assertSame((string) $this->payment->id, (string) $event->actor_id);
        $map = DB::table('tx_legacy_subscription_map')->where('legacy_user_id', $user->id)->first();
        $this->assertSame('source_changed', $map->state); $this->assertSame('LEGACY_CAPTURE_PENDING', $map->error_code);
        $this->assertSame($projection, $this->projection());
        $captureBindings = [];
        foreach (DB::getQueryLog() as $query) {
            if (str_contains($query['query'], 'tx_legacy_capture_shadow')) { $captureBindings[] = $query; }
        }
        $report = $this->capture()->verify($user->id);
        $this->assertSame('admin-user-update-evidence', $report['scope']);
        $this->assertTrue($report['page_ok']); $this->assertFalse($report['projection_applied']);
        $this->assertFalse($report['global_coverage_complete']); $this->assertFalse($report['migration_gate_open']);
        $this->assertSame('verified_payment_method', $report['events'][0]['actor_domain']);
        $this->assertSame((string) $this->payment->id, $report['events'][0]['actor_id']);
        $this->assertNoSecrets($user, $recharge, $params,
            json_encode([$event, $captureBindings, $report, $this->log->getRecords()], JSON_THROW_ON_ERROR));
    }

    public function test_create_and_checkout_native_requests_do_not_credit_or_append(): void
    {
        $user = $this->user(); Sanctum::actingAs($user);
        $response = $this->postJson('/txapi/billing/recharges', ['amount_minor' => 1000,
            'payment_method_id' => $this->payment->id], ['Idempotency-Key' => '72b01300-9d18-420d-a0d6-f213147d0001'])->assertCreated();
        $this->postJson('/txapi/billing/recharges/'.$response->json('data.trade_no').'/checkout')->assertOk();
        $this->assertSame(0, (int) $user->fresh()->balance);
        $this->assertSame(0, DB::table('tx_legacy_capture_shadow_stream')->count());
        $this->assertSame(0, DB::table('tx_legacy_capture_shadow_event')->count());
    }

    public function test_duplicate_paid_callback_preserves_native_ack_without_credit_or_event_duplication(): void
    {
        $user = $this->user(); $recharge = $this->recharge($user); $params = $this->signature($recharge);
        $this->notifyRecharge($params)->assertOk(); $before = $this->state();
        DB::enableQueryLog(); DB::flushQueryLog();
        $this->assertSame('success', $this->notifyRecharge($params)->assertOk()->getContent());
        $this->assertSame($before, $this->state());
        $this->assertSame(1000, (int) $user->fresh()->balance);
        $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count());
        $this->notifyRecharge($this->signature($recharge, 'different-provider-transaction'))->assertStatus(400);
        $this->assertSame(1000, (int) $user->fresh()->balance);
    }

    public function test_invalid_signature_amount_method_and_callback_reuse_remain_native_rejections(): void
    {
        $user = $this->user(); $recharge = $this->recharge($user);
        $bad = $this->signature($recharge); $bad['sign'] = 'invalid';
        $this->notifyRecharge($bad)->assertStatus(422);
        $this->notifyRecharge($this->signature($recharge, money: '11.29'))->assertStatus(400);
        $other = $this->method('alternate-payment-uuid');
        $this->post('/txapi/payment/webhook/EPay/'.$other->uuid, $this->signature($recharge))->assertStatus(400);
        $this->assertSame(0, DB::table('tx_legacy_capture_shadow_event')->count());
        $this->notifyRecharge($this->signature($recharge))->assertOk();
        $second = $this->recharge($user);
        $this->notifyRecharge($this->signature($second))->assertStatus(400);
        $this->assertSame(WalletRecharge::STATUS_PENDING, (int) $second->fresh()->status);
        $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count());
        $this->assertSame(1000, (int) $user->fresh()->balance);
    }

    public function test_balance_limit_refuses_native_settlement_before_capture_without_wrapping_money(): void
    {
        $user = $this->user(); $recharge = $this->recharge($user);
        DB::table('tx_user')->where('id', $user->id)->update(['balance' => 2147483600]);
        $this->notifyRecharge($this->signature($recharge))->assertStatus(400);
        $this->assertSame(2147483600, (int) $user->fresh()->balance);
        $this->assertSame(WalletRecharge::STATUS_PENDING, (int) $recharge->fresh()->status);
        $this->assertSame(0, DB::table('tx_legacy_capture_shadow_event')->count());
    }

    public function test_disabled_production_settlement_keeps_ack_and_performs_zero_capture_sql(): void
    {
        $user = $this->user(); $recharge = $this->recharge($user);
        config(['legacy_capture_shadow.enabled' => false, 'core_shadow.isolated_copy' => false]);
        app()->instance('env', 'production'); DB::enableQueryLog(); DB::flushQueryLog();
        $this->assertSame('success', $this->notifyRecharge($this->signature($recharge))->assertOk()->getContent());
        $this->assertSame([], array_values(array_filter(DB::getQueryLog(), fn ($q) =>
            preg_match('/tx_legacy_capture_shadow|tx_legacy_subscription_map/', $q['query']))));
        $this->assertSame(1000, (int) $user->fresh()->balance);
        $this->assertSame(WalletRecharge::STATUS_PAID, (int) $recharge->fresh()->status);
    }

    public function test_strict_activation_and_context_drift_roll_back_settlement_with_fixed_failure_ack(): void
    {
        $user = $this->user(); $recharge = $this->recharge($user);
        config(['legacy_capture_shadow.enabled' => '1']);
        $this->assertSame('fail', $this->notifyRecharge($this->signature($recharge))->assertStatus(500)->getContent());
        $this->assertSame(0, (int) $user->fresh()->balance);
        config(['legacy_capture_shadow.enabled' => true]);
        $this->notifyRecharge($this->signature($recharge))->assertOk();
        $second = $this->recharge($user);
        config(['legacy_capture_shadow.currency' => 'CNY']);
        $this->notifyRecharge($this->signature($second, 'second-callback'))->assertStatus(500);
        $this->assertSame(1000, (int) $user->fresh()->balance);
        $this->assertSame(WalletRecharge::STATUS_PENDING, (int) $second->fresh()->status);
        $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count());
    }

    public function test_capture_failure_with_exception_arguments_enabled_has_one_fixed_log_and_atomic_rollback(): void
    {
        $user = $this->user(); $this->backfill($user); $recharge = $this->recharge($user);
        $before = $this->state(); $params = $this->signature($recharge);
        $old = ini_set('zend.exception_ignore_args', '0');
        $this->assertSame('0', ini_get('zend.exception_ignore_args'));
        DB::listen(function ($query) use ($params): void {
            if (str_starts_with(strtolower($query->sql), 'update')
                && str_contains($query->sql, 'tx_legacy_subscription_map')) {
                throw new \RuntimeException($params['trade_no'].' '.$params['sign'].' fixture-provider-secret');
            }
        });
        try {
            $response = $this->notifyRecharge($params)->assertStatus(500);
            $this->assertSame('fail', $response->getContent());
            $this->assertSame($before, $this->state());
            $records = $this->log->getRecords(); $this->assertCount(1, $records);
            $this->assertSame('LEGACY_RECHARGE_CAPTURE_FAILED', $records[0]->message);
            $this->assertSame([], $records[0]->context);
            $this->assertStringNotContainsString('stacktrace', $records[0]->formatted);
            $this->assertNoSecrets($user, $recharge, $params, $records[0]->formatted.$response->getContent());
        } finally { ini_set('zend.exception_ignore_args', $old); }
    }

    public function test_uncaptured_endpoint_gap_rolls_back_native_credit_and_recharge_paid_state(): void
    {
        $user = $this->user(); $first = $this->recharge($user);
        $this->notifyRecharge($this->signature($first))->assertOk();
        $second = $this->recharge($user);
        DB::table('tx_user')->where('id', $user->id)->increment('u'); $before = $this->state();
        $this->notifyRecharge($this->signature($second, 'second-callback'))->assertStatus(500);
        $this->assertSame($before, $this->state());
        $this->assertContains('CAPTURE_SOURCE_GAP', $this->capture()->verify($user->id)['issues']);
    }

    public function test_original_admin_hashes_and_stream_context_survive_mixed_writer_history(): void
    {
        $user = $this->user(); $admin = $this->user(['is_admin' => true]);
        admin_setting(['secure_path' => 'recharge_capture_admin']); Sanctum::actingAs($admin);
        $url = '/txapi/admin/recharge_capture_admin/users/'.$user->id.'/update';
        $this->postJson($url, ['speed_limit' => 1])->assertOk();
        $original = (array) DB::table('tx_legacy_capture_shadow_event')->first();
        $stream = DB::table('tx_legacy_capture_shadow_stream')->first();
        // Reconstruct the original B1.6 payload exactly, without new metadata in its hash.
        $payload = $original; unset($payload['id'], $payload['content_hash']);
        foreach (['stream_id', 'legacy_user_id', 'sequence', 'actor_id', 'captured_at'] as $key) { $payload[$key] = (string) $payload[$key]; }
        foreach (['before_snapshot', 'after_snapshot'] as $key) { $payload[$key] = json_decode($payload[$key], true); }
        $this->assertSame($original['content_hash'], $this->hash($payload));
        $recharge = $this->recharge($user); $this->notifyRecharge($this->signature($recharge))->assertOk();
        $this->postJson($url, ['speed_limit' => 2])->assertOk();
        $this->assertSame($original, (array) DB::table('tx_legacy_capture_shadow_event')->where('id', $original['id'])->first());
        $current = DB::table('tx_legacy_capture_shadow_stream')->first();
        $this->assertSame($stream->context, $current->context); $this->assertSame($stream->context_hash, $current->context_hash);
        $report = $this->capture()->verify($user->id);
        $this->assertTrue($report['page_ok']); $this->assertSame('3', $report['high_water']);
        $this->assertSame(['admin_user', 'verified_payment_method', 'admin_user'], array_column($report['events'], 'actor_domain'));
        $this->assertSame([(string) $admin->id, (string) $this->payment->id, (string) $admin->id], array_column($report['events'], 'actor_id'));
    }

    public function test_writer_specific_tickets_reject_cross_use_without_source_or_event_changes(): void
    {
        $user = $this->user(); DB::beginTransaction();
        try {
            User::query()->whereKey($user->id)->lockForUpdate()->first();
            $admin = $this->capture()->begin($user->id);
            $recharge = $this->capture()->beginVerifiedRecharge($user->id, $this->payment->id);
            $this->assertFailure('CAPTURE_WRITER_TICKET_INVALID', fn () => $this->capture()->appendVerifiedRecharge($admin));
            $this->assertFailure('CAPTURE_WRITER_TICKET_INVALID', fn () => $this->capture()->append($recharge, $user->id));
            $recharge['source_version'] = 'arbitrary-version';
            $this->assertFailure('CAPTURE_WRITER_TICKET_INVALID', fn () => $this->capture()->appendVerifiedRecharge($recharge));
            $this->assertSame(0, DB::table('tx_legacy_capture_shadow_event')->count());
        } finally { DB::rollBack(); }
    }

    public function test_unknown_policy_and_wrong_version_are_rejected_even_with_recomputed_hash(): void
    {
        $user = $this->user(); $recharge = $this->recharge($user); $this->notifyRecharge($this->signature($recharge))->assertOk();
        $row = (array) DB::table('tx_legacy_capture_shadow_event')->first();
        foreach ([['source_kind' => 'payment.unapproved'], ['source_version' => 'unapproved/v1']] as $change) {
            $payload = array_replace($row, $change); unset($payload['id'], $payload['content_hash']);
            foreach (['stream_id', 'legacy_user_id', 'sequence', 'actor_id', 'captured_at'] as $key) { $payload[$key] = (string) $payload[$key]; }
            foreach (['before_snapshot', 'after_snapshot'] as $key) { $payload[$key] = json_decode($payload[$key], true); }
            $hash = $this->hash($payload);
            DB::table('tx_legacy_capture_shadow_event')->where('id', $row['id'])->update(array_replace($row, $change, ['content_hash' => $hash]));
            DB::table('tx_legacy_capture_shadow_stream')->update(['last_event_hash' => $hash]);
            $this->assertFailure('CAPTURE_EVENT_TAMPERED', fn () => $this->capture()->verify($user->id, 1));
        }
    }

    public function test_mixed_policy_bounded_pages_are_select_only_and_never_apply_projection(): void
    {
        $user = $this->user(); $this->backfill($user);
        foreach (['receipt-one', 'receipt-two'] as $receipt) {
            $recharge = $this->recharge($user); $this->notifyRecharge($this->signature($recharge, $receipt))->assertOk();
        }
        $before = $this->state(); DB::enableQueryLog(); DB::flushQueryLog();
        $one = $this->capture()->verify($user->id, 0, 1);
        $two = $this->capture()->verify($user->id, 1, 1);
        $empty = $this->capture()->verify($user->id, 2, 1);
        foreach ([$one, $two, $empty] as $report) {
            $this->assertTrue($report['read_only']); $this->assertTrue($report['page_ok']);
            $this->assertFalse($report['projection_applied']); $this->assertFalse($report['migration_gate_open']);
        }
        $this->assertTrue($one['has_more']); $this->assertFalse($two['has_more']); $this->assertSame([], $empty['events']);
        foreach (DB::getQueryLog() as $query) { $this->assertMatchesRegularExpression('/^select/i', $query['query']); }
        $this->assertSame($before, $this->state());
    }

    public function test_independent_mysql_duplicate_callback_waits_then_returns_native_success_once(): void
    {
        $this->requireFork(); $user = $this->user(); $recharge = $this->recharge($user);
        $params = $this->signature($recharge);
        $this->concurrentCallbacks($params, $params);
        $this->assertSame(1000, (int) $user->fresh()->balance);
        $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count());
        $this->assertTrue($this->capture()->verify($user->id)['page_ok']);
    }

    public function test_independent_mysql_two_recharges_serialize_user_endpoint_and_contiguous_stream(): void
    {
        $this->requireFork(); $user = $this->user();
        $one = $this->recharge($user); $two = $this->recharge($user);
        $this->concurrentCallbacks($this->signature($one, 'first-receipt'), $this->signature($two, 'second-receipt'));
        $this->assertSame(2000, (int) $user->fresh()->balance);
        $this->assertSame(2, WalletRecharge::query()->where('status', WalletRecharge::STATUS_PAID)->count());
        $report = $this->capture()->verify($user->id);
        $this->assertTrue($report['page_ok']); $this->assertSame(['1', '2'], array_column($report['events'], 'sequence'));
    }

    public function test_independent_mysql_admin_and_recharge_keep_one_shared_stream_without_reverse_lock(): void
    {
        $this->requireFork(); $user = $this->user(); $admin = $this->user(['is_admin' => true]);
        $recharge = $this->recharge($user); $params = $this->signature($recharge);
        admin_setting(['secure_path' => 'recharge_capture_admin']); Sanctum::actingAs($admin);
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        DB::purge(); $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($parent); stream_set_timeout($child, 10); fread($child, 1); fwrite($child, 'R');
            $response = $this->notifyRecharge($params);
            fwrite($child, json_encode(['status' => $response->status(), 'body' => $response->getContent()]));
            fclose($child); DB::disconnect(); exit(0);
        }
        $this->assertGreaterThan(0, $pid); fclose($child); stream_set_timeout($parent, 10);
        try {
            DB::beginTransaction();
            $this->postJson('/txapi/admin/recharge_capture_admin/users/'.$user->id.'/update', ['speed_limit' => 5])->assertOk();
            fwrite($parent, 'S'); $this->assertSame('R', fread($parent, 1)); $this->assertWaiting($parent);
            DB::commit(); $result = json_decode(stream_get_contents($parent, 8192), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(['status' => 200, 'body' => 'success'], $result);
        } finally {
            if (DB::transactionLevel()) { DB::rollBack(); }
            fclose($parent); pcntl_waitpid($pid, $status);
        }
        $this->assertSame(0, pcntl_wexitstatus($status));
        $report = $this->capture()->verify($user->id);
        $this->assertTrue($report['page_ok']);
        $this->assertSame(['admin_user', 'verified_payment_method'], array_column($report['events'], 'actor_domain'));
    }

    public function test_mysql_process_death_before_and_after_commit_preserves_atomic_settlement_recovery(): void
    {
        $this->requireFork(); $user = $this->user(); $this->backfill($user); $recharge = $this->recharge($user);
        $params = $this->signature($recharge); $before = $this->state();
        $this->killSettlement($params, false); $this->assertSame($before, $this->state());
        $this->killSettlement($params, true);
        $this->assertSame(1000, (int) $user->fresh()->balance);
        $this->assertSame(WalletRecharge::STATUS_PAID, (int) $recharge->fresh()->status);
        $this->assertSame('source_changed', DB::table('tx_legacy_subscription_map')->where('legacy_user_id', $user->id)->value('state'));
        $committed = $this->state();
        $this->assertTrue($this->capture()->verify($user->id)['page_ok']);
        $this->notifyRecharge($params)->assertOk(); $this->assertSame($committed, $this->state());
        $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count());
    }

    private function concurrentCallbacks(array $one, array $two): void
    {
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        DB::purge(); $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($parent); stream_set_timeout($child, 10); fread($child, 1); fwrite($child, 'R');
            $response = $this->notifyRecharge($two);
            fwrite($child, json_encode(['status' => $response->status(), 'body' => $response->getContent()]));
            fclose($child); DB::disconnect(); exit(0);
        }
        $this->assertGreaterThan(0, $pid); fclose($child); stream_set_timeout($parent, 10);
        try {
            DB::beginTransaction(); $this->notifyRecharge($one)->assertOk();
            fwrite($parent, 'S'); $this->assertSame('R', fread($parent, 1)); $this->assertWaiting($parent);
            DB::commit(); $result = json_decode(stream_get_contents($parent, 8192), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(['status' => 200, 'body' => 'success'], $result);
        } finally {
            if (DB::transactionLevel()) { DB::rollBack(); }
            fclose($parent); pcntl_waitpid($pid, $status);
        }
        $this->assertSame(0, pcntl_wexitstatus($status));
    }

    private function killSettlement(array $params, bool $afterCommit): void
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
            $this->notifyRecharge($params); exit(3);
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
    private function assertNoSecrets(User $user, WalletRecharge $recharge, array $params, string $text): void
    {
        foreach ([$user->email, $user->password, $user->token, $user->uuid, $recharge->trade_no,
            $this->payment->uuid, $params['trade_no'], $params['sign'], 'fixture-provider-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }
    }
    private function user(array $overrides = []): User
    {
        $id = ++$this->counter;
        return User::create(array_replace(['email' => 'recharge-capture-'.$id.'@example.test',
            'password' => 'recharge-fixture-password', 'uuid' => sprintf('00000000-0000-4000-8000-%012d', $id),
            'token' => sprintf('%032d', $id), 'plan_id' => $this->plan->id, 'group_id' => 1,
            'transfer_enable' => 1000, 'u' => 10, 'd' => 20, 'expired_at' => time() + 86400], $overrides));
    }
    private function method(string $uuid = 'recharge-capture-payment-uuid'): Payment
    {
        return Payment::create(['uuid' => $uuid, 'payment' => 'EPay', 'name' => 'Capture EPay',
            'enable' => true, 'handling_fee_fixed' => 30, 'handling_fee_percent' => 10,
            'config' => ['pid' => 'capture-merchant', 'key' => 'fixture-provider-secret', 'url' => 'https://payment.invalid']]);
    }
    private function recharge(User $user): WalletRecharge
    {
        return app(WalletRechargeService::class)->create($user->id, 1000, $this->payment->id,
            (string) \Illuminate\Support\Str::uuid());
    }
    private function signature(WalletRecharge $recharge, string $receipt = 'recharge-capture-receipt', string $money = '11.30'): array
    {
        $params = ['pid' => 'capture-merchant', 'out_trade_no' => $recharge->trade_no,
            'trade_no' => $receipt, 'money' => $money, 'trade_status' => 'TRADE_SUCCESS'];
        ksort($params); $params['sign'] = md5(stripslashes(urldecode(http_build_query($params))).'fixture-provider-secret');
        $params['sign_type'] = 'MD5'; return $params;
    }
    private function notifyRecharge(array $params): \Illuminate\Testing\TestResponse
    {
        return $this->post('/txapi/payment/webhook/EPay/'.$this->payment->uuid, $params);
    }
    private function backfill(User $user): void
    {
        app(SubscriptionShadowBackfill::class)->run('recharge-capture-map-'.$user->id, 100,
            app(LegacySubscriptionSnapshot::class)->context('UTC', 'USD'));
    }
    private function capture(): LegacyUserMutationCapture { return app(LegacyUserMutationCapture::class); }
    private function hash(array $value): string { return \App\Services\Subscription\SubscriptionTermsTimeline::hash($value); }
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
        foreach (['tx_user', 'tx_wallet_recharge', 'tx_legacy_capture_shadow_stream',
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
            throw new \RuntimeException('Recharge capture fixture requires its dedicated database.');
        }
        Schema::disableForeignKeyConstraints();
        try { foreach (Schema::getTableListing() as $table) { Schema::drop($table); } }
        finally { Schema::enableForeignKeyConstraints(); }
    }
}
