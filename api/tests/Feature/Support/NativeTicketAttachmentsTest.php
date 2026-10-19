<?php

namespace Tests\Feature\Support;

use App\Core\Kernel\Execution\CurrentInvocation;
use App\Core\Kernel\Execution\InvocationContext;
use App\Domains\Identity\Internal\CurrentSessionAuthorization;
use App\Domains\Installation\InstallationAuthority;
use App\Domains\InternalApi\ServiceCredentials;
use App\Domains\Notifications\Internal\EventJournal;
use App\Domains\Support\NativeTicketAttachments;
use App\Domains\Support\NativeTicketWorkflow;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\NativeRuntimeTestCase;

/** Deferred acceptance: production schema/admission/session/crypto, no outer test transaction. */
final class NativeTicketAttachmentsTest extends NativeRuntimeTestCase
{
    private NativeTicketAttachments $files;

    protected function setUp(): void
    {
        parent::setUp();
        $this->activate();
        $this->files = app(NativeTicketAttachments::class);
    }

    public function test_drafts_are_private_and_bound_files_follow_current_ticket_access(): void
    {
        $owner = $this->actor(); $outsider = $this->actor(); $admin = $this->actor(true);
        $ticket = $this->ticket($owner); $this->authenticateActor($admin);
        $file = $this->ready($admin, $ticket, "Private support evidence.\n", true);
        $this->authenticateActor($owner);
        $this->assertNull($this->files->get($owner->id, $file['id']));
        $this->assertNull($this->files->download($owner->id, ['attachment_id' => $file['id'], 'chunk_index' => 0]));
        $this->authenticateActor($admin);
        $reply = $this->workflow()->reply($admin->id, ['ticket_id' => (string) $ticket, 'expected_version' => '1',
            'message' => 'Attached evidence', 'attachment_ids' => [$file['id']]], $this->key(), true);
        $this->authenticateActor($owner);
        $this->assertSame($reply['message_id'], $this->files->get($owner->id, $file['id'])['message_id']);
        $chunk = $this->files->download($owner->id, ['attachment_id' => $file['id'], 'chunk_index' => 0]);
        $this->assertSame("Private support evidence.\n", base64_decode($chunk['content_base64'], true));
        $this->authenticateActor($outsider);
        $this->assertNull($this->files->get($outsider->id, $file['id']));
        $this->authenticateActor($owner);
        DB::table('tx_user')->where('id', $owner->id)->update(['banned' => true]);
        $this->httpRejects(403, fn () => $this->files->get($owner->id, $file['id']));
    }

    public function test_chunk_order_size_and_original_key_replay_do_not_duplicate_bytes(): void
    {
        $owner = $this->actor(); $this->authenticateActor($owner); $ticket = $this->ticket($owner);
        $content = str_repeat('a', 8192)."End.\n"; $key = $this->key();
        $input = $this->beginInput($ticket, $content); $file = $this->files->begin($owner->id, $input, $key);
        $this->httpRejects(409, fn () => $this->files->append($owner->id,
            ['attachment_id' => $file['id'], 'chunk_index' => 1, 'content_base64' => base64_encode('x')], $this->key()));
        $this->httpRejects(409, fn () => $this->files->append($owner->id,
            ['attachment_id' => $file['id'], 'chunk_index' => 0, 'content_base64' => base64_encode('short')], $this->key()));
        $chunkKey = $this->key(); $chunk = ['attachment_id' => $file['id'], 'chunk_index' => 0,
            'content_base64' => base64_encode(substr($content, 0, 8192))];
        $this->files->append($owner->id, $chunk, $chunkKey);
        $this->files->append($owner->id, $chunk, $chunkKey);
        $this->assertSame(1, DB::table('tx_support_attachment_chunk')->count());
        $this->files->append($owner->id, ['attachment_id' => $file['id'], 'chunk_index' => 1,
            'content_base64' => base64_encode(substr($content, 8192))], $this->key());
        $this->files->finish($owner->id, ['attachment_id' => $file['id']], $this->key());
        $replayed = $this->files->begin($owner->id, $input, $key);
        $this->assertSame('ready', $replayed['status']);
        $this->assertSame((string) strlen($content), $replayed['received_bytes']);
        $this->assertSame('ready', $this->files->append($owner->id, $chunk, $chunkKey)['status']);
        $this->assertSame(2, DB::table('tx_support_attachment_chunk')->count());
        $stored = DB::table('tx_support_attachment_chunk')->first();
        $this->assertStringNotContainsString(substr($content, 0, 64), $stored->ciphertext);
        $changed = $chunk; $changed['content_base64'] = base64_encode(str_repeat('b', 8192));
        $this->httpRejects(409, fn () => $this->files->append($owner->id, $changed, $chunkKey));
    }

