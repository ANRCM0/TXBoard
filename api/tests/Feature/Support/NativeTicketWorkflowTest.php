<?php

namespace Tests\Feature\Support;

use App\Core\Kernel\Execution\CurrentInvocation;
use App\Domains\Identity\Internal\CurrentSessionAuthorization;
use App\Domains\InternalApi\OperationSchemas;
use App\Domains\Notifications\Internal\EventJournal;
use App\Domains\Support\Contracts\TicketAttachments;
use App\Domains\Support\Contracts\TicketListQuery;
use App\Domains\Support\NativeTicketWorkflow;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Support\NativeRuntimeTestCase;
use Tests\Support\NativeWorkflowActors;

final class NativeTicketWorkflowTest extends NativeRuntimeTestCase
{
    use NativeWorkflowActors;

    private NativeTicketWorkflow $workflow;
    private array $input = ['subject' => 'Native support request', 'level' => 1, 'message' => 'Original private body'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->activate();
        $this->workflow = app(NativeTicketWorkflow::class);
    }

    public function test_original_create_recovery_has_one_effect_and_foreign_reads_and_writes_are_denied(): void
    {
        $owner = $this->workflowActor(); $outsider = $this->workflowActor();
        $this->authenticateWorkflowActor($owner); $key = $this->workflowKey();
        $created = $this->invokeWorkflow(fn () => $this->workflow->create($owner->id, $this->input, $key));
        $this->assertSame($created, $this->invokeWorkflow(fn () => $this->workflow->create($owner->id, $this->input, $key)));
        $this->workflowHttpFailure(409, fn () => $this->invokeWorkflow(fn () => $this->workflow->create($owner->id, array_replace($this->input, ['subject' => 'Changed intent']), $key)));
        $this->workflowHttpFailure(409, fn () => $this->invokeWorkflow(fn () => $this->workflow->create($owner->id, $this->input, $this->workflowKey())));
        $this->assertSame(1, DB::table('tx_ticket')->count());
        $this->assertSame(1, DB::table('tx_ticket_message')->count());
        $this->assertSame(1, DB::table('tx_support_request')->count());
        $this->assertSame(1, DB::table('tx_support_audit')->count());
        $this->assertSame(1, DB::table('tx_module_event')->where('kind', 'support.ticket.created.v1')->count());
        $this->authenticateWorkflowActor($outsider);
        $this->assertNull($this->workflow->find($outsider->id, (int) $created['ticket_id']));
        $this->assertSame([], $this->workflow->listing($outsider->id, new TicketListQuery)->items);
        $this->workflowHttpFailure(404, fn () => $this->invokeWorkflow(fn () => $this->workflow->reply($outsider->id,
            ['ticket_id' => $created['ticket_id'], 'expected_version' => '1', 'message' => 'Foreign reply'], $this->workflowKey())));
    }

