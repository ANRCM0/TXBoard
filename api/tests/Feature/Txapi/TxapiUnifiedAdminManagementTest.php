<?php

namespace Tests\Feature\Txapi;

use App\Domains\InternalApi\ServiceCredentials;
use App\Services\Auth\NativeIdentityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\NativeRuntimeTestCase;

final class TxapiUnifiedAdminManagementTest extends NativeRuntimeTestCase
{
    public function test_native_management_keeps_resource_ids_and_permissions_on_create_update_and_replay(): void
    {
        $this->activate();
        config(['internal_api.enabled' => true, 'internal_api.trusted_tls_proxies' => ['127.0.0.1']]);
        admin_setting(['secure_path' => 'unified_admin']);
        [, $actor] = app(NativeIdentityService::class)->create('unified-admin@example.test', 'admin-password');
        $actor->forceFill(['is_admin' => true])->saveOrFail();
        $bearer = 'Bearer '.$actor->createToken('unified', ['*'])->plainTextToken;
        $ops = ['identity.accounts.create', 'identity.accounts.status.update', 'content.notices.create',
            'content.notices.update', 'content.notices.get', 'network.groups.list'];
        foreach (['identity.accounts.manage', 'content.manage', 'content.read', 'network.groups.read'] as $permission) {
            DB::table('tx_internal_actor_grant')->insert(['user_id' => $actor->id, 'permission' => $permission, 'created_at' => time()]);
        }
        $fingerprint = str_repeat('e', 64);
        $service = app(ServiceCredentials::class)->issue('unified-admin', 'admin', $fingerprint, $ops, time() + 3600);
        $invoke = function (string $operation, array $input, ?string $key = null) use ($bearer, $service, $fingerprint) {
            return $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTPS' => 'on'])
                ->postJson('https://core.example.test/txapi/internal/native/v1/admin/operations/'.$operation, [
                    'contract_version' => '1', 'request_id' => (string) Str::uuid(), 'deadline_at' => time() + 15,
                    'input' => $input === [] ? (object) [] : $input,
                ], ['Authorization' => $bearer, 'X-Core-Admin-Path' => 'unified_admin',
                    'X-Core-Service-Id' => $service['service_id'], 'X-Core-Service-Credential' => $service['credential'],
                    'X-Core-Service-Epoch' => $service['epoch'], 'X-Core-Tls-Verified' => 'SUCCESS',
                    'X-Core-Tls-Certificate-Sha256' => $fingerprint, 'Idempotency-Key' => $key ?? (string) Str::uuid()]);
        };
        $created = $invoke('identity.accounts.create', ['email' => 'created-native@example.test', 'password' => 'private-password'])
            ->assertCreated()->json('data');
        $this->assertIsString($created['id']);
        $this->assertArrayNotHasKey('password', $created);
        $key = (string) Str::uuid();
        $input = ['user_id' => $created['id'], 'expected_banned' => false, 'banned' => true];
        $invoke('identity.accounts.status.update', $input, $key)->assertOk()->assertJsonPath('data.banned', true);
        $invoke('identity.accounts.status.update', $input, $key)->assertOk()->assertJsonPath('data.banned', true);
        $invoke('identity.accounts.status.update', $input + ['balance' => '100'], (string) Str::uuid())->assertStatus(422);
        $notice = $invoke('content.notices.create', ['title' => 'Original', 'content' => 'Read me'])->assertCreated()->json('data.id');
        $invoke('content.notices.update', ['content_id' => $notice, 'title' => 'Updated', 'content' => 'Changed'])
            ->assertOk()->assertJsonPath('data.id', $notice);
        $invoke('content.notices.get', ['content_id' => $notice])->assertOk()->assertJsonPath('data.title', 'Updated');
        $this->assertSame(1, DB::table('tx_notice')->where('id', (int) $notice)->count());
        $invoke('network.groups.list', ['page' => 1, 'per_page' => 1])->assertOk()->assertJsonPath('meta.per_page', 1);
        DB::table('tx_internal_actor_grant')->where('user_id', $actor->id)->where('permission', 'identity.accounts.manage')->delete();
        $invoke('identity.accounts.status.update', $input, $key)->assertForbidden();
    }
}
