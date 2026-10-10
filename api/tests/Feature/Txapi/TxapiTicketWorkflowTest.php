<?php

namespace Tests\Feature\Txapi;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TxapiTicketWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_read_reply_close_and_duplicate_close(): void
    {
        $owner = $this->user('ticket-owner@example.test');
        Sanctum::actingAs($owner);
        $new = $this->postJson('/txapi/tickets', [
            'subject' => 'P2 support', 'level' => 1, 'message' => 'First message',
        ]);
        $new->assertStatus(201);
        $id = $new->json('data.id');
        $this->assertSame(1, TicketMessage::where('ticket_id', $id)->count());
        $this->getJson('/txapi/tickets?per_page=1')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.subject', 'P2 support');
        $this->getJson("/txapi/tickets/{$id}")->assertOk()
            ->assertJsonPath('data.messages.0.is_me', true);
        $this->postJson("/txapi/tickets/{$id}/messages", ['message' => 'Second'])
            ->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame(2, TicketMessage::where('ticket_id', $id)->count());
        $this->postJson("/txapi/tickets/{$id}/close")->assertOk();
        $this->postJson("/txapi/tickets/{$id}/close")->assertOk();
        $this->postJson("/txapi/tickets/{$id}/messages", ['message' => 'Third'])
            ->assertStatus(409)->assertJsonPath('error.code', 'CONFLICT');
        $this->assertSame(Ticket::STATUS_CLOSED, (int) Ticket::findOrFail($id)->status);
        $this->assertSame(2, TicketMessage::where('ticket_id', $id)->count());
    }

    public function test_cross_user_cannot_read_or_modify_another_ticket(): void
    {
        Sanctum::actingAs($this->user('owner@example.test'));
        $id = $this->postJson('/txapi/tickets', [
            'subject' => 'Owner only', 'level' => 0, 'message' => 'Hidden',
        ])->json('data.id');
        Sanctum::actingAs($this->user('outsider@example.test'));
        $this->getJson('/txapi/tickets')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/txapi/tickets/{$id}")->assertStatus(404);
        $this->postJson("/txapi/tickets/{$id}/messages", ['message' => 'Attack'])->assertStatus(404);
        $this->postJson("/txapi/tickets/{$id}/close")->assertStatus(404);
        $this->assertSame(Ticket::STATUS_OPENING, (int) Ticket::findOrFail($id)->status);
    }

    public function test_wait_reply_rule_is_atomic_and_enforced(): void
    {
        admin_setting(['ticket_must_wait_reply' => 1]);
        Sanctum::actingAs($this->user('wait@example.test'));
        $id = $this->postJson('/txapi/tickets', [
            'subject' => 'Wait', 'level' => 1, 'message' => 'Initial',
        ])->json('data.id');
        $this->postJson("/txapi/tickets/{$id}/messages", ['message' => 'Bump'])
            ->assertStatus(409)->assertJsonPath('error.code', 'CONFLICT');
        $this->assertSame(1, TicketMessage::where('ticket_id', $id)->count());
    }

    public function test_bad_input_and_unauthorized_users_are_rejected(): void
    {
        $this->getJson('/txapi/tickets')->assertStatus(401);
        $this->postJson('/txapi/tickets', ['subject' => 'hi'])->assertStatus(401);
        Sanctum::actingAs($this->user('invalid@example.test'));
        $this->postJson('/txapi/tickets', [
            'subject' => '', 'level' => 7, 'message' => '',
        ])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->getJson('/txapi/tickets?per_page=101')
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    private function user(string $email): User
    {
        return User::create(['email' => $email, 'password' => 'test-only-hash',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)), 'banned' => 0]);
    }
}
