<?php

namespace Tests\Feature\Subscription;

use App\Domains\Subscription\Services\NativeResetHistory;
use App\Domains\Subscription\Services\SubscriptionLifecycleService;
use App\Services\Auth\NativeIdentityService;
use Illuminate\Support\Facades\DB;
use Tests\Support\NativeRuntimeTestCase;

final class NativeResetHistoryTest extends NativeRuntimeTestCase
{
    public function test_real_browser_reads_retained_native_periods_with_exact_cursor_and_refuses_revoked_permission(): void
    {
        $this->activate();
        [$user, $initial] = $this->subscribe();
        DB::table('tx_usage_period')->where('id', $initial['usage_period_id'])->update(['upload_bytes' => 3, 'download_bytes' => 4, 'billable_bytes' => 7]);
        $first = app(SubscriptionLifecycleService::class)->reset($user->id, (int) $initial['subscription_id'], 'history-first', '1', time() - 2);
        app(SubscriptionLifecycleService::class)->reset($user->id, (int) $initial['subscription_id'], 'history-second', $first['subscription_version'], time() - 1);
        DB::table('tx_subscription_operation')->where('business_key', 'history-second')->update(['id' => '9007199254740993']);
        config(['browser.enabled' => true, 'browser.admin_enabled' => true, 'browser.origin' => 'https://core.example.test', 'browser.rate_store' => 'array']);
        admin_setting(['secure_path' => 'reset_history']);
        [, $actor] = app(NativeIdentityService::class)->create('history-admin@example.test', 'admin-password');
        $actor->forceFill(['is_admin' => true])->saveOrFail();
        DB::table('tx_internal_actor_grant')->insert(['user_id' => $actor->id, 'permission' => 'subscription.manage', 'created_at' => time()]);
        $headers = ['Authorization' => 'Bearer '.$actor->createToken('history-browser', ['*'])->plainTextToken, 'X-Core-Admin-Path' => 'reset_history', 'Origin' => 'https://core.example.test'];
        $invoke = fn (array $input) => $this->postJson('https://core.example.test/gateway/v1/core/admin/operations/subscription.lifecycle.resets.list', ['input' => $input === [] ? (object) [] : $input], $headers);
        $latest = $invoke(['limit' => 1])->assertOk()->assertJsonPath('data.records.0.id', '9007199254740993')->assertJsonPath('data.next_before_id', '9007199254740993');
        $this->assertSame((string) $user->id, $latest->json('data.records.0.user_id'));
        $previous = $invoke(['limit' => 1, 'before_id' => '9007199254740993'])->assertOk()->assertJsonPath('data.records.0.previous_billable_bytes', '7')->assertJsonPath('data.next_before_id', null);
        $this->assertSame($initial['usage_period_id'], $previous->json('data.records.0.previous_period_id'));
        $this->assertSame(3, DB::table('tx_usage_period')->count());
        $invoke(['user_id' => (string) $actor->id])->assertOk()->assertJsonPath('data.records', []);
        $invoke(['start_date' => '2024-01-01', 'end_date' => '2026-01-01'])->assertStatus(422);
        DB::table('tx_internal_actor_grant')->where('user_id', $actor->id)->delete();
        $invoke([])->assertForbidden();
    }

    public function test_damaged_reset_evidence_is_never_projected_as_success(): void
    {
        $this->activate();
        [$user, $initial] = $this->subscribe();
        app(SubscriptionLifecycleService::class)->reset($user->id, (int) $initial['subscription_id'], 'history-damaged', '1', time() - 1);
        DB::table('tx_subscription_operation')->where('business_key', 'history-damaged')->update(['content_hash' => str_repeat('0', 64)]);
        $this->expectExceptionMessage('NATIVE_OPERATION_DAMAGED');
        app(NativeResetHistory::class)->read([], time());
    }
}
