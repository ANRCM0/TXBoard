<?php

namespace Tests\Feature\Agent;

use App\Models\AgentAuditLog;
use App\Models\AgentSupportReplyRequest;
use App\Models\Server;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\AgentOps\AgentAbility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AgentSupportTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_support_read_ability_and_sanitized_context(): void
    {
        $admin = $this->user(true);
        $customer = $this->user(false);
        $ticket = $this->ticket($customer);
        $plain = $admin->createToken('agent:support-reader', [AgentAbility::SUPPORT_READ])->plainTextToken;
        $without = $admin->createToken('agent:node-only', [AgentAbility::NODES_READ])->plainTextToken;

        $this->withToken($without)->getJson('/api/v2/agent/support/overview')->assertForbidden();
        $this->withToken($plain)->getJson('/api/v2/agent/support/overview')->assertOk()->assertJsonPath('data.tickets_waiting', 1);
        $this->getJson('/api/v2/agent/support/tickets?limit=1')->assertOk()->assertJsonCount(1, 'data');
        $detail = $this->getJson('/api/v2/agent/support/tickets/'.$ticket->id)->assertOk()
            ->assertJsonPath('data.customer.id', $customer->id)
            ->assertJsonPath('data.messages.0.message', 'Need help');
        foreach (['email', 'uuid', 'token', 'password', 'subscribe_url', 'balance'] as $secret) {
            $this->assertArrayNotHasKey($secret, $detail->json('data.customer'));
        }
        $this->getJson('/api/v2/agent/support/tickets?limit=51')->assertUnprocessable();
    }

    public function test_reply_requires_separate_ability_and_admin_approval_and_is_audited_without_message(): void
    {
        Queue::fake();
        $admin = $this->user(true);
        $customer = $this->user(false);
        $ticket = $this->ticket($customer);
        $read = $admin->createToken('agent:read', [AgentAbility::SUPPORT_READ])->plainTextToken;
        $write = $admin->createToken('agent:reply', [AgentAbility::SUPPORT_REPLY_REQUEST])->plainTextToken;
        $url = '/api/v2/agent/support/tickets/'.$ticket->id.'/reply-requests';

        $this->withToken($read)->postJson($url, ['message' => 'We are looking into this'])->assertForbidden();
        $this->withToken($write)->postJson($url, ['message' => ' '])->assertStatus(422);
        $created = $this->postJson($url, ['message' => 'We are looking into this'])->assertOk()->assertJsonPath('data.status', 'pending');
        $id = $created->json('data.request_id');
        $this->assertSame(1, TicketMessage::where('ticket_id', $ticket->id)->count());
        $this->assertStringNotContainsString('We are looking into this', $created->getContent());
        $this->postJson($url, ['message' => 'Another reply'])->assertUnprocessable();
        $this->getJson('/api/v2/agent/support/reply-requests/'.$id)->assertOk()->assertJsonPath('data.status', 'pending');
        $this->withToken($read)->getJson('/api/v2/agent/support/reply-requests/'.$id)->assertForbidden();
        $this->assertStringNotContainsString('We are looking into this', (string) AgentAuditLog::where('target_type', 'ticket')->firstOrFail()->input_redacted);

        Sanctum::actingAs($admin);
        $secure = (string) admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));
        $this->getJson('/api/v2/'.$secure.'/agent/support/reply-requests')->assertOk()->assertJsonPath('data.0.message', 'We are looking into this');
        $this->postJson('/api/v2/'.$secure.'/agent/support/reply-requests/approve', ['request_id' => $id])->assertOk()->assertJsonPath('data.status', 'succeeded');
        $this->assertSame(2, TicketMessage::where('ticket_id', $ticket->id)->count());
        $this->assertDatabaseHas('v2_ticket', ['id' => $ticket->id, 'reply_status' => Ticket::REPLY_STATUS_REPLIED]);
        $this->postJson('/api/v2/'.$secure.'/agent/support/reply-requests/approve', ['request_id' => $id])->assertUnprocessable();
        $this->assertSame(2, TicketMessage::where('ticket_id', $ticket->id)->count());
    }

    public function test_stale_reply_and_revoked_token_cannot_be_approved(): void
    {
        $admin = $this->user(true);
        $customer = $this->user(false);
        $ticket = $this->ticket($customer);
        $token = $admin->createToken('agent:reply', [AgentAbility::SUPPORT_REPLY_REQUEST]);
        $url = '/api/v2/agent/support/tickets/'.$ticket->id.'/reply-requests';
        $id = $this->withToken($token->plainTextToken)->postJson($url, ['message' => 'Old answer'])->assertOk()->json('data.request_id');
        TicketMessage::create(['ticket_id' => $ticket->id, 'user_id' => $customer->id, 'message' => 'new info']);
        Sanctum::actingAs($admin);
        $secure = (string) admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));
        $this->postJson('/api/v2/'.$secure.'/agent/support/reply-requests/approve', ['request_id' => $id])->assertUnprocessable();
        $this->assertSame('pending', AgentSupportReplyRequest::where('request_id', $id)->firstOrFail()->status);
        $token->accessToken->delete();
        $this->postJson('/api/v2/'.$secure.'/agent/support/reply-requests/approve', ['request_id' => $id])->assertUnprocessable();
    }

    public function test_restricted_node_token_cannot_be_issued_with_global_support_access(): void
    {
        $admin = $this->user(true);
        Sanctum::actingAs($admin);
        $secure = (string) admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));
        $node = Server::create([
            'type' => 'vless', 'name' => 'support-scope-test', 'rate' => 1,
            'host' => 'support.example.com', 'port' => '443', 'server_port' => 443,
            'group_ids' => [], 'route_ids' => [], 'protocol_settings' => [], 'show' => true,
        ]);
        $this->postJson('/api/v2/'.$secure.'/agent/tokens/create', [
            'client_name' => 'unsafe-support', 'abilities' => [AgentAbility::SUPPORT_READ],
            'target_mode' => 'restricted', 'target_node_ids' => [$node->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('target_scope');

        $manual = $admin->createToken('agent:manually-restricted', [
            AgentAbility::SUPPORT_READ, 'agent:target:restricted', 'agent:target:node:'.$node->id,
        ])->plainTextToken;
        $this->withToken($manual)->getJson('/api/v2/agent/support/overview')->assertForbidden();
    }

    private function ticket(User $customer): Ticket
    {
        $ticket = Ticket::create(['user_id' => $customer->id, 'subject' => 'Connectivity help', 'status' => Ticket::STATUS_OPENING, 'reply_status' => Ticket::REPLY_STATUS_WAITING]);
        TicketMessage::create(['ticket_id' => $ticket->id, 'user_id' => $customer->id, 'message' => 'Need help']);
        return $ticket;
    }

    private function user(bool $admin): User
    {
        static $seq = 0;
        $seq++;
        return User::create([
            'email' => "support-{$seq}@example.com", 'password' => 'password',
            'uuid' => sprintf('00000000-0000-0000-0000-%012d', 900 + $seq),
            'token' => str_pad((string) (900 + $seq), 32, 'a', STR_PAD_LEFT),
            'balance' => 0, 'commission_balance' => 0, 'transfer_enable' => 0,
            'u' => 0, 'd' => 0, 'banned' => 0, 'is_admin' => (int) $admin,
            'is_staff' => 0, 'expired_at' => 0, 'remind_expire' => 1, 'remind_traffic' => 1,
        ]);
    }
}
