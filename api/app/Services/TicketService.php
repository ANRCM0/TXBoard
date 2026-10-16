<?php
namespace App\Services;


use App\Exceptions\ApiException;
use App\Domains\Identity\AccountSqlPhase;
use App\Domains\AuthorityMigration\AuthorityAdmissionRegistry;
use App\Domains\AuthorityMigration\AuthorityFenceException;
use App\Domains\AuthorityMigration\AuthorityAdmission;
use App\Domains\AuthorityMigration\AuthorityIdentityInventory;
use App\Domains\AuthorityMigration\AuthorityTicketInventory;
use App\Domains\AuthorityMigration\AuthorityTicketSqlPhase;
use App\Jobs\SendEmailJob;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Services\Plugin\HookManager;

class TicketService
{
    /** Withdrawal already owns the outer User transaction and its admission. */
    public function createWithdrawalTicket($userId, $subject, $level, $message)
    {
        if (!AccountSqlPhase::enabled()) {
            return $this->createTicket($userId, $subject, $level, $message);
        }
        $proof = app(AuthorityAdmissionRegistry::class)->current();
        $proof->assertValid();
        if ($proof->writer !== AccountSqlPhase::WITHDRAWAL_REQUEST) {
            throw new AuthorityFenceException('AUTHORITY_WITHDRAWAL_ADMISSION_REQUIRED');
        }
        return app(AccountSqlPhase::class)->run(
            AccountSqlPhase::WITHDRAWAL_REQUEST,
            fn () => $this->createTicket($userId, $subject, $level, $message)
        );
    }

    /** The user workflow supplies this fixed source owner before creation SQL. */
    public function createUserTicket($userId, $subject, $level, $message)
    {
        if (!AuthorityTicketSqlPhase::enabled()) {
            return $this->createTicket($userId, $subject, $level, $message);
        }
        return app(AuthorityTicketSqlPhase::class)->run(
            AuthorityTicketSqlPhase::USER_TICKET_CREATE,
            fn () => $this->createTicket($userId, $subject, $level, $message)
        );
    }

    public function reply($ticket, $message, $userId)
    {
        if (AuthorityTicketSqlPhase::enabled()) {
            // Refuse an unrelated/expired parent before false-on-SQL-error handling.
            $proof = $this->requireReplyProof($ticket, $userId);
            try {
                return DB::transaction(function () use ($ticket, $message, $userId, $proof) {
                    $current = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->first();
                    if ($current === null || (int) $current->status !== Ticket::STATUS_OPENING
                        || (int) $current->user_id !== (int) $ticket->user_id
                        || ($proof->writer === AuthorityTicketSqlPhase::USER_TICKET_REPLY
                            && (int) $current->user_id !== (int) $userId)) {
                        return false;
                    }
                    $ticket->setRawAttributes($current->getAttributes(), true);
                    return $this->replyRecords($ticket, $message, $userId);
                });
            } catch (AuthorityFenceException $e) {
                throw $e;
            } catch (\Exception $e) {
                return false;
            }
        }
        try {
            DB::beginTransaction();
            $ticketMessage = $this->replyRecords($ticket, $message, $userId);
            DB::commit();
            return $ticketMessage;
        } catch (\Exception $e) {
            DB::rollback();
            return false;
        }
    }

    private function replyRecords(Ticket $ticket, $message, $userId): TicketMessage
    {
        $ticketMessage = TicketMessage::create([
            'user_id' => $userId,
            'ticket_id' => $ticket->id,
            'message' => $message
        ]);
        $isAdmin = $userId !== $ticket->user_id;
        $ticket->reply_status = $isAdmin
            ? Ticket::REPLY_STATUS_REPLIED
            : Ticket::REPLY_STATUS_WAITING;
        $ticket->last_reply_user_id = $userId;
        if (!$ticketMessage || !$ticket->save()) {
            throw new \Exception();
        }
        return $ticketMessage;
    }

    public function replyByAdmin($ticketId, $message, $userId): void
    {
        if (AuthorityTicketSqlPhase::enabled()) {
            [$ticket, $ticketMessage] = app(AuthorityTicketSqlPhase::class)->run(
                AuthorityTicketSqlPhase::ADMIN_TICKET_REPLY,
                function () use ($ticketId, $message, $userId): array {
                    $ticket = Ticket::query()->whereKey($ticketId)->lockForUpdate()->first();
                    if (!$ticket) {
                        throw new ApiException('工单不存在');
                    }
                    if ((int) $ticket->status === Ticket::STATUS_CLOSED) {
                        abort(409);
                    }
                    $ticketMessage = $this->reply($ticket, $message, $userId);
                    if (!$ticketMessage) {
                        throw new ApiException('工单回复失败');
                    }
                    return [$ticket, $ticketMessage];
                }
            );
        } else {
            $ticket = Ticket::where('id', $ticketId)->first();
            if (!$ticket) {
                throw new ApiException('工单不存在');
            }
            $ticketMessage = $this->reply($ticket, $message, $userId);
            if (!$ticketMessage) {
                throw new ApiException('工单回复失败');
            }
        }
        // These callbacks retain their original postcommit partial outcomes.
        HookManager::call('ticket.reply.admin.after', [$ticket, $ticketMessage]);
        $this->sendEmailNotify($ticket, $ticketMessage);
    }