    public function test_finish_rejects_wrong_whole_file_hash_and_mislabeled_html(): void
    {
        $owner = $this->actor(); $this->authenticateActor($owner); $ticket = $this->ticket($owner);
        foreach ([["Ordinary evidence.\n", str_repeat('0', 64), 409],
            ['<!DOCTYPE html><html><body>active document</body></html>', null, 422]] as [$content, $digest, $status]) {
            $input = $this->beginInput($ticket, $content); if ($digest !== null) $input['sha256'] = $digest;
            $file = $this->files->begin($owner->id, $input, $this->key());
            $this->files->append($owner->id, ['attachment_id' => $file['id'], 'chunk_index' => 0,
                'content_base64' => base64_encode($content)], $this->key());
            $this->httpRejects($status, fn () => $this->files->finish($owner->id, ['attachment_id' => $file['id']], $this->key()));
            $this->assertSame('uploading', $this->files->get($owner->id, $file['id'])['status']);
        }
    }

    public function test_pending_limit_spans_tickets_and_ticket_budget_reserves_declared_sizes(): void
    {
        $admin = $this->actor(true); $this->authenticateActor($admin);
        $first = $this->ticket($this->actor()); $second = $this->ticket($this->actor());
        $drafts = [];
        for ($i = 0; $i < 16; $i++) {
            $drafts[] = $this->files->begin($admin->id, $this->beginInput($i % 2 ? $first : $second, 'a'), $this->key(), true);
        }
        $this->httpRejects(409, fn () => $this->files->begin($admin->id, $this->beginInput($second, 'a'), $this->key(), true));
        foreach ($drafts as $draft) $this->files->cancel($admin->id, ['attachment_id' => $draft['id']], $this->key(), true);
        $input = $this->beginInput($first, 'a'); $input['size_bytes'] = '5242880';
        for ($i = 0; $i < 6; $i++) $this->files->begin($admin->id, $input, $this->key(), true);
        $input['size_bytes'] = '2097152'; $this->files->begin($admin->id, $input, $this->key(), true);
        $this->httpRejects(409, fn () => $this->files->begin($admin->id, $this->beginInput($first, 'a'), $this->key(), true));
        $this->assertSame(33554432, (int) DB::table('tx_support_attachment')->where('ticket_id', $first)
            ->where('status', 'uploading')->sum('size_bytes'));
    }

    public function test_reply_binding_is_atomic_with_ticket_version_message_role_and_audit(): void
    {
        $owner = $this->actor(); $this->authenticateActor($owner); $ticket = $this->ticket($owner);
        $file = $this->ready($owner, $ticket, "Evidence for atomic reply.\n"); $workflow = $this->workflow();
        $input = ['ticket_id' => (string) $ticket, 'expected_version' => '2', 'message' => 'Stale', 'attachment_ids' => [$file['id']]];
        $this->httpRejects(409, fn () => $workflow->reply($owner->id, $input, $this->key()));
        $this->assertSame(0, DB::table('tx_ticket_message')->where('ticket_id', $ticket)->count());
        $this->httpRejects(409, function () use ($owner, $ticket, $file): void {
            DB::transaction(function () use ($owner, $ticket, $file): void {
                $installation = app(InstallationAuthority::class)->assertNativeMutation();
                app(CurrentSessionAuthorization::class)->assertCurrentLocked($owner->id);
                DB::table('tx_ticket')->where('id', $ticket)->lockForUpdate()->first();
                $message = DB::table('tx_ticket_message')->insertGetId(['ticket_id' => $ticket, 'user_id' => $owner->id,
                    'author_kind' => 'admin', 'message' => 'Wrong role', 'created_at' => time(), 'updated_at' => time()]);
                $this->files->bindLocked($installation, $owner->id, $ticket, $message, [$file['id']], false);
            });
        });
        $this->assertSame(0, DB::table('tx_ticket_message')->where('ticket_id', $ticket)->count());
        $input['expected_version'] = '1'; $replyKey = $this->key(); $inject = true;
        DB::connection()->beforeExecuting(function (string $sql) use (&$inject): void {
            if ($inject && preg_match('/insert into ["`]tx_support_audit["`]/i', $sql)) {
                $inject = false; throw new \RuntimeException('INJECTED_SUPPORT_AUDIT_FAILURE');
            }
        });
        try { $workflow->reply($owner->id, $input, $replyKey); $this->fail('Expected injected transaction failure'); }
        catch (\RuntimeException $error) { $this->assertSame('INJECTED_SUPPORT_AUDIT_FAILURE', $error->getMessage()); }
        $this->assertSame('ready', $this->files->get($owner->id, $file['id'])['status']);
        $this->assertSame(0, DB::table('tx_ticket_message')->where('ticket_id', $ticket)->count());
        $this->assertSame(1, (int) DB::table('tx_ticket')->where('id', $ticket)->value('row_version'));
        $reply = $workflow->reply($owner->id, $input, $replyKey);
        $this->assertSame($reply, $workflow->reply($owner->id, $input, $replyKey));
        $this->assertSame(1, DB::table('tx_ticket_message')->where('ticket_id', $ticket)->count());
        $message = DB::table('tx_ticket_message')->where('id', $reply['message_id'])->first();
        $this->assertSame($owner->id, (int) $message->user_id); $this->assertSame('user', $message->author_kind);
        $this->assertSame('attached', $this->files->get($owner->id, $file['id'])['status']);
        $this->assertSame(1, DB::table('tx_support_audit')->where('ticket_id', $ticket)->count());
    }

