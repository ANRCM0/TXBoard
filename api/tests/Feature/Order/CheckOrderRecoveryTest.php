<?php

namespace Tests\Feature\Order;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use App\Jobs\OrderHandleJob;
use Tests\TestCase;

class CheckOrderRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_expired_pending_and_processing_orders_are_queued(): void
    {
        Bus::fake();

        $user = User::create([
            'email' => 'check-order@example.com',
            'password' => 'password',
            'uuid' => '00000000-0000-0000-0000-000000000011',
            'token' => '0123456789abcdef0123456789abcdef',
            'balance' => 0,
            'commission_balance' => 0,
            'transfer_enable' => 0,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
            'is_admin' => 0,
            'is_staff' => 0,
            'expired_at' => 0,
            'remind_expire' => 1,
            'remind_traffic' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $plan = Plan::create([
            'name' => 'Recovery plan',
            'transfer_enable' => 1,
            'prices' => [Plan::PERIOD_MONTHLY => 1],
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        foreach ([
            ['recent-pending', Order::STATUS_PENDING, time()],
            ['expired-pending', Order::STATUS_PENDING, time() - 10800],
            ['processing', Order::STATUS_PROCESSING, time()],
            ['completed', Order::STATUS_COMPLETED, time() - 10800],
        ] as [$tradeNo, $status, $createdAt]) {
            Order::create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'trade_no' => $tradeNo,
                'period' => Plan::PERIOD_MONTHLY,
                'status' => $status,
                'total_amount' => 100,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }

        $this->artisan('check:order')->assertExitCode(0);

        Bus::assertDispatchedTimes(OrderHandleJob::class, 2);
        Bus::assertDispatched(OrderHandleJob::class, fn ($job) => true);
    }
}
