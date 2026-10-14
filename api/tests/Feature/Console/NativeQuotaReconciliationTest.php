<?php

namespace Tests\Feature\Console;

use App\Models\Server;
use App\Models\User;
use App\Services\NodeSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class NativeQuotaReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): User
    {
        config()->set('native_traffic_receipts.enabled', false);
        $user = User::create(['email' => 'quota@example.test', 'password' => 'test',
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'token' => bin2hex(random_bytes(16)),
            'group_id' => 7, 'u' => 10, 'd' => 10, 'transfer_enable' => 20]);
        foreach ([[7], ['7']] as $groups) {
            $node = Server::create(['name' => 'quota', 'type' => 'vless', 'host' => '127.0.0.1',
                'port' => 443, 'server_port' => 443, 'group_ids' => $groups, 'rate' => 1, 'enabled' => true]);
            NodeSyncService::markNodeOnline((int) $node->id);
        }
        return $user;
    }

    public function test_integer_and_string_groups_receive_current_snapshot_before_hint_removed(): void
    {
        $user = $this->fixture();
        Redis::shouldReceive('srandmember')->once()->with('traffic:pending_check', 100)->andReturn([(string) $user->id]);
        Redis::shouldReceive('publish')->twice()->with('node:push', \Mockery::on(function ($json): bool {
            $frame = json_decode($json, true);
            return $frame['event'] === 'sync.users' && $frame['data']['users'] === [];
        }))->andReturn(1);
        Redis::shouldReceive('srem')->once()->with('traffic:pending_check', $user->id)->andReturn(1);
        $this->artisan('check:traffic-exceeded')->assertExitCode(0);
    }

    public function test_invalid_limit_does_not_touch_receipts_or_redis(): void
    {
        Redis::shouldReceive('srandmember')->never();
        $this->artisan('check:traffic-exceeded', ['--limit' => 0])->assertExitCode(1);
        $this->artisan('check:traffic-exceeded', ['--limit' => 1001])->assertExitCode(1);
    }

    public function test_zero_subscribers_keeps_quota_hint_until_worker_is_available(): void
    {
        $user = $this->fixture();
        Redis::shouldReceive('srandmember')->once()->andReturn([(string) $user->id]);
        Redis::shouldReceive('publish')->once()->andReturn(0);
        Redis::shouldReceive('srem')->never();
        $this->artisan('check:traffic-exceeded')->assertExitCode(1);
    }

    public function test_failed_publication_keeps_original_hint_for_retry(): void
    {
        $user = $this->fixture();
        Redis::shouldReceive('srandmember')->once()->andReturn([(string) $user->id]);
        Redis::shouldReceive('publish')->once()->andThrow(new \RuntimeException('synthetic unavailable'));
        Redis::shouldReceive('srem')->never();
        $this->artisan('check:traffic-exceeded')->assertExitCode(1);
    }
}
