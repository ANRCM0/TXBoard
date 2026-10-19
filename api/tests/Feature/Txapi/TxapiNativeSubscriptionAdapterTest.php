<?php

namespace Tests\Feature\Txapi;

use App\Domains\Installation\InstallationAuthority;
use App\Domains\Installation\InstallationAuthorityException;
use App\Http\Controllers\Txapi\AccountController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\NativeSubscriptionTestCase;

class TxapiNativeSubscriptionAdapterTest extends NativeSubscriptionTestCase
{
    public function test_native_adapter_uses_domain_denial_without_legacy_credential_or_quota_fallback(): void
    {
        $user = $this->user(['u' => 111, 'd' => 222, 'transfer_enable' => 99999,
            'expired_at' => time() + 3600]);
        Sanctum::actingAs($user);
        $response = app(AccountController::class)->subscription(
            Request::create('/txapi/me/subscription'));
        $data = $response->getData(true);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotEmpty($data['request_id']);
        $this->assertSame([
            'schema' => 'core-entitlement/v1', 'authorized' => false,
            'reason' => 'ENTITLEMENT_SLOT_MISSING', 'user_id' => (string) $user->id,
            'credential_status' => 'provider_managed',
            'delivery_formats' => ['native-connection-bundle/v1', 'clash/v1', 'clash-meta/v1', 'sing-box/v1', 'shadowrocket/v1', 'surge-ios/v1', 'surge-mac/v1'],
            'delivery_endpoint' => '/txapi/me/subscription-deliveries',
        ], $data['data']);
        $this->assertStringNotContainsString((string) $user->token, $response->getContent());
        $this->assertStringNotContainsString((string) $user->uuid, $response->getContent());
        $this->assertSame(0, DB::table('tx_subscription_slot')->count());
    }

    public function test_native_adapter_propagates_installation_rejection_instead_of_trying_legacy(): void
    {
        Sanctum::actingAs($this->user());
        $authority = \Mockery::mock(InstallationAuthority::class);
        $authority->shouldReceive('assertNativeRead')->once()
            ->andThrow(new InstallationAuthorityException('INSTALLATION_AUTHORITY_NATIVE_NOT_READY'));
        $this->app->instance(InstallationAuthority::class, $authority);
        $this->expectException(InstallationAuthorityException::class);
        $this->expectExceptionMessage('INSTALLATION_AUTHORITY_NATIVE_NOT_READY');
        app(AccountController::class)->subscription(
            Request::create('/txapi/me/subscription'));
    }

    public function test_account_identity_read_does_not_serialize_legacy_entitlements_or_proxy_secret(): void
    {
        $user = $this->user(['u' => 111, 'd' => 222, 'transfer_enable' => 99999,
            'balance' => 88888, 'expired_at' => time() + 3600]);
        Sanctum::actingAs($user);
        $response = app(AccountController::class)->me(Request::create('/txapi/me'));
        $data = $response->getData(true)['data'];
        $this->assertSame((int) $user->id, $data['id']);
        $this->assertFalse($data['subscription']['authorized']);
        foreach (['uuid', 'balance_minor', 'commission_balance_minor', 'traffic', 'expired_at', 'plan_id'] as $field) {
            $this->assertArrayNotHasKey($field, $data);
        }
        $this->assertStringNotContainsString((string) $user->uuid, $response->getContent());
    }
}
