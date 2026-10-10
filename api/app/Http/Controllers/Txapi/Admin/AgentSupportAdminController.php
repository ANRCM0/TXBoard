<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Models\AgentAuditLog;
use App\Models\AgentSupportReplyRequest;
use App\Services\AgentOps\AgentSupportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class AgentSupportAdminController
{
    public function __construct(private readonly AgentSupportService $support) {}

    public function replies(Request $request): JsonResponse
    {
        $params = $request->validate(['limit' => ['sometimes', 'integer', 'min:1', 'max:50']]);
        $rows = AgentSupportReplyRequest::query()->orderByDesc('id')
            ->limit((int) ($params['limit'] ?? 20))->get()
            ->map(fn ($reply) => $this->support->serialize($reply, true))->all();
        return TxapiResponse::success($request, $rows)->header('Cache-Control', 'no-store');
    }

    public function approve(Request $request): JsonResponse
    {
        $params = $this->validRequestId($request);
        $reply = $this->support->approve($params['request_id'], (int) $request->user()->id);
        $this->audit($reply, 'approve', (int) $request->user()->id);
        return TxapiResponse::success($request, $this->support->serialize($reply, true))
            ->header('Cache-Control', 'no-store');
    }

    public function reject(Request $request): JsonResponse
    {
        $params = $this->validRequestId($request);
        $reply = $this->support->reject($params['request_id'], (int) $request->user()->id);
        $this->audit($reply, 'reject', (int) $request->user()->id);
        return TxapiResponse::success($request, $this->support->serialize($reply, true))
            ->header('Cache-Control', 'no-store');
    }

    private function validRequestId(Request $request): array
    {
        return $request->validate([
            'request_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);
    }

    private function audit(AgentSupportReplyRequest $reply, string $operation, int $adminId): void
    {
        // Never store the ticket reply contents in the approval audit record.
        AgentAuditLog::create([
            'request_id' => (string) Str::ulid(),
            'admin_id' => $adminId,
            'actor_type' => 'admin',
            'tool' => 'support.reply.' . $operation,
            'risk_level' => 'operate',
            'approval_required' => true,
            'approval_actor' => $adminId,
            'target_type' => 'ticket',
            'target_id' => (string) $reply->ticket_id,
            'input_redacted' => '{}',
            'result_status' => $reply->status,
            'result_summary' => 'Reply request ' . $reply->request_id,
        ]);
    }
}
