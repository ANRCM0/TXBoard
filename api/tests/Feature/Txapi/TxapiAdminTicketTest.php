<?php

namespace Tests\Feature\Txapi;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TxapiAdminTicketTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Bus::fake();
        admin_setting(['secure_path' => 'admin_ticket_core']);
    }

    public function test_native_ticket_routes_require_correct_admin_and_dynamic_path(): void
    {
        $user = $this->user('normal-ticket-owner@example.test', false);
        $admin = $this->user('ticket-admin@example.test', true);
        $this->getJson('/txapi/admin/admin_ticket_core/tickets')->assertStatus(403);
        Sanctum::actingAs($user);
        $this->getJson('/txapi/admin/admin_ticket_core/tickets')->assertStatus(403);
        $this->postJson('/txapi/admin/admin_ticket_core/tickets/1/reply', ['message' => 'no'])->assertStatus(403);
        Sanctum::actingAs($admin);
        $this->getJson('/txapi/admin/guessed/tickets')->assertStatus(404);
        $this->getJson('/txapi/admin/admin_ticket_core/tickets')->assertOk();
    }

    public function test_native_admin_ticket_filter_messages_reply_and_close_are_in_same_domain_state(): void
    {
        $user = $this->user('ticket-subject@example.test', false);
        $other = $this->user('ticket-outsider@example.test', false);
        $admin = $this->user('ticket-workflow-admin@example.test', true);
        $ticket = Ticket::create([
            'user_id' => $user->id, 'subject' => 'Trouble ticket',
            'level' => 2, 'status' => Ticket::STATUS_OPENING,
            'reply_status' => Ticket::REPLY_STATUS_WAITING, 'last_reply_user_id' => $user->id,
        ]);
        TicketMessage::create([
            'ticket_id' => $ticket->id, 'user_id' => $user->id,
            'message' => 'Initial message',
        ]);
        Ticket::create([
            'user_id' => $other->id, 'subject' => 'Different user',
            'level' => 1, 'status' => Ticket::STATUS_OPENING,
            'reply_status' => Ticket::REPLY_STATUS_WAITING,
        ]);
        Sanctum::actingAs($admin);
        $root = '/txapi/admin/admin_ticket_core/tickets';
        $index = $this->getJson($root . '?status=0&reply_status=0&email=' . rawurlencode($user->email));
        $index->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.user.email', $user->email)
            ->assertJsonPath('data.0.subject', 'Trouble ticket');
        $this->assertStringNotContainsString($user->token, $index->getContent());
        $this->getJson($root . '/'. $ticket->id)
            ->assertOk()->assertJsonPath('data.messages.0.message', 'Initial message')
            ->assertJsonPath('data.messages.0.is_from_user', true);
        $this->postJson($root . '/' . $ticket->id . '/reply', [
            'message' => 'Support has replied',
        ])->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame(2, TicketMessage::where('ticket_id', $ticket->id)->count());
        $this->getJson($root . '/' . $ticket->id)
            ->assertOk()->assertJsonPath('data.messages.1.is_from_admin', true);
        $this->postJson($root . '/' . $ticket->id . '/close')
            ->assertOk()->assertJsonPath('data.ok', true);
        $this->postJson($root . '/' . $ticket->id . '/close')->assertOk();
        $this->postJson($root . '/' . $ticket->id . '/reply', [
            'message' => 'Forbidden after closing',
        ])->assertStatus(409)->assertJsonPath('error.code', 'TICKET_CLOSED');
        $this->assertSame(Ticket::STATUS_CLOSED, (int) $ticket->fresh()->status);
        $this->assertSame(2, TicketMessage::where('ticket_id', $ticket->id)->count());
    }

    public function test_missing_and_invalid_ticket_parameters_are_rejected(): void
    {
        Sanctum::actingAs($this->user('admin-ticket-validation@example.test', true));
        $root = '/txapi/admin/admin_ticket_core/tickets';
        $this->getJson($root . '/999999')->assertStatus(404);
        $this->postJson($root . '/999999/close')->assertStatus(404);
        $this->postJson($root . '/999999/reply', ['message' => 'x'])->assertStatus(404);
        foreach (['per_page=101','page=0','status=5','reply_status=5'] as $query) {
            $this->getJson($root . '?' . $query)->assertStatus(422);
        }
        $this->postJson($root . '/1/reply', ['message' => ''])->assertStatus(422);
    }

    private function user(string $email, bool $admin): User
    {
        return User::create([
            'email' => $email, 'password' => 'not-public-hash',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)), 'is_admin' => $admin, 'banned' => 0,
        ]);
    }
}
