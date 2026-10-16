<?php

namespace Tests\Feature\Subscription;

use App\Domains\CredentialProvider\ProviderError;
use App\Domains\CredentialProvider\ProviderTransport;
use App\Models\Order;
use App\Services\Subscription\NativeCredentialProviderService;
use App\Services\Subscription\PublishedOrderService;
use App\Services\Subscription\SubscriptionFulfillmentService;
use Illuminate\Support\Facades\DB;
use Tests\Support\NativeSubscriptionTestCase;

/** Real HTTPS Provider process; only Core's not-yet-open installation admission is substituted. */
final class NativeCredentialProviderInteropTest extends NativeSubscriptionTestCase
{
    protected function setUp(): void
    {
        if (!getenv('CORE_PROVIDER_INTEROP_ENDPOINT')) {
            $this->markTestSkipped('Requires the dedicated real mTLS Provider process.');
        }
        parent::setUp();
        (require database_path('migrations/2026_10_16_000001_enable_native_credential_provider.php'))->up();
        (require database_path('migrations/2026_10_16_000002_enable_native_credential_rotation.php'))->up();
        config(['credential_provider.providers.primary-provider' => [
            'endpoint' => getenv('CORE_PROVIDER_INTEROP_ENDPOINT'),
            'instance_epoch' => '1', 'profiles' => ['vless-uuid/v1', 'vmess-uuid/v1'],
            'ca_file' => getenv('CORE_PROVIDER_INTEROP_CA'),
            'client_cert' => getenv('CORE_PROVIDER_INTEROP_CERT'),
            'client_key' => getenv('CORE_PROVIDER_INTEROP_KEY'),
        ]]);
    }

    public function test_real_process_recovers_lost_response_revokes_and_never_returns_material(): void
    {
        $transport = new class extends ProviderTransport {
            public bool $dropIssue = true;
            public ?int $banAfterIssue = null;
            public array $calls = [];
            public function call(array $registration, array $request): array
            {
                if (DB::transactionLevel() !== 0) {
                    throw new \RuntimeException('PROVIDER_NETWORK_INSIDE_TRANSACTION');
                }
                $this->calls[] = $request['operation'];
                $response = parent::call($registration, $request);
                if ($request['operation'] === 'issue' && $this->banAfterIssue !== null) {
                    DB::table('tx_user')->where('id', $this->banAfterIssue)->update(['banned' => true]);
                    $this->banAfterIssue = null;
                }
                if ($request['operation'] === 'issue' && $this->dropIssue) {
                    $this->dropIssue = false;
                    throw new ProviderError('PROVIDER_TRANSPORT_FAILED', true);
                }
                return $response;
            }
        };
        $this->app->instance(ProviderTransport::class, $transport);
        $service = app(NativeCredentialProviderService::class);
        $service->register('primary-provider', 'real-provider');
        [$user, $subscription] = $this->subscription('first');
        $pending = $service->issue($user->id, (int) $subscription['subscription_id'],
            'primary-provider', 'vless-uuid/v1', 'real-issue', '1');
        $this->assertSame('unknown', $pending['status']);
        $this->assertSame('pending', $pending['subject_status']);
        $restored = $service->recover($user->id, $pending['operation_ref']);
        $this->assertSame('succeeded', $restored['status']);
        $this->assertSame('active', $restored['subject_status']);
        $this->assertSame('1', $restored['credential_generation']);
        $this->assertSame(['issue', 'query'], $transport->calls);
        $this->assertSame($restored['operation_ref'], $service->issue($user->id, (int) $subscription['subscription_id'],
            'primary-provider', 'vless-uuid/v1', 'real-issue', '1')['operation_ref']);
        $this->assertCount(2, $transport->calls);

        $revoked = $service->revoke($user->id, (int) $restored['subject_id'], 'real-revoke', $restored['subject_version']);
        $this->assertSame('succeeded', $revoked['status']);
        $this->assertSame('revoked', $revoked['subject_status']);
        $this->assertNotNull($revoked['provider_revoked_at']);
        $this->assertSame('revoked', $service->recover($user->id, $restored['operation_ref'])['subject_status']);

        [$other, $otherSubscription] = $this->subscription('second');
        $transport->banAfterIssue = $other->id;
        $orphan = $service->issue($other->id, (int) $otherSubscription['subscription_id'],
            'primary-provider', 'vmess-uuid/v1', 'late-issue', '1');
        $this->assertSame('orphaned', $orphan['status']);
        $this->assertSame('revoked', $orphan['subject_status']);
        $this->assertNotNull($orphan['remediation_operation_ref']);
        $this->assertSame('succeeded', $service->operation($other->id, $orphan['remediation_operation_ref'])['status']);
        $this->assertNotNull($orphan['provider_revoked_at']);

        [$third, $thirdSubscription] = $this->subscription('third');
        $transport->dropIssue = true;
        $unknown = $service->issue($third->id, (int) $thirdSubscription['subscription_id'],
            'primary-provider', 'vless-uuid/v1', 'issue-before-revoke', '1');
        $manual = $service->revoke($third->id, (int) $unknown['subject_id'],
            'manual-before-recovery', $unknown['subject_version']);
        $this->assertSame('succeeded', $manual['status']);
        $late = $service->recover($third->id, $unknown['operation_ref']);
        $this->assertSame('orphaned', $late['status']);
        $this->assertSame('revoked', $late['subject_status']);
        $this->assertSame('succeeded', $service->operation($third->id, $late['remediation_operation_ref'])['status']);

        $stored = json_encode([
            DB::table('tx_credential_provider_registration')->get(),
            DB::table('tx_credential_provider_operation')->get(),
            DB::table('tx_access_subject')->get(),
            $restored, $revoked, $orphan,
        ], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($user->uuid, $stored);
        $this->assertStringNotContainsString($user->token, $stored);
        $this->assertStringNotContainsString('PRIVATE KEY', $stored);
        $this->assertSame(0, (int) $user->fresh()->transfer_enable);
        $this->assertSame(3, DB::table('tx_access_subject')->count());
    }

    private function subscription(string $key): array
    {
        $user = $this->user();
        $order = app(PublishedOrderService::class)->create($user->id,
            $this->publishedOffer($this->plan(), key: $key), $key);
        $order->update(['status' => Order::STATUS_PROCESSING]);
        return [$user, app(SubscriptionFulfillmentService::class)->fulfill($order->id, time() - 1)];
    }
}
