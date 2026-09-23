<?php

namespace App\Http\Controllers\V2\Agent;

use App\Http\Controllers\Controller;
use App\Models\AgentSupportReplyRequest;
use App\Services\AgentOps\AgentAbility;
use App\Services\AgentOps\AgentSupportService;
use App\Services\AgentOps\AgentTargetScope;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Illuminate\Http\Request;

class AgentSupportController extends Controller
{
    public function __construct(private readonly AgentSupportService $support) {}

    private function assertSupportScope(Request $request): void
    {
        if (AgentTargetScope::describe($request->user()->currentAccessToken())['mode'] !== 'all') {
            throw new AccessDeniedHttpException('Node/machine-restricted tokens cannot access administrator-wide support data');
        }
    }

    public function overview(Request $request)
    {
        $this->assertSupportScope($request);
        AgentAbility::assert($request, AgentAbility::SUPPORT_READ);
        return $this->success($this->support->overview());
    }

    public function tickets(Request $request)
    {
        $this->assertSupportScope($request);
        AgentAbility::assert($request, AgentAbility::SUPPORT_READ);
        $params = $request->validate([
            'status' => 'nullable|in:waiting,open,closed',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);
        return $this->success($this->support->tickets($params['status'] ?? 'waiting', (int) ($params['limit'] ?? 20)));
    }

    public function ticket(Request $request, int $ticketId)
    {
        $this->assertSupportScope($request);
        AgentAbility::assert($request, AgentAbility::SUPPORT_READ);
        $ticket = $this->support->ticket($ticketId);
        return $ticket ? $this->success($ticket) : $this->fail([404000, 'Ticket not found']);
    }

    public function requestReply(Request $request, int $ticketId)
    {
        $this->assertSupportScope($request);
        AgentAbility::assert($request, AgentAbility::SUPPORT_REPLY_REQUEST);
        $params = $request->validate(['message' => 'required|string|min:1|max:2000']);
        if (trim($params['message']) === '') {
            return $this->fail([422000, 'Reply cannot be blank']);
        }
        $reply = $this->support->requestReply($ticketId, $params['message'], (int) $request->user()->id, (int) $request->user()->currentAccessToken()->id);
        return $this->success($this->support->serialize($reply));
    }

    public function replyStatus(Request $request, string $requestId)
    {
        $this->assertSupportScope($request);
        AgentAbility::assert($request, AgentAbility::SUPPORT_REPLY_REQUEST);
        $reply = AgentSupportReplyRequest::where('request_id', $requestId)
            ->where('admin_id', $request->user()->id)
            ->where('token_id', $request->user()->currentAccessToken()->id)
            ->first();
        return $reply ? $this->success($this->support->serialize($reply)) : $this->fail([404000, 'Reply request not found']);
    }
}
