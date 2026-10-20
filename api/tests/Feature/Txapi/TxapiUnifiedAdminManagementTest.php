<?php

namespace Tests\Feature\Txapi;

use App\Domains\InternalApi\ServiceCredentials;
use App\Domains\InternalApi\AdminOperationBindings;
use App\Domains\InternalApi\ContentOperationBindings;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\Auth\NativeIdentityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\NativeRuntimeTestCase;

final class TxapiUnifiedAdminManagementTest extends NativeRuntimeTestCase
{
    public function test_native_management_keeps_resource_ids_and_permissions_on_create_update_and_replay(): void
    {
        [$invoke, $actor] = $this->managementClient([
            'identity.accounts.create', 'identity.accounts.status.update', 'content.notices.create',
            'content.notices.update', 'content.notices.get', 'content.notices.delete', 'network.groups.list',
        ], ['identity.accounts.manage', 'content.manage', 'content.read', 'network.groups.read']);
        $created = $invoke('identity.accounts.create', ['email' => 'created-native@example.test', 'password' => 'private-password'])
            ->assertCreated()->json('data');
        $this->assertIsString($created['id']);
        $this->assertArrayNotHasKey('password', $created);
        $key = (string) Str::uuid();
        $input = ['user_id' => $created['id'], 'expected_banned' => false, 'banned' => true];
        $invoke('identity.accounts.status.update', $input, $key)->assertOk()->assertJsonPath('data.banned', true);
        $invoke('identity.accounts.status.update', $input, $key)->assertOk()->assertJsonPath('data.banned', true);
        $invoke('identity.accounts.status.update', $input + ['balance' => '100'], (string) Str::uuid())->assertStatus(422);
        $notice = $invoke('content.notices.create', $this->noticeInput(['title' => 'Original', 'content' => 'Read me']))->assertCreated()->json('data.id');
        $invoke('content.notices.update', $this->noticeInput(['content_id' => $notice, 'expected_version' => '1', 'title' => 'Updated', 'content' => 'Changed']))
            ->assertOk()->assertJsonPath('data.id', $notice);
        $invoke('content.notices.get', ['content_id' => $notice])->assertOk()->assertJsonPath('data.title', 'Updated');
        $this->assertSame(1, DB::table('tx_notice')->where('id', (int) $notice)->count());
        $invoke('content.notices.delete', ['content_id' => $notice, 'expected_version' => '2'])->assertOk();
        $invoke('content.notices.get', ['content_id' => $notice])->assertNotFound();
        $invoke('network.groups.list', ['page' => 1, 'per_page' => 1])->assertOk()->assertJsonPath('meta.per_page', 1);
        DB::table('tx_internal_actor_grant')->where('user_id', $actor->id)->where('permission', 'identity.accounts.manage')->delete();
        $invoke('identity.accounts.status.update', $input, $key)->assertForbidden();
    }

