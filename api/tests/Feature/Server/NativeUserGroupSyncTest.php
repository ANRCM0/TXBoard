<?php

namespace Tests\Feature\Server;

use App\Jobs\NodeUserSyncJob;
use App\Models\Plan;
use App\Models\Server;
use App\Models\User;
use App\Services\NodeSyncService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

/** Real outer commits: RefreshDatabase's wrapping transaction would hide this regression. */
class NativeUserGroupSyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $mysql = getenv('P0_ACCOUNT_MYSQL') === '1';
        $connection = $mysql ? config('database.connections.mysql') : [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ];
        if ($mysql && (($connection['database'] ?? '') !== 'txboard_basic_account_test'
            || ($connection['username'] ?? '') !== 'txboard_basic_account'
            || !in_array($connection['host'] ?? '', ['127.0.0.1', 'localhost', '::1'], true)
            || !empty($connection['url']) || !empty($connection['unix_socket']))) {
            throw new RuntimeException('Group sync needs its dedicated loopback account database.');
        }
        config([
            'database.default' => 'group_sync_fixture',
            'database.connections.group_sync_fixture' => $connection,
            'authority_writer_fence.enabled' => false,
            'legacy_observer_capture.enabled' => false,
            'cache.default' => 'array',
        ]);
        DB::purge('group_sync_fixture');
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->assertSame(0, DB::transactionLevel());
        Bus::fake([NodeUserSyncJob::class]);
    }

    protected function tearDown(): void
    {
        if (getenv('P0_ACCOUNT_MYSQL') === '1') {
            $connection=DB::connection('group_sync_fixture');
            if ($connection->getDatabaseName() !== 'txboard_basic_account_test'
                || $connection->getConfig('username') !== 'txboard_basic_account') {
                throw new RuntimeException('Refusing cleanup outside the dedicated account database.');
            }
            $schema=$connection->getSchemaBuilder();
            $schema->disableForeignKeyConstraints();
            try { foreach ($schema->getTableListing() as $table) $schema->drop($table); }
            finally { $schema->enableForeignKeyConstraints(); }
        }
        DB::purge('group_sync_fixture');
        // Independent migrated fixtures invalidate RefreshDatabase's suite-wide schema cache.
        \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_outer_commit_preserves_old_group_and_publishes_removal_before_new_group_addition(): void
    {
        $old = $this->node(['11']);
        $new = $this->node(['22']);
        $shared = $this->node(['11', '22']);
        $unrelated = $this->node(['33']);
        $user = $this->user(11);

        DB::transaction(function () use ($user): void {
            $user->group_id = 22;
            $user->save();
            Bus::assertNotDispatched(NodeUserSyncJob::class);
        });

        $job = $this->onlyJob();
        $this->assertSame(11, $this->oldGroup($job), 'The queued callback must retain the pre-save group after syncOriginal().');
        $messages = $this->handle($job);
        $this->assertSame([
            [$old->id, 'remove'], [$shared->id, 'remove'],
            [$new->id, 'add'], [$shared->id, 'add'],
        ], $this->targets($messages));
        $this->assertNotContains($unrelated->id, array_column($messages, 'node_id'));
        foreach ($messages as $message) {
            $this->assertSame('sync.user.delta', $message['event']);
            $this->assertSame($user->id, $message['data']['users'][0]['id']);
        }
    }

    public function test_clearing_group_after_outer_commit_removes_user_from_old_nodes(): void
    {
        $old = $this->node(['11']);
        $user = $this->user(11);
        DB::transaction(function () use ($user): void {
            $user->group_id = null;
            $user->save();
            Bus::assertNotDispatched(NodeUserSyncJob::class);
        });
        $job = $this->onlyJob();
        $this->assertSame(11, $this->oldGroup($job));
        $this->assertSame([[$old->id, 'remove']], $this->targets($this->handle($job)));
        $this->assertNull($user->fresh()->group_id);
    }

    public function test_rollback_does_not_dispatch_group_sync(): void
    {
        $user = $this->user(11);
        try {
            DB::transaction(function () use ($user): void {
                $user->group_id = 22;
                $user->save();
                Bus::assertNotDispatched(NodeUserSyncJob::class);
                throw new RuntimeException('synthetic-group-change-rollback');
            });
            $this->fail('Expected the synthetic rollback.');
        } catch (RuntimeException $e) {
            $this->assertSame('synthetic-group-change-rollback', $e->getMessage());
        }
        $this->assertSame(11, $user->fresh()->group_id);
        Bus::assertNotDispatched(NodeUserSyncJob::class);
    }

    public function test_same_group_sync_field_update_does_not_emit_removal(): void
    {
        $old = $this->node(['11']);
        $user = $this->user(11);
        DB::transaction(function () use ($user): void {
            $user->group_id = 11;
            $user->speed_limit = 25;
            $user->save();
            Bus::assertNotDispatched(NodeUserSyncJob::class);
        });
        $job = $this->onlyJob();
        $this->assertNull($this->oldGroup($job));
        $messages = $this->handle($job);
        $this->assertSame([[$old->id, 'add']], $this->targets($messages));
        $this->assertSame(25, $messages[0]['data']['users'][0]['speed_limit']);
    }

    public function test_group_assignment_without_outer_transaction_retains_previous_group(): void
    {
        $user = $this->user(11);
        $user->group_id = 22;
        $user->save();
        $this->assertSame(11, $this->oldGroup($this->onlyJob()));
    }

    public function test_repeated_saves_inside_one_outer_transaction_keep_each_previous_group(): void
    {
        $user = $this->user(11);
        DB::transaction(function () use ($user): void {
            $user->group_id = 22;
            $user->save();
            $user->group_id = 33;
            $user->save();
            Bus::assertNotDispatched(NodeUserSyncJob::class);
        });
        Bus::assertDispatchedTimes(NodeUserSyncJob::class, 2);
        $this->assertSame([11, 22], Bus::dispatched(NodeUserSyncJob::class)
            ->map(fn (NodeUserSyncJob $job): ?int => $this->oldGroup($job))->all());
    }

    public function test_numeric_and_string_group_json_receive_the_same_real_job_delta(): void
    {
        // Both representations are accepted by current node management validation.
        $oldString = $this->node(['11']);
        $oldNumeric = $this->node([11]);
        $newString = $this->node(['22']);
        $newNumeric = $this->node([22]);
        $sharedMixed = $this->node([11, '22']);
        $unrelated = $this->node([33]);
        $user = $this->user(22);
        // Isolate actual job node selection from observer's independent old-group issue.
        $messages = $this->handle(new NodeUserSyncJob($user->id, 'updated', 11));
        $this->assertSame([
            [$oldString->id, 'remove'], [$oldNumeric->id, 'remove'], [$sharedMixed->id, 'remove'],
            [$newString->id, 'add'], [$newNumeric->id, 'add'], [$sharedMixed->id, 'add'],
        ], $this->targets($messages));
        $this->assertNotContains($unrelated->id, array_column($messages, 'node_id'));
    }

    public function test_nested_rollback_discards_only_its_group_change_callback(): void
    {
        $node = $this->node(['11']);
        $user = $this->user(11);
        DB::transaction(function () use ($user): void {
            try {
                DB::transaction(function () use ($user): void {
                    $user->group_id = 22;
                    $user->save();
                    throw new RuntimeException('synthetic-inner-rollback');
                });
            } catch (RuntimeException $e) {
                $this->assertSame('synthetic-inner-rollback', $e->getMessage());
            }
            $user->refresh();
            $user->speed_limit = 35;
            $user->save();
            Bus::assertNotDispatched(NodeUserSyncJob::class);
        });
        $job = $this->onlyJob();
        $this->assertNull($this->oldGroup($job));
        $this->assertSame([[$node->id, 'add']], $this->targets($this->handle($job)));
        $this->assertSame(11, $user->fresh()->group_id);
    }

    public function test_uuid_rotation_publishes_the_committed_credential(): void
    {
        $node = $this->node(['11']);
        $user = $this->user(11);
        $uuid = '22222222-2222-4222-8222-222222222222';
        DB::transaction(function () use ($user, $uuid): void {
            $user->uuid = $uuid;
            $user->save();
            Bus::assertNotDispatched(NodeUserSyncJob::class);
        });
        $job = $this->onlyJob();
        $this->assertNull($this->oldGroup($job));
        $messages = $this->handle($job);
        $this->assertSame([[$node->id, 'add']], $this->targets($messages));
        $this->assertSame($uuid, $messages[0]['data']['users'][0]['uuid']);

    }

    public function test_suspension_publishes_removal_after_commit(): void
    {
        $node = $this->node(['11']);
        $user = $this->user(11);
        DB::transaction(function () use ($user): void {
            $user->banned = 1;
            $user->save();
            Bus::assertNotDispatched(NodeUserSyncJob::class);
        });
        $job = $this->onlyJob();
        $this->assertNull($this->oldGroup($job));
        $this->assertSame([[$node->id, 'remove']], $this->targets($this->handle($job)));
    }

    public function test_deleted_user_keeps_its_group_after_model_mutation_and_outer_commit(): void
    {
        $node = $this->node(['11']);
        $user = $this->user(11);
        DB::transaction(function () use ($user): void {
            $user->delete();
            $user->group_id = 22;
            Bus::assertNotDispatched(NodeUserSyncJob::class);
        });
        $job = $this->onlyJob();
        $this->assertSame(11, $this->oldGroup($job));
        $this->assertSame([[$node->id, 'remove']], $this->targets($this->handle($job)));
        $this->assertNull(User::find($user->id));
    }

    private function user(int $group): User
    {
        $plan = Plan::create([
            'name' => 'Synthetic Group Sync', 'transfer_enable' => 1,
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
            'prices' => [Plan::PERIOD_MONTHLY => 1],
            'sell' => 1, 'show' => 1, 'renew' => 1,
        ]);
        return User::withoutEvents(fn (): User => User::create([
            'email' => 'native-group-sync@example.test', 'password' => 'synthetic-password',
            'uuid' => '11111111-1111-4111-8111-111111111111',
            'token' => str_repeat('c', 32),
            'plan_id' => $plan->id, 'group_id' => $group, 'banned' => 0, 'u' => 0, 'd' => 0,
            'transfer_enable' => 1000, 'expired_at' => time() + 3600,
            'created_at' => time(), 'updated_at' => time(),
        ]));
    }

    private function node(array $groups): Server
    {
        $node = Server::withoutEvents(fn (): Server => Server::create([
            'name' => 'synthetic-group-node', 'type' => Server::TYPE_VMESS,
            'host' => '127.0.0.1', 'port' => 443, 'server_port' => 443,
            'rate' => 1, 'group_ids' => $groups, 'enabled' => true,
        ]));
        NodeSyncService::markNodeOnline($node->id);
        return $node;
    }

    private function onlyJob(): NodeUserSyncJob
    {
        Bus::assertDispatchedTimes(NodeUserSyncJob::class, 1);
        return Bus::dispatched(NodeUserSyncJob::class)->sole();
    }

    private function oldGroup(NodeUserSyncJob $job): ?int
    {
        return (new ReflectionProperty(NodeUserSyncJob::class, 'oldGroupId'))->getValue($job);
    }

    private function handle(NodeUserSyncJob $job): array
    {
        $messages = [];
        Redis::shouldReceive('publish')->zeroOrMoreTimes()->andReturnUsing(
            function (string $channel, string $payload) use (&$messages): int {
                $this->assertSame('node:push', $channel);
                $messages[] = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
                return 1;
            }
        );
        $job->handle();
        return $messages;
    }

    private function targets(array $messages): array
    {
        return array_map(static fn (array $message): array =>
            [$message['node_id'], $message['data']['action']], $messages);
    }
}
