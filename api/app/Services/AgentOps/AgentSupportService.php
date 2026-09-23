<?php

namespace App\Services\AgentOps;

use App\Models\AgentSupportReplyRequest;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AgentSupportService
{
    public function overview(): array
    {
        return [
            'tickets_waiting' => Ticket::where('status', Ticket::STATUS_OPENING)->where('reply_status', Ticket::REPLY_STATUS_WAITING)->count(),
            'tickets_open' => Ticket::where('status', Ticket::STATUS_OPENING)->count(),
            'subscriptions_expiring_7d' => User::whereNotNull('plan_id')->whereBetween('expired_at', [time(), time() + 7 * 86400])->count(),
            'orders_pending' => Order::where('status', Order::STATUS_PENDING)->count(),
            'observed_at' => time(),
        ];
    }

    public function tickets(string $status, int $limit): array
    {
        $query = Ticket::query()->orderByDesc('updated_at')->orderByDesc('id');
        if ($status === 'waiting') {
            $query->where('status', Ticket::STATUS_OPENING)->where('reply_status', Ticket::REPLY_STATUS_WAITING);
        } elseif ($status === 'open') {
            $query->where('status', Ticket::STATUS_OPENING);
        } elseif ($status === 'closed') {
            $query->where('status', Ticket::STATUS_CLOSED);
        }
        return $query->limit($limit)->get()->map(fn (Ticket $ticket) => [
            'id' => $ticket->id,
            'subject' => mb_substr((string) $ticket->subject, 0, 200),
            'status' => $ticket->status,
            'reply_status' => $ticket->reply_status,
            'updated_at' => $ticket->updated_at,
        ])->all();
    }

    public function ticket(int $id): ?array
    {
        $ticket = Ticket::find($id);
        if (!$ticket) return null;
        $user = User::with('plan:id,name')->find($ticket->user_id);
        $messages = $ticket->messages()->orderByDesc('id')->limit(20)->get(['id', 'ticket_id', 'user_id', 'message', 'created_at']);
        $orders = Order::where('user_id', $ticket->user_id)->orderByDesc('id')->limit(5)->get(['id', 'user_id', 'status', 'created_at']);
        return [
            'id' => $ticket->id,
            'subject' => mb_substr((string) $ticket->subject, 0, 200),
            'status' => $ticket->status,
            'reply_status' => $ticket->reply_status,
            'updated_at' => $ticket->updated_at,
            'messages' => $messages->reverse()->values()->map(fn ($message) => [
                'id' => $message->id,
                'from_customer' => (int) $message->user_id === (int) $ticket->user_id,
                'message' => mb_substr((string) $message->message, 0, 4000),
                'created_at' => $message->created_at,
            ])->all(),
            'customer' => $user ? [
                'id' => $user->id,
                'plan' => $user->plan?->name,
                'expired_at' => $user->expired_at,
                'remaining_traffic_bytes' => max(0, (int) $user->transfer_enable - (int) $user->u - (int) $user->d),
                'banned' => (bool) $user->banned,
                'recent_orders' => $orders->map(fn ($order) => [
                    'id' => $order->id, 'status' => $order->status, 'created_at' => $order->created_at,
                ])->all(),
            ] : null,
        ];
    }

    public function requestReply(int $ticketId, string $message, int $adminId, int $tokenId): AgentSupportReplyRequest
    {
        return DB::transaction(function () use ($ticketId, $message, $adminId, $tokenId) {
            $ticket = Ticket::whereKey($ticketId)->lockForUpdate()->first();
            if (!$ticket || $ticket->status !== Ticket::STATUS_OPENING) {
                throw ValidationException::withMessages(['ticket' => 'Ticket is missing or closed']);
            }
            $lastId = (int) $ticket->messages()->max('id');
            if ($lastId < 1) {
                throw ValidationException::withMessages(['ticket' => 'Ticket has no messages']);
            }
            if (AgentSupportReplyRequest::where('ticket_id', $ticketId)->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages(['ticket' => 'A reply is already pending approval']);
            }
            return AgentSupportReplyRequest::create([
                'request_id' => (string) Str::ulid(),
                'ticket_id' => $ticketId,
                'admin_id' => $adminId,
                'token_id' => $tokenId,
                'last_message_id' => $lastId,
                'status' => 'pending',
                'message' => $message,
            ]);
        });
    }

    public function serialize(AgentSupportReplyRequest $request, bool $admin = false): array
    {
        $data = [
            'request_id' => $request->request_id,
            'ticket_id' => $request->ticket_id,
            'status' => $request->status,
            'created_at' => $request->created_at,
            'approved_at' => $request->approved_at,
        ];
        if ($admin) $data['message'] = $request->message;
        return $data;
    }

    public function approve(string $requestId, int $approverId): AgentSupportReplyRequest
    {
        $request = DB::transaction(function () use ($requestId, $approverId) {
            $reply = AgentSupportReplyRequest::where('request_id', $requestId)->lockForUpdate()->first();
            if (!$reply || $reply->status !== 'pending') {
                throw ValidationException::withMessages(['request_id' => 'Pending reply not found']);
            }
            $ticket = Ticket::whereKey($reply->ticket_id)->lockForUpdate()->first();
            $token = DB::table('personal_access_tokens')->where('id', $reply->token_id)->first();
            if (!$ticket || $ticket->status !== Ticket::STATUS_OPENING ||
                (int) $ticket->messages()->max('id') !== (int) $reply->last_message_id ||
                (int) $reply->getRawOriginal('created_at') < time() - 86400 ||
                !$token || (int) $token->tokenable_id !== (int) $reply->admin_id ||
                !str_starts_with((string) $token->name, 'agent:') ||
                ($token->expires_at && strtotime($token->expires_at) <= time())) {
                throw ValidationException::withMessages(['request_id' => 'Reply is stale or Agent token was revoked']);
            }
            $reply->update(['status' => 'sending', 'approved_by' => $approverId, 'approved_at' => time()]);
            return $reply;
        });
        try {
            app(TicketService::class)->replyByAdmin((int) $request->ticket_id, (string) $request->message, $approverId);
            $request->update(['status' => 'succeeded']);
        } catch (\Throwable $e) {
            // Notification hooks may have run already. Never retry automatically.
            $request->update(['status' => 'unknown']);
            \Log::error('Agent support reply delivery uncertain', ['request_id' => $requestId, 'exception' => $e]);
        }
        return $request->fresh();
    }

    public function reject(string $requestId, int $approverId): AgentSupportReplyRequest
    {
        return DB::transaction(function () use ($requestId, $approverId) {
            $reply = AgentSupportReplyRequest::where('request_id', $requestId)->lockForUpdate()->first();
            if (!$reply || $reply->status !== 'pending') {
                throw ValidationException::withMessages(['request_id' => 'Pending reply not found']);
            }
            $reply->update(['status' => 'rejected', 'approved_by' => $approverId, 'approved_at' => time()]);
            return $reply;
        });
    }
}