    public function test_network_management_round_trips_nested_sort_payloads_and_does_not_expose_machine_credentials(): void
    {
        [$invoke] = $this->boundManagementClient([
            'network.groups.save', 'network.groups.list', 'network.groups.delete',
            'network.routes.save', 'network.routes.sort', 'network.routes.list',
            'network.routes.simulate', 'network.routes.delete', 'network.nodes.copy',
            'network.machines.update', 'network.nodes.patch', 'network.machines.nodes.list',
            'network.nodes.sort', 'network.nodes.traffic-reset', 'network.nodes.batch-update',
            'network.nodes.batch-traffic-reset', 'network.machines.delete', 'network.nodes.batch-delete',
            'network.access-audit.rules.save', 'network.access-audit.rules.list', 'network.access-audit.events.list',
            'network.access-audit.rules.delete',
        ]);
        $group = $invoke('network.groups.save', ['name' => 'Managed group'])->assertOk()->json('data.id');
        $invoke('network.groups.save', ['id' => $group, 'name' => 'Renamed group'])->assertOk();
        $invoke('network.groups.list', [])->assertOk()->assertJsonFragment(['id' => $group, 'name' => 'Renamed group']);
        $invoke('network.groups.delete', ['group_id' => $group])->assertOk();
        $route = $invoke('network.routes.save', ['remarks' => 'Test route', 'match' => ['example.test'], 'action' => 'direct'])
            ->assertOk()->json('data.id');
        $invoke('network.routes.sort', ['items' => [['id' => $route, 'sort' => 42]]])->assertOk();
        $invoke('network.routes.list', [])->assertOk()->assertJsonFragment(['id' => $route, 'sort' => 42]);
        $invoke('network.routes.simulate', ['node_id' => (string) $this->node->id, 'target' => 'example.test'])
            ->assertOk()->assertJsonPath('data.authoritative', true);
        $invoke('network.routes.delete', ['route_id' => $route])->assertOk();

        $copy = $invoke('network.nodes.copy', ['node_id' => (string) $this->node->id])->assertOk()->json('data.id');
        $this->assertIsString($copy);
        $machine = ServerMachine::create(['name' => 'Managed machine', 'token' => ServerMachine::generateToken(), 'is_active' => true]);
        $machineId = (string) $machine->id;
        $invoke('network.machines.update', ['machine_id' => $machineId, 'name' => 'Renamed machine'])->assertOk();
        $invoke('network.nodes.patch', ['node_id' => $copy, 'machine_id' => $machineId, 'show' => true])->assertOk();
        $machineNodes = $invoke('network.machines.nodes.list', ['machine_id' => $machineId])->assertOk();
        $machineNodes->assertJsonPath('data.0.id', $copy)->assertJsonMissingPath('data.0.protocol_settings');
        $this->assertStringNotContainsString($machine->token, $machineNodes->getContent());
        $invoke('network.nodes.sort', ['items' => [['id' => $copy, 'order' => 12]]])->assertOk();
        $this->assertSame(12, Server::findOrFail((int) $copy)->sort);
        $invoke('network.nodes.traffic-reset', ['node_id' => $copy])->assertOk();
        $invoke('network.nodes.batch-update', ['ids' => [$copy], 'enabled' => false])->assertOk();
        $invoke('network.nodes.batch-traffic-reset', ['ids' => [$copy]])->assertOk();
        $invoke('network.machines.delete', ['machine_id' => $machineId])->assertOk();
        $this->assertNull(Server::findOrFail((int) $copy)->machine_id);
        $invoke('network.nodes.batch-delete', ['ids' => [$copy]])->assertOk();
        $invoke('network.nodes.patch', ['node_id' => $copy, 'show' => true])->assertNotFound();

        $rule = $invoke('network.access-audit.rules.save', ['name' => 'Domain audit', 'match_type' => 'domain', 'match_value' => 'example.test'])
            ->assertOk()->json('data.id');
        $invoke('network.access-audit.rules.list', [])->assertOk()->assertJsonFragment(['id' => $rule, 'name' => 'Domain audit']);
        $invoke('network.access-audit.events.list', [])->assertOk()->assertJsonPath('meta.total', 0);
        $invoke('network.access-audit.rules.delete', ['rule_id' => $rule])->assertOk();
    }

