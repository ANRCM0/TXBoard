<?php
namespace App\Domains\Support;


use App\Exceptions\ApiException;
use App\Domains\Installation\InstallationAuthority;
use App\Jobs\SendEmailJob;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Services\Plugin\HookManager;

class TicketService
{
    public function reply(Ticket $ticket, $message, $userId)
    {
        try {
            return DB::transaction(function () use ($ticket, $message, $userId) {
                app(InstallationAuthority::class)->assertLegacyWriter();
                $current = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->first();
                if ($current === null || (int) $current->status !== Ticket::STATUS_OPENING
                    || (int) $current->user_id !== (int) $ticket->user_id
                    || (int) $current->user_id !== (int) $userId) {
                    return false;
                }
                $ticket->setRawAttributes($current->getAttributes(), true);
                return $this->replyRecords($ticket, $message, $userId);
            });
        } catch (\Exception $exception) {
            return false;
        }
    }

    private function replyRecords(Ticket $ticket, $message, $userId): TicketMessage
    {
        $ticketMessage = TicketMessage::create([
            'user_id' => $userId,
            'ticket_id' => $ticket->id,
            'message' => $message,
        ]);
        $isAdmin = (int) $userId !== (int) $ticket->user_id;
        $ticket->reply_status = $isAdmin
            ? Ticket::REPLY_STATUS_REPLIED
            : Ticket::REPLY_STATUS_WAITING;
        $ticket->last_reply_user_id = $userId;
        if (!$ticketMessage || !$ticket->save()) {
            throw new \RuntimeException('TICKET_REPLY_FAILED');
        }
        return $ticketMessage;
    }

    public function replyByAdmin($ticketId, $message, $userId): void
    {
        [$ticket, $ticketMessage] = DB::transaction(function () use ($ticketId, $message, $userId): array {
            app(InstallationAuthority::class)->assertLegacyWriter();
            $ticket = Ticket::query()->whereKey($ticketId)->lockForUpdate()->first();
            if (!$ticket) {
                throw new ApiException('工单不存在');
            }
            if ((int) $ticket->status === Ticket::STATUS_CLOSED) {
                abort(409);
            }
            return [$ticket, $this->replyRecords($ticket, $message, $userId)];
        });
        HookManager::call('ticket.reply.admin.after', [$ticket, $ticketMessage]);
        $this->sendEmailNotify($ticket, $ticketMessage);
    }

    public function createTicket($userId, $subject, $level, $message): Ticket
    {
        // A child rollback must leave the caller's outer transaction intact.
        return DB::transaction(function () use ($userId, $subject, $level, $message): Ticket {
            app(InstallationAuthority::class)->assertLegacyWriter();
            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            if (Ticket::query()->where('status', Ticket::STATUS_OPENING)
                ->where('user_id', $userId)->lockForUpdate()->first()) {
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
                'message' => $message,
            ]);
            if (!$ticketMessage) {
                throw new ApiException('工单消息创建失败');
            }
            return $ticket;
        });
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
