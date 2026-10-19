<?php

namespace Tests\Feature\Txapi;

use App\Domains\InternalApi\ServiceCredentials;
use App\Domains\UsageV2\Contracts\UsageQueries;
use App\Domains\UsageV2\Contracts\UsageTargetMissing;
use App\Domains\UsageV2\UsageReceipts;
use App\Domains\UsageV2\UsageSettlement;
use App\Models\Server;
use App\Services\Auth\NativeIdentityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\NativeRuntimeTestCase;
use Tests\Support\UsageV2Fixture;

final class NodeTrafficHistoryTest extends NativeRuntimeTestCase
{
    use UsageV2Fixture;

    private function settled(): array
    {
        $f = $this->usageFixture();
        app(UsageReceipts::class)->submit($f['node']->id, $this->usagePayload($f), $f['now']);
        app(UsageSettlement::class)->scan(10, $f['now']);
        $this->assertSame('settled', DB::table('tx_usage_batch')->value('status'));
        return $f;
    }

    private function copyEntry(int $endedAt, string $up, string $down, string $status = 'settled', ?int $nodeId = null): void
    {
        $batch = (array) DB::table('tx_usage_batch')->orderBy('id')->first();
        unset($batch['id']);
        $batch['batch_id'] = 'history_'.Str::random(20);
        $batch['receipt_id'] = 'history_'.Str::random(20);
        $batch['ended_at'] = $endedAt;
        $batch['started_at'] = $endedAt - 1;
        $batch['status'] = $status;
        if ($nodeId !== null) $batch['node_id'] = $nodeId;
        $id = DB::table('tx_usage_batch')->insertGetId($batch);
        $entry = (array) DB::table('tx_usage_entry')->orderBy('id')->first();
        unset($entry['id']);
        $entry['batch_id'] = $id;
        $entry['raw_up_bytes'] = $up;
        $entry['raw_down_bytes'] = $down;
        DB::table('tx_usage_entry')->insert($entry);
    }

    public function test_history_uses_exact_settled_raw_bytes_utc_end_exclusive_day_and_input_order(): void
    {
        $f = $this->settled();
        $today = intdiv($f['now'], 86400) * 86400;
        $now = $today + 43200;
        $start = $today - 6 * 86400;
        // Remove the real settlement's contribution while retaining valid native references.
        DB::table('tx_usage_batch')->update(['ended_at' => $start, 'started_at' => $start - 1]);
        $other = Server::withoutEvents(function () { $node = $this->node->replicate(); $node->name = 'Other'; $node->save(); return $node; });
        $this->copyEntry($today, '9007199254740993', '7'); // midnight belongs to yesterday
        $this->copyEntry($today + 1, '11', '13');
        $this->copyEntry($today - 86400 + 1, '3', '5');
        $this->copyEntry($now + 1, '100', '100');
        foreach (['pending', 'processing', 'retry_wait', 'quarantined'] as $status) $this->copyEntry($today + 2, '100', '100', $status);
        $this->copyEntry($today + 3, '17', '19', nodeId: $other->id);
        $history = app(UsageQueries::class)->nodeTrafficHistory([(string) $other->id, (string) $this->node->id], 7, $now);
        $this->assertSame(['UTC', 'settled-raw-usage/v1', 'day', $start, $now], array_values(array_diff_key($history, ['nodes' => true])));
        $this->assertSame([(string) $other->id, (string) $this->node->id], array_column($history['nodes'], 'id'));
        $points = $history['nodes'][1]['points'];
        $this->assertCount(7, $points);
        $this->assertSame(['date' => gmdate('Y-m-d', $start), 'total_bytes' => '0'], $points[0]);
        $this->assertSame('9007199254741008', $points[5]['total_bytes']);
        $this->assertSame('24', $points[6]['total_bytes']);
        $this->assertSame('36', $history['nodes'][0]['points'][6]['total_bytes']);
        foreach ([14, 30] as $days) {
            $long = app(UsageQueries::class)->nodeTrafficHistory([(string) $this->node->id], $days, $now);
            $this->assertCount($days, $long['nodes'][0]['points']);
            $this->assertSame($today - ($days - 1) * 86400, $long['starts_at']);
        }
        $this->assertSame(0, (int) $f['user']->fresh()->u);
    }