    public function test_content_and_runtime_views_keep_their_typed_projections_through_the_management_channel(): void
    {
        [$invoke] = $this->boundManagementClient([
            'content.notices.create', 'content.notices.visibility.toggle', 'content.notices.sort',
            'content.notices.list', 'content.notices.update', 'content.notices.get',
            'content.knowledge.create', 'content.knowledge.update', 'content.knowledge.get',
            'content.knowledge.visibility.toggle', 'content.knowledge.sort', 'content.knowledge.list',
            'content.knowledge.categories.list', 'content.knowledge.delete', 'notifications.mail-templates.list',
            'notifications.mail-templates.save', 'notifications.mail-templates.get', 'notifications.mail-templates.reset',
            'platform.queue.snapshot.get', 'platform.queue.failures.list', 'platform.queue.failures.get',
            'platform.modules.list', 'platform.modules.get', 'platform.modules.operations.list',
            'audit.logs.list', 'catalog.admin-offers.list',
        ]);
        $this->assertTrue(Schema::hasColumn('tx_notice', 'popup'));
        $notice = $invoke('content.notices.create', $this->noticeInput(['show' => false, 'popup' => true]))->assertCreated()->json('data.id');
        $invoke('content.notices.visibility.toggle', ['content_id' => $notice, 'expected_version' => '1', 'show' => true])->assertOk()->assertJsonPath('data.show', true);
        $invoke('content.notices.sort', ['items' => [['id' => $notice, 'expected_version' => '2']]])->assertOk();
        $invoke('content.notices.list', ['per_page' => 1])->assertOk()->assertJsonPath('data.0.id', $notice)
            ->assertJsonPath('data.0.popup', true);
        $invoke('content.notices.update', $this->noticeInput(['content_id' => $notice, 'expected_version' => '3', 'content' => 'Updated body', 'popup' => false]))->assertOk();
        $invoke('content.notices.get', ['content_id' => $notice])->assertOk()->assertJsonPath('data.popup', false);
        $articleInput = ['title' => 'Article', 'category' => 'Guides', 'language' => 'en-US', 'body' => 'Original body', 'show' => false,
            'sort' => 0, 'audience' => 'authenticated', 'publish_at' => null, 'expire_at' => null];
        $article = $invoke('content.knowledge.create', $articleInput)->assertCreated()->json('data.id');
        $invoke('content.knowledge.update', array_replace($articleInput, ['content_id' => $article, 'expected_version' => '1', 'body' => 'Changed body']))->assertOk();
        $invoke('content.knowledge.get', ['content_id' => $article])->assertOk()->assertJsonPath('data.body', 'Changed body');
        $invoke('content.knowledge.visibility.toggle', ['content_id' => $article, 'expected_version' => '2', 'show' => true])->assertOk()->assertJsonPath('data.show', true);
        $invoke('content.knowledge.sort', ['items' => [['id' => $article, 'expected_version' => '3']]])->assertOk();
        $invoke('content.knowledge.list', ['category' => 'Guides'])->assertOk()->assertJsonPath('data.0.id', $article);
        $invoke('content.knowledge.categories.list', [])->assertOk()->assertJsonPath('data.categories', ['Guides']);
        $invoke('content.knowledge.delete', ['content_id' => $article, 'expected_version' => '4'])->assertOk();
        $invoke('content.knowledge.get', ['content_id' => $article])->assertNotFound();

        $invoke('notifications.mail-templates.list', [])->assertOk()->assertJsonPath('data.0.name', 'verify');
        $invoke('notifications.mail-templates.save', ['name' => 'verify', 'subject' => 'Verification', 'content' => '<p>{{code}}</p>'])->assertOk();
        $invoke('notifications.mail-templates.get', ['name' => 'verify'])->assertOk()->assertJsonPath('data.customized', true);
        $invoke('notifications.mail-templates.reset', ['name' => 'verify'])->assertOk();
        $invoke('notifications.mail-templates.get', ['name' => 'verify'])->assertOk()->assertJsonPath('data.customized', false);
        $invoke('platform.queue.snapshot.get', [])->assertOk()->assertJsonPath('data.status', 'not_applicable');
        $invoke('platform.queue.failures.list', [])->assertOk()->assertJsonPath('data', []);
        $invoke('platform.queue.failures.get', ['failure_id' => '9223372036854775807'])->assertNotFound();
        $modules = $invoke('platform.modules.list', [])->assertOk()->json('data.modules');
        $this->assertNotEmpty($modules);
        $invoke('platform.modules.get', ['module_id' => $modules[0]['id']])->assertOk()->assertJsonPath('data.id', $modules[0]['id']);
        $invoke('platform.modules.operations.list', ['module_id' => $modules[0]['id']])->assertOk();
        $invoke('platform.modules.get', ['module_id' => 'missing-module'])->assertNotFound();
        $invoke('audit.logs.list', [])->assertOk();
        $offers = $invoke('catalog.admin-offers.list', ['per_page' => 1])->assertOk();
        $offers->assertJsonPath('data.0.currency', 'USD')->assertJsonPath('meta.per_page', 1);
    }

    private function boundManagementClient(array $operations): array
    {
        $bindings = array_merge(AdminOperationBindings::all(), array_filter(ContentOperationBindings::all(),
            static fn (array $spec): bool => $spec['domains'] === ['admin']));
        $bindings = array_intersect_key($bindings, array_fill_keys($operations, true));
        $this->assertCount(count($operations), $bindings);
        return $this->managementClient(array_keys($bindings), array_values(array_unique(array_column($bindings, 'permission'))));
    }

    private function managementClient(array $ops, array $permissions): array
    {
        $this->activate();
        config(['internal_api.enabled' => true, 'internal_api.trusted_tls_proxies' => ['127.0.0.1']]);
        admin_setting(['secure_path' => 'unified_admin']);
        [, $actor] = app(NativeIdentityService::class)->create('unified-admin@example.test', 'admin-password');
        $actor->forceFill(['is_admin' => true])->saveOrFail();
        $bearer = 'Bearer '.$actor->createToken('unified', ['*'])->plainTextToken;
        foreach ($permissions as $permission) {
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
        return [$invoke, $actor];
    }

    private function noticeInput(array $values): array
    {
        return $values + ['title' => 'Notice', 'content' => 'Body', 'show' => true, 'popup' => false,
            'img_url' => null, 'tags' => [], 'sort' => 0, 'audience' => 'authenticated', 'publish_at' => null, 'expire_at' => null];
    }
}
