<?php

namespace Tests\Feature\Txapi;

use App\Domains\InternalApi\ServiceCredentials;
use App\Domains\Subscription\Services\PublishedOrderService;
use App\Services\Auth\NativeIdentityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\NativeRuntimeTestCase;

final class TxapiAdminResourceReadsTest extends NativeRuntimeTestCase
{
    public function test_owner_backed_accounts_and_orders_filter_page_project_and_recheck_authority(): void
    {
        $this->activate();
        config(['internal_api.enabled' => true, 'internal_api.trusted_tls_proxies' => ['127.0.0.1']]);
        admin_setting(['secure_path' => 'resource_admin']);
        [, $admin] = app(NativeIdentityService::class)->create('resource-admin@example.test', 'resource-password');
        [, $user] = app(NativeIdentityService::class)->create('resource-user@example.test', 'private-user-password');
        [, $other] = app(NativeIdentityService::class)->create('resource-other@example.test', 'private-other-password');
        $admin->forceFill(['is_admin' => true])->saveOrFail();
        $token = 'Bearer '.$admin->createToken('resources', ['*'])->plainTextToken;
        $ops = ['identity.accounts.list', 'identity.accounts.get', 'commerce.admin-orders.list', 'commerce.admin-orders.get'];
        $fingerprint = str_repeat('d', 64);
        $service = app(ServiceCredentials::class)->issue('admin-resources', 'admin', $fingerprint, $ops, time() + 3600);
        foreach (['identity.accounts.read', 'commerce.orders.read'] as $permission) {
            DB::table('tx_internal_actor_grant')->insert(['user_id' => $admin->id, 'permission' => $permission, 'created_at' => time()]);
        }
        $invoke = function ($id, $input, $bearer = null, $path = 'resource_admin') use ($service, $fingerprint, $token) {
            return $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTPS' => 'on'])
                ->postJson('https://core.example.test/txapi/internal/native/v1/admin/operations/'.$id, [
                    'contract_version' => '1', 'request_id' => (string) Str::uuid(), 'deadline_at' => time() + 15,
                    'input' => $input === [] ? (object) [] : $input,
                ], ['X-Core-Service-Id' => $service['service_id'], 'X-Core-Service-Credential' => $service['credential'],
                    'X-Core-Service-Epoch' => $service['epoch'], 'X-Core-Tls-Verified' => 'SUCCESS',
                    'X-Core-Tls-Certificate-Sha256' => $fingerprint, 'Authorization' => $bearer ?? $token,
                    'X-Core-Admin-Path' => $path]);
        };
        $offer = collect($this->offers)->firstWhere('offer_code', 'monthly');
        $order = app(PublishedOrderService::class)->create($user->id, (int) $offer['offer_id'], 'resource-order-a');
        $foreign = app(PublishedOrderService::class)->create($other->id, (int) $offer['offer_id'], 'resource-order-b');
        $legacy = $order->getAttributes();
        unset($legacy['id']);
        $legacy['trade_no'] = 'legacy-resource-order';
        $legacy['purchase_terms_version'] = null;
        DB::table('tx_order')->insert($legacy);
        $before = DB::table('tx_order')->orderBy('id')->get()->toJson();

        $accounts = $invoke('identity.accounts.list', ['email' => $user->email, 'banned' => false, 'per_page' => 1])
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', (string) $user->id);
        $this->assertSame(['id', 'email', 'banned', 'is_admin', 'created_at', 'last_login_at'], array_keys($accounts->json('data.0')));
        $invoke('identity.accounts.get', ['user_id' => (string) $user->id])->assertOk()
            ->assertJsonPath('data.subscription.user_id', (string) $user->id)
            ->assertJsonPath('data.credential_status', 'provider_managed');
        $invoke('identity.accounts.get', ['user_id' => '9223372036854775807'])->assertNotFound();
        $invoke('identity.accounts.list', ['email' => '%'])->assertOk()->assertJsonPath('meta.total', 0);
        $invoke('identity.accounts.list', ['balance' => '0'])->assertStatus(422);
        $invoke('identity.accounts.get', ['user_id' => 1])->assertStatus(422);

        $orders = $invoke('commerce.admin-orders.list', ['user_id' => (string) $user->id, 'per_page' => 1, 'status' => 0])
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.trade_no', $order->trade_no)
            ->assertJsonPath('data.0.amount_minor', (string) $order->total_amount);
        $invoke('commerce.admin-orders.list', ['trade_no' => $foreign->trade_no])->assertOk()->assertJsonPath('meta.total', 1);
        $invoke('commerce.admin-orders.get', ['trade_no' => $foreign->trade_no])->assertOk()
            ->assertJsonPath('data.user_id', (string) $other->id)->assertJsonPath('data.currency', 'USD');
        $invoke('commerce.admin-orders.get', ['trade_no' => 'legacy-resource-order'])->assertNotFound();
        $invoke('commerce.admin-orders.list', ['per_page' => 1, 'page' => 2])->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonCount(1, 'data');
        $invoke('commerce.admin-orders.list', ['status' => 5])->assertStatus(422);
        foreach (['password', 'token', 'purchase_terms_json', 'callback_no', 'commission_balance'] as $secret) {
            $this->assertArrayNotHasKey($secret, $orders->json('data.0'));
            $this->assertArrayNotHasKey($secret, $accounts->json('data.0'));
        }
        $this->assertSame($before, DB::table('tx_order')->orderBy('id')->get()->toJson());
        $frozenName = $orders->json('data.0.plan.name');
        DB::table('tx_plan')->where('id', $order->plan_id)->update(['name' => 'Mutable name changed']);
        $invoke('commerce.admin-orders.get', ['trade_no' => $order->trade_no])->assertOk()
            ->assertJsonPath('data.plan.name', $frozenName);

        $ordinary = 'Bearer '.$user->createToken('not-admin', ['*'])->plainTextToken;
        $invoke('identity.accounts.list', [], $ordinary)->assertForbidden();
        $limited = 'Bearer '.$admin->createToken('limited', ['identity.accounts.read'])->plainTextToken;
        $invoke('commerce.admin-orders.list', [], $limited)->assertForbidden();
        $invoke('identity.accounts.list', [], path: 'stale_path')->assertNotFound();
        DB::table('tx_internal_actor_grant')->where('user_id', $admin->id)->where('permission', 'identity.accounts.read')->delete();
        $invoke('identity.accounts.get', ['user_id' => (string) $user->id])->assertForbidden();
        DB::table('tx_internal_service')->where('service_id', $service['service_id'])
            ->update(['operations_json' => json_encode(['identity.accounts.list'])]);
        $invoke('commerce.admin-orders.list', [])->assertForbidden();
    }
}