    public function test_expiry_reclaims_only_unbound_chunks_and_cancel_preserves_attached_files(): void
    {
        $owner = $this->actor(); $this->authenticateActor($owner); $ticket = $this->ticket($owner);
        $attached = $this->ready($owner, $ticket, "Keep attached evidence.\n");
        $this->workflow()->reply($owner->id, ['ticket_id' => (string) $ticket, 'expected_version' => '1',
            'message' => 'Bound', 'attachment_ids' => [$attached['id']]], $this->key());
        $draft = $this->ready($owner, $ticket, "Expired draft evidence.\n");
        $this->age($attached['id']); $this->age($draft['id']);
        $this->assertSame(1, $this->files->expire());
        $this->assertSame('expired', $this->files->get($owner->id, $draft['id'])['status']);
        $this->assertSame(0, DB::table('tx_support_attachment_chunk')->where('attachment_id', $draft['id'])->count());
        $this->assertSame('attached', $this->files->get($owner->id, $attached['id'])['status']);
        $this->httpRejects(409, fn () => $this->files->cancel($owner->id, ['attachment_id' => $attached['id']], $this->key()));
        $this->assertSame(1, DB::table('tx_support_attachment_chunk')->where('attachment_id', $attached['id'])->count());
        $this->assertSame(0, $this->files->expire());
    }

    public function test_standalone_entry_does_not_consume_or_rollback_an_outer_transaction(): void
    {
        $owner = $this->actor(); $this->authenticateActor($owner); $ticket = $this->ticket($owner);
        DB::transaction(function () use ($owner, $ticket): void {
            $level = DB::transactionLevel(); DB::table('tx_ticket')->where('id', $ticket)->update(['subject' => 'Parent survives']);
            try { $this->files->begin($owner->id, $this->beginInput($ticket, "Outer transaction.\n"), $this->key()); $this->fail('Expected rejection'); }
            catch (\LogicException $error) { $this->assertSame('SUPPORT_ATTACHMENT_OUTER_TRANSACTION_UNSUPPORTED', $error->getMessage()); }
            $this->assertSame($level, DB::transactionLevel());
        });
        $this->assertSame('Parent survives', DB::table('tx_ticket')->where('id', $ticket)->value('subject'));
        $this->assertSame(0, DB::table('tx_support_attachment')->count());
    }

