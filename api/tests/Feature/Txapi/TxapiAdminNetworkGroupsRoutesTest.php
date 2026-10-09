<?php

namespace Tests\Feature\Txapi;

use App\Models\ServerGroup;
use App\Models\ServerRoute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class TxapiAdminNetworkGroupsRoutesTest extends TestCase
{
    use RefreshDatabase;

    private const ROOT = '/txapi/admin/network_admin_secret';

    protected function setUp(): void
    {
        parent::setUp();
        admin_setting(['secure_path' => 'network_admin_secret']);
    }

    public function test_group_and_route_operations_require_a_real_administrator(): void
    {
        $this->getJson(self::ROOT . '/network-groups')->assertStatus(403);
        $this->postJson(self::ROOT . '/network-routes',
            ['remarks' => 'No', 'match' => ['example.com'], 'action' => 'direct'])
            ->assertStatus(403);
        Sanctum::actingAs($this->account('network-user@example.test'));
        $this->getJson(self::ROOT . '/network-routes')->assertStatus(403);
        $this->postJson(self::ROOT . '/network-groups', ['name' => 'Forbidden'])
            ->assertStatus(403);

        Sanctum::actingAs($this->account('network-admin@example.test', true));
        $this->getJson('/txapi/admin/wrong/network-groups')->assertStatus(404);
        $this->getJson(self::ROOT . '/network-groups')->assertOk()->assertJsonStructure(['data', 'request_id']);
        $this->getJson(self::ROOT . '/network-routes')->assertOk()->assertJsonStructure(['data', 'request_id']);
    }

    public function test_groups_can_be_created_renamed_but_not_deleted_when_assigned(): void
    {
        Sanctum::actingAs($this->account('group-admin@example.test', true));
        $this->postJson(self::ROOT . '/network-groups', ['name' => '   '])->assertStatus(422);
        $created = $this->postJson(self::ROOT . '/network-groups', ['name' => ' Alpha '])
            ->assertOk()->assertJsonPath('data.ok', true);
        $id = $created->json('data.id');
        $this->assertDatabaseHas('v2_server_group', ['id' => $id, 'name' => 'Alpha']);
        $this->postJson(self::ROOT . '/network-groups', ['id' => $id, 'name' => 'Beta'])
            ->assertOk();
        $this->getJson(self::ROOT . '/network-groups')->assertOk()
            ->assertJsonFragment(['id' => $id, 'name' => 'Beta']);

        $member = $this->account('group-member@example.test');
        $member->group_id = $id;
        $member->save();

        $this->deleteJson(self::ROOT . '/network-groups/' . $id)
            ->assertStatus(409)->assertJsonPath('error.code', 'NETWORK_GROUP_IN_USE');
        $member->group_id = null;
        $member->save();
        $this->deleteJson(self::ROOT . '/network-groups/' . $id)
            ->assertOk()->assertJsonPath('data.ok', true);
        $this->deleteJson(self::ROOT . '/network-groups/' . $id)->assertStatus(404);
    }

    public function test_native_routes_validate_mutations_and_sort_atomically(): void
    {
        Sanctum::actingAs($this->account('route-admin@example.test', true));
        $this->postJson(self::ROOT . '/network-routes', [
            'remarks' => 'blank', 'match' => ['  '], 'action' => 'direct',
        ])->assertStatus(422);

        $created = $this->postJson(self::ROOT . '/network-routes', [
            'remarks' => ' Direct ', 'match' => ['example.com', 'example.com', ' x.test '],
            'action' => 'direct', 'action_value' => 'unwanted',
        ])->assertOk()->assertJsonPath('data.ok', true);
        $id = $created->json('data.id');
        $route = ServerRoute::findOrFail($id);
        $this->assertSame(['example.com', 'x.test'], $route->match);
        $this->assertNull($route->action_value);

        $this->putJson(self::ROOT . '/network-routes/sort', [
            ['id' => $id, 'sort' => 7], ['id' => $id, 'sort' => 8],
        ])->assertStatus(422);
        $this->putJson(self::ROOT . '/network-routes/sort', [
            ['id' => $id, 'sort' => 4], ['id' => 999999, 'sort' => 5],
        ])->assertStatus(404);
        $this->assertSame(10, (int) $route->fresh()->sort);
        $this->putJson(self::ROOT . '/network-routes/sort', [
            ['id' => $id, 'sort' => 4],
        ])->assertOk();
        $this->assertSame(4, (int) $route->fresh()->sort);

        $this->postJson(self::ROOT . '/network-routes/simulate',
            ['node_id' => 999999, 'target' => 'example.com'])->assertStatus(422);
        $this->deleteJson(self::ROOT . '/network-routes/' . $id)
            ->assertOk()->assertJsonPath('data.ok', true);
        $this->deleteJson(self::ROOT . '/network-routes/' . $id)->assertStatus(404);
    }

    private function account(string $email, bool $admin = false): User
    {
        return User::create([
            'email' => $email,
            'password' => 'test-password',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)),
            'is_admin' => $admin,
            'banned' => 0,
        ]);
    }
}
