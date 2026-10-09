<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Services\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class TicketAdminController
{
    public function index(Request $request): JsonResponse
    {
        $params = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', 'integer', 'in:0,1'],
            'reply_status' => ['sometimes', 'integer', 'in:0,1'],
            'email' => ['sometimes', 'string', 'max:254'],
        ]);
        $query = Ticket::query()->with('user:id,email');
        foreach (['status', 'reply_status'] as $key) {
            if (isset($params[$key])) {
                $query->where($key, (int) $params[$key]);
            }
        }
        if (!empty($params['email'])) {
            $query->whereHas('user', static function ($users) use ($params): void {
                $users->where('email', $params['email']);
            });
        }
        $page = $query->orderByDesc('updated_at')->orderByDesc('id')
            ->paginate((int) ($params['per_page'] ?? 10),
                ['id','user_id','subject','level','status','reply_status','created_at','updated_at'],
                'page', (int) ($params['page'] ?? 1));
        return TxapiResponse::success($request,
            $page->getCollection()->map(static fn (Ticket $ticket): array => self::ticketDto($ticket))->all(),
            ['page' => $page->currentPage(), 'per_page' => $page->perPage(),
             'total' => $page->total(), 'last_page' => $page->lastPage()]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $ticket = Ticket::query()->with(['user:id,email',
            'messages' => static fn ($query) => $query
                ->orderBy('id')->select(['id','ticket_id','user_id','message','created_at'])])
            ->findOrFail((int) $id);
        $data = self::ticketDto($ticket);
        $data['messages'] = $ticket->messages->map(static fn (TicketMessage $message): array => [
            'id' => (int) $message->id,
            'message' => (string) $message->message,
            'user_id' => (int) $message->user_id,
            'is_from_user' => (int) $message->user_id === (int) $ticket->user_id,
            'is_from_admin' => (int) $message->user_id !== (int) $ticket->user_id,
            'created_at' => (int) $message->created_at,
        ])->all();
        return TxapiResponse::success($request, $data);
    }

    public function reply(Request $request, TicketService $tickets, string $id): JsonResponse
    {
        $params = $request->validate(['message' => ['required','string','max:10000']]);
        $ticket = Ticket::query()->findOrFail((int) $id);
        if ((int) $ticket->status === Ticket::STATUS_CLOSED) {
            return TxapiResponse::error($request, 'TICKET_CLOSED', 'Closed tickets cannot be replied to', 409);
        }
        // Keep the existing shared service: sends notifications and plugin hooks.
        $tickets->replyByAdmin((int) $id, $params['message'], (int) $request->user()->id);
        return TxapiResponse::success($request, ['ok' => true]);
    }

    public function close(Request $request, string $id): JsonResponse
    {
        DB::transaction(static function () use ($id): void {
            $ticket = Ticket::query()->lockForUpdate()->findOrFail((int) $id);
            if ((int) $ticket->status !== Ticket::STATUS_CLOSED) {
                $ticket->status = Ticket::STATUS_CLOSED;
                $ticket->saveOrFail();
            }
        });
        return TxapiResponse::success($request, ['ok' => true]);
    }

    private static function ticketDto(Ticket $ticket): array
    {
        return [
            'id' => (int) $ticket->id,
            'user_id' => (int) $ticket->user_id,
            'subject' => (string) $ticket->subject,
            'level' => (int) $ticket->level,
            'status' => (int) $ticket->status,
            'reply_status' => (int) $ticket->reply_status,
            'created_at' => (int) $ticket->created_at,
            'updated_at' => (int) $ticket->updated_at,
            'user' => $ticket->user ? [
                'id' => (int) $ticket->user->id, 'email' => (string) $ticket->user->email,
            ] : null,
        ];
    }
}