    public function test_assignment_reply_close_and_cursor_audit_keep_versions_and_private_metadata(): void
    {
        $owner = $this->workflowActor();
        $admin = $this->workflowActor(true, ['support.tickets.read', 'support.tickets.write']);
        $otherAdmin = $this->workflowActor(true, ['support.tickets.read', 'support.tickets.write']);
        $this->authenticateWorkflowActor($owner);
        $created = $this->invokeWorkflow(fn () => $this->workflow->create($owner->id, $this->input, $this->workflowKey()));
        $ticket = $created['ticket_id']; $this->authenticateWorkflowActor($admin);
        $claim = $this->invokeWorkflow(fn () => $this->workflow->claim($admin->id,
            ['ticket_id' => $ticket, 'expected_version' => '1'], $this->workflowKey()));
        $this->assertSame('2', $claim['row_version']); $this->assertSame((string) $admin->id, $claim['assigned_admin_id']);
        $this->assertCount(1, $this->workflow->listing($admin->id, new TicketListQuery(assignment: 'mine'), true)->items);
        $this->authenticateWorkflowActor($otherAdmin);
        $this->workflowHttpFailure(409, fn () => $this->invokeWorkflow(fn () => $this->workflow->unassign($otherAdmin->id,
            ['ticket_id' => $ticket, 'expected_version' => '2'], $this->workflowKey())));
        $this->authenticateWorkflowActor($admin);
        $reply = $this->invokeWorkflow(fn () => $this->workflow->reply($admin->id,
            ['ticket_id' => $ticket, 'expected_version' => '2', 'message' => 'Reviewed reply'], $this->workflowKey(), true));
        $this->assertSame('3', $reply['row_version']);
        $key = $this->workflowKey(); $release = ['ticket_id' => $ticket, 'expected_version' => '3'];
        $released = $this->invokeWorkflow(fn () => $this->workflow->unassign($admin->id, $release, $key));
        $this->assertNull($released['assigned_admin_id']);
        $this->assertSame($released, $this->invokeWorkflow(fn () => $this->workflow->unassign($admin->id, $release, $key)));
        $first = $this->workflow->audit($admin->id, (int) $ticket, '0', 2);
        $this->assertSame(['create', 'claim'], array_column($first->items, 'action'));
        $this->assertTrue($first->meta['has_more']);
        $second = $this->workflow->audit($admin->id, (int) $ticket, $first->meta['next_after_id'], 2);
        $this->assertSame(['reply', 'unassign'], array_column($second->items, 'action'));
        $this->assertFalse($second->meta['has_more']);
        DB::table('tx_internal_actor_grant')->where('user_id', $admin->id)->where('permission', 'support.tickets.write')->delete();
        $this->workflowHttpFailure(403, fn () => $this->invokeWorkflow(fn () => $this->workflow->unassign($admin->id, $release, $key)));
        $this->authenticateWorkflowActor($owner);
        $view = $this->workflow->find($owner->id, (int) $ticket);
        $public = OperationSchemas::output('support.tickets.get', $view);
        $this->assertArrayNotHasKey('assigned_admin_id', $public);
        $messages = $this->workflow->messages($owner->id, (int) $ticket, '0', 1);
        $this->assertTrue($messages->meta['has_more']);
        $close = $this->invokeWorkflow(fn () => $this->workflow->close($owner->id,
            ['ticket_id' => $ticket, 'expected_version' => '4'], $this->workflowKey()));
        $this->assertSame(1, $close['status']);
        $this->workflowHttpFailure(409, fn () => $this->invokeWorkflow(fn () => $this->workflow->reply($owner->id,
            ['ticket_id' => $ticket, 'expected_version' => '5', 'message' => 'Too late'], $this->workflowKey())));
    }

    public function test_event_failure_rolls_back_message_audit_and_receipt_and_same_key_can_recover(): void
    {
        $owner = $this->workflowActor(); $this->authenticateWorkflowActor($owner); $key = $this->workflowKey();
        $events = Mockery::mock(EventJournal::class);
        $events->shouldReceive('appendLocked')->once()->andThrow(new \RuntimeException('INJECTED_EVENT_FAILURE'));
        $failing = new NativeTicketWorkflow(app(CurrentSessionAuthorization::class), $events,
            app(CurrentInvocation::class), app(TicketAttachments::class));
        try { $this->invokeWorkflow(fn () => $failing->create($owner->id, $this->input, $key)); $this->fail('Expected injected failure'); }
        catch (\RuntimeException $error) { $this->assertSame('INJECTED_EVENT_FAILURE', $error->getMessage()); }
        foreach (['tx_ticket', 'tx_ticket_message', 'tx_support_audit', 'tx_support_request'] as $table) $this->assertSame(0, DB::table($table)->count());
        $created = $this->invokeWorkflow(fn () => $this->workflow->create($owner->id, $this->input, $key));
        $this->assertSame('1', $created['row_version']);
    }

    public function test_revoked_session_cannot_replay_a_completed_command(): void
    {
        $owner = $this->workflowActor(); $token = $this->authenticateWorkflowActor($owner); $key = $this->workflowKey();
        $this->invokeWorkflow(fn () => $this->workflow->create($owner->id, $this->input, $key));
        $token->delete();
        try { $this->invokeWorkflow(fn () => $this->workflow->create($owner->id, $this->input, $key)); $this->fail('Revoked session was replayed'); }
        catch (AuthenticationException) { $this->assertSame(1, DB::table('tx_support_request')->count()); }
    }
}
