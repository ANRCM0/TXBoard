<?php

namespace Tests\Feature\Subscription;

use App\Domains\CredentialProvider\ProviderCodec;
use App\Domains\UsageV2\NativeUsageCorrections;
use App\Domains\UsageV2\UsageError;
use App\Domains\UsageV2\UsagePayload;
use App\Domains\UsageV2\UsageReceipts;
use App\Domains\UsageV2\UsageSettlement;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\NativeMysqlWorkflowRace;
use Tests\Support\NativeRuntimeTestCase;
use Tests\Support\NativeWorkflowActors;

final class NativeUsageCorrectionMysqlConcurrencyTest extends NativeRuntimeTestCase
{
    use NativeWorkflowActors, NativeMysqlWorkflowRace;

    public function test_correction_and_pending_original_settlement_preserve_one_projection_in_both_commit_orders(): void
    {
        $this->requireMysqlProcesses(); $this->activate(); [$user] = $this->subscribe();
        $admin = $this->workflowActor(true, [NativeUsageCorrections::PERMISSION]); $token = $this->authenticateWorkflowActor($admin); $snapshot = $this->snapshot();
        $this->submit($snapshot, 'mysql-correction-original', '3', '5'); app(UsageSettlement::class)->scan(10, time() + 2);
        while (time() <= $snapshot['issued_at'] + 1) usleep(100000);
        foreach ([true, false] as $correctionFirst) {
            $this->authenticateWorkflowActor($admin); $owner = app(NativeUsageCorrections::class);
            $entry = collect($owner->entries($admin->id, $user->id)['items'])->firstWhere('entry_id', (string) DB::table('tx_usage_entry')->orderBy('id')->value('id'));
            $this->submit($snapshot, 'mysql-correction-pending-'.($correctionFirst ? 'a' : 'b'), '2', '2');
            $input = ['entry_id' => $entry['entry_id'], 'expected_entry_revision' => $entry['entry_revision'], 'expected_period_version' => $entry['period_version'],
                'billed_up_bytes' => $correctionFirst ? '2' : '1', 'billed_down_bytes' => $correctionFirst ? '3' : '2', 'reason' => 'mysql_usage_case'];
            $correction = function () use ($admin, $token, $user, $input): array {
                Auth::forgetGuards(); Auth::guard('sanctum')->setUser($admin->withAccessToken($token));
                try { return app(NativeUsageCorrections::class)->create($admin->id, $user->id, $input, $this->workflowKey()); }
                catch (UsageError $error) { return ['error' => $error->errorCode]; }
            };
            $settle = fn (): array => ['states' => app(UsageSettlement::class)->scan(10, time() + 2)];
            $results = $this->race($correctionFirst ? [$correction, $settle] : [$settle, $correction], true);
            if ($correctionFirst) $this->assertSame('5', $results[0]['period_billable_bytes']);
            else $this->assertSame('USAGE_CORRECTION_VERSION_CONFLICT', $results[1]['error']);
            $this->assertSame($correctionFirst ? '11' : '17', (string) DB::table('tx_usage_period')->value('billable_bytes'));
            $this->assertSame(0, DB::table('tx_native_usage_reservation')->count());
        }
        $this->assertSame(1, DB::table('tx_native_usage_correction')->count()); $this->assertSame(3, DB::table('tx_usage_entry')->count());
        $this->assertSame('7', (string) DB::table('tx_node_usage_stat')->value('raw_up_bytes')); $this->assertSame('9', (string) DB::table('tx_node_usage_stat')->value('raw_down_bytes'));
    }

    private function submit(array $snapshot, string $batch, string $up, string $down): void
    {
        $grant = $snapshot['usage_v2']['grants'][0];
        $payload = ['protocol_version' => 2, 'node_id' => (string) $this->node->id, 'source_epoch' => $snapshot['usage_v2']['source_epoch'], 'batch_id' => $batch,
            'started_at' => $snapshot['issued_at'], 'ended_at' => $snapshot['issued_at'] + 1, 'entries' => [array_intersect_key($grant, array_flip(['access_subject_id', 'auth_snapshot_id', 'tariff_revision_id', 'terms_id', 'usage_period_id'])) + ['raw_up_bytes' => $up, 'raw_down_bytes' => $down]]];
        $submission = app(UsagePayload::class)->submission(json_encode(['payload' => $payload, 'payload_digest' => 'sha256:'.hash('sha256', ProviderCodec::canonical($payload))], JSON_THROW_ON_ERROR));
        app(UsageReceipts::class)->submit((int) $this->node->id, $submission, $snapshot['issued_at'] + 2);
    }
}