    public function createTicket($userId, $subject, $level, $message)
    {
        if (AuthorityTicketSqlPhase::enabled()) {
            $this->requireCreateProof();
            // One managed child rollback: duplicate/message failure must never
            // roll back the already-admitted withdrawal or user-create parent.
            return DB::transaction(fn () => $this->createTicketRecords($userId, $subject, $level, $message, false));
        }
        try {
            DB::beginTransaction();
            $ticket = $this->createTicketRecords($userId, $subject, $level, $message, true);
            DB::commit();
            return $ticket;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function createTicketRecords($userId, $subject, $level, $message, bool $nativeRollback): Ticket
    {
        if (Ticket::where('status', 0)->where('user_id', $userId)->lockForUpdate()->first()) {
            if ($nativeRollback) DB::rollBack();
            throw new ApiException('存在未关闭的工单');
        }
        $ticket = Ticket::create([
            'user_id' => $userId,
            'subject' => $subject,
            'level' => $level,
            'reply_status' => Ticket::REPLY_STATUS_WAITING,
            'last_reply_user_id' => $userId,
        ]);
        if (!$ticket) {
            throw new ApiException('工单创建失败');
        }
        $ticketMessage = TicketMessage::create([
            'user_id' => $userId,
            'ticket_id' => $ticket->id,
            'message' => $message
        ]);
        if (!$ticketMessage) {
            if ($nativeRollback) DB::rollBack();
            throw new ApiException('工单消息创建失败');
        }
        return $ticket;
    }

    private function requireCreateProof(): AuthorityAdmission
    {
        $proof = app(AuthorityAdmissionRegistry::class)->current();
        $proof->assertValid();
        $this->requireDeclaredProof($proof);
        $userCreate = $proof->writer === AuthorityTicketSqlPhase::USER_TICKET_CREATE
            && $proof->scope === AuthorityTicketInventory::SCOPE
            && $proof->version === AuthorityTicketInventory::VERSION && $proof->epoch === '6';
        $withdrawal = $proof->writer === AccountSqlPhase::WITHDRAWAL_REQUEST
            && (($proof->scope === AuthorityIdentityInventory::SCOPE
                    && $proof->version === AuthorityIdentityInventory::VERSION && $proof->epoch === '5')
                || ($proof->scope === AuthorityTicketInventory::SCOPE
                    && $proof->version === AuthorityTicketInventory::VERSION && $proof->epoch === '6'));
        if (!$userCreate && !$withdrawal) {
            throw new AuthorityFenceException('AUTHORITY_TICKET_CREATE_ADMISSION_REQUIRED');
        }
        return $proof;
    }

    private function requireReplyProof(Ticket $ticket, $userId): AuthorityAdmission
    {
        $proof = app(AuthorityAdmissionRegistry::class)->current();
        $proof->assertValid();
        $this->requireDeclaredProof($proof);
        if ($proof->scope !== AuthorityTicketInventory::SCOPE
            || $proof->version !== AuthorityTicketInventory::VERSION || $proof->epoch !== '6'
            || !in_array($proof->writer, [AuthorityTicketSqlPhase::USER_TICKET_REPLY,
                AuthorityTicketSqlPhase::ADMIN_TICKET_REPLY], true)
            || ($proof->writer === AuthorityTicketSqlPhase::USER_TICKET_REPLY
                && (int) $ticket->user_id !== (int) $userId)) {
            throw new AuthorityFenceException('AUTHORITY_TICKET_REPLY_ADMISSION_REQUIRED');
        }
        return $proof;
    }

    private function requireDeclaredProof(AuthorityAdmission $proof): void
    {
        $settings = config('authority_writer_fence');
        if (!is_array($settings) || count($settings) !== 5
            || array_diff(array_keys($settings),
                ['enabled', 'worker_epoch', 'worker_role', 'worker_scope', 'worker_version'])
            || $settings['enabled'] !== true || $settings['worker_role'] !== 'legacy'
            || (!is_int($settings['worker_epoch']) && !is_string($settings['worker_epoch']))
            || (string) $settings['worker_epoch'] !== $proof->epoch
            || $settings['worker_scope'] !== $proof->scope || $settings['worker_version'] !== $proof->version
            || !app()->environment(['local', 'testing']) || config('core_shadow.isolated_copy') !== true) {
            throw new AuthorityFenceException('AUTHORITY_TICKET_DECLARATION_INVALID');
        }
    }

    // 半小时内不再重复通知
    private function sendEmailNotify(Ticket $ticket, TicketMessage $ticketMessage)
    {
        $user = User::find($ticket->user_id);
        $cacheKey = 'ticket_sendEmailNotify_' . $ticket->user_id;
        if (!Cache::get($cacheKey)) {
            Cache::put($cacheKey, 1, 1800);
            SendEmailJob::dispatch([
                'email' => $user->email,
                'subject' => '您在' . admin_setting('app_name', 'TXBoard') . '的工单得到了回复',
                'template_name' => 'notify',
                'template_value' => [
                    'name' => admin_setting('app_name', 'TXBoard'),
                    'url' => admin_setting('app_url'),
                    'content' => "主题：{$ticket->subject}\r\n回复内容：{$ticketMessage->message}"
                ]
            ]);
        }
    }
}
