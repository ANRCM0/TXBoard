<?php

namespace Tests\Support;

use App\Domains\UsageV2\UsagePayload;
use Illuminate\Support\Facades\DB;

trait UsageV2Fixture
{
    /** Real installation activation, Provider receipt and signed Node authorization. */
    private function usageFixture(): array
    {
        $this->activate();
        [$user, $opened] = $this->subscribe();
        $snapshot = $this->snapshot();
        $now = $snapshot['issued_at'];
        $entry = $snapshot['usage_v2']['grants'][0];
        $node = $this->node;
        $sub = $opened['subscription_id'];
        $terms = $opened['terms_id'];
        $period = $opened['usage_period_id'];
        $subject = $entry['access_subject_id'];
        $grant = DB::table('tx_auth_snapshot')->where('id', $entry['auth_snapshot_id'])->first();
        $source = DB::table('tx_node_usage_source')->where('id', $grant->source_id)->first();
        $tariff = DB::table('tx_tariff_revision')->where('id', $grant->tariff_revision_id)->first();
        return compact('node', 'user', 'sub', 'terms', 'period', 'subject', 'source', 'tariff', 'grant', 'now');
    }

    private function usagePayload(array $f, string $batch = 'usage_batch_test_0001', int $offset = 0): array
    {
        $p = ['protocol_version' => 2, 'node_id' => (string) $f['node']->id, 'source_epoch' => (string) $f['source']->source_epoch,
            'batch_id' => $batch, 'started_at' => $f['now'] + $offset, 'ended_at' => $f['now'] + 1 + $offset,
            'entries' => [['access_subject_id' => (string) $f['subject'], 'auth_snapshot_id' => (string) $f['grant']->id,
                'tariff_revision_id' => (string) $f['tariff']->id, 'terms_id' => (string) $f['terms'], 'usage_period_id' => (string) $f['period'],
                'raw_up_bytes' => '3', 'raw_down_bytes' => '5']]];
        foreach ($p['entries'] as &$e) { ksort($e, SORT_STRING); } unset($e); ksort($p, SORT_STRING);
        $canonical = json_encode($p, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        return (new UsagePayload())->submission(json_encode(['payload' => $p,'payload_digest' => 'sha256:'.hash('sha256', $canonical)]));
    }
}
