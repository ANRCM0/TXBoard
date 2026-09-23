<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\AgentAuditLog;
use App\Models\AgentSupportReplyRequest;
use App\Services\AgentOps\AgentSupportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AgentSupportController extends Controller
{
    public function __construct(private readonly AgentSupportService $support) {}

    public function replies(Request $request)
    {
        $params = $request->validate(['limit' => 'nullable|integer|min:1|max:50']);
        return $this->success(AgentSupportReplyRequest::orderByDesc('id')->limit((int) ($params['limit'] ?? 20))
            ->get()->map(fn ($reply) => $this->support->serialize($reply, true)));
    }

    public function approve(Request $request)
    {
        $params = $request->validate(['request_id' => 'required|string|max:64']);
        $reply = $this->support->approve($params['request_id'], (int) Auth::guard('sanctum')->id());
        $this->audit($reply, 'approve');
        return $this->success($this->support->serialize($reply, true));
    }

    public function reject(Request $request)
    {
        $params = $request->validate(['request_id' => 'required|string|max:64']);
        $reply = $this->support->reject($params['request_id'], (int) Auth::guard('sanctum')->id());
        $this->audit($reply, 'reject');
        return $this->success($this->support->serialize($reply, true));
    }

    private function audit(AgentSupportReplyRequest $reply, string $operation): void
    {
        AgentAuditLog::create([
            'request_id' => (string) Str::ulid(),
            'admin_id' => Auth::guard('sanctum')->id(),
            'actor_type' => 'admin',
            'tool' => 'support.reply.' . $operation,
            'risk_level' => 'operate',
            'approval_required' => true,
            'approval_actor' => Auth::guard('sanctum')->id(),
            'target_type' => 'ticket',
            'target_id' => (string) $reply->ticket_id,
            'input_redacted' => '{}',
            'result_status' => $reply->status,
            'result_summary' => 'Reply request ' . $reply->request_id,
        ]);
    }
}
