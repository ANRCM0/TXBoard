<?php

namespace Tests\Feature\Performance;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Comparable synthetic CI data only. No network QPS, production load,
 * queue lag, real user traffic or SLA conformance is implied.
 */
class P0ReadPathBaselineTest extends TestCase
{
    use RefreshDatabase;

    public function test_synthetic_read_latency_and_query_count_are_recorded(): void
    {
        Bus::fake();
        $user = User::create([
            'email' => 'p0b-performance@example.test', 'password' => 'test-password',
            'uuid' => '00000000-0000-0000-0000-000000009001',
            'token' => '00112233445566778899aabbccddeeff',
            'u' => 0, 'd' => 0, 'balance' => 0, 'commission_balance' => 0,
            'created_at' => time(), 'updated_at' => time(),
        ]);
        $plan = Plan::create([
            'name' => 'Synthetic Read Plan', 'group_id' => 1,
            'transfer_enable' => 2, 'show' => 1, 'sell' => 1, 'renew' => 1,
            'sort' => 0, 'capacity_limit' => null,
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
            'prices' => [Plan::PERIOD_MONTHLY => 10],
            'created_at' => time(), 'updated_at' => time(),
        ]);

        for ($i = 0; $i < 30; $i++) {
            Order::create([
                'user_id' => $user->id, 'plan_id' => $plan->id,
                'type' => Order::TYPE_NEW_PURCHASE,
                'period' => Plan::PERIOD_MONTHLY,
                'trade_no' => sprintf('synthetic-p0b-%04d', $i),
                'total_amount' => 1000, 'balance_amount' => 0,
                'status' => Order::STATUS_COMPLETED,
                'commission_status' => 0, 'commission_balance' => 0,
                'created_at' => time() - $i, 'updated_at' => time() - $i,
            ]);
        }

        Sanctum::actingAs($user);
        $queryCount = 0;
        $counting = false;
        DB::listen(function () use (&$queryCount, &$counting): void {
            if ($counting) $queryCount++;
        });
        $routes = [
            'public_plans' => '/api/v1/guest/plan/fetch',
            'user_orders' => '/api/v1/user/order/fetch',
        ];
        $results = [];
        foreach ($routes as $name => $path) {
            for ($warmup = 0; $warmup < 3; $warmup++) $this->getJson($path)->assertOk();

            $samplesMs = [];
            $sampleQueries = [];
            for ($i = 0; $i < 20; $i++) {
                $queryCount = 0;
                $counting = true;
                $start = hrtime(true);
                $response = $this->getJson($path);
                $elapsed = (hrtime(true) - $start) / 1_000_000;
                $counting = false;
                $response->assertOk();
                $samplesMs[] = round($elapsed, 3);
                $sampleQueries[] = $queryCount;
            }
            sort($samplesMs, SORT_NUMERIC);
            $sortedQueries = $sampleQueries;
            sort($sortedQueries, SORT_NUMERIC);
            $results[$name] = [
                'samples' => count($samplesMs),
                'latency_ms' => [
                    'p50' => $this->percentile($samplesMs, 0.50),
                    'p95' => $this->percentile($samplesMs, 0.95),
                    'p99' => $this->percentile($samplesMs, 0.99),
                ],
                'sql_queries' => [
                    'min' => min($sampleQueries),
                    'p95' => $this->percentile($sortedQueries, 0.95),
                    'max' => max($sampleQueries),
                ],
            ];
        }

        $report = [
            'schema_version' => 1,
            'kind' => 'synthetic_ci_in_process_read_only_baseline',
            'db_driver' => DB::getDriverName(),
            'fixtures' => ['users' => 1, 'plans' => 1, 'orders' => 30],
            'warmups_per_route' => 3,
            'results' => $results,
        ];
        $this->assertSame(20, $report['results']['user_orders']['samples']);
        $this->assertGreaterThanOrEqual(0, $report['results']['public_plans']['sql_queries']['min']);

        $output = getenv('P0_BASELINE_OUTPUT');
        if ($output !== false && $output !== '') {
            $this->assertSame('testing', app()->environment(),
                'Never write synthetic benchmark reports outside testing.');
            $directory = dirname($output);
            if (!is_dir($directory)) mkdir($directory, 0700, true);
            file_put_contents($output, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
        }
    }

    /** Nearest rank: stable across PHP, no interpolation or extrapolation. */
    private function percentile(array $sorted, float $fraction): int|float
    {
        return $sorted[max(0, (int) ceil($fraction * count($sorted)) - 1)];
    }
}
