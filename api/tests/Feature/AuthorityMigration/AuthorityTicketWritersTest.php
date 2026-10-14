<?php

namespace Tests\Feature\AuthorityMigration;

use App\Domains\AuthorityMigration\AuthorityAdmissionRegistry;
use App\Domains\AuthorityMigration\AuthorityFenceException;
use App\Domains\AuthorityMigration\AuthorityIdentitySqlPhase;
use App\Domains\AuthorityMigration\AuthorityPostcommitHandoff;
use App\Domains\Support\TicketWorkflow;
use App\Exceptions\ApiException;
use App\Jobs\NodeUserSyncJob;
use App\Jobs\SendEmailJob;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Services\Plugin\HookManager;
use App\Services\TicketService;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AuthorityTicketFixture;
use Tests\TestCase;

/** Real HTTP and native domain roots; observations come from the actual SQL transaction. */
class AuthorityTicketWritersTest extends TestCase
{
    use AuthorityTicketFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureTicketDatabase();
        config(['authority_writer_fence.enabled' => false, 'core_shadow.isolated_copy' => true,
            'legacy_capture_shadow.enabled' => false, 'legacy_observer_capture.enabled' => false,
            'legacy_admin_creation_shadow.enabled' => false, 'legacy_registration_creation_shadow.enabled' => false,
            'legacy_bootstrap_creation_shadow.enabled' => false, 'legacy_admin_deletion_shadow.enabled' => false,
            'cache.default' => 'array', 'cache.setting_store' => 'array']);
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        admin_setting(['secure_path' => 'ticket_fixture', 'ticket_must_wait_reply' => 0,
            'commission_withdraw_limit' => 0, 'commission_withdraw_method' => ['alipay']]);
        Bus::fake();
        HookManager::reset();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        config(['authority_writer_fence' => ['enabled' => true, 'worker_epoch' => '1', 'worker_role' => 'legacy']]);
    }

    protected function tearDown(): void
    {
        HookManager::reset();
        $this->cleanupTicketDatabase();
        parent::tearDown();
    }

    public function test_actual_user_and_admin_http_and_cron_cover_each_fixed_root_before_domain_sql(): void
    {
        $this->tickets();
        $user = $this->user();
        $admin = $this->user(['is_admin' => 1]);
        $seen = [];
        $this->observeTicketMutations($seen);
        Sanctum::actingAs($user);
        $id = $this->postJson('/txapi/tickets', ['subject' => 'Support fixture', 'level' => 1, 'message' => 'Initial'])
            ->assertCreated()->json('data.id');
        $this->postJson('/txapi/tickets/'.$id.'/messages', ['message' => 'User reply'])->assertOk();
        Sanctum::actingAs($admin);
        $root = '/txapi/admin/ticket_fixture/tickets/'.$id;
        $this->postJson($root.'/reply', ['message' => 'Admin reply'])->assertOk();
        Bus::assertDispatchedTimes(SendEmailJob::class, 1);
        $this->postJson($root.'/close')->assertOk();
        $this->postJson($root.'/close')->assertOk();
        Sanctum::actingAs($user);
        $second = $this->postJson('/txapi/tickets', ['subject' => 'Second fixture', 'level' => 1, 'message' => 'Initial'])
            ->assertCreated()->json('data.id');
        $this->postJson('/txapi/tickets/'.$second.'/close')->assertOk();
        $this->postJson('/txapi/tickets/'.$second.'/close')->assertOk();
        $cron = $this->ticket($user, ['reply_status' => Ticket::REPLY_STATUS_REPLIED,
            'last_reply_user_id' => $admin->id, 'updated_at' => time() - 90000]);
        $this->assertSame(0, Artisan::call('check:ticket'));
        $this->assertSame(Ticket::STATUS_CLOSED, (int) $cron->fresh()->status);
        $this->assertEqualsCanonicalizing(['user.ticket.create', 'user.ticket.reply', 'user.ticket.close',
            'admin.ticket.reply', 'admin.ticket.close', 'ticket.close.cron'], array_keys($seen));
        $this->assertSame(0, DB::table('tx_authority_postcommit_intent')->count());
    }

    public function test_missing_cross_account_closed_and_wait_reply_rules_preserve_exact_ticket_rows(): void
    {
        $this->tickets();
        $user = $this->user();
        $other = $this->user();
        $admin = $this->user(['is_admin' => 1]);
        $ticket = $this->ticket($user);
        $before = $this->rows(['tx_ticket', 'tx_ticket_message']);
        Sanctum::actingAs($other);
        $this->postJson('/txapi/tickets/'.$ticket->id.'/messages', ['message' => 'Cross-account'])->assertNotFound();
        $this->postJson('/txapi/tickets/'.$ticket->id.'/close')->assertNotFound();
        Sanctum::actingAs($user);
        $this->postJson('/txapi/tickets/999999/messages', ['message' => 'Missing'])->assertNotFound();
        $this->postJson('/txapi/tickets/999999/close')->assertNotFound();
        admin_setting(['ticket_must_wait_reply' => 1]);
        $this->postJson('/txapi/tickets/'.$ticket->id.'/messages', ['message' => 'Bump'])->assertStatus(409);
        $this->assertSame($before, $this->rows(array_keys($before)));
        $this->postJson('/txapi/tickets/'.$ticket->id.'/close')->assertOk();
        $closed = $this->rows(array_keys($before));
        $this->postJson('/txapi/tickets/'.$ticket->id.'/messages', ['message' => 'Closed'])->assertStatus(409);
        Sanctum::actingAs($admin);
        $this->postJson('/txapi/admin/ticket_fixture/tickets/'.$ticket->id.'/reply', ['message' => 'Closed'])
            ->assertStatus(409)->assertJsonPath('error.code', 'TICKET_CLOSED');
        $this->assertSame($closed, $this->rows(array_keys($closed)));
    }

    public function test_prepared_authority_refuses_all_actual_sql_roots_with_no_business_mutation(): void
    {
        $this->tickets();
        $user = $this->user();
        $emptyUser = $this->user();
        $admin = $this->user(['is_admin' => 1]);
        $ticket = $this->ticket($user, ['reply_status' => Ticket::REPLY_STATUS_REPLIED,
            'last_reply_user_id' => $admin->id, 'updated_at' => time() - 90000]);
        $this->stop();
        $before = $this->rows(['tx_ticket', 'tx_ticket_message']);
        Sanctum::actingAs($emptyUser);
        $this->postJson('/txapi/tickets', ['subject' => 'Refused', 'level' => 1, 'message' => 'Refused'])->assertStatus(409);
        Sanctum::actingAs($user);
        $this->postJson('/txapi/tickets/'.$ticket->id.'/messages', ['message' => 'Refused'])->assertStatus(409);
        $this->postJson('/txapi/tickets/'.$ticket->id.'/close')->assertStatus(409);
        Sanctum::actingAs($admin);
        $this->postJson('/txapi/admin/ticket_fixture/tickets/'.$ticket->id.'/reply', ['message' => 'Refused'])->assertStatus(409);
        $this->postJson('/txapi/admin/ticket_fixture/tickets/'.$ticket->id.'/close')->assertStatus(409);
        $this->refused(fn () => Artisan::call('check:ticket'), 'AUTHORITY_FENCE_STALE_EPOCH');
        $this->assertSame($before, $this->rows(array_keys($before)));
        Bus::assertNotDispatched(SendEmailJob::class);
    }

    public function test_create_message_failure_rolls_back_both_rows_and_skips_postcommit_hook(): void
    {
        $this->tickets();
        $user = $this->user();
        $hook = false;
        HookManager::register('ticket.create.after', function () use (&$hook): void { $hook = true; });
        TicketMessage::creating(static fn () => throw new \RuntimeException('TICKET_SYNTHETIC_MESSAGE_FAILURE'));
        $this->fault(fn () => app(TicketWorkflow::class)->create($user->id, 'Atomic fixture', 1, 'Initial'),
            'TICKET_SYNTHETIC_MESSAGE_FAILURE');
        $this->assertSame(0, Ticket::count());
        $this->assertSame(0, TicketMessage::count());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse($hook);
    }

    public function test_create_hook_runs_after_commit_and_failure_retains_the_original_ticket_and_message(): void
    {
        $this->tickets();
        $user = $this->user();
        HookManager::register('ticket.create.after', function (): void {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
            $this->assertSame(1, Ticket::count());
            $this->assertSame(1, TicketMessage::count());
            throw new \RuntimeException('TICKET_SYNTHETIC_CREATE_HOOK_FAILURE');
        });
        $this->fault(fn () => app(TicketWorkflow::class)->create($user->id, 'Commit fixture', 1, 'Initial'),
            'TICKET_SYNTHETIC_CREATE_HOOK_FAILURE');
        $this->assertSame(1, Ticket::count());
        $this->assertSame(1, TicketMessage::count());
    }

    public function test_user_reply_hook_stays_inside_outer_transaction_and_failure_rolls_back_reply_state(): void
    {
        $this->tickets();
        $user = $this->user();
        $ticket = $this->ticket($user);
        $before = $this->rows(['tx_ticket', 'tx_ticket_message']);
        HookManager::register('ticket.reply.user.after', function (): void {
            $this->assertGreaterThan(0, DB::transactionLevel());
            $proof = app(AuthorityAdmissionRegistry::class)->current();
            $proof->assertValid();
            $this->assertSame('user.ticket.reply', $proof->writer);
            $this->assertSame(2, TicketMessage::count());
            throw new \RuntimeException('TICKET_SYNTHETIC_USER_HOOK_FAILURE');
        });
        $this->fault(fn () => app(TicketWorkflow::class)->reply($user->id, $ticket->id, 'Reply'),
            'TICKET_SYNTHETIC_USER_HOOK_FAILURE');
        $this->assertSame($before, $this->rows(array_keys($before)));
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_admin_reply_hook_failure_occurs_after_commit_and_does_not_dispatch_or_replay_sql(): void
    {
        $this->tickets();
        $user = $this->user();
        $admin = $this->user(['is_admin' => 1]);
        $ticket = $this->ticket($user);
        HookManager::register('ticket.reply.admin.after', function (): void {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame(2, TicketMessage::count());
            throw new \RuntimeException('TICKET_SYNTHETIC_ADMIN_HOOK_FAILURE');
        });
        $this->fault(fn () => app(TicketService::class)->replyByAdmin($ticket->id, 'Reply', $admin->id),
            'TICKET_SYNTHETIC_ADMIN_HOOK_FAILURE');
        $this->assertSame(2, TicketMessage::count());
        $this->assertSame(Ticket::REPLY_STATUS_REPLIED, (int) $ticket->fresh()->reply_status);
        Bus::assertNotDispatched(SendEmailJob::class);
    }

    public function test_admin_notification_dispatch_failure_keeps_committed_sql_and_original_cache_gate(): void
    {
        $this->tickets();
        $user = $this->user();
        $admin = $this->user(['is_admin' => 1]);
        $ticket = $this->ticket($user);
        $accepted = 0;
        Bus::swap(new class($accepted) {
            public function __construct(private mixed &$accepted) {}
            public function dispatch($job): void { ++$this->accepted; throw new \RuntimeException('TICKET_SYNTHETIC_QUEUE_FAILURE'); }
        });
        $this->fault(fn () => app(TicketService::class)->replyByAdmin($ticket->id, 'First reply', $admin->id),
            'TICKET_SYNTHETIC_QUEUE_FAILURE');
        $this->assertSame(1, $accepted);
        $this->assertSame(2, TicketMessage::count());
        app(TicketService::class)->replyByAdmin($ticket->id, 'Second independent reply', $admin->id);
        $this->assertSame(1, $accepted, 'Original cache gate remains set; no delivery certificate is inferred.');
        $this->assertSame(3, TicketMessage::count());
        $this->assertFalse($this->fence()->status()['global_drain_complete']);
    }

    public function test_cron_rechecks_stale_candidates_and_commits_per_ticket_before_later_prepare_refusal(): void
    {
        $this->tickets();
        $user = $this->user();
        $admin = $this->user(['is_admin' => 1]);
        $old = ['reply_status' => Ticket::REPLY_STATUS_REPLIED, 'last_reply_user_id' => $admin->id,
            'updated_at' => time() - 90000];
        $first = $this->ticket($user, $old);
        $second = $this->ticket($user, $old);
        $ignored = $this->ticket($user, array_replace($old, ['last_reply_user_id' => $user->id]));
        $prepared = false;
        Event::listen(TransactionCommitted::class, function ($event) use ($first, &$prepared): void {
            if (!$prepared && $event->connection->getName() === $this->ticketConnection()
                && DB::transactionLevel() === 0 && (int) $first->fresh()->status === Ticket::STATUS_CLOSED) {
                $prepared = true;
                $this->stop();
            }
        });
        $this->refused(fn () => Artisan::call('check:ticket'), 'AUTHORITY_FENCE_STALE_EPOCH');
        $this->assertTrue($prepared);
        $this->assertSame(Ticket::STATUS_CLOSED, (int) $first->fresh()->status);
        $this->assertSame(Ticket::STATUS_OPENING, (int) $second->fresh()->status);
        $this->assertSame(Ticket::STATUS_OPENING, (int) $ignored->fresh()->status);
        $this->assertSame(3, TicketMessage::count());
    }

    public function test_generic_helpers_refuse_unknown_unrelated_and_expired_parent_proofs_before_sql(): void
    {
        $this->tickets();
        $user = $this->user();
        $ticket = $this->ticket($user);
        $before = $this->rows(['tx_ticket', 'tx_ticket_message']);
        $service = app(TicketService::class);
        $this->refused(fn () => $service->createTicket($user->id, 'Unknown', 1, 'Message'), 'AUTHORITY_ADMISSION_REQUIRED');
        $this->refused(fn () => $service->reply($ticket, 'Unknown', $user->id), 'AUTHORITY_ADMISSION_REQUIRED');
        DB::transaction(function () use ($service, $ticket, $user): void {
            $this->fence()->admit('admin.user.update');
            $this->narrowRefusal(fn () => $service->createTicket($user->id, 'Wrong parent', 1, 'Message'));
            $this->narrowRefusal(fn () => $service->reply($ticket, 'Wrong parent', $user->id));
        });
        DB::beginTransaction();
        $proof = $this->fence()->admit('user.ticket.reply');
        DB::rollBack();
        $this->refused(fn () => $proof->assertValid(), 'AUTHORITY_ADMISSION_LIFETIME_INVALID');
        DB::beginTransaction();
        $this->refused(fn () => app(TicketWorkflow::class)->close($user->id, $ticket->id), 'AUTHORITY_ADMISSION_REQUIRED');
        DB::rollBack();
        $this->assertSame($before, $this->rows(array_keys($before)));
    }

    public static function withdrawalVersions(): array
    {
        return ['v5' => [5], 'v6' => [6]];
    }

    #[DataProvider('withdrawalVersions')]
    public function test_actual_withdrawal_preserves_its_v5_writer_and_duplicate_creation_never_consumes_an_ancestor(int $version): void
    {
        $version === 5 ? $this->identity() : $this->tickets();
        $user = $this->user(['commission_balance' => 1000]);
        $seen = [];
        $this->observeTicketMutations($seen, ['withdrawal.request']);
        Sanctum::actingAs($user);
        $this->postJson('/txapi/billing/withdrawals', ['withdraw_method' => 'alipay', 'withdraw_account' => 'Synthetic fixture'])
            ->assertCreated();
        $this->postJson('/txapi/billing/withdrawals', ['withdraw_method' => 'alipay', 'withdraw_account' => 'Synthetic fixture'])
            ->assertStatus(409);
        $this->assertSame(['withdrawal.request'], array_keys($seen));
        DB::beginTransaction();
        $this->fence()->admit(AuthorityIdentitySqlPhase::WITHDRAWAL_REQUEST);
        DB::table('tx_user')->where('id', $user->id)->update(['balance' => 123]);
        try {
            app(TicketService::class)->createWithdrawalTicket($user->id, 'Duplicate', 1, 'Message');
            $this->fail('Native duplicate-opening-ticket rule must reject.');
        } catch (ApiException) {
            $this->assertSame(1, DB::transactionLevel(), 'Enabled failure must preserve the admitted ancestor.');
            $this->assertTrue(DB::connection()->getPdo()->inTransaction());
            $this->assertSame(123, (int) $user->fresh()->balance);
        }
        DB::rollBack();
        $this->assertSame(0, (int) $user->fresh()->balance);
        $this->assertSame(1, Ticket::count());
        $this->assertSame(1, TicketMessage::count());
    }

    public function test_real_bearer_touches_native_session_and_executes_ticket_owner_under_v6_then_refuses_after_prepare(): void
    {
        $this->tickets();
        $user = $this->user();
        $token = $user->createToken('ticket-real-bearer');
        Auth::forgetGuards();
        $id = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)->postJson('/txapi/tickets',
            ['subject' => 'Bearer fixture', 'level' => 1, 'message' => 'Initial'])->assertCreated()->json('data.id');
        $this->assertNotNull($token->accessToken->fresh()->last_used_at);
        Auth::forgetGuards();
        $this->stop();
        $before = $this->rows(['tx_ticket', 'tx_ticket_message', 'personal_access_tokens']);
        $this->postJson('/txapi/tickets/'.$id.'/close')->assertStatus(409);
        $this->assertSame($before, $this->rows(array_keys($before)));
    }

    public function test_default_disabled_native_ticket_http_admin_cron_and_real_bearer_have_zero_authority_io(): void
    {
        config(['authority_writer_fence' => ['enabled' => false, 'worker_epoch' => 'invalid', 'worker_role' => 'invalid'],
            'core_shadow.isolated_copy' => false]);
        Schema::disableForeignKeyConstraints();
        try {
            foreach (['tx_authority_postcommit_delivery', 'tx_authority_postcommit_intent', 'tx_authority_ticket_upgrade',
                'tx_authority_identity_upgrade', 'tx_authority_native_upgrade', 'tx_authority_finance_upgrade',
                'tx_authority_inventory_upgrade', 'tx_authority_prepare_receipt', 'tx_authority_writer_fence'] as $table) Schema::drop($table);
        } finally { Schema::enableForeignKeyConstraints(); }
        $user = $this->user();
        $admin = $this->user(['is_admin' => 1]);
        $token = $user->createToken('ticket-default-off-bearer');
        DB::enableQueryLog();
        DB::flushQueryLog();
        Auth::forgetGuards();
        $id = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)->postJson('/txapi/tickets',
            ['subject' => 'Native fixture', 'level' => 1, 'message' => 'Initial'])->assertCreated()->json('data.id');
        Auth::forgetGuards();
        $this->postJson('/txapi/tickets/'.$id.'/messages', ['message' => 'User reply'])->assertOk();
        $this->withoutHeader('Authorization');
        Sanctum::actingAs($admin);
        $this->postJson('/txapi/admin/ticket_fixture/tickets/'.$id.'/reply', ['message' => 'Admin reply'])->assertOk();
        $this->postJson('/txapi/admin/ticket_fixture/tickets/'.$id.'/close')->assertOk();
        $cron = $this->ticket($user, ['reply_status' => Ticket::REPLY_STATUS_REPLIED,
            'last_reply_user_id' => $admin->id, 'updated_at' => time() - 90000]);
        $this->assertSame(0, Artisan::call('check:ticket'));
        $this->assertSame(Ticket::STATUS_CLOSED, (int) $cron->fresh()->status);
        foreach (DB::getQueryLog() as $query) $this->assertStringNotContainsString('tx_authority_', $query['query']);
        DB::disableQueryLog();
        $this->assertNotNull($token->accessToken->fresh()->last_used_at);
    }

    public static function pendingVersions(): array
    {
        return ['v4' => [4], 'v5' => [5]];
    }

    #[DataProvider('pendingVersions')]
    public function test_pending_old_intent_handoffs_under_v6_without_rewriting_the_original_payload(int $version): void
    {
        $version === 4 ? $this->native() : $this->identity();
        $user = $this->user(['group_id' => 1]);
        $previousBus = Bus::getFacadeRoot();
        Bus::swap(new class {
            public function dispatch($job): void { throw new \RuntimeException('TICKET_SYNTHETIC_QUEUE_UNAVAILABLE'); }
        });
        DB::transaction(function () use ($user): void {
            $this->fence()->admit('admin.user.update');
            $user->update(['group_id' => 2]);
        });
        $before = $this->rows(['tx_authority_postcommit_intent']);
        $intent = DB::table('tx_authority_postcommit_intent')->first();
        $this->assertSame('authority-writer-fence/v'.$version, $intent->version);
        $this->assertSame('unknown', DB::table('tx_authority_postcommit_delivery')->value('handoff_state'));
        if ($version === 4) $this->fence()->extendIdentity('ticket-parent-identity', '4');
        $this->fence()->extendTicket('ticket-fixture', '5');
        $this->declare(6, \App\Domains\AuthorityMigration\AuthorityTicketInventory::SCOPE,
            \App\Domains\AuthorityMigration\AuthorityTicketInventory::VERSION);
        Bus::swap($previousBus);
        $report = app(AuthorityPostcommitHandoff::class)->run(1);
        $this->assertSame('handed_off', $report['results'][0]['handoff_status']);
        $this->assertFalse($report['node_delivery_verified']);
        $this->assertFalse($report['global_drain_complete']);
        $this->assertSame($before, $this->rows(['tx_authority_postcommit_intent']));
        Bus::assertDispatchedTimes(NodeUserSyncJob::class, 1);
    }

    public function test_actual_registration_under_v6_stages_synchronous_intent_and_committing_schedule(): void
    {
        $this->tickets();
        admin_setting(['try_out_plan_id' => 0, 'captcha_enable' => 0, 'email_verify' => 0, 'invite_force' => 0]);
        $response = $this->postJson('/txapi/auth/register', ['email' => 'ticket-register@example.test',
            'password' => 'FixturePassword77'])->assertCreated();
        $intent = DB::table('tx_authority_postcommit_intent')->first();
        $this->assertNotNull($intent);
        $this->assertSame('native-sql-writers/v6', $intent->scope);
        $this->assertSame('authority-writer-fence/v6', $intent->version);
        $this->assertSame('6', (string) $intent->authority_epoch);
        $this->assertSame('user.register.create', $intent->writer);
        $this->assertSame('created', $intent->action);
        $this->assertSame('applied', DB::table('tx_authority_postcommit_delivery')->value('schedule_state'));
        Bus::assertDispatchedTimes(NodeUserSyncJob::class, 1);
        $evidence = json_encode($this->rows(['tx_authority_ticket_upgrade', 'tx_authority_postcommit_intent']), JSON_THROW_ON_ERROR);
        foreach (['ticket-register@example.test', 'FixturePassword77', $response->json('data.auth_data')] as $secret) {
            $this->assertStringNotContainsString($secret, $evidence);
        }
    }
    public function test_mutating_a_valid_parent_declaration_cannot_authorize_generic_ticket_helpers(): void
    {
        $this->tickets();
        $user = $this->user();
        $ticket = $this->ticket($user);
        $before = $this->rows(['tx_ticket', 'tx_ticket_message']);
        $declaration = config('authority_writer_fence');
        DB::transaction(function () use ($declaration, $user, $ticket): void {
            $this->fence()->admit('user.ticket.reply');
            foreach ([6.0, true, ['6']] as $invalid) {
                config(['authority_writer_fence.worker_epoch' => $invalid]);
                $this->refused(fn () => app(TicketService::class)->reply($ticket, 'Invalid declaration', $user->id),
                    'AUTHORITY_TICKET_DECLARATION_INVALID');
            }
            config(['authority_writer_fence' => $declaration + ['unexpected_key' => 'synthetic']]);
            $this->refused(fn () => app(TicketService::class)->reply($ticket, 'Invalid declaration', $user->id),
                'AUTHORITY_TICKET_DECLARATION_INVALID');
            config(['authority_writer_fence' => $declaration]);
        });
        DB::transaction(function () use ($declaration, $user): void {
            $this->fence()->admit('user.ticket.create');
            config(['authority_writer_fence.worker_epoch' => 6.0]);
            $this->refused(fn () => app(TicketService::class)->createTicket($user->id, 'Invalid declaration', 1, 'Message'),
                'AUTHORITY_TICKET_DECLARATION_INVALID');
            config(['authority_writer_fence' => $declaration]);
        });
        $this->assertSame($before, $this->rows(array_keys($before)));
    }

    public function test_native_reply_save_false_rolls_back_child_message_and_never_calls_user_hook(): void
    {
        $this->tickets();
        $user = $this->user();
        $ticket = $this->ticket($user);
        $before = $this->rows(['tx_ticket', 'tx_ticket_message']);
        $hook = false;
        HookManager::register('ticket.reply.user.after', function () use (&$hook): void { $hook = true; });
        Ticket::saving(static fn () => false);
        Sanctum::actingAs($user);
        $this->postJson('/txapi/tickets/'.$ticket->id.'/messages', ['message' => 'Save refused'])->assertStatus(409);
        $this->assertSame($before, $this->rows(array_keys($before)));
        $this->assertFalse($hook);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_withdrawal_message_failure_preserves_the_actual_admitted_ancestor_and_rolls_back_only_child_rows(): void
    {
        $this->tickets();
        $user = $this->user();
        TicketMessage::creating(static fn () => throw new \RuntimeException('TICKET_SYNTHETIC_WITHDRAWAL_MESSAGE_FAILURE'));
        DB::beginTransaction();
        $proof = $this->fence()->admit(AuthorityIdentitySqlPhase::WITHDRAWAL_REQUEST);
        DB::table('tx_user')->where('id', $user->id)->update(['balance' => 456]);
        $this->fault(fn () => app(TicketService::class)->createWithdrawalTicket($user->id, 'Withdrawal failure', 1, 'Initial'),
            'TICKET_SYNTHETIC_WITHDRAWAL_MESSAGE_FAILURE');
        $proof->assertValid();
        $this->assertSame(1, DB::transactionLevel());
        $this->assertSame(456, (int) $user->fresh()->balance);
        $this->assertSame(0, Ticket::count());
        $this->assertSame(0, TicketMessage::count());
        DB::rollBack();
        $this->assertSame(0, (int) $user->fresh()->balance);
    }

    public function test_cron_rechecks_native_predicates_after_enumeration_before_locked_mutation(): void
    {
        $this->tickets();
        $user = $this->user();
        $admin = $this->user(['is_admin' => 1]);
        $attributes = ['reply_status' => Ticket::REPLY_STATUS_REPLIED, 'last_reply_user_id' => $admin->id,
            'updated_at' => time() - 90000];
        $changed = $this->ticket($user, $attributes);
        $eligible = $this->ticket($user, $attributes);
        $enumerated = false;
        DB::listen(function ($query) use (&$enumerated, $changed, $user): void {
            $sql = strtolower($query->sql);
            if (!$enumerated && str_starts_with($sql, 'select ') && str_contains($sql, 'tx_ticket')
                && str_contains($sql, 'order by') && str_contains($sql, 'limit 200')) {
                $enumerated = true;
                DB::table('tx_ticket')->where('id', $changed->id)->update(['last_reply_user_id' => $user->id]);
            }
        });
        $this->assertSame(0, Artisan::call('check:ticket'));
        $this->assertTrue($enumerated);
        $this->assertSame(Ticket::STATUS_OPENING, (int) $changed->fresh()->status);
        $this->assertSame(Ticket::STATUS_CLOSED, (int) $eligible->fresh()->status);
        $this->assertSame(2, TicketMessage::count());
    }
    public function test_admin_service_rechecks_closed_ticket_after_the_controller_early_read(): void
    {
        $this->tickets();
        $user = $this->user();
        $admin = $this->user(['is_admin' => 1]);
        $ticket = $this->ticket($user);
        $changed = false;
        DB::listen(function ($query) use (&$changed, $ticket): void {
            $sql = strtolower($query->sql);
            if (!$changed && DB::transactionLevel() === 0 && str_starts_with($sql, 'select ')
                && str_contains($sql, 'tx_ticket') && str_contains($sql, 'where') && str_contains($sql, 'limit 1')) {
                $changed = true;
                DB::table('tx_ticket')->where('id', $ticket->id)->update(['status' => Ticket::STATUS_CLOSED]);
            }
        });
        Sanctum::actingAs($admin);
        $this->postJson('/txapi/admin/ticket_fixture/tickets/'.$ticket->id.'/reply', ['message' => 'Stale early read'])
            ->assertStatus(409);
        $this->assertTrue($changed);
        $this->assertSame(Ticket::STATUS_CLOSED, (int) $ticket->fresh()->status);
        $this->assertSame(1, TicketMessage::count());
        Bus::assertNotDispatched(SendEmailJob::class);
    }
    public function test_v6_registration_committing_schedule_failure_rolls_back_native_sql_creation_capture_and_intent(): void
    {
        $this->tickets();
        admin_setting(['try_out_plan_id' => 0, 'captcha_enable' => 0, 'email_verify' => 0, 'invite_force' => 0]);
        config(['legacy_capture_shadow' => ['enabled' => true, 'run_key' => 'ticket-capture',
            'billing_timezone' => 'UTC', 'currency' => 'USD'], 'legacy_observer_capture.enabled' => 'invalid',
            'legacy_registration_creation_shadow.enabled' => true]);
        $this->postJson('/txapi/auth/register', ['email' => 'ticket-register-rollback@example.test',
            'password' => 'FixturePassword77'])->assertStatus(409);
        $this->assertNull(\App\Models\User::byEmail('ticket-register-rollback@example.test')->first());
        $this->assertSame(0, DB::table('tx_legacy_user_creation_shadow')->count());
        $this->assertSame(0, DB::table('tx_authority_postcommit_intent')->count());
        $this->assertSame(0, DB::table('tx_authority_postcommit_delivery')->count());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        Bus::assertNothingDispatched();
    }
    private function observeTicketMutations(array &$seen, array $allowed = ['user.ticket.create', 'user.ticket.reply',
        'user.ticket.close', 'admin.ticket.reply', 'admin.ticket.close', 'ticket.close.cron']): void
    {
        $authoritySeen = false;
        DB::listen(function ($query) use (&$seen, &$authoritySeen, $allowed): void {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'tx_authority_writer_fence')) $authoritySeen = true;
            if (!preg_match('/\A(?:insert|update)\b/i', $sql) || !str_contains($sql, 'tx_ticket')) return;
            // Fixture inserts outside the declared root are deliberately not evidence.
            if (DB::transactionLevel() === 0) return;
            $proof = app(AuthorityAdmissionRegistry::class)->current();
            $proof->assertValid();
            $this->assertTrue($authoritySeen);
            $this->assertContains($proof->writer, $allowed);
            $this->assertSame(getmypid(), $proof->pid);
            $this->assertSame(DB::connection(), $proof->connection);
            $this->assertTrue(DB::connection()->getPdo()->inTransaction());
            $seen[$proof->writer] = true;
        });
    }

    private function fault(callable $call, string $code): void
    {
        try { $call(); $this->fail('Expected synthetic failure.'); }
        catch (\RuntimeException $error) { $this->assertSame($code, $error->getMessage()); }
    }

    private function narrowRefusal(callable $call): void
    {
        try { $call(); $this->fail('An unrelated valid proof must not authorize a generic helper.'); }
        catch (AuthorityFenceException $error) {
            $this->assertStringStartsWith('AUTHORITY_TICKET_', $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
    }
}