    public function test_history_missing_nodes_never_returns_partial_data(): void
    {
        $this->activate();
        $this->expectException(UsageTargetMissing::class);
        app(UsageQueries::class)->nodeTrafficHistory([(string) $this->node->id, '9223372036854775807'], 7, time());
    }

    public function test_admin_api_requires_current_grant_validates_ranges_duplicates_and_returns_no_store(): void
    {
        $this->activate();
        config(['internal_api.enabled' => true, 'internal_api.trusted_tls_proxies' => ['127.0.0.1']]);
        admin_setting(['secure_path' => 'traffic_admin']);
        [, $admin] = app(NativeIdentityService::class)->create('traffic-admin@example.test', 'traffic-admin-password');
        $admin->forceFill(['is_admin' => true])->saveOrFail();
        $token = $admin->createToken('traffic-history', ['*'])->plainTextToken;
        $fingerprint = str_repeat('a', 64);
        $service = app(ServiceCredentials::class)->issue('traffic-history-admin', 'admin', $fingerprint,
            ['network.nodes.traffic-history.get'], time() + 3600);
        $invoke = function (array $input) use ($service, $fingerprint, $token) {
            return $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTPS' => 'on'])
                ->postJson('https://core.example.test/txapi/internal/native/v1/admin/operations/network.nodes.traffic-history.get', [
                    'contract_version' => '1', 'request_id' => (string) Str::uuid(), 'deadline_at' => time() + 15, 'input' => $input,
                ], ['X-Core-Service-Id' => $service['service_id'], 'X-Core-Service-Credential' => $service['credential'],
                    'X-Core-Service-Epoch' => $service['epoch'], 'X-Core-Tls-Verified' => 'SUCCESS',
                    'X-Core-Tls-Certificate-Sha256' => $fingerprint, 'Authorization' => 'Bearer '.$token,
                    'X-Core-Admin-Path' => 'traffic_admin']);
        };
        $input = ['days' => 7, 'node_ids' => [(string) $this->node->id]];
        $invoke($input)->assertForbidden();
        DB::table('tx_internal_actor_grant')->insert(['user_id' => $admin->id, 'permission' => 'network.nodes.read', 'created_at' => time()]);
        $response = $invoke($input)->assertOk()->assertJsonPath('data.nodes.0.points.0.total_bytes', '0');
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        foreach ([['days' => 8], ['days' => '7'], ['node_ids' => []], ['node_ids' => array_fill(0, 21, '1')]] as $invalid) {
            $invoke(array_replace($input, $invalid))->assertStatus(422);
        }
        $invoke(['days' => 7, 'node_ids' => [(string) $this->node->id, (string) $this->node->id]])
            ->assertStatus(422)->assertJsonPath('error.code', 'USAGE_HISTORY_NODE_IDS_DUPLICATE');
        $invoke(['days' => 7, 'node_ids' => ['9223372036854775807']])->assertNotFound();
        DB::table('tx_internal_actor_grant')->where('permission', 'network.nodes.read')->delete();
        $invoke($input)->assertForbidden();
        $this->assertSame(0, DB::table('tx_internal_request')->count());
    }

    public function test_history_entry_limit_fails_closed_before_any_partial_result(): void
    {
        $f = $this->settled();
        $entry = (array) DB::table('tx_usage_entry')->first();
        unset($entry['id']);
        // The existing entry plus 100000 additional rows crosses the bound by one.
        for ($offset = 1; $offset <= 100000; $offset += 500) {
            $rows = [];
            for ($index = $offset; $index < $offset + 500; $index++) $rows[] = array_replace($entry, ['entry_no' => $index]);
            DB::table('tx_usage_entry')->insert($rows);
        }
        $this->expectExceptionMessage('USAGE_HISTORY_ENTRY_LIMIT');
        app(UsageQueries::class)->nodeTrafficHistory([(string) $this->node->id], 7, $f['now'] + 2);
    }
}