    public function test_internal_core_begin_dispatch_reaches_the_owner_with_the_original_key(): void
    {
        config(['internal_api.enabled' => true, 'internal_api.trusted_tls_proxies' => ['127.0.0.1']]);
        $owner = $this->actor(); $ticket = $this->ticket($owner); $token = $owner->createToken('attachment-transport', ['*'])->plainTextToken;
        $service = app(ServiceCredentials::class)->issue('attachment-transport', 'user', str_repeat('a', 64),
            ['support.tickets.attachments.begin'], time() + 3600);
        $key = $this->key();
        Auth::forgetGuards();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTPS' => 'on'])
            ->postJson('https://localhost/txapi/internal/native/v1/user/operations/support.tickets.attachments.begin', [
                'contract_version' => '1', 'request_id' => $this->key(), 'deadline_at' => time() + 15,
                'input' => $this->beginInput($ticket, "Transport evidence.\n"),
            ], ['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => $key,
                'X-Core-Service-Id' => $service['service_id'], 'X-Core-Service-Credential' => $service['credential'],
                'X-Core-Service-Epoch' => $service['epoch'], 'X-Core-Tls-Verified' => 'SUCCESS',
                'X-Core-Tls-Certificate-Sha256' => str_repeat('a', 64)])
            ->assertOk()->assertJsonPath('data.status', 'uploading')->assertJsonPath('data.ticket_id', (string) $ticket);
        $this->assertSame(1, DB::table('tx_support_attachment')->count());
        $this->assertSame(1, DB::table('tx_support_attachment_request')->count());
        $this->assertSame(hash('sha256', self::INSTALLATION.':'.$owner->id.':user.begin:'.$key),
            DB::table('tx_support_attachment_request')->value('key_hash'));
    }

    private function actor(bool $admin = false): User
    {
        $user = User::withoutEvents(fn () => User::query()->create(['email' => $this->key().'@example.test',
            'password' => 'fixture-only', 'token' => '', 'uuid' => $this->key(), 'banned' => false, 'is_admin' => $admin]));
        if ($admin) foreach (['support.tickets.read', 'support.tickets.write'] as $permission) {
            DB::table('tx_internal_actor_grant')->insert(['user_id' => $user->id, 'permission' => $permission, 'created_at' => time()]);
        }
        return $user;
    }

    private function authenticateActor(User $user): void
    {
        $token = $user->createToken('attachments-owner', ['*']); Auth::forgetGuards();
        Auth::guard('sanctum')->setUser($user->withAccessToken($token->accessToken));
    }

    private function ticket(User $owner): int
    {
        return DB::table('tx_ticket')->insertGetId(['user_id' => $owner->id, 'subject' => 'Attachment fixture', 'level' => 1,
            'status' => 0, 'reply_status' => 0, 'row_version' => 1, 'created_at' => time(), 'updated_at' => time()]);
    }

    private function workflow(): NativeTicketWorkflow
    {
        $context = Mockery::mock(CurrentInvocation::class);
        $context->shouldReceive('current')->andReturn(new InvocationContext($this->key(), $this->key(), 'internal'));
        return new NativeTicketWorkflow(app(CurrentSessionAuthorization::class), app(EventJournal::class), $context, $this->files);
    }

    private function ready(User $actor, int $ticket, string $content, bool $admin = false): array
    {
        $file = $this->files->begin($actor->id, $this->beginInput($ticket, $content), $this->key(), $admin);
        $this->files->append($actor->id, ['attachment_id' => $file['id'], 'chunk_index' => 0,
            'content_base64' => base64_encode($content)], $this->key(), $admin);
        return $this->files->finish($actor->id, ['attachment_id' => $file['id']], $this->key(), $admin);
    }

    private function beginInput(int $ticket, string $content): array
    {
        return ['ticket_id' => (string) $ticket, 'filename' => 'evidence.txt', 'media_type' => 'text/plain',
            'size_bytes' => (string) strlen($content), 'sha256' => hash('sha256', $content)];
    }

    private function age(string $id): void
    {
        // Wall-clock expiry uses time(); backdate only this persisted fixture, preserving its evidence seal.
        $row = DB::table('tx_support_attachment')->where('id', $id)->first();
        $row->created_at = time() - 172800; $row->expires_at = time() - 86400;
        $hash = (new \ReflectionMethod(NativeTicketAttachments::class, 'rowHash'))->invoke(null, $row);
        DB::table('tx_support_attachment')->where('id', $id)->update(['created_at' => $row->created_at,
            'expires_at' => $row->expires_at, 'content_hash' => $hash]);
    }

    private function httpRejects(int $status, \Closure $action): void
    {
        try { $action(); $this->fail('Expected HTTP '.$status); }
        catch (HttpExceptionInterface $error) { $this->assertSame($status, $error->getStatusCode()); }
    }

    private function key(): string { return (string) Str::uuid(); }
}
