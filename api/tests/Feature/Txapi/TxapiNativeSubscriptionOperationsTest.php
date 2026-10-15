<?php

namespace Tests\Feature\Txapi;

use App\Domains\Installation\InstallationAuthority;
use App\Models\Order;
use App\Services\Subscription\PublishedOrderService;
use App\Services\Subscription\SubscriptionFulfillmentService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\NativeSubscriptionTestCase;

final class TxapiNativeSubscriptionOperationsTest extends NativeSubscriptionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->settings['secure_path'] = 'native-admin';
        app(InstallationAuthority::class)->shouldReceive('assertRuntime')->andReturnNull();
        $plugins = \Mockery::mock(\App\Services\Plugin\PluginManager::class);
        $plugins->shouldReceive('initializeEnabledPlugins')->andReturnNull();
        $this->app->instance(\App\Services\Plugin\PluginManager::class, $plugins);
        (require database_path('migrations/2026_03_11_000001_replace_tx_log_with_admin_audit_log.php'))->up();
    }

    public function test_admin_operation_uses_expected_version_and_replays_without_second_transition(): void
    {
        $owner = $this->user();
        $order = app(PublishedOrderService::class)->create($owner->id, $this->publishedOffer($this->plan()), 'api-open');
        $order->update(['status' => Order::STATUS_PROCESSING]);
        $opened = app(SubscriptionFulfillmentService::class)->fulfill($order->id, time() - 10);
        Sanctum::actingAs($this->user(['is_admin' => true]));
        $path = '/txapi/admin/native-admin/subscriptions/'.$opened['subscription_id'].'/operations/suspend';
        $body = ['expected_version' => $opened['subscription_version'], 'reason' => 'manual'];
        $this->postJson($path, $body, ['Idempotency-Key' => 'api-suspend'])->assertOk()->assertJsonPath('data.status', 'suspended');
        $first = DB::table('tx_subscription')->where('id', $opened['subscription_id'])->first();
        $this->postJson($path, $body, ['Idempotency-Key' => 'api-suspend'])->assertOk();
        $this->assertSame($first->row_version, DB::table('tx_subscription')->where('id', $opened['subscription_id'])->value('row_version'));
        $this->postJson($path, $body, ['Idempotency-Key' => 'stale-operation'])->assertStatus(409)
            ->assertJsonPath('error.code', 'NATIVE_SUBSCRIPTION_VERSION_CONFLICT');
        $this->assertSame(3, DB::table('tx_admin_audit_log')->count());
        $this->assertSame('POST', DB::table('tx_admin_audit_log')->value('method'));
    }

    public function test_non_admin_and_wrong_admin_path_never_enter_domain_service(): void
    {
        Sanctum::actingAs($this->user());
        $body = ['expected_version' => '1'];
        $this->postJson('/txapi/admin/native-admin/subscriptions/1/operations/revoke', $body)->assertStatus(403);
        $this->postJson('/txapi/admin/wrong/subscriptions/1/operations/revoke', $body)->assertStatus(404);
        $this->assertSame(0, DB::table('tx_subscription_operation')->count());
    }

    public function test_missing_idempotency_and_caller_selected_owner_are_rejected(): void
    {
        Sanctum::actingAs($this->user(['is_admin' => true]));
        $this->postJson('/txapi/admin/native-admin/subscriptions/1/operations/revoke', ['expected_version' => '1'])
            ->assertStatus(422)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REQUIRED');
        $this->postJson('/txapi/admin/native-admin/subscriptions/1/operations/revoke', ['expected_version' => '1', 'user_id' => 99],
            ['Idempotency-Key' => 'invalid-owner'])->assertStatus(422);
        $this->assertSame(0, DB::table('tx_subscription_operation')->count());
    }
}
