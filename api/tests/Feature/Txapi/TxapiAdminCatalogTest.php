<?php

namespace Tests\Feature\Txapi;

use App\Domains\InternalApi\ServiceCredentials;
use App\Services\Auth\NativeIdentityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\NativeRuntimeTestCase;

final class TxapiAdminCatalogTest extends NativeRuntimeTestCase
{
    public function test_controlled_catalog_details_history_filters_and_revision_cas_recheck_actor_and_service_grants(): void
    {
        $this->activate();
        config(['internal_api.enabled' => true, 'internal_api.trusted_tls_proxies' => ['127.0.0.1']]);
        admin_setting(['secure_path' => 'catalog_admin']);
        [, $actor] = app(NativeIdentityService::class)->create('catalog-admin@example.test', 'catalog-password');
        $actor->forceFill(['is_admin' => true])->saveOrFail();
        foreach (['catalog.read', 'catalog.publish'] as $permission) {
            DB::table('tx_internal_actor_grant')->insert(['user_id' => $actor->id, 'permission' => $permission, 'created_at' => time()]);
        }
        $ops = ['catalog.admin-plans.list', 'catalog.admin-plans.get', 'catalog.admin-plans.history.list',
            'catalog.admin-offers.list', 'catalog.plans.publish'];
        $fingerprint = str_repeat('a', 64);
        $service = app(ServiceCredentials::class)->issue('catalog-admin', 'admin', $fingerprint, $ops, time() + 3600);
        $bearer = 'Bearer '.$actor->createToken('catalog-admin', ['*'])->plainTextToken;
        $invoke = function (string $operation, array $input, ?string $key = null) use ($bearer, $service, $fingerprint) {
            return $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTPS' => 'on'])
                ->postJson('https://core.example.test/txapi/internal/native/v1/admin/operations/'.$operation, [
                    'contract_version' => '1', 'request_id' => (string) Str::uuid(), 'deadline_at' => time() + 15,
                    'input' => $input === [] ? (object) [] : $input,
                ], ['Authorization' => $bearer, 'X-Core-Admin-Path' => 'catalog_admin',
                    'X-Core-Service-Id' => $service['service_id'], 'X-Core-Service-Credential' => $service['credential'],
                    'X-Core-Service-Epoch' => $service['epoch'], 'X-Core-Tls-Verified' => 'SUCCESS',
                    'X-Core-Tls-Certificate-Sha256' => $fingerprint, 'Idempotency-Key' => $key ?? (string) Str::uuid()]);
        };
        $input = ['name' => 'Managed catalog', 'content' => 'Frozen content', 'tags' => ['published'],
            'quota_gib' => 2, 'group_id' => (string) $this->node->group_ids[0], 'speed_limit_mbps' => 25,
            'device_limit' => 3, 'capacity_limit' => null, 'reset_method' => 2, 'currency' => 'CNY', 'prices_minor' => ['monthly' => '199']];
        $first = $invoke('catalog.plans.publish', $input)->assertOk()->json('data');
        $detail = $invoke('catalog.admin-plans.get', ['plan_id' => $first['plan_id']])->assertOk()
            ->assertJsonPath('data.revision.terms.name', 'Managed catalog')
            ->assertJsonPath('data.revision.terms.prices_minor.monthly', '199')
            ->assertJsonPath('data.current_revision_id', $first['publication']['revision_id'])
            ->assertJsonPath('data.offers.0.status', 'published')->json('data');
        $this->assertSame(['name', 'content', 'tags', 'quota_gib', 'group_id', 'speed_limit_mbps',
            'device_limit', 'reset_method', 'currency', 'prices_minor'], array_keys($detail['revision']['terms']));
        $invoke('catalog.admin-plans.list', ['per_page' => 1])->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.current_revision_id', $first['publication']['revision_id']);
        $secondInput = array_replace($input, ['plan_id' => $first['plan_id'],
            'expected_revision_id' => $first['publication']['revision_id'], 'name' => 'New managed catalog']);
        $key = (string) Str::uuid();
        $second = $invoke('catalog.plans.publish', $secondInput, $key)->assertOk()->json('data');
        $invoke('catalog.plans.publish', $secondInput, $key)->assertOk()->assertJsonPath('data.publication.revision_id', $second['publication']['revision_id']);
        $invoke('catalog.plans.publish', $secondInput)->assertStatus(409)->assertJsonPath('error.code', 'NATIVE_PLAN_REVISION_CONFLICT');
        $invoke('catalog.plans.publish', array_diff_key($secondInput, ['expected_revision_id' => true]))
            ->assertStatus(409)->assertJsonPath('error.code', 'NATIVE_PLAN_INPUT_INVALID');
        $invoke('catalog.admin-plans.get', ['plan_id' => $first['plan_id'], 'revision_id' => $first['publication']['revision_id']])
            ->assertOk()->assertJsonPath('data.revision.terms.name', 'Managed catalog')
            ->assertJsonPath('data.current_revision_id', $second['publication']['revision_id'])
            ->assertJsonPath('data.offers.0.status', 'superseded');
        $invoke('catalog.admin-plans.history.list', ['plan_id' => $first['plan_id'], 'per_page' => 1, 'page' => 2])
            ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.revision_id', $first['publication']['revision_id']);
        $invoke('catalog.admin-offers.list', ['plan_id' => $first['plan_id'], 'status' => 'published'])
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.revision_id', $second['publication']['revision_id']);
        $invoke('catalog.admin-offers.list', ['plan_id' => $first['plan_id'], 'status' => 'superseded'])
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.revision_id', $first['publication']['revision_id']);
        foreach (['catalog.admin-plans.get', 'catalog.admin-plans.history.list'] as $operation) {
            $invoke($operation, ['plan_id' => '9223372036854775807'])->assertNotFound();
            $invoke($operation, ['plan_id' => 1])->assertStatus(422);
        }
        $invoke('catalog.admin-offers.list', ['status' => 'withdrawn'])->assertStatus(422);
        DB::table('tx_internal_actor_grant')->where('user_id', $actor->id)->where('permission', 'catalog.read')->delete();
        foreach (array_slice($ops, 0, 3) as $operation) {
            $invoke($operation, $operation === 'catalog.admin-plans.list' ? [] : ['plan_id' => $first['plan_id']])->assertForbidden();
        }
        DB::table('tx_internal_actor_grant')->insert(['user_id' => $actor->id, 'permission' => 'catalog.read', 'created_at' => time()]);
        DB::table('tx_internal_service')->where('service_id', $service['service_id'])->update(['operations_json' => json_encode(['catalog.admin-plans.list'])]);
        $invoke('catalog.admin-plans.get', ['plan_id' => $first['plan_id']])->assertForbidden();
    }
}
