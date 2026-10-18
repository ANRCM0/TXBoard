<?php

namespace Tests\Feature\Txapi;

use App\Domains\InternalApi\ServiceCredentials;
use App\Services\Auth\NativeIdentityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\NativeRuntimeTestCase;

final class TxapiAdminBrowserContractTest extends NativeRuntimeTestCase
{
    public function test_login_and_read_contracts_require_current_admin_path_and_grants_and_never_project_credentials(): void
    {
        $this->activate();
        config(['internal_api.enabled' => true, 'internal_api.trusted_tls_proxies' => ['127.0.0.1']]);
        admin_setting(['secure_path' => 'contract_admin', 'captcha_enable' => 0, 'password_limit_enable' => 0]);
        [, $admin] = app(NativeIdentityService::class)->create('contract-admin@example.test', 'contract-admin-password');
        $admin->forceFill(['is_admin' => true])->saveOrFail();
        $fingerprint = str_repeat('b', 64);
        $service = app(ServiceCredentials::class)->issue('admin-contract', 'admin', $fingerprint,
            ['identity.admin-session.create', 'identity.admin.get', 'settings.site.get', 'network.nodes.list', 'network.machines.list'], time() + 3600);
        $invoke = function ($id, $input = [], $actor = null, $path = 'contract_admin') use ($service, $fingerprint) {
            return $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTPS' => 'on'])
                ->postJson('https://core.example.test/txapi/internal/native/v1/admin/operations/'.$id, [
                    'contract_version' => '1', 'request_id' => (string) Str::uuid(), 'deadline_at' => time() + 15,
                    'input' => $input === [] ? (object) [] : $input,
                ], ['X-Core-Service-Id' => $service['service_id'], 'X-Core-Service-Credential' => $service['credential'],
                    'X-Core-Service-Epoch' => $service['epoch'], 'X-Core-Tls-Verified' => 'SUCCESS',
                    'X-Core-Tls-Certificate-Sha256' => $fingerprint, 'Authorization' => $actor ?? '',
                    'X-Core-Admin-Path' => $path]);
        };
        $login = $invoke('identity.admin-session.create', ['email' => $admin->email, 'password' => 'contract-admin-password'])
            ->assertOk()->assertJsonPath('data.is_admin', true)->assertJsonPath('data.secure_path', 'contract_admin');
        $token = $login->json('data.auth_data');
        $invoke('identity.admin.get', actor: $token)->assertForbidden();
        foreach (['identity.read', 'settings.read', 'network.nodes.read', 'network.machines.read'] as $permission) {
            DB::table('tx_internal_actor_grant')->insert(['user_id' => $admin->id, 'permission' => $permission, 'created_at' => time()]);
        }
        $account = $invoke('identity.admin.get', actor: $token)->assertOk()
            ->assertJsonPath('data', ['id' => (string) $admin->id, 'email' => $admin->email]);
        $this->assertSame(['data', 'request_id'], array_keys($account->json()));
        $site = $invoke('settings.site.get', actor: $token)->assertOk();
        $this->assertSame(['logo', 'stop_register', 'app_name', 'app_description', 'app_url', 'tos_url', 'currency'], array_keys($site->json('data.site')));
        $invoke('network.nodes.list', actor: $token)->assertOk()->assertJsonPath('meta.page', 1);
        $invoke('network.machines.list', actor: $token)->assertOk()->assertJsonPath('meta.total', 0);
        $invoke('identity.admin.get', actor: $token, path: 'old_path')->assertNotFound();
        DB::table('tx_internal_actor_grant')->where('permission', 'identity.read')->delete();
        $invoke('identity.admin.get', actor: $token)->assertForbidden();
        $audit = json_encode(DB::table('tx_internal_audit')->get());
        $this->assertStringNotContainsString($token, $audit);
        $this->assertStringNotContainsString('contract-admin-password', $audit);
    }
}
