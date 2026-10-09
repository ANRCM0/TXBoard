<?php

namespace Tests\Feature\Txapi;

use App\Models\Plan;
use App\Models\TrafficResetLog;
use App\Models\User;
use App\Services\TrafficResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class TxapiAdminTrafficResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        admin_setting(['secure_path' => 'traffic_security_path']);
    }

    public function test_only_scoped_admin_can_see_traffic_and_execute_manual_reset(): void
    {
        $operator = $this->user('traffic-admin@example.test', true);
        $subscriber = $this->user('traffic-owner@example.test');
        $prefix = '/txapi/admin/traffic_security_path/traffic-resets';

        $this->getJson($prefix)->assertStatus(403);
        Sanctum::actingAs($subscriber);
        $this->getJson($prefix)->assertStatus(403);
        $this->postJson($prefix . '/users/' . $subscriber->id . '/reset')->assertStatus(403);
        Sanctum::actingAs($operator);
        $this->getJson('/txapi/admin/guessed/traffic-resets')->assertStatus(404);
        $this->getJson($prefix)->assertOk();
        $this->getJson($prefix . '/stats')->assertOk();
        $this->getJson($prefix . '/users/' . $subscriber->id)->assertOk();
    }

    public function test_manual_reset_uses_locked_fresh_traffic_and_preserves_operator_reason(): void
    {
        $operator = $this->user('traffic-operator@example.test', true);
        $subscriber = $this->user('traffic-person@example.test');
        $plan = $this->plan();
        $subscriber->plan_id = $plan->id;
        $subscriber->expired_at = time() + 86400 * 15;
        $subscriber->u = 150;
        $subscriber->d = 300;
        $subscriber->reset_count = 0;
        $subscriber->saveOrFail();

        // This stale copy predates the most recently reported traffic.
        $stale = User::findOrFail($subscriber->id);
        $subscriber->u = 780;
        $subscriber->d = 940;
        $subscriber->saveOrFail();

        // Service must read the locked current row, not overwrite it from
        // the stale Eloquent model passed by an earlier HTTP request.
        $service = app(TrafficResetService::class);
        $this->assertTrue($service->manualReset($stale, [
            'reason' => 'manual reset check', 'admin_id' => $operator->id,
        ]));
        $log = TrafficResetLog::query()->firstOrFail();
        $this->assertSame(780, (int) $log->old_upload);
        $this->assertSame(940, (int) $log->old_download);
        $this->assertSame('manual reset check', $log->metadata['reason']);
        $this->assertSame($operator->id, $log->metadata['admin_id']);
        $this->assertSame(0, (int) $subscriber->fresh()->u);
        $this->assertSame(0, (int) $subscriber->fresh()->d);

        $subscriber->u = 300;
        $subscriber->d = 500;
        $subscriber->saveOrFail();

        Sanctum::actingAs($operator);
        $prefix = '/txapi/admin/traffic_security_path/traffic-resets';
        $this->postJson($prefix . '/users/' . $subscriber->id . '/reset', [
            'reason' => 'requested by customer',
        ])->assertOk()->assertJsonPath('data.user_id', $subscriber->id);

        $this->assertSame(0, (int) $subscriber->fresh()->u);
        $this->assertSame(2, (int) $subscriber->fresh()->reset_count);
        $this->assertSame('requested by customer',
            TrafficResetLog::query()->orderByDesc('id')->firstOrFail()->metadata['reason']);
        $res = $this->getJson($prefix . '?user_id=' . $subscriber->id . '&per_page=1');
        $res->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.user_email', $subscriber->email);
        $this->assertStringNotContainsString($subscriber->token, $res->getContent());

        $this->getJson($prefix . '/stats?days=30')->assertOk()
            ->assertJsonPath('data.total_resets', 2)
            ->assertJsonPath('data.manual_resets', 2);
        $this->getJson($prefix . '/users/' . $subscriber->id . '?limit=1')
            ->assertOk()->assertJsonPath('data.user.id', $subscriber->id)
            ->assertJsonCount(1, 'data.history');
    }

    public function test_stale_scheduled_reset_must_recheck_due_after_lock(): void
    {
        $subscriber = $this->user('not-due@example.test');
        $plan = $this->plan();
        $subscriber->plan_id = $plan->id;
        $subscriber->expired_at = time() + 86400 * 15;
        $subscriber->next_reset_at = time() + 3600;
        $subscriber->u = 1234;
        $subscriber->saveOrFail();
        $service = app(TrafficResetService::class);

        $this->assertFalse($service->performReset($subscriber, TrafficResetLog::SOURCE_CRON, [], true));
        $this->assertSame(1234, (int) $subscriber->fresh()->u);
        $this->assertSame(0, TrafficResetLog::query()->count());
    }

    public function test_monthly_day_31_clamps_to_february_last_day(): void
    {
        \Carbon\Carbon::setTestNow('2026-02-10 08:00:00');
        try {
            $user = $this->user('end-of-month@example.test');
            $plan = $this->plan();
            $plan->reset_traffic_method = Plan::RESET_TRAFFIC_MONTHLY;
            $plan->saveOrFail();
            $user->plan_id = $plan->id;
            $user->expired_at = \Carbon\Carbon::parse('2026-01-31 10:20:00', config('app.timezone'))->timestamp;
            $user->saveOrFail();
            $next = app(TrafficResetService::class)->calculateNextResetTime($user->fresh());
            $this->assertSame('2026-02-28 10:20:00',
                $next?->format('Y-m-d H:i:s'));
        } finally {
            \Carbon\Carbon::setTestNow();
        }
    }

    public function test_yearly_february_29_clamps_in_nonleap_year(): void
    {
        \Carbon\Carbon::setTestNow('2025-01-11 08:00:00');
        try {
            $user = $this->user('leap-expiry@example.test');
            $plan = $this->plan();
            $plan->reset_traffic_method = Plan::RESET_TRAFFIC_YEARLY;
            $plan->saveOrFail();
            $user->plan_id = $plan->id;
            $user->expired_at = \Carbon\Carbon::parse('2024-02-29 09:30:00', config('app.timezone'))->timestamp;
            $user->saveOrFail();
            $next = app(TrafficResetService::class)->calculateNextResetTime($user->fresh());
            $this->assertSame('2025-02-28 09:30:00',
                $next?->format('Y-m-d H:i:s'));
        } finally {
            \Carbon\Carbon::setTestNow();
        }
    }

    public function test_invalid_filters_and_inactive_or_missing_subscriptions_cannot_reset(): void
    {
        $admin = $this->user('traffic-validator@example.test', true);
        $user = $this->user('traffic-inactive@example.test');
        Sanctum::actingAs($admin);
        $prefix = '/txapi/admin/traffic_security_path/traffic-resets';
        $this->postJson($prefix . '/users/' . $user->id . '/reset')
            ->assertStatus(409)->assertJsonPath('error.code', 'TRAFFIC_RESET_NOT_ALLOWED');
        $this->postJson($prefix . '/users/999999/reset')->assertStatus(404);
        foreach (['per_page=101', 'page=0', 'reset_type=other',
            'trigger_source=other', 'start_date=yesterday', 'user_id=no'] as $input) {
            $this->getJson($prefix . '?' . $input)->assertStatus(422);
        }
        $this->getJson($prefix . '/stats?days=366')->assertStatus(422);
        $this->getJson($prefix . '/users/999999')->assertStatus(404);
        $this->getJson($prefix . '/users/' . $user->id . '?limit=51')->assertStatus(422);
        $this->postJson($prefix . '/users/' . $user->id . '/reset', [
            'reason' => str_repeat('X', 256),
        ])->assertStatus(422);
    }

    private function user(string $email, bool $admin = false): User
    {
        return User::create([
            'email' => $email,
            'password' => 'test-secure-password',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)),
            'is_admin' => $admin, 'banned' => 0,
            'balance' => 0, 'commission_balance' => 0,
        ]);
    }

    private function plan(): Plan
    {
        return Plan::create([
            'name' => 'Traffic native test', 'group_id' => 1,
            'transfer_enable' => 5, 'show' => true, 'sell' => true,
            'renew' => true, 'prices' => ['monthly' => 10],
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
        ]);
    }
}
