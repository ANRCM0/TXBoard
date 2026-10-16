<?php

namespace Tests\Feature\Txapi;

use App\Exceptions\ApiException;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Domains\Support\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
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

    public function test_duplicate_creation_rolls_back_only_its_child_transaction(): void
    {
        $owner = $this->user('nested-ticket@example.test');
        $tickets = app(TicketService::class);
        $ticket = $tickets->createTicket($owner->id, 'Existing', 1, 'Initial');

        DB::transaction(function () use ($owner, $tickets): void {
            DB::table('tx_user')->where('id', $owner->id)->update(['balance' => 123]);
            $level = DB::transactionLevel();
            try {
                $tickets->createTicket($owner->id, 'Duplicate', 1, 'Rejected');
                $this->fail('A second open ticket was created');
            } catch (ApiException) {
                $this->assertSame($level, DB::transactionLevel());
                $this->assertSame(123, (int) $owner->fresh()->balance);
            }
        });

        $this->assertSame(123, (int) $owner->fresh()->balance);
        $this->assertSame(1, Ticket::where('user_id', $owner->id)->count());
        $this->assertSame(1, TicketMessage::where('ticket_id', $ticket->id)->count());
    }

    public function test_reply_rechecks_current_ticket_and_owner_before_persistence(): void
    {
        $owner = $this->user('stale-ticket@example.test');
        $outsider = $this->user('stale-outsider@example.test');
        $tickets = app(TicketService::class);
        $ticket = $tickets->createTicket($owner->id, 'Current state', 1, 'Initial');
        $this->assertFalse($tickets->reply($ticket, 'Wrong owner', $outsider->id));

        Ticket::query()->whereKey($ticket->id)->update(['status' => Ticket::STATUS_CLOSED]);
        $this->assertFalse($tickets->reply($ticket, 'Stale opening state', $owner->id));
        $this->assertSame(1, TicketMessage::where('ticket_id', $ticket->id)->count());
        $this->assertSame(Ticket::STATUS_CLOSED, (int) $ticket->fresh()->status);
    }

    public function test_message_insert_failure_preserves_parent_and_removes_partial_ticket(): void
    {
        $owner = $this->user('message-failure@example.test');
        DB::transaction(function () use ($owner): void {
            DB::table('tx_user')->where('id', $owner->id)->update(['balance' => 456]);
            $level = DB::transactionLevel();
            try {
                // Exercise a real NOT NULL failure after the ticket insert.
                app(TicketService::class)->createTicket($owner->id, 'Must roll back', 1, null);
                $this->fail('An invalid message was persisted');
            } catch (QueryException) {
                $this->assertSame($level, DB::transactionLevel());
                $this->assertSame(456, (int) $owner->fresh()->balance);
                $this->assertSame(0, Ticket::where('user_id', $owner->id)->count());
                $this->assertSame(0, TicketMessage::where('user_id', $owner->id)->count());
            }
        });
        $this->assertSame(456, (int) $owner->fresh()->balance);
    }

    private function user(string $email): User
    {
        return User::create(['email' => $email, 'password' => 'test-only-hash',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)), 'banned' => 0]);
    }
}
