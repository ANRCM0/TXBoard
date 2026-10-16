<?php

namespace Tests\Feature\UsageV2;

use App\Domains\UsageV2\UsageError;
use App\Domains\UsageV2\UsagePayload;
use App\Domains\UsageV2\UsageReceipts;
use App\Domains\UsageV2\UsageRegistry;
use App\Domains\UsageV2\UsageSettlement;
use App\Services\Subscription\NativeCredentialSynchronization;
use App\Services\Subscription\NativeCredentialProviderService;
use App\Services\Subscription\SubscriptionLifecycleService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\NativeRuntimeTestCase;

final class NativeRuntimeUsageTest extends NativeRuntimeTestCase
{
    public function test_signed_native_grants_real_subject_and_settlement_changes_only_native_period_once(): void
    {
        $this->activate();
        [$user, $subscription] = $this->subscribe();
        $this->assertSame('completed', DB::table('tx_native_runtime_outbox')->value('status'));
        $snapshot = $this->snapshot();
        $grant = $snapshot['usage_v2']['grants'][0];
        $this->assertSame($snapshot['issued_at'], $grant['issued_at']);
        $this->assertSame($snapshot['expires_at'], $grant['lease_expires_at']);
        $this->assertSame($snapshot['provider']['subjects'][0]['access_subject_id'], $grant['access_subject_id']);
        $this->assertSame(1, DB::table('tx_native_usage_grant')->count());
        $payload = $this->payload($snapshot);
        $receipts = app(UsageReceipts::class);
        $first = $receipts->submit((int) $this->node->id, $payload, $snapshot['issued_at'] + 2);
        $this->assertSame($first, $receipts->submit((int) $this->node->id, $payload, $snapshot['issued_at'] + 3));
        $this->assertSame(['settled'], array_values(app(UsageSettlement::class)->scan(10, $snapshot['issued_at'] + 3)));
        $this->assertSame([], app(UsageSettlement::class)->scan(10, $snapshot['issued_at'] + 4));
        $this->assertSame('11', (string) DB::table('tx_usage_period')->where('id', $subscription['usage_period_id'])->value('billable_bytes'));
        $this->assertSame(0, (int) $user->fresh()->u);
        $this->assertSame(0, (int) $user->fresh()->d);
        $this->assertSame(1, DB::table('tx_usage_entry')->count());
        $this->assertSame($snapshot['usage_v2']['source_epoch'], $this->snapshot()['usage_v2']['source_epoch']);
    }

    public function test_perpetual_subscription_has_finite_grants_and_bills_its_open_period(): void
    {
        $this->activate();
        [, $subscription] = $this->subscribe('onetime');
        $this->assertNull(DB::table('tx_usage_period')->where('id', $subscription['usage_period_id'])->value('ends_at'));
        $snapshot = $this->snapshot();
        $this->assertLessThanOrEqual(120, $snapshot['expires_at'] - $snapshot['issued_at']);
        app(UsageReceipts::class)->submit((int) $this->node->id, $this->payload($snapshot), $snapshot['issued_at'] + 2);
        $this->assertSame(['settled'], array_values(app(UsageSettlement::class)->scan(10, $snapshot['issued_at'] + 3)));
    }

    public function test_tampered_tariff_cannot_change_new_receipt_billing(): void
    {
        $this->activate();
        $this->subscribe();
        $snapshot = $this->snapshot();
        DB::table('tx_tariff_revision')->update(['numerator' => 999]);
        try {
            app(UsageReceipts::class)->submit((int) $this->node->id, $this->payload($snapshot), $snapshot['issued_at'] + 2);
            $this->fail('Changed tariff accepted');
        } catch (UsageError $error) {
            $this->assertSame('USAGE_TARIFF_EVIDENCE_INVALID', $error->errorCode);
        }
        $this->assertSame(0, DB::table('tx_usage_batch')->count());
    }

