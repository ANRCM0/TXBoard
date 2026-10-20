<?php

namespace Tests\Feature\Txapi;

use App\Domains\Network\NativeNodeConfiguration;
use App\Models\Server;
use App\Services\Auth\NativeIdentityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\NativeRuntimeTestCase;

final class NativeNodeOrderingTest extends NativeRuntimeTestCase
{
    public function test_full_order_rejects_partial_sets_stale_revisions_duplicates_and_replays_exact_original_command(): void
    {
        [$invoke] = $this->client();
        $big = '9007199254740993';
        $this->insertNode($big, 10);
        $this->node->update(['sort' => 20]);
        $id = (string) $this->node->id;
        $snapshot = $invoke('network.nodes.ordering.get', [])->assertOk()->json('data');
        $this->assertSame([$big, $id], array_column($snapshot['nodes'], 'id'));
        $invoke('network.nodes.ordering.replace', ['expected_revision' => $snapshot['revision'], 'node_ids' => [$id]])->assertStatus(409);
        $invoke('network.nodes.ordering.replace', ['expected_revision' => $snapshot['revision'], 'node_ids' => [$id, $id]])->assertStatus(422);
        $invoke('network.nodes.sort', ['items' => [['id' => $id, 'order' => 0]]])->assertOk();
        $input = ['expected_revision' => $snapshot['revision'], 'node_ids' => [$id, $big]];
        $invoke('network.nodes.ordering.replace', $input)->assertStatus(409);
        $snapshot = $invoke('network.nodes.ordering.get', [])->assertOk()->json('data');
        $key = (string) Str::uuid();
        $input = ['expected_revision' => $snapshot['revision'], 'node_ids' => [$big, $id]];
        $invoke('network.nodes.ordering.replace', $input, $key)->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame(0, (int) Server::findOrFail($big)->sort);
        $this->assertSame(1, (int) Server::findOrFail($id)->sort);
        $invoke('network.nodes.ordering.replace', $input, $key)->assertOk();
        $invoke('network.nodes.ordering.replace', $input)->assertStatus(409);
        $this->assertSame([$big, $id], array_column($invoke('network.nodes.ordering.get', [])->assertOk()->json('data.nodes'), 'id'));
    }

    public function test_copy_delete_and_configured_create_invalidate_membership_and_keep_the_stable_head(): void
    {
        [$invoke] = $this->client();
        $id = (string) $this->node->id;
        $before = $invoke('network.nodes.ordering.get', [])->assertOk()->json('data');
        $copy = $invoke('network.nodes.copy', ['node_id' => $id])->assertOk()->json('data.id');
        $invoke('network.nodes.ordering.replace', ['expected_revision' => $before['revision'], 'node_ids' => [$id]])->assertStatus(409);
        $withCopy = $invoke('network.nodes.ordering.get', [])->assertOk()->json('data');
        $invoke('network.nodes.delete', ['node_id' => $copy])->assertOk();
        $invoke('network.nodes.ordering.replace', ['expected_revision' => $withCopy['revision'], 'node_ids' => [$id, $copy]])->assertStatus(409);
        $configuration = NativeNodeConfiguration::read($this->node->fresh());
        $configuration['name'] = 'New configured node';
        $configuration['code'] = null;
        $beforeCreate = $invoke('network.nodes.ordering.get', [])->assertOk()->json('data');
        $created = $invoke('network.nodes.create', ['configuration_json' => NativeNodeConfiguration::encode($configuration)])->assertOk()->json('data.id');
        $invoke('network.nodes.ordering.replace', ['expected_revision' => $beforeCreate['revision'], 'node_ids' => [$id, $created]])->assertStatus(409);
        $this->assertSame(1, DB::table('tx_native_node_ordering_head')->where('id', 1)->count());
    }

    public function test_overlimit_is_an_explicit_failure_and_permission_revocation_blocks_read_write_and_replay(): void
    {
        [$invoke, $actor] = $this->client();
        $id = (string) $this->node->id;
        $snapshot = $invoke('network.nodes.ordering.get', [])->assertOk()->json('data');
        $key = (string) Str::uuid();
        $input = ['expected_revision' => $snapshot['revision'], 'node_ids' => [$id]];
        $invoke('network.nodes.ordering.replace', $input, $key)->assertOk();
        for ($index = 1; $index <= 100; $index++) $this->insertNode((string) (1000 + $index), $index);
        $invoke('network.nodes.ordering.get', [])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $invoke('network.nodes.ordering.replace', $input)->assertStatus(422);
        $this->assertSame(101, Server::query()->count());
        DB::table('tx_internal_actor_grant')->where('user_id', $actor->id)->delete();
        $invoke('network.nodes.ordering.get', [])->assertForbidden();
        $invoke('network.nodes.ordering.replace', $input, $key)->assertForbidden();
    }

    private function insertNode(string $id, int $sort): void
    {
        $attributes = $this->node->getAttributes();
        $attributes['id'] = $id;
        $attributes['name'] = 'Node '.$id;
        $attributes['sort'] = $sort;
        $attributes['code'] = null;
        DB::table('tx_server')->insert($attributes);
    }

    private function client(): array
    {
        $this->activate();
        config(['browser.enabled' => true, 'browser.admin_enabled' => true,
            'browser.origin' => 'https://core.example.test', 'browser.rate_store' => 'array']);
        admin_setting(['secure_path' => 'node_ordering']);
        [, $actor] = app(NativeIdentityService::class)->create('node-ordering@example.test', 'admin-password');
        $actor->forceFill(['is_admin' => true])->saveOrFail();
        foreach (['network.nodes.read', 'network.nodes.manage'] as $permission) {
            DB::table('tx_internal_actor_grant')->insert(['user_id' => $actor->id, 'permission' => $permission, 'created_at' => time()]);
        }
        $headers = ['Authorization' => 'Bearer '.$actor->createToken('browser', ['*'])->plainTextToken,
            'X-Core-Admin-Path' => 'node_ordering', 'Origin' => 'https://core.example.test'];
        $invoke = fn (string $operation, array $input, ?string $key = null) => $this->postJson(
            'https://core.example.test/gateway/v1/core/admin/operations/'.$operation,
            ['input' => $input === [] ? (object) [] : $input], $headers + ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
        return [$invoke, $actor];
    }
}
