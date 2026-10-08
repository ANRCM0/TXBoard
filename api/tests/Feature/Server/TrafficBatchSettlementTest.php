<?php

namespace Tests\Feature\Server;

use App\Jobs\TrafficBatchJob;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class TrafficBatchSettlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_batch_retried_does_not_charge_or_count_twice(): void
    {
        Bus::fake();
        $user = User::create([
            'email' => 'billing-phase2@example.test', 'password' => 'password',
            'uuid' => '10000000-0000-0000-0000-000000000002',
            'token' => '1234567890abcdef1234567890abcdef',
            'u' => 0, 'd' => 0, 'transfer_enable' => 1073741824,
            'created_at' => time(), 'updated_at' => time(),
        ]);
        $server = Server::create([
            'name' => 'batch-node', 'type' => Server::TYPE_VMESS,
            'host' => '127.0.0.1', 'port' => '443', 'server_port' => 443,
            'rate' => 2, 'group_ids' => [1], 'enabled' => true,
        ]);
        Redis::shouldReceive('sadd')->zeroOrMoreTimes()->andReturn(1);
        $payload = [$user->id => [100, 300]];
        $new = fn ($id, $traffic) => new TrafficBatchJob(
            ['id' => $server->id, 'rate' => 2], $traffic, 'vmess',
            strtotime(date('Y-m-d')), $id
        );

        $new('report-0001', $payload)->handle();
        $new('report-0001', $payload)->handle();
        $this->assertSame(200, (int) $user->fresh()->u);
        $this->assertSame(600, (int) $user->fresh()->d);
        $this->assertSame(1, DB::table('v2_traffic_batch')->count());
        $this->assertSame(1, DB::table('v2_stat_user')->where('user_id', $user->id)->count());
        $this->assertSame(800, (int) DB::table('v2_stat_user')->where('user_id', $user->id)->first()->u
            + (int) DB::table('v2_stat_user')->where('user_id', $user->id)->first()->d);
        $this->assertSame(400, (int) ($server->fresh()->u + $server->fresh()->d));
        $this->assertSame(400, (int) DB::table('v2_stat_server')->where('server_id', $server->id)->first()->u
            + (int) DB::table('v2_stat_server')->where('server_id', $server->id)->first()->d);
    }

    public function test_out_of_order_batches_and_replays_converge_without_double_charging(): void
    {
        Bus::fake();
        $user = User::create([
            'email' => 'out-of-order@example.test', 'password' => 'password',
            'uuid' => '10000000-0000-0000-0000-000000000003',
            'token' => '1234567890abcdef1234567890abcdea',
            'u' => 0, 'd' => 0, 'transfer_enable' => 1073741824,
            'created_at' => time(), 'updated_at' => time(),
        ]);
        $server = Server::create([
            'name' => 'out-of-order-node', 'type' => Server::TYPE_VMESS,
            'host' => '127.0.0.1', 'port' => '443', 'server_port' => 443,
            'rate' => 1, 'group_ids' => [1], 'enabled' => true,
        ]);
        Redis::shouldReceive('sadd')->zeroOrMoreTimes()->andReturn(1);
        $send = static function (int $seq) use ($server, $user): void {
            (new TrafficBatchJob(
                ['id' => $server->id, 'rate' => 1],
                [$user->id => [$seq, $seq * 2]],
                'vmess', strtotime(date('Y-m-d')), 'sequence-' . str_pad((string) $seq, 8, '0', STR_PAD_LEFT)
            ))->handle();
        };

        foreach ([4, 2, 1, 3, 4, 1, 3, 2] as $seq) {
            $send($seq);
        }
        $this->assertSame(10, (int) $user->fresh()->u);
        $this->assertSame(20, (int) $user->fresh()->d);
        $this->assertSame(4, DB::table('v2_traffic_batch')->count());
        $this->assertSame(30, (int) ($server->fresh()->u + $server->fresh()->d));
        $this->assertSame(30, (int) DB::table('v2_stat_server')->where('server_id', $server->id)->first()->u
            + (int) DB::table('v2_stat_server')->where('server_id', $server->id)->first()->d);
    }

    public function test_overflow_rolls_back_ledger_stats_and_all_accounts(): void
    {
        Bus::fake();
        $user = User::create([
            'email' => 'rollback@example.test', 'password' => 'password',
            'uuid' => '10000000-0000-0000-0000-000000000004',
            'token' => '1234567890abcdef1234567890abcdeb',
            'u' => PHP_INT_MAX - 5, 'd' => 0, 'transfer_enable' => PHP_INT_MAX,
            'created_at' => time(), 'updated_at' => time(),
        ]);
        $server = Server::create([
            'name' => 'rollback-node', 'type' => Server::TYPE_VMESS,
            'host' => '127.0.0.1', 'port' => '443', 'server_port' => 443,
            'rate' => 1, 'group_ids' => [1], 'enabled' => true,
        ]);
        $job = new TrafficBatchJob(
            ['id' => $server->id, 'rate' => 1],
            [$user->id => [10, 20]],
            'vmess', strtotime(date('Y-m-d')), 'rollback-batch-001'
        );

        try {
            $job->handle();
            $this->fail('Counter overflow must abort the entire batch.');
        } catch (\RuntimeException $e) {
            $this->assertSame('User traffic counter overflow', $e->getMessage());
        }

        $this->assertSame(PHP_INT_MAX - 5, (int) $user->fresh()->u);
        $this->assertSame(0, DB::table('v2_traffic_batch')->count());
        $this->assertSame(0, DB::table('v2_stat_user')->count());
        $this->assertSame(0, DB::table('v2_stat_server')->count());
        $this->assertSame(0, (int) ($server->fresh()->u + $server->fresh()->d));
    }

    public function test_same_batch_id_with_changed_payload_is_rejected(): void
    {
        Bus::fake();
        $server = Server::create([
            'name' => 'collision-node', 'type' => Server::TYPE_VMESS,
            'host' => '127.0.0.1', 'port' => '443', 'server_port' => 443,
            'rate' => 1, 'group_ids' => [1], 'enabled' => true,
        ]);
        $date = strtotime(date('Y-m-d'));
        (new TrafficBatchJob(['id' => $server->id, 'rate' => 1],
            [123 => [1, 1]], 'vmess', $date, 'collision-01'))->handle();
        (new TrafficBatchJob(['id' => $server->id, 'rate' => 1],
            [123 => [100, 100]], 'vmess', $date, 'collision-01'))->handle();
        $this->assertSame(1, DB::table('v2_traffic_batch')->count());
        $this->assertSame(0, (int) ($server->fresh()->u + $server->fresh()->d));
    }
}