    public function test_lost_provider_response_leaves_outbox_pending_and_query_recovery_completes_it(): void
    {
        $this->activate();
        $this->loseProviderResponse = true;
        [$user, $subscription] = $this->subscribe();
        $this->assertSame('pending', DB::table('tx_native_runtime_outbox')->value('status'));
        $this->assertSame('unknown', DB::table('tx_credential_provider_operation')->value('status'));
        $this->loseProviderResponse = false;
        $recovered = app(NativeCredentialSynchronization::class)->synchronize($user->id, (int) $subscription['subscription_id']);
        $this->assertTrue($recovered['completed']);
        $this->assertSame('completed', DB::table('tx_native_runtime_outbox')->value('status'));
        $this->assertSame('active', DB::table('tx_access_subject')->value('status'));
        $this->assertSame(1, DB::table('tx_credential_provider_operation')->count());
    }

    public function test_quota_exhaustion_settles_once_closes_the_next_snapshot_and_keeps_historical_receipt_replayable(): void
    {
        $this->activate();
        [$user, $subscription] = $this->subscribe();
        $snapshot = $this->snapshot();
        $quota = (string) DB::table('tx_usage_period')->where('id', $subscription['usage_period_id'])->value('quota_bytes');
        $payload = $this->payload($snapshot, $quota, '0');
        $receipt = app(UsageReceipts::class)->submit((int) $this->node->id, $payload, $snapshot['issued_at'] + 2);
        Redis::shouldReceive('publish')->andReturn(0);
        $this->assertSame(0, Artisan::call('core:native-work', ['--limit' => 10]));
        $worker = json_decode(Artisan::output(), true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame(['settled'], array_values($worker['settlement']));
        $this->assertTrue($worker['credentials'][$subscription['subscription_id']]['completed']);
        $this->assertSame('revoked', DB::table('tx_access_subject')->value('status'));
        $closed = $this->snapshot();
        $this->assertSame([], $closed['users']);
        $this->assertSame([], $closed['usage_v2']['grants']);
        $this->assertSame($snapshot['usage_v2']['source_epoch'], $closed['usage_v2']['source_epoch']);
        $replay = app(UsageReceipts::class)->submit((int) $this->node->id, $payload, $snapshot['issued_at'] + 300);
        $this->assertSame($receipt['receipt_id'], $replay['receipt_id']);
        $this->assertSame('settled', $replay['settlement_status']);
        $this->assertSame(1, DB::table('tx_usage_entry')->count());
        $this->assertSame(bcdiv(bcmul($quota, '150', 0), '100', 0),
            (string) DB::table('tx_usage_period')->where('id', $subscription['usage_period_id'])->value('billable_bytes'));
        $this->assertSame(0, (int) $user->fresh()->u);
        $this->assertSame(0, (int) $user->fresh()->d);
    }

    public function test_direct_rotation_cuts_off_old_grants_but_accepts_their_proven_historical_interval(): void
    {
        $this->activate();
        [$user] = $this->subscribe();
        $snapshot = $this->snapshot();
        $subject = DB::table('tx_access_subject')->first();
        // Production grant times use whole seconds; establish a nonempty historical interval.
        $deadline = microtime(true) + 3;
        while (time() <= $snapshot['issued_at'] && microtime(true) < $deadline) { usleep(20000); }
        $rotation = app(NativeCredentialProviderService::class)->rotate(
            $user->id, (int) $subject->id, 'runtime-direct-rotate', (string) $subject->row_version);
        $this->assertSame('succeeded', $rotation['status']);
        $grant = DB::table('tx_auth_snapshot')->where('id', $snapshot['usage_v2']['grants'][0]['auth_snapshot_id'])->first();
        $this->assertNotNull($grant->revoked_at);
        $cutoff = (int) $grant->revoked_at;
        $this->assertGreaterThan($snapshot['issued_at'], $cutoff);
        $after = $this->payload($snapshot, overrides: [
            'batch_id' => 'runtime-after-rotation', 'started_at' => $cutoff, 'ended_at' => $cutoff + 1]);
        try {
            app(UsageReceipts::class)->submit((int) $this->node->id, $after, $cutoff + 2);
            $this->fail('Old grant admitted after the immediate rotation cutoff');
        } catch (UsageError $error) {
            $this->assertSame('USAGE_NATIVE_AUTHORIZATION_CUTOFF', $error->errorCode);
        }
        $before = $this->payload($snapshot, overrides: ['batch_id' => 'runtime-before-rotation', 'ended_at' => $cutoff]);
        app(UsageReceipts::class)->submit((int) $this->node->id, $before, $cutoff + 2);
        $this->assertSame(['settled'], array_values(app(UsageSettlement::class)->scan(10, $cutoff + 3)));
        $this->assertSame(1, DB::table('tx_usage_entry')->count());
    }

    public function test_verified_pending_subject_does_not_block_other_users_complete_snapshot(): void
    {
        $this->activate();
        [$active] = $this->subscribe();
        $this->loseProviderResponse = true;
        [, $pendingSubscription] = $this->subscribe();
        $this->assertSame('pending', DB::table('tx_access_subject')
            ->where('subscription_id', $pendingSubscription['subscription_id'])->value('status'));
        $snapshot = $this->snapshot();
        $this->assertTrue($snapshot['complete']);
        $this->assertSame([$active->id], array_column($snapshot['users'], 'id'));
        $this->assertSame([(string) $active->id], array_column($snapshot['usage_v2']['grants'], 'user_id'));
    }

    public function test_damaged_pending_operation_is_not_silently_excluded_from_authorization(): void
    {
        $this->activate();
        $this->subscribe();
        $this->loseProviderResponse = true;
        [$pending] = $this->subscribe();
        DB::table('tx_credential_provider_operation')->where('user_id', $pending->id)->update(['request_hash' => str_repeat('0', 64)]);
        $this->expectExceptionMessage('NATIVE_CREDENTIAL_OPERATION_DAMAGED');
        $this->snapshot();
    }

    public function test_reset_cutoff_is_distinguished_from_an_interval_never_covered_by_the_original_grant(): void
    {
        $this->activate();
        [$user, $subscription] = $this->subscribe();
        $snapshot = $this->snapshot();
        app(SubscriptionLifecycleService::class)->reset($user->id, (int) $subscription['subscription_id'],
            'runtime-cutoff-reset', $subscription['subscription_version']);
        $cutoff = (int) DB::table('tx_usage_period')->where('id', $subscription['usage_period_id'])->value('ends_at');
        $after = $this->payload($snapshot, overrides: ['ended_at' => $cutoff + 1]);
        try {
            app(UsageReceipts::class)->submit((int) $this->node->id, $after, $cutoff + 2);
            $this->fail('Old period accepted after a proven reset cutoff');
        } catch (UsageError $error) {
            $this->assertSame('USAGE_NATIVE_AUTHORIZATION_CUTOFF', $error->errorCode);
        }
        $uncovered = $this->payload($snapshot, overrides: ['ended_at' => $snapshot['expires_at'] + 1]);
        try {
            app(UsageReceipts::class)->submit((int) $this->node->id, $uncovered, $snapshot['expires_at'] + 2);
            $this->fail('Originally uncovered bytes classified as a safe cutoff');
        } catch (UsageError $error) {
            $this->assertSame('USAGE_GRANT_OWNERSHIP_OR_COVERAGE', $error->errorCode);
        }
        $this->assertSame(0, DB::table('tx_usage_batch')->count());
        $this->assertSame(0, DB::table('tx_usage_entry')->count());
    }

    #[DataProvider('mixedCutoffCases')]
    public function test_cutoff_never_masks_another_entry_with_invalid_proof_or_scope(int $cutoffIndex, string $damage): void
    {
        $this->activate();
        $this->subscribe();
        $this->subscribe();
        $snapshot = $this->snapshot();
        $grants = $this->sortedGrants($snapshot);
        $this->assertCount(2, $grants);
        $cutoff = $this->rotateGrant($grants[$cutoffIndex], 'runtime-mixed-cutoff');
        $entries = array_map(fn (array $grant): array => $this->usageEntry($grant), $grants);
        $damagedIndex = 1 - $cutoffIndex;
        if ($damage === 'proof') {
            DB::table('tx_native_usage_grant')->where('grant_id', $grants[$damagedIndex]['auth_snapshot_id'])
                ->update(['binding_hash' => str_repeat('0', 64)]);
            $expected = 'USAGE_NATIVE_GRANT_PROOF_INVALID';
        } else {
            $entries[$damagedIndex]['terms_id'] = $grants[$cutoffIndex]['terms_id'];
            $expected = 'USAGE_GRANT_OWNERSHIP_OR_COVERAGE';
        }
        $payload = $this->payload($snapshot, overrides: ['entries' => $entries, 'ended_at' => $cutoff + 1]);
        $this->assertRejectedWithoutUsageWrites($payload, $cutoff + 2, $expected);
    }

    public static function mixedCutoffCases(): array
    {
        return [
            'cutoff before damaged proof' => [0, 'proof'],
            'damaged proof before cutoff' => [1, 'proof'],
            'cutoff before invalid scope' => [0, 'scope'],
            'invalid scope before cutoff' => [1, 'scope'],
        ];
    }

    #[DataProvider('validCutoffCases')]
    public function test_batches_with_only_proven_cutoffs_keep_the_specific_error_without_persisting(array $cutoffIndexes): void
    {
        $this->activate();
        $this->subscribe();
        $this->subscribe();
        $snapshot = $this->snapshot();
        $grants = $this->sortedGrants($snapshot);
        $this->assertCount(2, $grants);
        $cutoff = $snapshot['issued_at'];
        foreach ($cutoffIndexes as $index) {
            $cutoff = max($cutoff, $this->rotateGrant($grants[$index], 'runtime-valid-cutoff-'.$index));
        }
        $payload = $this->payload($snapshot, overrides: ['ended_at' => $cutoff + 1,
            'entries' => array_map(fn (array $grant): array => $this->usageEntry($grant), $grants)]);
        $this->assertRejectedWithoutUsageWrites($payload, $cutoff + 2, 'USAGE_NATIVE_AUTHORIZATION_CUTOFF');
    }

    public static function validCutoffCases(): array
    {
        return ['first entry only' => [[0]], 'last entry only' => [[1]], 'every entry' => [[0, 1]]];
    }

    #[DataProvider('invalidCutoffBillingCases')]
    public function test_a_proven_cutoff_does_not_mask_invalid_billing_in_the_same_entry(array $changes, string $expected): void
    {
        $this->activate();
        $this->subscribe();
        $snapshot = $this->snapshot();
        $grant = $snapshot['usage_v2']['grants'][0];
        $cutoff = $this->rotateGrant($grant, 'runtime-billing-cutoff');
        $tariff = DB::table('tx_tariff_revision')->where('id', $grant['tariff_revision_id'])->first();
        $changed = array_replace(['node_id' => (int) $tariff->node_id, 'numerator' => (string) $tariff->numerator,
            'denominator' => (string) $tariff->denominator, 'effective_from' => (int) $tariff->effective_from,
            'effective_until' => (int) $tariff->effective_until, 'rounding_revision' => $tariff->rounding_revision], $changes);
        DB::table('tx_tariff_revision')->where('id', $tariff->id)
            ->update($changed + ['content_hash' => UsageRegistry::hash($changed)]);
        $payload = $this->payload($snapshot, overrides: ['ended_at' => $cutoff + 1]);
        $this->assertRejectedWithoutUsageWrites($payload, $cutoff + 2, $expected);
    }

    public static function invalidCutoffBillingCases(): array
    {
        return [
            'unsupported rounding' => [['rounding_revision' => 'unsupported-rounding'], 'USAGE_ROUNDING_UNSUPPORTED'],
            'zero denominator' => [['denominator' => '0'], 'USAGE_DECIMAL_INVALID'],
            'billing overflow' => [['numerator' => '9223372036854775807', 'denominator' => '1'], 'USAGE_ACCOUNTING_OVERFLOW'],
        ];
    }

    private function sortedGrants(array $snapshot): array
    {
        $grants = $snapshot['usage_v2']['grants'];
        $tuple = static fn (array $grant): string => implode("\0", array_map(
            static fn (string $key): string => $grant[$key],
            ['access_subject_id', 'auth_snapshot_id', 'tariff_revision_id', 'usage_period_id']));
        usort($grants, static fn (array $a, array $b): int => strcmp($tuple($a), $tuple($b)));
        return $grants;
    }

    private function rotateGrant(array $grant, string $key): int
    {
        $subject = DB::table('tx_access_subject')->where('id', $grant['access_subject_id'])->first();
        $rotation = app(NativeCredentialProviderService::class)->rotate(
            (int) $grant['user_id'], (int) $subject->id, $key, (string) $subject->row_version);
        $this->assertSame('succeeded', $rotation['status']);
        $cutoff = DB::table('tx_auth_snapshot')->where('id', $grant['auth_snapshot_id'])->value('revoked_at');
        $this->assertNotNull($cutoff);
        $this->assertGreaterThanOrEqual($grant['issued_at'], (int) $cutoff);
        $this->assertLessThan($grant['lease_expires_at'], (int) $cutoff);
        return (int) $cutoff;
    }

    private function assertRejectedWithoutUsageWrites(array $payload, int $now, string $expected): void
    {
        try {
            app(UsageReceipts::class)->submit((int) $this->node->id, $payload, $now);
            $this->fail('Invalid or cut off batch persisted');
        } catch (UsageError $error) {
            $this->assertSame($expected, $error->errorCode);
            $this->assertSame(422, $error->httpStatus);
        }
        $this->assertSame(0, DB::table('tx_usage_batch')->count());
        $this->assertSame(0, DB::table('tx_usage_entry')->count());
        $this->assertSame(0, DB::table('tx_usage_outbox')->count());
        $this->assertSame(0, (int) DB::table('tx_usage_period')->sum('billable_bytes'));
        $this->assertSame(0, (int) DB::table('tx_user')->sum('u'));
        $this->assertSame(0, (int) DB::table('tx_user')->sum('d'));
    }

    private function usageEntry(array $grant, string $up = '3', string $down = '5'): array
    {
        return array_intersect_key($grant, array_flip([
            'access_subject_id', 'auth_snapshot_id', 'tariff_revision_id', 'terms_id', 'usage_period_id',
        ])) + ['raw_up_bytes' => $up, 'raw_down_bytes' => $down];
    }

    private function payload(array $snapshot, string $up = '3', string $down = '5', array $overrides = []): array
    {
        $grant = $snapshot['usage_v2']['grants'][0];
        $entry = $this->usageEntry($grant, $up, $down);
        $payload = ['protocol_version' => 2, 'node_id' => (string) $this->node->id, 'source_epoch' => $snapshot['usage_v2']['source_epoch'],
            'batch_id' => 'runtime-batch-0001', 'started_at' => $snapshot['issued_at'], 'ended_at' => $snapshot['issued_at'] + 1, 'entries' => [$entry]];
        $payload = array_replace($payload, $overrides);
        $canonical = \App\Domains\CredentialProvider\ProviderCodec::canonical($payload);
        return app(UsagePayload::class)->submission(json_encode(['payload' => $payload,
            'payload_digest' => 'sha256:'.hash('sha256', $canonical)], JSON_THROW_ON_ERROR));
    }
}
