<?php

namespace Tests\Feature\Subscription;

use App\Domains\CredentialProvider\ProviderError;
use App\Domains\CredentialProvider\ProviderTransport;
use App\Models\Order;
use App\Services\Subscription\NativeCredentialProviderService;
use App\Services\Subscription\PublishedOrderService;
use App\Services\Subscription\SubscriptionFulfillmentService;
use App\Services\Subscription\SubscriptionLifecycleService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\Support\NativeSubscriptionTestCase;
use Throwable;

class NativeCredentialProviderTest extends NativeSubscriptionTestCase
{
    private array $calls = [];
    private array $receipts = [];
    private array $tombstones = [];
    private int $providerClockOffset = 0;
    private mixed $exchange = null;
    private ProviderTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_10_16_000001_enable_native_credential_provider.php'))->up();
        $this->transport = Mockery::mock(ProviderTransport::class);
        $this->transport->shouldReceive('configuration')->with('fixture-provider')->andReturn([
            'provider_id' => 'fixture-provider', 'provider_instance_epoch' => '7',
            'config_digest' => str_repeat('a', 64), 'profiles' => ['vless-uuid/v1', 'vmess-uuid/v1'],
        ]);
        $this->transport->shouldReceive('call')->andReturnUsing(function (array $registration, array $wire): array {
            $this->assertSame(0, DB::transactionLevel(), 'Provider calls must happen after the intent transaction commits.');
            $this->assertSame('7', $registration['provider_instance_epoch']);
            $this->assertSame(str_repeat('a', 64), $registration['config_digest']);
            $this->assertSame(1, DB::table('tx_credential_provider_operation')
                ->where('operation_ref', $wire['operation_id'])->count());
            $this->calls[] = $wire;
            return $this->exchange === null ? $this->respond($wire) : ($this->exchange)($wire);
        });
        $this->app->instance(ProviderTransport::class, $this->transport);
        $this->service()->register('fixture-provider', 'register');
    }

    public function test_registration_replay_and_conflict_have_no_network_or_shadow_promotion(): void
    {
        $first = $this->service()->register('fixture-provider', 'register');
        $this->assertSame($first, $this->service()->register('fixture-provider', 'register'));
        $this->assertSame('7', $first['provider_instance_epoch']);
        $this->reject(fn () => $this->service()->register('fixture-provider', 'different'), 'REGISTRATION_CONFLICT');
        $this->assertSame([], $this->calls);
        $this->assertSame(0, DB::table('tx_access_subject')->count());
        $this->assertSame(1, DB::table('tx_credential_provider_registration')->count());
        $this->expectExceptionMessage('NATIVE_CREDENTIAL_POPULATED_ROLLBACK_REFUSED');
        (require database_path('migrations/2026_10_16_000001_enable_native_credential_provider.php'))->down();
    }

    public function test_issue_binds_metadata_once_and_replays_without_material_or_legacy_user_writes(): void
    {
        [$user, $subscription] = $this->subscription();
        $before = $user->fresh()->getAttributes();
        $first = $this->issue($user->id, $subscription);
        $this->assertSame('succeeded', $first['status']);
        $this->assertSame('active', $first['subject_status']);
        $this->assertSame('2', $first['subject_version']);
        $this->assertMatchesRegularExpression('/\Acredential_[0-9a-f]{32}\z/D', $first['credential_ref']);
        $this->assertSame('1', $first['credential_generation']);
        $this->assertSame($first, $this->issue($user->id, $subscription));
        $this->assertCount(1, $this->calls);
        $this->assertSame($before, $user->fresh()->getAttributes());
        $serialized = json_encode($first);
        foreach ([$user->uuid, $user->token, 'credential_material', 'private_key', 'password'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
        $subject = DB::table('tx_access_subject')->first();
        $this->assertSame(NativeCredentialProviderService::SUBJECT, $subject->authority_version);
        $this->assertSame(self::INSTALLATION, $subject->installation_id);
        $this->assertSame((string) $subscription->current_terms_id, (string) $subject->bound_terms_id);
        $this->reject(fn () => $this->issue($user->id, $subscription, 'another'), 'SUBJECT_ALREADY_EXISTS');
        $this->reject(fn () => $this->service()->issue($user->id, $subscription->id, 'fixture-provider',
            'vmess-uuid/v1', 'issue', $subscription->row_version), 'IDEMPOTENCY_CONFLICT');
    }

    public function test_lost_issue_response_recovers_by_query_without_reissuing(): void
    {
        [$user, $subscription] = $this->subscription();
        $this->exchange = function (array $wire): array {
            $response = $this->respond($wire);
            if ($wire['operation'] === 'issue') {
                throw new ProviderError('PROVIDER_TRANSPORT_FAILED', true);
            }
            return $response;
        };
        $pending = $this->issue($user->id, $subscription);
        $this->assertSame('unknown', $pending['status']);
        $this->assertSame('pending', $pending['subject_status']);
        $this->assertNull($pending['credential_ref']);
        $recovered = $this->service()->recover($user->id, $pending['operation_ref']);
        $this->assertSame('succeeded', $recovered['status']);
        $this->assertSame(['issue', 'query'], array_column($this->calls, 'operation'));
        $this->assertSame(1, DB::table('tx_credential_provider_operation')->count());
        $this->assertSame($this->calls[0]['operation_id'], $this->calls[1]['operation_id']);
        $this->assertSame($this->calls[0]['idempotency_key'], $this->calls[1]['idempotency_key']);
    }

    public function test_query_missing_resends_identical_issue_identity_hash_and_input(): void
    {
        [$user, $subscription] = $this->subscription();
        $this->exchange = fn () => throw new ProviderError('PROVIDER_TRANSPORT_FAILED', true);
        $pending = $this->issue($user->id, $subscription);
        $this->exchange = null;
        $recovered = $this->service()->recover($user->id, $pending['operation_ref']);
        $this->assertSame('succeeded', $recovered['status']);
        $this->assertSame(['issue', 'query', 'issue'], array_column($this->calls, 'operation'));
        foreach (['operation_id', 'idempotency_key', 'input_hash', 'input'] as $field) {
            $this->assertSame($this->calls[0][$field], $this->calls[2][$field]);
        }
        $this->assertSame(1, DB::table('tx_access_subject')->count());
    }

    public function test_revoke_is_local_before_network_and_unknown_recovery_does_not_restore_subject(): void
    {
        [$user, $subscription] = $this->subscription();
        $issued = $this->issue($user->id, $subscription);
        $this->exchange = function (array $wire): array {
            $this->assertSame('revoked', DB::table('tx_access_subject')->value('status'));
            $response = $this->respond($wire);
            if ($wire['operation'] === 'revoke') {
                throw new ProviderError('PROVIDER_TRANSPORT_FAILED', true);
            }
            return $response;
        };
        $revoked = $this->service()->revoke($user->id, (int) $issued['subject_id'], 'revoke', $issued['subject_version']);
        $this->assertSame('unknown', $revoked['status']);
        $this->assertSame('revoked', $revoked['subject_status']);
        $this->assertSame('1', $revoked['revocation_fact_version']);
        $this->assertSame('3', $revoked['subject_version']);
        $recovered = $this->service()->recover($user->id, $revoked['operation_ref']);
        $this->assertSame('succeeded', $recovered['status']);
        $this->assertNotNull($recovered['provider_revoked_at']);
        $this->assertSame('revoked', $this->issue($user->id, $subscription)['subject_status']);
        $this->assertSame(['issue', 'revoke', 'query'], array_column($this->calls, 'operation'));
    }

    public function test_ban_during_issue_keeps_receipt_orphaned_and_revokes_provider_subject(): void
    {
        [$user, $subscription] = $this->subscription();
        $this->exchange = function (array $wire) use ($user): array {
            if ($wire['operation'] === 'issue') {
                DB::table('tx_user')->where('id', $user->id)->update(['banned' => true]);
            }
            return $this->respond($wire);
        };
        $result = $this->issue($user->id, $subscription);
        $this->assertSame('orphaned', $result['status']);
        $this->assertSame('revoked', $result['subject_status']);
        $this->assertNotNull($result['credential_ref']);
        $this->assertNotNull($result['provider_revoked_at']);
        $this->assertNotNull($result['remediation_operation_ref']);
        $this->assertSame(['issue', 'query', 'revoke'], array_column($this->calls, 'operation'));
        $this->assertSame('ACCOUNT_BANNED', $this->calls[2]['input']['reason_code']);
        $this->assertSame(2, DB::table('tx_credential_provider_operation')->count());
    }

    public function test_subscription_change_during_issue_cannot_bind_late_receipt(): void
    {
        [$user, $subscription] = $this->subscription();
        $this->exchange = function (array $wire) use ($user, $subscription): array {
            if ($wire['operation'] === 'issue') {
                app(SubscriptionLifecycleService::class)->suspend($user->id, $subscription->id, 'pause',
                    $subscription->row_version);
            }
            return $this->respond($wire);
        };
        $result = $this->issue($user->id, $subscription);
        $this->assertSame('orphaned', $result['status']);
        $this->assertSame('revoked', $result['subject_status']);
        $this->assertSame('SUBSCRIPTION_CHANGED', $this->calls[2]['input']['reason_code']);
    }

    public function test_revocation_during_unknown_issue_cannot_be_overwritten_by_old_issue_receipt(): void
    {
        [$user, $subscription] = $this->subscription();
        $this->exchange = function (array $wire): array {
            $response = $this->respond($wire);
            if ($wire['operation'] === 'issue') {
                throw new ProviderError('PROVIDER_TRANSPORT_FAILED', true);
            }
            return $response;
        };
        $pending = $this->issue($user->id, $subscription);
        $revoked = $this->service()->revoke($user->id, (int) $pending['subject_id'], 'cancel-pending', $pending['subject_version']);
        $this->assertSame('succeeded', $revoked['status']);
        $result = $this->service()->recover($user->id, $pending['operation_ref']);
        $this->assertSame('orphaned', $result['status']);
        $this->assertSame('revoked', $result['subject_status']);
        $this->assertSame('2', $result['revocation_fact_version']);
        $this->assertSame('3', $result['subject_version']);
        $remediation = $this->service()->operation($user->id, $result['remediation_operation_ref']);
        $this->assertSame('succeeded', $remediation['status']);
        $this->assertSame(['issue', 'revoke', 'query', 'query', 'revoke'], array_column($this->calls, 'operation'));
        $this->assertSame('1', $this->calls[1]['input']['revocation_fact_version']);
        $this->assertSame('MANUAL', $this->calls[1]['input']['reason_code']);
        $this->assertSame('2', $this->calls[4]['input']['revocation_fact_version']);
        $this->assertSame('SUBSCRIPTION_CHANGED', $this->calls[4]['input']['reason_code']);
    }

    public function test_old_revoke_accepts_higher_provider_fact_and_first_revocation_time(): void
    {
        [$user, $subscription] = $this->subscription();
        $issued = $this->issue($user->id, $subscription);
        $this->exchange = fn () => throw new ProviderError('PROVIDER_TRANSPORT_FAILED', true);
        $old = $this->service()->revoke($user->id, (int) $issued['subject_id'], 'old-fact', $issued['subject_version']);
        $this->assertSame('unknown', $old['status']);
        $this->exchange = null;
        $this->providerClockOffset = -1;
        $new = $this->service()->revoke($user->id, (int) $issued['subject_id'], 'new-fact',
            $old['subject_version'], 'ACCOUNT_BANNED');
        $this->assertSame('succeeded', $new['status']);
        $this->assertSame('2', $new['revocation_fact_version']);
        $this->assertSame('4', $new['subject_version']);
        $this->assertLessThan($this->calls[2]['input']['effective_at'], $new['provider_revoked_at']);
        $recovered = $this->service()->recover($user->id, $old['operation_ref']);
        $this->assertSame('succeeded', $recovered['status']);
        $this->assertSame($new['provider_revoked_at'], $recovered['provider_revoked_at']);
        $receipt = json_decode(DB::table('tx_credential_provider_operation')->where('operation_ref', $old['operation_ref'])
            ->value('result_snapshot'), true);
        $this->assertSame('2', $receipt['accepted_revocation_fact_version']);
        $before = count($this->calls);
        $replay = $this->service()->revoke($user->id, (int) $issued['subject_id'], 'new-fact',
            $old['subject_version'], 'ACCOUNT_BANNED');
        $this->assertSame($new, $replay);
        $this->assertCount($before, $this->calls);
        $this->assertSame('2', $replay['revocation_fact_version']);
    }

    public function test_terminal_operation_refuses_missing_receipt_or_changed_subject_metadata(): void
    {
        [$user, $subscription] = $this->subscription();
        $issued = $this->issue($user->id, $subscription);
        $operation = DB::table('tx_credential_provider_operation')->where('operation_ref', $issued['operation_ref'])->first();
        DB::table('tx_credential_provider_operation')->where('id', $operation->id)->update(['result_snapshot' => null]);
        $this->reject(fn () => $this->service()->recover($user->id, $issued['operation_ref']), 'OPERATION_DAMAGED');
        $this->reject(fn () => $this->service()->operation($user->id, $issued['operation_ref']), 'OPERATION_DAMAGED');
        DB::table('tx_credential_provider_operation')->where('id', $operation->id)->update([
            'result_snapshot' => $operation->result_snapshot, 'result_hash' => null,
        ]);
        $this->reject(fn () => $this->service()->recover($user->id, $issued['operation_ref']), 'OPERATION_DAMAGED');
        DB::table('tx_credential_provider_operation')->where('id', $operation->id)->update(['result_hash' => $operation->result_hash]);
        DB::table('tx_access_subject')->where('id', $issued['subject_id'])->update(['credential_ref' => 'credential_'.str_repeat('b', 32)]);
        $this->reject(fn () => $this->service()->recover($user->id, $issued['operation_ref']), 'OPERATION_DAMAGED');
        $this->assertCount(1, $this->calls);
    }

    public function test_bad_receipt_retains_unknown_and_bounded_diagnostic_without_serializing_body(): void
    {
        [$user, $subscription] = $this->subscription();
        $this->exchange = function (array $wire): array {
            $response = $this->respond($wire);
            $response['result']['unexpected_secret'] = 'DO_NOT_PERSIST_THIS';
            return $response;
        };
        $result = $this->issue($user->id, $subscription);
        $this->assertSame('unknown', $result['status']);
        $this->assertSame('PROVIDER_RESPONSE_INVALID', $result['error_code']);
        $this->assertNull(DB::table('tx_credential_provider_operation')->value('result_snapshot'));
        $this->assertStringNotContainsString('DO_NOT_PERSIST_THIS', json_encode($result));
        $this->assertSame('pending', $result['subject_status']);
    }

    public function test_ownership_stale_version_outer_transaction_and_admission_deny_before_network(): void
    {
        [$user, $subscription] = $this->subscription();
        $other = $this->user();
        $this->reject(fn () => $this->issue($other->id, $subscription), 'SUBSCRIPTION_MISSING');
        $this->reject(fn () => $this->service()->issue($user->id, $subscription->id, 'fixture-provider',
            'vless-uuid/v1', 'stale', '999'), 'VERSION_CONFLICT');
        $this->reject(fn () => DB::transaction(fn () => $this->issue($user->id, $subscription)), 'OUTER_TRANSACTION_UNSUPPORTED');
        $this->assertSame([], $this->calls);
        $this->assertSame(0, DB::table('tx_access_subject')->count());
        $issued = $this->issue($user->id, $subscription);
        $this->reject(fn () => $this->service()->recover($other->id, $issued['operation_ref']), 'OPERATION_MISSING');
        $this->reject(fn () => $this->service()->revoke($other->id, (int) $issued['subject_id'], 'foreign', '2'), 'SUBJECT_MISSING');
        $this->denyNativeAdmission = true;
        try {
            $this->service()->recover($user->id, $issued['operation_ref']);
            $this->fail('Native admission must reject.');
        } catch (Throwable $error) {
            $this->assertSame('INSTALLATION_NATIVE_NOT_READY', $error->getMessage());
        }
        $this->assertCount(1, $this->calls);
    }

    public function test_shadow_subject_cannot_be_upgraded_or_recovered_as_native(): void
    {
        [$user, $subscription] = $this->subscription();
        $subject = DB::table('tx_access_subject')->insertGetId([
            'subscription_id' => $subscription->id, 'provider_id' => 'fixture-provider',
            'provider_subject_ref' => 'shadow-reference', 'status' => 'active',
            'auth_version' => 1, 'revocation_fact_version' => 0, 'row_version' => 1,
            'created_at' => time(), 'updated_at' => time(),
        ]);
        $this->reject(fn () => $this->issue($user->id, $subscription), 'SUBJECT_INVALID');
        $this->reject(fn () => $this->service()->revoke($user->id, $subject, 'shadow', '1'), 'SUBJECT_INVALID');
        $this->assertNull(DB::table('tx_access_subject')->value('authority_version'));
        $this->assertSame([], $this->calls);
    }

    public function test_read_only_operation_does_not_recreate_missing_account_slot(): void
    {
        [$user, $subscription] = $this->subscription();
        $issued = $this->issue($user->id, $subscription);
        DB::table('tx_subscription_slot')->where('user_id', $user->id)->delete();
        $this->reject(fn () => $this->service()->operation($user->id, $issued['operation_ref']), 'SUBJECT_INVALID');
        $this->assertSame(0, DB::table('tx_subscription_slot')->count());
        $this->assertCount(1, $this->calls);
    }

    public function test_schema_rejects_terms_from_another_subscription(): void
    {
        [$user, $subscription] = $this->subscription();
        $issued = $this->issue($user->id, $subscription);
        [, $other] = $this->subscription();
        $this->expectException(QueryException::class);
        DB::table('tx_access_subject')->where('id', $issued['subject_id'])->update(['bound_terms_id' => $other->current_terms_id]);
    }

    public function test_empty_migration_reverses_and_reapplies_without_promoting_shadow_rows(): void
    {
        DB::table('tx_credential_provider_registration')->delete();
        $migration = require database_path('migrations/2026_10_16_000001_enable_native_credential_provider.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('tx_credential_provider_registration'));
        $this->assertFalse(Schema::hasColumn('tx_access_subject', 'authority_version'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('tx_credential_provider_operation'));
        $this->assertTrue(Schema::hasColumn('tx_access_subject', 'bound_terms_id'));
        $this->assertSame(0, DB::table('tx_access_subject')->count());
    }

    private function subscription(): array
    {
        $user = $this->user();
        $order = app(PublishedOrderService::class)->create($user->id, $this->publishedOffer($this->plan()), 'purchase');
        DB::table('tx_order')->where('id', $order->id)->update(['status' => Order::STATUS_PROCESSING]);
        $fulfilled = app(SubscriptionFulfillmentService::class)->fulfill($order->id, time() - 5);
        return [$user, DB::table('tx_subscription')->where('id', $fulfilled['subscription_id'])->first()];
    }

    private function issue(int $userId, object $subscription, string $key = 'issue'): array
    {
        return $this->service()->issue($userId, (int) $subscription->id, 'fixture-provider', 'vless-uuid/v1',
            $key, (string) $subscription->row_version);
    }

    private function service(): NativeCredentialProviderService
    {
        return app(NativeCredentialProviderService::class);
    }

    private function respond(array $wire): array
    {
        $input = $wire['input'];
        $base = [
            'contract_version' => 'credential-provider/1', 'request_id' => $wire['request_id'],
            'operation_id' => $wire['operation_id'], 'provider_id' => $wire['provider_id'],
            'provider_instance_epoch' => $wire['provider_instance_epoch'], 'status' => 'succeeded', 'error' => null,
        ];
        if ($wire['operation'] === 'query') {
            if (!isset($this->receipts[$wire['operation_id']])) {
                return array_replace($base, ['status' => 'unknown', 'result' => null,
                    'error' => ['code' => 'OPERATION_UNKNOWN', 'retryable' => true]]);
            }
            return $base + ['result' => $this->receipts[$wire['operation_id']]];
        }
        if (isset($this->receipts[$wire['operation_id']])) {
            $original = $this->receipts[$wire['operation_id']];
            if ($original['operation'] !== $wire['operation'] || $original['input_hash'] !== $wire['input_hash']) {
                return array_replace($base, ['status' => 'rejected', 'result' => null,
                    'error' => ['code' => 'IDEMPOTENCY_CONFLICT', 'retryable' => false]]);
            }
            return $base + ['result' => $original['result']];
        }
        if ($wire['operation'] === 'issue') {
            if (isset($this->tombstones[$input['provider_subject_ref']])) {
                return array_replace($base, ['status' => 'rejected', 'result' => null,
                    'error' => ['code' => 'SUBJECT_REVOKED', 'retryable' => false]]);
            }
            $result = [
                'access_subject_id' => $input['access_subject_id'], 'provider_subject_ref' => $input['provider_subject_ref'],
                'credential_profile_id' => $input['credential_profile_id'], 'credential_ref' => 'credential_'.str_repeat('a', 32),
                'credential_generation' => '1', 'issued_at' => time(), 'not_before_at' => $input['not_before_at'],
                'credential_expires_at' => $input['credential_expires_at'],
            ];
        } else {
            $previous = $this->tombstones[$input['provider_subject_ref']] ?? null;
            $requested = (int) $input['revocation_fact_version'];
            $current = $previous === null ? 0 : (int) $previous['revocation_fact_version'];
            if ($current === $requested && ($previous['effective_at'] !== $input['effective_at']
                || $previous['reason_code'] !== $input['reason_code'])) {
                return array_replace($base, ['status' => 'rejected', 'result' => null,
                    'error' => ['code' => 'REVOCATION_FACT_CONFLICT', 'retryable' => false]]);
            }
            if ($requested > $current) {
                $this->tombstones[$input['provider_subject_ref']] = [
                    'revocation_fact_version' => $input['revocation_fact_version'],
                    'effective_at' => $input['effective_at'], 'reason_code' => $input['reason_code'],
                    'provider_revoked_at' => $previous['provider_revoked_at'] ?? time() + $this->providerClockOffset,
                ];
            }
            $accepted = $this->tombstones[$input['provider_subject_ref']];
            $result = [
                'access_subject_id' => $input['access_subject_id'], 'provider_subject_ref' => $input['provider_subject_ref'],
                'accepted_revocation_fact_version' => $accepted['revocation_fact_version'],
                'provider_revoked_at' => $accepted['provider_revoked_at'],
            ];
        }
        $this->receipts[$wire['operation_id']] = [
            'operation' => $wire['operation'], 'input_hash' => $wire['input_hash'], 'result' => $result,
        ];
        return $base + ['result' => $result];
    }

    private function reject(callable $operation, string $suffix): void
    {
        try {
            $operation();
            $this->fail('Expected NATIVE_CREDENTIAL_'.$suffix);
        } catch (DomainException $error) {
            $this->assertSame('NATIVE_CREDENTIAL_'.$suffix, $error->getMessage());
        }
    }
}
