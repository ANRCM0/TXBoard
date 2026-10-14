<?php

namespace Tests\Feature\Server;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\NodeSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class NativeServerMachineSyncTest extends TestCase
{
    private array $published = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Independent memory connection: never reset the application's external
        // database, even when another CI suite uses a dedicated MySQL fixture.
        config(['database.connections.native_machine_registry_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
            'database.default' => 'native_machine_registry_fixture']);
        DB::purge('native_machine_registry_fixture');
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh', ['--database' => 'native_machine_registry_fixture', '--force' => true])->assertExitCode(0);
        config(['cache.default' => 'array', 'setting.cache_store' => 'array']);
        Redis::shouldReceive('publish')->andReturnUsing(function ($channel, $body): int {
            $this->assertSame('node:push', $channel);
            $this->published[] = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            return 1;
        });
    }


    protected function tearDown(): void
    {
        // Explicit migrations do not certify another fixture connection's schema.
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    private function machine(string $name): ServerMachine
    {
        return ServerMachine::create(['name' => $name, 'token' => 'synthetic-'.$name, 'is_active' => true]);
    }

    private function node(int $machineId): Server
    {
        return Server::create(['name' => 'synthetic-node', 'type' => 'vless', 'host' => '127.0.0.1', 'port' => 24443, 'server_port' => 24443,
            'group_ids' => ['1'], 'rate' => 1, 'enabled' => true, 'machine_id' => $machineId, 'protocol_settings' => ['tls' => 0, 'network' => 'tcp']]);
    }

    private function machineEvents(): array
    {
        return array_values(array_map(static fn (array $event): int => $event['machine_id'], array_filter($this->published,
            static fn (array $event): bool => $event['event'] === 'sync.nodes')));
    }

    public function test_committed_machine_move_notifies_original_and_new_owner(): void
    {
        $a = $this->machine('a'); $b = $this->machine('b'); $node = $this->node($a->id);
        $this->published = [];
        DB::beginTransaction();
        $node->update(['machine_id' => $b->id]);
        $this->assertSame([], $this->published);
        DB::commit();
        $this->assertSame([$b->id, $a->id], $this->machineEvents());
        $this->assertSame([], $this->published[1]['data']['nodes']);
        $this->assertSame($node->id, $this->published[0]['data']['nodes'][0]['id']);
    }

    public function test_repeated_saves_keep_each_committed_event_flags_and_old_owner(): void
    {
        $a = $this->machine('a'); $b = $this->machine('b'); $c = $this->machine('c'); $node = $this->node($a->id);
        NodeSyncService::markNodeOnline($node->id);
        $this->published = [];
        DB::beginTransaction();
        $node->update(['machine_id' => $b->id]);
        $node->update(['server_port' => 24444]);
        $node->update(['machine_id' => $c->id]);
        $node->update(['name' => 'later-non-control-save']);
        $this->assertSame([], $this->published);
        DB::commit();
        $this->assertSame([$b->id, $a->id, $c->id, $b->id], $this->machineEvents());
        $config = array_values(array_filter($this->published, static fn (array $e): bool => $e['event'] === 'sync.config'));
        $this->assertCount(1, $config);
        $this->assertSame(24444, $config[0]['data']['config']['server_port']);
    }

    public function test_nested_rollback_discards_only_its_notifications_and_outer_rollback_discards_all(): void
    {
        $a = $this->machine('a'); $b = $this->machine('b'); $c = $this->machine('c'); $node = $this->node($a->id);
        $this->published = [];
        DB::beginTransaction();
        $node->update(['machine_id' => $b->id]);
        DB::beginTransaction();
        $node->update(['machine_id' => $c->id]);
        DB::rollBack();
        $node->refresh();
        DB::commit();
        $this->assertSame([$b->id, $a->id], $this->machineEvents());
        $this->assertSame($b->id, $node->refresh()->machine_id);
        $this->published = [];
        DB::beginTransaction();
        $node->update(['machine_id' => $c->id]);
        $node->delete();
        DB::rollBack();
        $this->assertSame([], $this->published);
        $this->assertSame($b->id, Server::findOrFail($node->id)->machine_id);
    }

    public function test_create_delete_and_enable_events_bind_membership_and_wait_for_commit(): void
    {
        $a = $this->machine('a');
        DB::beginTransaction();
        $node = $this->node($a->id);
        $this->assertSame([], $this->published);
        DB::commit();
        $this->assertSame([$a->id], $this->machineEvents());
        $this->published = [];
        DB::transaction(function () use ($node): void { $node->update(['enabled' => false]); $node->update(['name' => 'second-save']); });
        $this->assertSame([$a->id], $this->machineEvents());
        $this->assertSame([], $this->published[0]['data']['nodes']);
        $this->published = [];
        DB::transaction(function () use ($node): void { $node->delete(); });
        $this->assertSame([$a->id], $this->machineEvents());
    }

    public function test_non_transactional_creation_and_updates_publish_immediately(): void
    {
        $a = $this->machine('a'); $b = $this->machine('b');
        $node = $this->node($a->id);
        $this->assertSame([$a->id], $this->machineEvents());
        $this->published = [];
        $node->update(['machine_id' => $b->id]);
        $this->assertSame([$b->id, $a->id], $this->machineEvents());
    }
}
