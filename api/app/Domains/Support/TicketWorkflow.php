<?php

namespace App\Domains\Support;

use App\Exceptions\ApiException;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Services\Plugin\HookManager;
use App\Services\TicketService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class TicketWorkflow
{
    public function list(int $userId, int $page, int $perPage): array
    {
        $pager = Ticket::query()->where('user_id', $userId)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($perPage, ['id', 'subject', 'level', 'status', 'reply_status',
                'created_at', 'updated_at'], 'page', $page);
        return [
            $pager->getCollection()->map(static fn (Ticket $t): array => self::dto($t))->all(),
            ['page' => $pager->currentPage(), 'per_page' => $pager->perPage(),
                'total' => $pager->total(), 'last_page' => $pager->lastPage()],
        ];
    }

    public function find(int $userId, int $id): ?array
    {
        $ticket = Ticket::query()->where('user_id', $userId)->find($id);
        if ($ticket === null) {
            return null;
        }
        $messages = TicketMessage::query()->where('ticket_id', $ticket->id)
            ->orderBy('id')->get(['id', 'user_id', 'message', 'created_at'])
            ->map(static fn (TicketMessage $m): array => [
                'id' => (int) $m->id,
                'message' => (string) $m->message,
                'is_me' => (int) $m->user_id === (int) $ticket->user_id,
                'created_at' => self::date($m->created_at),
            ])->all();
        return self::dto($ticket) + ['messages' => $messages];
    }

    public function create(int $userId, string $subject, int $level, string $message): int
    {
        // Central business service remains authoritative for one-open-ticket
        // enforcement, transaction boundaries and initial message creation.
        try {
            $ticket = app(TicketService::class)->createTicket($userId, $subject, $level, $message);
        } catch (ApiException $exception) {
            // Central service denies another open ticket. Never leak internal
            // exception text through the native API.
            abort(409);
        }
        HookManager::call('ticket.create.after', $ticket);
        return (int) $ticket->id;
    }

    public function reply(int $userId, int $id, string $message): void
    {
        DB::transaction(function () use ($userId, $id, $message): void {
            $ticket = Ticket::query()->where('user_id', $userId)
                ->whereKey($id)->lockForUpdate()->first();
            if ($ticket === null) {
                abort(404);
            }
            if ((int) $ticket->status !== Ticket::STATUS_OPENING) {
                abort(409);
            }
            if ((int) admin_setting('ticket_must_wait_reply', 0)) {
                $last = TicketMessage::query()->where('ticket_id', $id)
                    ->orderByDesc('id')->first();
                if ($last && (int) $last->user_id === $userId) {
                    abort(409);
                }
            }
            if (!app(TicketService::class)->reply($ticket, $message, $userId)) {
                abort(409);
            }
            HookManager::call('ticket.reply.user.after', $ticket);
        });
    }

    public function close(int $userId, int $id): void
    {
        DB::transaction(static function () use ($userId, $id): void {
            $ticket = Ticket::query()->where('user_id', $userId)
                ->whereKey($id)->lockForUpdate()->first();
            if (!$ticket) {
                abort(404);
            }
            if ((int) $ticket->status === Ticket::STATUS_CLOSED) {
                return; // Closing twice is idempotent.
            }
            $ticket->status = Ticket::STATUS_CLOSED;
            $ticket->saveOrFail();
        });
    }

    private static function dto(Ticket $ticket): array
    {
        return [
            'id' => (int) $ticket->id,
            'subject' => (string) $ticket->subject,
            'level' => (int) $ticket->level,
            'status' => (int) $ticket->status,
            'reply_status' => (int) $ticket->reply_status,
            'created_at' => self::date($ticket->created_at),
            'updated_at' => self::date($ticket->updated_at),
        ];
    }

    private static function date(mixed $stamp): string
    {
        return Carbon::createFromTimestampUTC((int) $stamp)->toIso8601String();
    }
}
