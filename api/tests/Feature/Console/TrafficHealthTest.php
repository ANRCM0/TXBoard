<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TrafficHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_durable_batch_activity_without_requiring_a_node_connection(): void
    {
        Queue::shouldReceive('connection')->once()->with('redis')->andReturn(
            new class {
                public function size(string $queue): int
                {
                    return $queue === 'traffic_fetch' ? 3 : 0;
                }
            }
        );

        DB::table('tx_traffic_batch')->insert([
            ['server_id' => 11, 'batch_id' => 'health-00000001',
                'payload_hash' => str_repeat('a', 64), 'created_at' => time()],
            ['server_id' => 11, 'batch_id' => 'health-00000002',
                'payload_hash' => str_repeat('b', 64), 'created_at' => time()],
        ]);

        $this->artisan('traffic:health')
            ->expectsOutputToContain('Settled batches: 2')
            ->expectsOutputToContain('Pending traffic jobs: 3')
            ->assertExitCode(0);
    }

    public function test_queue_failure_raises_a_health_alarm_without_erasing_ledger(): void
    {
        Queue::shouldReceive('connection')->once()->with('redis')
            ->andThrow(new \RuntimeException('redis unavailable'));

        $this->artisan('traffic:health', ['--json' => true])
            ->expectsOutputToContain('traffic_queue_unavailable')
            ->assertExitCode(1);
    }

    public function test_invalid_window_fails_closed(): void
    {
        $this->artisan('traffic:health', ['--minutes' => 0])->assertExitCode(1);
    }
}
