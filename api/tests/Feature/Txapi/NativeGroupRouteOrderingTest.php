<?php

namespace Tests\Feature\Txapi;

use App\Domains\InternalApi\ServiceCredentials;
use App\Models\ServerGroup;
use App\Models\ServerRoute;
use App\Services\Auth\NativeIdentityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\NativeRuntimeTestCase;

final class NativeGroupRouteOrderingTest extends NativeRuntimeTestCase
{
    public function test_complete_route_order_rejects_changed_revisions_subsets_and_duplicate_ids_and_replays_original_key(): void
    {
        [$invoke] = $this->client();
        // Retain exact string IDs within the production unsigned INT schema on MySQL.
        $big = DB::connection()->getDriverName() === 'mysql' ? '4000000001' : '9007199254740993';
        foreach ([['2', 20], [$big, 10]] as [$id, $sort]) {
            DB::table('tx_server_route')->insert(['id' => $id, 'remarks' => 'Route '.$id, 'match' => '["example.test"]',
                'action' => 'direct', 'enabled' => true, 'sort' => $sort, 'created_at' => time(), 'updated_at' => time()]);
        }
        $snapshot = $invoke('network.routes.ordering.get', [])->assertOk()->assertJsonPath('data.route_ids', [$big, '2'])->json('data');
        $invoke('network.routes.sort', ['items' => [['id' => '2', 'sort' => 0]]])->assertOk();
        $input = ['expected_revision' => $snapshot['revision'], 'route_ids' => ['2', $big]];
        $invoke('network.routes.ordering.replace', $input)->assertStatus(409);
        $this->assertSame(10, ServerRoute::findOrFail($big)->sort);
        $fresh = $invoke('network.routes.ordering.get', [])->assertOk()->json('data');
        $this->assertNotSame($snapshot['revision'], $fresh['revision']);
        $invoke('network.routes.ordering.replace', ['expected_revision' => $fresh['revision'], 'route_ids' => [$big]])->assertStatus(409);
        $invoke('network.routes.ordering.replace', ['expected_revision' => $fresh['revision'], 'route_ids' => [$big, $big]])->assertStatus(422);
        $key = (string) Str::uuid();
        $input['expected_revision'] = $fresh['revision'];
        $invoke('network.routes.ordering.replace', $input, $key)->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame(0, ServerRoute::findOrFail('2')->sort);
        $this->assertSame(1, ServerRoute::findOrFail($big)->sort);
        $invoke('network.routes.ordering.replace', $input, $key)->assertOk();
        $invoke('network.routes.ordering.replace', $input)->assertStatus(409);
        $invoke('network.routes.ordering.get', [])->assertOk()->assertJsonPath('data.route_ids', ['2', $big]);
    }

    public function test_route_creation_invalidates_full_set_revision_and_names_resolve_selected_groups_without_scanning_pages(): void
    {
        [$invoke, $actor] = $this->client();
        $snapshot = $invoke('network.routes.ordering.get', [])->assertOk()->assertJsonPath('data.route_ids', [])->json('data');
        $created = $invoke('network.routes.save', ['remarks' => 'First route', 'match' => ['example.test'], 'action' => 'direct'])->assertOk()->json('data.id');
        $invoke('network.routes.ordering.replace', ['expected_revision' => $snapshot['revision'], 'route_ids' => [$created]])->assertStatus(409);
        $group = new ServerGroup();
        $group->name = 'Selected named group';
        $group->saveOrFail();
        $invoke('network.groups.names.get', ['group_ids' => [(string) $group->id, '9223372036854775807']])->assertOk()
            ->assertJsonPath('data.groups', [['id' => (string) $group->id, 'name' => 'Selected named group']]);
        $invoke('network.groups.names.get', ['group_ids' => []])->assertOk()->assertJsonPath('data.groups', []);
        $invoke('network.groups.names.get', ['group_ids' => [(string) $group->id, (string) $group->id]])->assertStatus(422);
        DB::table('tx_internal_actor_grant')->where('user_id', $actor->id)->delete();
        $invoke('network.groups.names.get', ['group_ids' => [(string) $group->id]])->assertForbidden();
        $invoke('network.routes.ordering.get', [])->assertForbidden();
    }

    public function test_bounded_snapshot_refuses_an_overlimit_collection_without_truncating_or_reordering_it(): void
    {
        [$invoke] = $this->client();
        $rows = [];
        for ($id = 1; $id <= 501; $id++) {
            $rows[] = ['id' => $id, 'remarks' => 'Route '.$id, 'match' => '["example.test"]',
                'action' => 'direct', 'enabled' => true, 'sort' => $id + 10, 'created_at' => time(), 'updated_at' => time()];
        }
        foreach (array_chunk($rows, 100) as $chunk) DB::table('tx_server_route')->insert($chunk);
        $invoke('network.routes.ordering.get', [])->assertStatus(422);
        $invoke('network.routes.ordering.replace', ['expected_revision' => str_repeat('a', 64), 'route_ids' => ['1']])->assertStatus(422);
        $this->assertSame(501, ServerRoute::query()->count());
        $this->assertSame(11, ServerRoute::findOrFail('1')->sort);
    }

    private function client(): array
    {
        $this->activate();
        config(['internal_api.enabled' => true, 'internal_api.trusted_tls_proxies' => ['127.0.0.1']]);
        admin_setting(['secure_path' => 'group_route_order']);
        [, $actor] = app(NativeIdentityService::class)->create('group-route-admin@example.test', 'admin-password');
        $actor->forceFill(['is_admin' => true])->saveOrFail();
        $bearer = 'Bearer '.$actor->createToken('group-route', ['*'])->plainTextToken;
        foreach (['network.routes.read', 'network.routes.manage', 'network.groups.read'] as $permission) {
            DB::table('tx_internal_actor_grant')->insert(['user_id' => $actor->id, 'permission' => $permission, 'created_at' => time()]);
        }
        $fingerprint = str_repeat('e', 64);
        $service = app(ServiceCredentials::class)->issue('group-route-admin', 'admin', $fingerprint,
            ['network.routes.list', 'network.routes.save', 'network.routes.sort', 'network.routes.ordering.get',
                'network.routes.ordering.replace', 'network.groups.names.get'], time() + 3600);
        $invoke = function (string $operation, array $input, ?string $key = null) use ($bearer, $service, $fingerprint) {
            return $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTPS' => 'on'])
                ->postJson('https://core.example.test/txapi/internal/native/v1/admin/operations/'.$operation, [
                    'contract_version' => '1', 'request_id' => (string) Str::uuid(), 'deadline_at' => time() + 15,
                    'input' => $input === [] ? (object) [] : $input,
                ], ['Authorization' => $bearer, 'X-Core-Admin-Path' => 'group_route_order',
                    'X-Core-Service-Id' => $service['service_id'], 'X-Core-Service-Credential' => $service['credential'],
                    'X-Core-Service-Epoch' => $service['epoch'], 'X-Core-Tls-Verified' => 'SUCCESS',
                    'X-Core-Tls-Certificate-Sha256' => $fingerprint, 'Idempotency-Key' => $key ?? (string) Str::uuid()]);
        };
        return [$invoke, $actor];
    }
}
