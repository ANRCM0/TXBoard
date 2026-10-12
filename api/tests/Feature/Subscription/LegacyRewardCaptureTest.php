<?php

namespace Tests\Feature\Subscription;

use App\Console\Commands\CheckCommission;
use App\Exceptions\ApiException;
use App\Models\GiftCardCode;
use App\Models\GiftCardTemplate;
use App\Models\Order;
use App\Models\Plan;
use App\Models\TrafficResetLog;
use App\Models\User;
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

class LegacyRewardCaptureTest extends TestCase
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
            'legacy_capture_shadow.run_key' => 'reward-fixture', 'legacy_capture_shadow.billing_timezone' => 'UTC',
            'legacy_capture_shadow.currency' => 'USD']);
        Bus::fake(); HookManager::reset();
        admin_setting(['commission_distribution_enable' => 0, 'withdraw_close_enable' => 0,
            'commission_auto_check_enable' => 0]);
    }

    protected function tearDown(): void
    {
        HookManager::reset(); if ($this->mysql()) $this->clearMysql();
        DB::purge($this->connection()); parent::tearDown();
    }

    public function test_commission_exact_endpoints_native_records_mapping_and_readonly_chain(): void
    {
        $inviter = $this->user(); $buyer = $this->user(); $order = $this->order($buyer, $inviter);
        $this->backfill($inviter); $projection = $this->projection(); $before = $this->source($inviter);
        (new CheckCommission())->autoPayCommission();
        $event = DB::table('tx_legacy_capture_shadow_event')->first();
        $this->assertSame($before, json_decode($event->before_snapshot, true));
        $this->assertSame($this->source($inviter), json_decode($event->after_snapshot, true));
        $this->assertSame([100, 120], $this->wallets($inviter));
        $this->assertSame([2, 100], [(int) $order->fresh()->commission_status, (int) $order->fresh()->actual_commission_balance]);
        $this->assertSame(1, DB::table('tx_commission_log')->count());
        $this->assertSame('source_changed', DB::table('tx_legacy_subscription_map')->where('legacy_user_id', $inviter->id)->value('state'));
        $this->assertSame($projection, $this->projection());
        DB::enableQueryLog(); DB::flushQueryLog(); $page = $this->capture()->verify($inviter->id);
        foreach (DB::getQueryLog() as $query) $this->assertMatchesRegularExpression('/^select/i', $query['query']);
        $this->assertTrue($page['page_ok']); $summary = $page['events'][0];
        $this->assertSame(['commission.distribute', 'commission-distribution/v1', 'system_commission_distribution', (string) $order->id],
            [$summary['source_kind'], $summary['source_version'], $summary['actor_domain'], $summary['actor_id']]);
        $this->assertSafe($inviter, json_encode([$event, $page]), [$order->trade_no, $order->callback_no]);
        foreach (['global_coverage_complete', 'projection_applied', 'migration_gate_open'] as $flag) $this->assertFalse($page[$flag]);
    }

    public function test_three_native_levels_wallet_mode_and_self_cycle_create_actual_contiguous_events(): void
    {
        admin_setting(['commission_distribution_enable' => 1, 'commission_distribution_l1' => 50,
            'commission_distribution_l2' => 30, 'commission_distribution_l3' => 20, 'withdraw_close_enable' => 1]);
        $last = $this->user(); $middle = $this->user(['invite_user_id' => $last->id]);
        $first = $this->user(['invite_user_id' => $middle->id]); $buyer = $this->user();
        $order = $this->order($buyer, $first); (new CheckCommission())->autoPayCommission();
        foreach ([[$first, 150], [$middle, 130], [$last, 120]] as [$user, $balance]) {
            $this->assertSame([$balance, 20], $this->wallets($user)); $this->assertTrue($this->capture()->verify($user->id)['page_ok']);
        }
        $this->assertSame(3, DB::table('tx_commission_log')->count());
        $this->assertSame(100, (int) $order->fresh()->actual_commission_balance);
        $cycle = $this->user(); $cycle->update(['invite_user_id' => $cycle->id]);
        $this->order($buyer, $cycle); (new CheckCommission())->autoPayCommission();
        $this->assertSame([200, 20], $this->wallets($cycle));
        $page = $this->capture()->verify($cycle->id); $this->assertTrue($page['page_ok']); $this->assertCount(3, $page['events']);
    }

    public function test_commission_missing_zero_and_absent_share_skip_invalid_capture_context(): void
    {
        $buyer = $this->user(); $inviter = $this->user();
        $this->order($buyer, $inviter, ['commission_balance' => 0]);
        $this->order($buyer, $inviter, ['invite_user_id' => 2147483647]);
        admin_setting(['commission_distribution_enable' => 1, 'commission_distribution_l1' => 0,
            'commission_distribution_l2' => 0, 'commission_distribution_l3' => 0]);
        $this->order($buyer, $inviter); config(['legacy_capture_shadow.enabled' => 'invalid']);
        DB::enableQueryLog(); DB::flushQueryLog(); (new CheckCommission())->autoPayCommission();
        $this->assertNoCaptureQueries(); $this->assertSame(3, Order::where('commission_status', 2)->count());
        $this->assertSame([100, 20], $this->wallets($inviter));
    }

    public function test_second_commission_recipient_failure_rolls_back_entire_current_order_but_retains_earlier_order(): void
    {
        $buyer = $this->user(); $prior = $this->user(); $tail = $this->user(); $head = $this->user(['invite_user_id' => $tail->id]);
        $firstOrder = $this->order($buyer, $prior); $failedOrder = $this->order($buyer, $head);
        admin_setting(['commission_distribution_enable' => 1, 'commission_distribution_l1' => 50,
            'commission_distribution_l2' => 50, 'commission_distribution_l3' => 0]);
        $inserts = 0; $active = true;
        DB::listen(function ($query) use (&$inserts, &$active): void {
            if ($active && preg_match('/^insert.*tx_legacy_capture_shadow_event/i', $query->sql) && ++$inserts === 3)
                throw new \RuntimeException('private-reward-storage-detail');
        });
        $this->failure('CAPTURE_STORAGE_OR_EVIDENCE_FAILED', fn () => (new CheckCommission())->autoPayCommission()); $active = false;
        $this->assertSame([100, 70], $this->wallets($prior)); $this->assertSame([100, 20], $this->wallets($head));
        $this->assertSame([100, 20], $this->wallets($tail));
        $this->assertSame(2, (int) $firstOrder->fresh()->commission_status); $this->assertSame(1, (int) $failedOrder->fresh()->commission_status);
        $this->assertSame(1, DB::table('tx_commission_log')->count()); $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count());
    }

    public function test_commission_native_fraction_assignment_is_actual_persisted_value_or_refused(): void
    {
        $buyer = $this->user(); $inviter = $this->user();
        admin_setting(['commission_distribution_enable' => 1, 'commission_distribution_l1' => 25,
            'commission_distribution_l2' => 0, 'commission_distribution_l3' => 0]);
        $order = $this->order($buyer, $inviter, ['commission_balance' => 10]);
        config(['legacy_capture_shadow.enabled' => false]); (new CheckCommission())->autoPayCommission();
        $native = DB::table('tx_user')->where('id', $inviter->id)->value('commission_balance');
        $nativeTotal = $order->fresh()->actual_commission_balance;
        DB::table('tx_user')->where('id', $inviter->id)->update(['commission_balance' => 20]);
        $order->refresh()->update(['commission_status' => 1, 'actual_commission_balance' => 0]); config(['legacy_capture_shadow.enabled' => true]);
        if ($this->mysql()) {
            (new CheckCommission())->autoPayCommission();
            $this->assertSame($native, DB::table('tx_user')->where('id', $inviter->id)->value('commission_balance'));
            $this->assertSame($nativeTotal, $order->fresh()->actual_commission_balance); $this->assertTrue($this->capture()->verify($inviter->id)['page_ok']);
        } else {
            $this->assertSame(22.5, $native);
            $this->failure('CAPTURE_SOURCE_INVALID', fn () => (new CheckCommission())->autoPayCommission());
            $this->assertSame([100, 20], $this->wallets($inviter)); $this->assertSame(1, (int) $order->fresh()->commission_status);
        }
    }

    public function test_direct_commission_mutation_requires_transaction_and_source_gaps_reject(): void
    {
        $buyer = $this->user(); $inviter = $this->user(); $order = $this->order($buyer, $inviter);
        $this->failure('CAPTURE_TRANSACTION_REQUIRED', fn () => (new CheckCommission())->payHandle($inviter->id, $order));
        $this->assertSame([100, 20], $this->wallets($inviter));
        (new CheckCommission())->autoPayCommission(); DB::table('tx_user')->where('id', $inviter->id)->increment('balance');
        $next = $this->order($buyer, $inviter); $before = $this->source($inviter);
        $this->failure('CAPTURE_SOURCE_GAP', fn () => (new CheckCommission())->autoPayCommission());
        $this->assertSame($before, $this->source($inviter)); $this->assertSame(1, (int) $next->fresh()->commission_status);
    }

    public function test_gift_primary_and_inviter_exact_rewards_code_usage_and_mapping_commit_together(): void
    {
        $plan = $this->plan(); $inviter = $this->user(); $user = $this->user(['plan_id' => $plan->id, 'invite_user_id' => $inviter->id]);
        $this->backfill($user); $projection = $this->projection(); $before = $this->source($user);
        $code = $this->code(['balance' => 101, 'transfer_enable' => 103, 'device_limit' => 2, 'expire_days' => 2, 'invite_reward_rate' => 0.2]);
        $expires = (int) $user->expired_at; $result = $this->redeem($code, $user);
        $this->assertSame([201, 20], $this->wallets($user)); $this->assertSame([120, 20], $this->wallets($inviter));
        $this->assertSame(1103, (int) $user->fresh()->transfer_enable); $this->assertSame(1020, (int) $inviter->fresh()->transfer_enable);
        $this->assertSame($expires + 172800, (int) $user->fresh()->expired_at); $this->assertSame(2, (int) $user->fresh()->device_limit);
        $this->assertSame(['balance' => 20, 'transfer_enable' => 20], $result['invite_rewards']);
        $this->assertSame(1, (int) $code->fresh()->usage_count); $this->assertSame(1, DB::table('tx_gift_card_usage')->count());
        $event = DB::table('tx_legacy_capture_shadow_event')->where('legacy_user_id', $user->id)->first();
        $this->assertSame($before, json_decode($event->before_snapshot, true));
        $persistedAfter = $this->source($user);
        $this->assertNotSame($before['next_reset_at'], $persistedAfter['next_reset_at']);
        // The native after-commit observer recalculates the schedule outside this ticket.
        $persistedAfter['next_reset_at'] = $before['next_reset_at'];
        $this->assertSame($persistedAfter, json_decode($event->after_snapshot, true));
        $this->assertSame($projection, $this->projection());
        $this->assertSame('source_changed', DB::table('tx_legacy_subscription_map')->where('legacy_user_id', $user->id)->value('state'));
        foreach ([[$user, 'gift.reward.apply'], [$inviter, 'gift.invite.reward']] as [$recipient, $kind]) {
            $page = $this->capture()->verify($recipient->id);
            if ($recipient->id === $user->id) {
                $this->assertFalse($page['page_ok']); $this->assertContains('CAPTURE_SOURCE_GAP', $page['issues']);
            } else $this->assertTrue($page['page_ok']);
            $this->assertSame($kind, $page['events'][0]['source_kind']); $this->assertSame((string) $code->id, $page['events'][0]['actor_id']);
            $this->assertSafe($recipient, json_encode($page), [$code->code]);
        }
    }

    public function test_gift_single_use_rejects_before_capture_and_reusable_code_rewards_twice(): void
    {
        $user = $this->user(); $single = $this->code(['balance' => 10]); $this->redeem($single, $user);
        config(['legacy_capture_shadow.enabled' => 'invalid']); DB::enableQueryLog(); DB::flushQueryLog();
        try { $this->redeem($single, $user); $this->fail('Consumed code accepted.'); } catch (ApiException) {}
        $this->assertNoCaptureQueries(); config(['legacy_capture_shadow.enabled' => true]);
        $multi = $this->code(['balance' => 10], ['max_usage' => 2]);
        $this->redeem($multi, $user); $this->redeem($multi, $user);
        $this->assertSame([130, 20], $this->wallets($user)); $this->assertSame(2, (int) $multi->fresh()->usage_count);
        $this->assertCount(3, $this->capture()->verify($user->id)['events']);
    }

    public function test_gift_self_invitation_and_zero_or_missing_inviter_keep_native_behavior(): void
    {
        $user = $this->user(); $user->update(['invite_user_id' => $user->id]);
        $this->redeem($this->code(['balance' => 10, 'invite_reward_rate' => 0.2]), $user);
        $page = $this->capture()->verify($user->id); $this->assertTrue($page['page_ok']);
        $this->assertSame(['gift.reward.apply', 'gift.invite.reward'], array_column($page['events'], 'source_kind'));
        $this->assertSame([112, 20], $this->wallets($user));
        $other = $this->user(['invite_user_id' => 2147483647]);
        $this->assertNull($this->redeem($this->code(['balance' => 10, 'invite_reward_rate' => 0.2]), $other)['invite_rewards']);
        $zero = $this->code(['balance' => 0, 'invite_reward_rate' => 0.2]); $this->redeem($zero, $user);
        $this->assertCount(2, $this->capture()->verify($user->id)['events']); $this->assertSame(1, (int) $zero->fresh()->usage_count);
    }

    public function test_gift_nested_reset_plan_and_native_mystery_festival_reward_are_captured_once(): void
    {
        $plan = $this->plan(); $user = $this->user(['plan_id' => $plan->id]);
        $this->redeem($this->code(['reset_package' => true]), $user);
        $this->assertSame([0, 0], [(int) $user->fresh()->u, (int) $user->fresh()->d]);
        $this->assertSame(1, TrafficResetLog::count()); $this->assertCount(1, $this->capture()->verify($user->id)['events']);
        $mystery = $this->code(['random_rewards' => [['weight' => 1, 'balance' => 11]]], [],
            ['type' => GiftCardTemplate::TYPE_MYSTERY, 'special_config' => ['festival_bonus' => 2, 'start_time' => time()-100, 'end_time' => time()+100]]);
        $this->assertSame(22, $this->redeem($mystery, $user)['rewards']['balance']); $this->assertSame(22, $mystery->fresh()->actual_rewards['balance']);
        $other = $this->user(['expired_at' => time()-100]);
        $code = $this->code(['plan_id' => $plan->id, 'plan_validity_days' => 3], [], ['type' => GiftCardTemplate::TYPE_PLAN]);
        $this->redeem($code, $other); $other->refresh();
        $this->assertSame($plan->id, (int) $other->plan_id); $this->assertSame(10737418240, (int) $other->transfer_enable);
        $this->assertSame(3, (int) $other->device_limit); $this->assertGreaterThan(time()+259190, (int) $other->expired_at);
        $page = $this->capture()->verify($other->id);
        $this->assertCount(1, $page['events']); $this->assertContains('CAPTURE_SOURCE_GAP', $page['issues']);
    }

    public function test_late_gift_usage_failure_rolls_back_both_users_events_maps_and_code_consumption(): void
    {
        $inviter = $this->user(); $user = $this->user(['invite_user_id' => $inviter->id]); $this->backfill($user);
        $map = DB::table('tx_legacy_subscription_map')->orderBy('id')->get()->toJson(); $code = $this->code(['balance' => 100, 'invite_reward_rate' => 0.2]);
        $active = true; DB::listen(function ($query) use (&$active): void {
            if ($active && preg_match('/^insert.*tx_gift_card_usage/i', $query->sql)) throw new \RuntimeException('native-late-usage-failure');
        });
        try { $this->redeem($code, $user); $this->fail('Late SQL failure accepted.'); }
        catch (\RuntimeException $e) { $this->assertSame('native-late-usage-failure', $e->getMessage()); } $active = false;
        $this->assertSame([100, 20], $this->wallets($user)); $this->assertSame([100, 20], $this->wallets($inviter));
        $this->assertSame(0, (int) $code->fresh()->usage_count); $this->assertSame(0, DB::table('tx_gift_card_usage')->count());
        $this->assertSame(0, DB::table('tx_legacy_capture_shadow_event')->count());
        $this->assertSame($map, DB::table('tx_legacy_subscription_map')->orderBy('id')->get()->toJson());
    }

    public function test_gift_inviter_capture_failure_and_source_gap_roll_back_original_transaction(): void
    {
        $inviter = $this->user(); $user = $this->user(['invite_user_id' => $inviter->id]);
        $this->redeem($this->code(['balance' => 10]), $inviter);
        DB::table('tx_user')->where('id', $inviter->id)->increment('balance'); $before = $this->source($inviter);
        $code = $this->code(['balance' => 100, 'invite_reward_rate' => 0.2]);
        $this->failure('CAPTURE_SOURCE_GAP', fn () => $this->redeem($code, $user));
        $this->assertSame([100, 20], $this->wallets($user)); $this->assertSame($before, $this->source($inviter));
        $this->assertSame(0, (int) $code->fresh()->usage_count); $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count());
    }

    public function test_disabled_production_both_writers_add_zero_capture_sql(): void
    {
        $user = $this->user(); $buyer = $this->user(); $this->order($buyer, $user); $code = $this->code(['balance' => 10]);
        config(['legacy_capture_shadow.enabled' => false, 'core_shadow.isolated_copy' => false]); $this->app['env'] = 'production';
        Schema::drop('tx_legacy_capture_shadow_event'); Schema::drop('tx_legacy_capture_shadow_stream');
        DB::enableQueryLog(); DB::flushQueryLog(); (new CheckCommission())->autoPayCommission(); $this->redeem($code, $user);
        $this->assertNoCaptureQueries(); $this->assertSame([110, 120], $this->wallets($user));
    }

    public function test_gift_native_ignored_code_save_result_keeps_rewards_and_does_not_invent_consumption(): void
    {
        $user = $this->user(); $code = $this->code(['balance' => 10]);
        $name = 'eloquent.saving: '.GiftCardCode::class;
        Event::listen($name, static fn (GiftCardCode $row) => $row->status === GiftCardCode::STATUS_USED ? false : null);
        try {
            $this->redeem($code, $user); $this->redeem($code, $user);
        } finally { Event::forget($name); }
        $this->assertSame([120, 20], $this->wallets($user)); $this->assertSame(0, (int) $code->fresh()->usage_count);
        $this->assertSame(2, DB::table('tx_gift_card_usage')->count()); $page = $this->capture()->verify($user->id);
        $this->assertTrue($page['page_ok']); $this->assertCount(2, $page['events']);
    }

    public function test_fixed_reward_tickets_reject_cross_writer_use(): void
    {
        $user = $this->user(); $buyer = $this->user(); $order = $this->order($buyer, $user); $code = $this->code(['balance' => 10]);
        DB::transaction(function () use ($user, $order, $code): void {
            User::lockForUpdate()->findOrFail($user->id); $capture = $this->capture();
            $primary = $capture->beginGiftReward($user->id, $code->id);
            $invite = $capture->beginGiftInviteReward($user->id, $code->id);
            $commission = $capture->beginCommissionDistribution($user->id, $order->id);
            $this->failure('CAPTURE_WRITER_TICKET_INVALID', fn () => $capture->appendGiftInviteReward($primary));
            $this->failure('CAPTURE_WRITER_TICKET_INVALID', fn () => $capture->appendGiftReward($invite));
            $this->failure('CAPTURE_WRITER_TICKET_INVALID', fn () => $capture->appendGiftReward($commission));
            $this->failure('CAPTURE_WRITER_TICKET_INVALID', fn () => $capture->appendCommissionDistribution($primary));
        });
        $this->assertSame(0, DB::table('tx_legacy_capture_shadow_event')->count());
    }

    public function test_independent_mysql_stale_commission_work_still_pays_twice_with_contiguous_evidence(): void
    {
        $this->requireFork(); $inviter = $this->user(); $buyer = $this->user(); $order = $this->order($buyer, $inviter);
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP); DB::purge(); $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($parent); stream_set_timeout($child, 10); $stale = Order::findOrFail($order->id); fwrite($child, 'R'); fread($child, 1);
            try { DB::transaction(function () use ($stale): void {
                (new CheckCommission())->payHandle($stale->invite_user_id, $stale); $stale->commission_status = 2; $stale->save();
            }); fwrite($child, 'OK'); } catch (\Throwable) { fwrite($child, 'FAIL'); }
            fclose($child); DB::disconnect(); exit(0);
        }
        $this->assertGreaterThan(0, $pid); fclose($child); stream_set_timeout($parent, 10);
        try {
            $this->assertSame('R', fread($parent, 1)); DB::beginTransaction(); (new CheckCommission())->autoPayCommission(); fwrite($parent, 'S');
            $read = [$parent]; $write = []; $except = []; $this->assertSame(0, stream_select($read, $write, $except, 0, 250000));
            DB::commit(); $this->assertSame('OK', stream_get_contents($parent, 32));
        } finally { if (DB::transactionLevel()) DB::rollBack(); fclose($parent); pcntl_waitpid($pid, $status); }
        $this->assertSame(0, pcntl_wexitstatus($status)); $this->assertSame([100, 220], $this->wallets($inviter));
        $this->assertSame(2, DB::table('tx_commission_log')->count()); $page = $this->capture()->verify($inviter->id);
        $this->assertTrue($page['page_ok']); $this->assertCount(2, $page['events']);
    }

    public function test_independent_mysql_single_use_gift_current_lock_rejects_second_stale_candidate(): void
    {
        $this->requireFork(); $user = $this->user(); $code = $this->code(['balance' => 10]);
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP); DB::purge(); $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($parent); stream_set_timeout($child, 10); $service = (new GiftCardService($code->code))->setUser($user); fwrite($child, 'R'); fread($child, 1);
            try { $service->redeem(); fwrite($child, 'BAD'); } catch (ApiException) { fwrite($child, 'NO'); } catch (\Throwable) { fwrite($child, 'FAIL'); }
            fclose($child); DB::disconnect(); exit(0);
        }
        $this->assertGreaterThan(0, $pid); fclose($child); stream_set_timeout($parent, 10);
        try {
            $this->assertSame('R', fread($parent, 1)); DB::beginTransaction(); $this->redeem($code, $user); fwrite($parent, 'S');
            $read = [$parent]; $write = []; $except = []; $this->assertSame(0, stream_select($read, $write, $except, 0, 250000));
            DB::commit(); $this->assertSame('NO', stream_get_contents($parent, 32));
        } finally { if (DB::transactionLevel()) DB::rollBack(); fclose($parent); pcntl_waitpid($pid, $status); }
        $this->assertSame(0, pcntl_wexitstatus($status)); $this->assertSame([110, 20], $this->wallets($user));
        $this->assertSame(1, (int) $code->fresh()->usage_count); $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count());
    }

    public function test_mysql_sigkill_gift_before_and_after_commit_preserves_native_consumption_and_evidence(): void
    {
        $this->requireFork(); $user = $this->user(); $code = $this->code(['balance' => 10]); $this->backfill($user);
        $this->killGift($user, $code, false); $this->assertSame([100, 20], $this->wallets($user));
        $this->assertSame(0, (int) $code->fresh()->usage_count); $this->assertSame(0, DB::table('tx_legacy_capture_shadow_event')->count());
        $this->killGift($user, $code, true); $this->assertSame([110, 20], $this->wallets($user));
        $this->assertSame(1, (int) $code->fresh()->usage_count); $this->assertSame(1, DB::table('tx_gift_card_usage')->count());
        $this->assertTrue($this->capture()->verify($user->id)['page_ok']);
        try { $this->redeem($code, $user); $this->fail('Committed single-use code replayed.'); } catch (ApiException) {}
        $this->assertSame([110, 20], $this->wallets($user));
    }

    private function killGift(User $user, GiftCardCode $code, bool $afterCommit): void
    {
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP); DB::purge(); $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($parent); $barrier = function () use ($child): void { fwrite($child, 'K'); while (true) usleep(10000); };
            if ($afterCommit) Event::listen(TransactionCommitted::class, function ($event) use ($barrier): void {
                if ($event->connection->transactionLevel() === 0) $barrier();
            });
            else DB::listen(function ($query) use ($barrier): void {
                if (preg_match('/^insert.*tx_gift_card_usage/i', $query->sql)) $barrier();
            });
            $this->redeem($code, $user); exit(2);
        }
        $this->assertGreaterThan(0, $pid); fclose($child); stream_set_timeout($parent, 10);
        try { $this->assertSame('K', fread($parent, 1)); }
        finally { posix_kill($pid, SIGKILL); pcntl_waitpid($pid, $status); fclose($parent);
            $this->assertTrue(pcntl_wifsignaled($status)); $this->assertSame(SIGKILL, pcntl_wtermsig($status)); }
    }

    private function user(array $override = []): User
    {
        $id = ++$this->counter;
        return User::create(array_replace(['email' => 'reward-'.$id.'@example.test', 'password' => 'reward-private-password',
            'uuid' => sprintf('70000000-0000-4000-8000-%012d', $id), 'token' => sprintf('%032d', $id),
            'transfer_enable' => 1000, 'u' => 10, 'd' => 20, 'balance' => 100, 'commission_balance' => 20,
            'expired_at' => time()+86400], $override));
    }
    private function plan(): Plan
    { return Plan::create(['name' => 'reward-plan', 'group_id' => 1, 'transfer_enable' => 10, 'speed_limit' => 20,
        'device_limit' => 3, 'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY, 'prices' => ['monthly' => 10], 'show' => 1, 'sell' => 1]); }
    private function order(User $buyer, User $inviter, array $override = []): Order
    { return Order::create(array_replace(['user_id' => $buyer->id, 'invite_user_id' => $inviter->id, 'plan_id' => 1,
        'type' => Order::TYPE_NEW_PURCHASE, 'period' => 'month_price', 'trade_no' => 'reward-trade-'.(++$this->counter),
        'callback_no' => 'reward-private-callback', 'total_amount' => 1000, 'commission_balance' => 100,
        'actual_commission_balance' => 0, 'commission_status' => 1, 'status' => Order::STATUS_COMPLETED], $override)); }
    private function code(array $rewards, array $override = [], array $template = []): GiftCardCode
    {
        $model = GiftCardTemplate::create(array_replace(['name' => 'fixture', 'type' => GiftCardTemplate::TYPE_GENERAL,
            'status' => true, 'admin_id' => 1, 'rewards' => $rewards], $template));
        return GiftCardCode::create(array_replace(['template_id' => $model->id, 'code' => 'REWARDFIXTURE'.(++$this->counter),
            'status' => GiftCardCode::STATUS_UNUSED, 'max_usage' => 1, 'usage_count' => 0], $override));
    }
    private function redeem(GiftCardCode $code, User $user): array
    { return (new GiftCardService($code->code))->setUser($user)->redeem(); }
    private function wallets(User $user): array { $user->refresh(); return [(int) $user->balance, (int) $user->commission_balance]; }
    private function capture(): LegacyUserMutationCapture { return app(LegacyUserMutationCapture::class); }
    private function backfill(User $user): void
    { app(SubscriptionShadowBackfill::class)->run('reward-map-'.$user->id, 100, app(LegacySubscriptionSnapshot::class)->context('UTC', 'USD')); }
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
    private function assertSafe(User $user, string $text, array $other = []): void
    { foreach (array_merge([$user->email, $user->password, $user->uuid, $user->token], $other) as $secret) $this->assertStringNotContainsString($secret, $text); }
    private function failure(string $code, callable $operation): void
    {
        try { $operation(); $this->fail('Invalid reward capture accepted.'); }
        catch (LegacyCaptureShadowException $e) { $this->assertSame($code, $e->getMessage()); $this->assertNull($e->getPrevious()); }
    }
    private function mysql(): bool { return getenv('CORE_CAPTURE_TEST_MYSQL') === '1'; }
    private function connection(): string { return $this->mysql() ? 'reward_capture_mysql_testing' : 'reward_capture_sqlite_testing'; }
    private function requireFork(): void
    { if (!$this->mysql() || !function_exists('pcntl_fork') || !function_exists('posix_kill')) $this->markTestSkipped('Requires local independent MariaDB sessions and process control.'); }
    private function clearMysql(): void
    {
        if (DB::connection()->getDatabaseName() !== 'txboard_concurrency_test') throw new \RuntimeException('Reward capture needs its dedicated database.');
        Schema::disableForeignKeyConstraints(); try { foreach (Schema::getTableListing() as $table) Schema::drop($table); }
        finally { Schema::enableForeignKeyConstraints(); }
    }
}
