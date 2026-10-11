<?php

namespace Tests\Feature\Txapi;

use App\Domains\Billing\OrderIdempotency;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TxapiOrderIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private function buyer(int $balance = 0): User
    {
        return User::create([
            'email' => 'idempotent-buyer@example.test',
            'password' => 'test-hash',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)),
            'balance' => $balance,
            'commission_balance' => 0,
            'banned' => 0,
            'expired_at' => 0,
        ]);
    }

    private function plan(): Plan
    {
        return Plan::create([
            'name' => 'Idempotency checkout plan',
            'group_id' => 1,
            'show' => true,
            'sell' => true,
            'renew' => true,
            'capacity_limit' => null,
            'transfer_enable' => 2,
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
            'prices' => [Plan::PERIOD_MONTHLY => 10, Plan::PERIOD_QUARTERLY => 28],
        ]);
    }

    private function payload(Plan $plan, array $extra = []): array
    {
        return array_merge([
            'plan_id' => $plan->id,
            'period' => Plan::PERIOD_MONTHLY,
        ], $extra);
    }

    private function keyedPost(string $key, array $body)
    {
        return $this->withHeader('Idempotency-Key', $key)
            ->postJson('/txapi/orders', $body);
    }

    public function test_100_identical_replays_return_one_order_and_one_wallet_reservation(): void
    {
        $user = $this->buyer(300);
        $plan = $this->plan();
        Sanctum::actingAs($user);
        $payload = $this->payload($plan);
        $first = $this->keyedPost('purchase-A', $payload)->assertCreated();
        $trade = $first->json('data.trade_no');
        for ($i = 0; $i < 99; $i++) {
            $this->keyedPost('purchase-A', $payload)->assertCreated()
                ->assertJsonPath('data.trade_no', $trade);
        }
        $this->assertSame(1, Order::query()->where('user_id', $user->id)->count());
        $this->assertSame(0, (int) $user->fresh()->balance);
        $this->assertSame(1, DB::table('idempotency_keys')->count());
        $row = DB::table('idempotency_keys')->first();
        $this->assertSame('succeeded', $row->status);
        $this->assertSame($trade, $row->trade_no);
        $this->assertSame(['trade_no' => $trade], json_decode($row->result_snapshot, true));
    }

    public function test_same_key_different_parameters_conflicts_before_business_preflight(): void
    {
        $user = $this->buyer();
        $plan = $this->plan();
        Sanctum::actingAs($user);
        $this->keyedPost('single-intent', $this->payload($plan))->assertCreated();
        $this->keyedPost('single-intent', $this->payload($plan, [
            'period' => Plan::PERIOD_QUARTERLY,
        ]))->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        $this->assertSame(1, Order::count());
        $this->assertSame(1, DB::table('idempotency_keys')->count());
    }

    public function test_different_key_is_a_new_intent_after_cancel_and_missing_key_keeps_compatibility(): void
    {
        $user = $this->buyer();
        $plan = $this->plan();
        Sanctum::actingAs($user);
        $body = $this->payload($plan);
        $original = $this->keyedPost('intent-1', $body)->assertCreated()->json('data.trade_no');
        $this->keyedPost('intent-2', $body)->assertStatus(409)
            ->assertJsonPath('error.code', 'ORDER_REJECTED');
        $this->postJson("/txapi/orders/{$original}/cancel")->assertOk();
        $second = $this->keyedPost('intent-2', $body)->assertCreated()->json('data.trade_no');
        $this->assertNotSame($original, $second);
        $this->keyedPost('intent-1', $body)->assertCreated()
            ->assertJsonPath('data.trade_no', $original);
        $this->assertSame(2, Order::count());
        $this->withoutHeader('Idempotency-Key')->postJson('/txapi/orders', $body)->assertStatus(409)
            ->assertJsonPath('error.code', 'ORDER_REJECTED');
    }

    public function test_invalid_client_keys_fail_without_creating_any_record(): void
    {
        $user = $this->buyer();
        $plan = $this->plan();
        Sanctum::actingAs($user);
        foreach (['key with spaces', 'one,two', str_repeat('X', 129)] as $key) {
            $this->keyedPost($key, $this->payload($plan))->assertStatus(400)
                ->assertJsonPath('error.code', 'VALIDATION_ERROR');
        }
        $this->assertSame(0, Order::count());
        $this->assertSame(0, DB::table('idempotency_keys')->count());
    }

    public function test_failed_create_rolls_back_claim_and_can_retry_with_same_key(): void
    {
        $user = $this->buyer();
        $plan = $this->plan();
        Sanctum::actingAs($user);
        $plan->update(['prices' => []]);
        $this->keyedPost('retryable-intent', $this->payload($plan))
            ->assertStatus(409)->assertJsonPath('error.code', 'ORDER_REJECTED');
        $this->assertSame(0, DB::table('idempotency_keys')->count());
        $plan->update(['prices' => [Plan::PERIOD_MONTHLY => 10]]);
        $this->keyedPost('retryable-intent', $this->payload($plan))->assertCreated();
        $this->assertSame(1, Order::count());
        $this->assertSame(1, DB::table('idempotency_keys')->count());
    }

    public function test_stale_in_progress_is_recoverable_and_fresh_claim_is_not(): void
    {
        $user = $this->buyer();
        $plan = $this->plan();
        Sanctum::actingAs($user);
        $key = 'abandoned-intent';
        $payload = [
            'plan_id' => (int) $plan->id,
            'period' => Plan::PERIOD_MONTHLY,
            'coupon_code' => null,
        ];
        $id = DB::table('idempotency_keys')->insertGetId([
            'user_id' => $user->id,
            'operation' => OrderIdempotency::OPERATION_CREATE,
            'key_hash' => OrderIdempotency::keyHash($user->id, OrderIdempotency::OPERATION_CREATE, $key),
            'request_hash' => OrderIdempotency::requestHash($payload),
            'status' => 'in_progress',
            'trade_no' => null,
            'result_snapshot' => null,
            'created_at' => time() - 1200,
            'updated_at' => time(),
            'expires_at' => time() + 86400,
        ]);
        $this->keyedPost($key, $this->payload($plan))->assertStatus(409)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_IN_PROGRESS')
            ->assertHeader('Retry-After', '2');
        DB::table('idempotency_keys')->where('id', $id)
            ->update(['updated_at' => time() - 1200]);
        $trade = $this->keyedPost($key, $this->payload($plan))
            ->assertCreated()->json('data.trade_no');
        $this->assertSame(1, Order::count());
        $this->assertSame('succeeded', DB::table('idempotency_keys')->where('id', $id)->value('status'));
        $this->keyedPost($key, $this->payload($plan))->assertCreated()
            ->assertJsonPath('data.trade_no', $trade);
    }

    public function test_expired_claim_can_be_reused_and_pruned(): void
    {
        $user = $this->buyer();
        $plan = $this->plan();
        Sanctum::actingAs($user);
        $body = $this->payload($plan);
        $trade = $this->keyedPost('expire-key', $body)->assertCreated()->json('data.trade_no');
        $this->postJson("/txapi/orders/{$trade}/cancel")->assertOk();
        DB::table('idempotency_keys')->update(['expires_at' => time() - 1]);
        $second = $this->keyedPost('expire-key', $body)->assertCreated()->json('data.trade_no');
        $this->assertNotSame($trade, $second);
        $this->assertSame(2, Order::count());
        DB::table('idempotency_keys')->update(['expires_at' => time() - 1]);
        $this->artisan('idempotency:prune')->assertExitCode(0);
        $this->assertSame(0, DB::table('idempotency_keys')->count());
        $this->assertSame(2, Order::count());
    }

    public function test_transaction_crash_simulation_rolls_back_both_order_and_claim(): void
    {
        $user = $this->buyer();
        $plan = $this->plan();
        $body = ['plan_id' => (int) $plan->id, 'period' => Plan::PERIOD_MONTHLY, 'coupon_code' => null];
        try {
            DB::transaction(function () use ($user, $plan, $body): void {
                User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                OrderIdempotency::run($user->id, 'crash-key', $body, function () use ($user, $plan): Order {
                    Order::create([
                        'user_id' => $user->id,
                        'plan_id' => $plan->id,
                        'trade_no' => 'would-have-committed',
                        'period' => Plan::PERIOD_MONTHLY,
                        'type' => Order::TYPE_NEW_PURCHASE,
                        'status' => Order::STATUS_PENDING,
                        'total_amount' => 1000,
                    ]);
                    throw new \RuntimeException('simulated worker death before transaction commit');
                });
            });
            $this->fail('Simulated failure should abort the transaction');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('worker death', $e->getMessage());
        }
        $this->assertSame(0, Order::count());
        $this->assertSame(0, DB::table('idempotency_keys')->count());
        Sanctum::actingAs($user);
        $this->keyedPost('crash-key', $this->payload($plan))->assertCreated();
        $this->assertSame(1, Order::count());
    }
}
