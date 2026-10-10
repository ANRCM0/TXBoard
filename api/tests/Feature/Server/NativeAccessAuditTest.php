<?php

namespace Tests\Feature\Server;

use App\Domains\Network\NativeAccessAudit;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class NativeAccessAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        admin_setting(['server_token' => 'audit-node-token', 'secure_path' => 'test_admin_audit']);
    }

    private function node(array $values = []): Server
    {
        return Server::create(array_merge([
            'name' => 'audit node', 'type' => Server::TYPE_VMESS,
            'host' => '127.0.0.1', 'port' => 443, 'server_port' => 443,
            'rate' => 1, 'group_ids' => [1], 'enabled' => true,
        ], $values));
    }

    private function actor(bool $admin): User
    {
        static $n = 0; ++$n;
        return User::create([
            'email' => 'native-audit-'.$n.'@example.test', 'password' => 'secret',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)), 'is_admin' => $admin, 'banned' => 0,
        ]);
    }

    private function nodeHeaders(Server $node): array
    {
        return ['Authorization' => 'Bearer audit-node-token', 'X-TX-Node-ID' => (string) $node->id];
    }

    private function event(int $userId, string $id = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'): array
    {
        return [
            'event_id' => $id, 'user_id' => $userId,
            'target' => 'a.example.net', 'target_ip' => '203.0.113.1',
            'source_ip' => '198.51.100.2', 'matched' => true,
        ];
    }

    public function test_rule_management_is_admin_scoped_and_node_uses_bearer_protocol(): void
    {
        $node = $this->node();
        $path = '/txapi/admin/test_admin_audit/access-audit/rules';
        $this->postJson($path, ['name' => 'denied', 'match_type' => 'domain', 'match_value' => 'example.net'])
            ->assertStatus(403);
        Sanctum::actingAs($this->actor(false));
        $this->getJson($path)->assertStatus(403);
        Sanctum::actingAs($this->actor(true));

        $this->postJson($path, [
            'name' => 'Watch example',
            'match_type' => 'domain_suffix', 'match_value' => 'example.net',
        ])->assertOk()->assertJsonPath('data.ok', true);
        $this->getJson($path)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/txapi/node/v1/audit/rules')->assertStatus(401);
        $this->getJson('/txapi/node/v1/audit/rules?token=audit-node-token')
            ->assertStatus(401);
        $this->getJson('/txapi/node/v1/audit/rules', $this->nodeHeaders($node))
            ->assertOk()->assertJsonPath('data.protocol_version', 1)
            ->assertJsonPath('data.rules.0.match_type', 'domain_suffix')
            ->assertJsonPath('data.rules.0.match_value', 'example.net');

        $id = DB::table(NativeAccessAudit::rulesTable())->value('id');
        $this->postJson($path, [
            'id' => $id, 'name' => 'Watch example', 'match_type' => 'domain',
            'match_value' => 'example.net', 'enabled' => false,
        ])->assertOk();
        $this->getJson('/txapi/node/v1/audit/rules', $this->nodeHeaders($node))
            ->assertOk()->assertJsonCount(0, 'data.rules');
        $this->deleteJson($path.'/'.$id)->assertOk();
        $this->getJson($path)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_bounded_idempotent_audit_reports_do_not_touch_billing(): void
    {
        $node = $this->node();
        $user = $this->actor(false);
        $path = '/txapi/node/v1/audit/report';
        $body = ['protocol_version' => 1, 'events' => [$this->event($user->id)]];
        $this->postJson($path, $body, $this->nodeHeaders($node))->assertOk()
            ->assertJsonPath('data.received', 1)->assertJsonPath('data.inserted', 1);
        $this->postJson($path, $body, $this->nodeHeaders($node))->assertOk()
            ->assertJsonPath('data.received', 1)->assertJsonPath('data.inserted', 0);
        $this->assertSame(1, DB::table(NativeAccessAudit::eventsTable())->count());
        $this->assertDatabaseCount('tx_traffic_batch', 0);
        $this->postJson($path, ['protocol_version' => 1, 'events' => [
            $this->event($user->id, 'invalid-event-id'),
        ]], $this->nodeHeaders($node))->assertStatus(422);
        $this->postJson($path, ['protocol_version' => 1, 'events' => [
            $this->event($user->id), $this->event($user->id),
        ]], $this->nodeHeaders($node))->assertStatus(422);
        $this->postJson($path, ['protocol_version' => 1, 'events' => [
            $this->event(999999, 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'),
        ]], $this->nodeHeaders($node))->assertStatus(422);
        $this->assertSame(1, DB::table(NativeAccessAudit::eventsTable())->count());

        Sanctum::actingAs($this->actor(true));
        $this->getJson('/txapi/admin/test_admin_audit/access-audit/events')
            ->assertOk()->assertJsonPath('data.0.target', 'a.example.net')
            ->assertJsonPath('data.0.matched', true)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_machine_credentials_cannot_report_for_foreign_nodes(): void
    {
        $owner = ServerMachine::create(['name' => 'owner', 'token' => 'owner-audit-token', 'is_active' => true]);
        $other = ServerMachine::create(['name' => 'other', 'token' => 'other-audit-token', 'is_active' => true]);
        $ownedNode = $this->node(['machine_id' => $owner->id]);
        $foreignNode = $this->node(['machine_id' => $other->id]);
        $user = $this->actor(false);
        $body = ['protocol_version' => 1, 'events' => [$this->event($user->id)]];
        $headers = [
            'Authorization' => 'Bearer owner-audit-token',
            'X-TX-Machine-ID' => (string) $owner->id,
            'X-TX-Node-ID' => (string) $foreignNode->id,
        ];
        $this->postJson('/txapi/node/v1/audit/report', $body, $headers)->assertStatus(404);
        $headers['X-TX-Node-ID'] = (string) $ownedNode->id;
        $this->postJson('/txapi/node/v1/audit/report', $body, $headers)
            ->assertOk()->assertJsonPath('data.inserted', 1);
        $this->assertDatabaseCount('tx_access_audit_event', 1);
    }
}
