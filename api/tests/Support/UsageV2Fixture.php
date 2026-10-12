<?php

namespace Tests\Support;

use App\Domains\UsageV2\UsagePayload;
use App\Domains\UsageV2\UsageRegistry;
use App\Models\Server;
use Illuminate\Support\Facades\DB;

trait UsageV2Fixture
{
    private function usageConfig(): void
    {
        config(['core_usage_v2.isolated_copy' => true, 'core_usage_v2.core_instance_id' => 'core_instance_test_0001',
            'core_usage_v2.receipt_generation' => '1']);
    }

    private function usageFixture(): array
    {
        $now = time();
        $node = Server::create(['name' => 'usage-v2-isolated', 'type' => Server::TYPE_VMESS, 'host' => '127.0.0.1',
            'port' => 443, 'server_port' => 443, 'rate' => 1, 'group_ids' => [1], 'enabled' => true]);
        $sub = DB::table('tx_subscription')->insertGetId(['user_id' => 7, 'status' => 'active', 'starts_at' => $now - 1000,
            'expires_at' => $now + 1000, 'created_at' => $now, 'updated_at' => $now]);
        $terms = DB::table('tx_subscription_terms')->insertGetId(['subscription_id' => $sub, 'version_no' => 1,
            'effective_from' => $now - 1000, 'effective_until' => $now + 1000, 'reset_policy' => '{}', 'content_hash' => str_repeat('a', 64), 'created_at' => $now]);
        DB::table('tx_subscription')->where('id', $sub)->update(['current_terms_id' => $terms]);
        $period = DB::table('tx_usage_period')->insertGetId(['subscription_id' => $sub, 'terms_id' => $terms, 'period_no' => 1,
            'starts_at' => $now - 1000, 'ends_at' => $now + 1000, 'boundary_kind' => 'known', 'status' => 'open', 'created_at' => $now, 'updated_at' => $now]);
        $subject = DB::table('tx_access_subject')->insertGetId(['subscription_id' => $sub, 'provider_id' => 'isolated-test',
            'provider_subject_ref' => 'subject_'.$sub, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $registry = app(UsageRegistry::class);
        $source = $registry->source($node->id, 'installation_test_0001', $now);
        $tariff = $registry->tariff($node->id, '3', '2', $now - 1000, $now + 1000);
        $grant = $registry->grant($source->id, $subject, $terms, $period, $tariff->id, $now - 100, $now + 100);
        return compact('node','sub','terms','period','subject','source','tariff','grant','now');
    }

    private function usagePayload(array $f, string $batch = 'usage_batch_test_0001', int $offset = 0): array
    {
        $p = ['protocol_version' => 2, 'node_id' => (string) $f['node']->id, 'source_epoch' => (string) $f['source']->source_epoch,
            'batch_id' => $batch, 'started_at' => $f['now'] - 60 + $offset, 'ended_at' => $f['now'] - 30 + $offset,
            'entries' => [['access_subject_id' => (string) $f['subject'], 'auth_snapshot_id' => (string) $f['grant']->id,
                'tariff_revision_id' => (string) $f['tariff']->id, 'terms_id' => (string) $f['terms'], 'usage_period_id' => (string) $f['period'],
                'raw_up_bytes' => '3', 'raw_down_bytes' => '5']]];
        foreach ($p['entries'] as &$e) { ksort($e, SORT_STRING); } unset($e); ksort($p, SORT_STRING);
        $canonical = json_encode($p, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        return (new UsagePayload())->submission(json_encode(['payload' => $p,'payload_digest' => 'sha256:'.hash('sha256', $canonical)]));
    }
}
