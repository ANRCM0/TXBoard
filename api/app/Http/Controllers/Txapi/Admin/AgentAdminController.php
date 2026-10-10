<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Models\AgentAction;
use App\Services\AgentOps\AgentAbility;
use App\Services\AgentOps\AgentActionService;
use App\Services\AgentOps\AgentInsightService;
use App\Services\AgentOps\AgentPairingService;
use App\Services\AgentOps\AgentTargetScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class AgentAdminController
{
    public function __construct(
        private readonly AgentActionService $actions,
        private readonly AgentInsightService $insights,
        private readonly AgentPairingService $pairings,
    ) {}

    public function abilities(Request $request): JsonResponse
    {
        return TxapiResponse::success($request, [
            'default_read' => AgentAbility::DEFAULT_READ,
            'all' => AgentAbility::ALL,
        ]);
    }

    public function tokens(Request $request): JsonResponse
    {
        $params = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        // An admin can only inspect their OWN Agent tokens. Tokens are never
        // re-disclosed after their one-time creation response.
        $page = $request->user()->tokens()->where('name', 'like', 'agent:%')
            ->orderByDesc('id')->paginate(
                (int) ($params['per_page'] ?? 100),
                ['id', 'name', 'abilities', 'last_used_at', 'expires_at', 'created_at'],
                'page', (int) ($params['page'] ?? 1)
            );
        $items = $page->getCollection()->map(static fn ($token) => [
            'id' => (int) $token->id,
            'client_name' => preg_replace('/^agent:/', '', (string) $token->name),
            'abilities' => AgentTargetScope::functionalAbilities($token),
            'target_scope' => AgentTargetScope::describe($token),
            'last_used_at' => $token->last_used_at,
            'expires_at' => $token->expires_at,
            'created_at' => $token->created_at,
        ])->values()->all();

        return TxapiResponse::success($request, $items, [
            'page' => $page->currentPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
            'last_page' => $page->lastPage(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function createToken(Request $request): JsonResponse
    {
        $params = $request->validate([
            'client_name' => ['required', 'string', 'min:2', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'abilities' => ['sometimes', 'array', 'min:1', 'max:32'],
            'abilities.*' => ['required', 'string', 'max:64', 'distinct'],
            'expires_in_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
            'target_mode' => ['sometimes', 'in:all,restricted'],
            'target_node_ids' => ['sometimes', 'array', 'max:100'],
            'target_node_ids.*' => ['integer', 'min:1', 'distinct', \Illuminate\Validation\Rule::exists(\App\Support\Database\NativeTableName::runtime('v2_server'), 'id')],
            'target_machine_ids' => ['sometimes', 'array', 'max:100'],
            'target_machine_ids.*' => ['integer', 'min:1', 'distinct', \Illuminate\Validation\Rule::exists(\App\Support\Database\NativeTableName::runtime('v2_server_machine'), 'id')],
        ]);
        try {
            $functional = AgentAbility::validate($params['abilities'] ?? AgentAbility::DEFAULT_READ);
            if (($params['target_mode'] ?? 'all') === 'restricted' &&
                array_intersect($functional, [AgentAbility::SUPPORT_READ, AgentAbility::SUPPORT_REPLY_REQUEST])) {
                throw new \InvalidArgumentException(
                    'Support abilities require an unrestricted back-office token; node scope does not restrict customer data'
                );
            }
            $abilities = AgentTargetScope::compile(
                $functional, $params['target_mode'] ?? 'all',
                $params['target_node_ids'] ?? [], $params['target_machine_ids'] ?? []
            );
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['target_scope' => $e->getMessage()]);
        }

        $admin = $request->user();
        $expires = now()->addDays((int) ($params['expires_in_days'] ?? 30));
        $created = $admin->createToken('agent:' . $params['client_name'], $abilities, $expires);
        $pairing = null;
        try {
            $pairing = $this->pairings->issue(
                (int) $admin->id, (int) $created->accessToken->id, $created->plainTextToken
            );
        } catch (\Throwable) {
            Log::warning('Agent pairing issuance unavailable', [
                'token_id' => (int) $created->accessToken->id,
            ]);
        }

        return TxapiResponse::success($request, [
            'id' => (int) $created->accessToken->id,
            'client_name' => $params['client_name'],
            'abilities' => $functional,
            'target_scope' => AgentTargetScope::describe($created->accessToken),
            'expires_at' => $expires->toIso8601String(),
            'plain_text_token' => $created->plainTextToken,
            'pairing' => $pairing,
        ], [], 201)->header('Cache-Control', 'private, no-store')
            ->header('Pragma', 'no-cache');
    }

    public function revokeToken(Request $request): JsonResponse
    {
        $id = (int) $request->route('id');
        $token = $request->user()->tokens()->where('id', $id)->first();
        if (!$token || !str_starts_with((string) $token->name, 'agent:')) {
            return TxapiResponse::error($request, 'AGENT_TOKEN_NOT_FOUND', 'Agent token not found', 404);
        }
        $token->delete();
        return TxapiResponse::success($request, ['ok' => true])
            ->header('Cache-Control', 'no-store');
    }

    public function fleetHealth(Request $request): JsonResponse
    {
        return TxapiResponse::success($request, $this->insights->fleetHealth())
            ->header('Cache-Control', 'no-store');
    }

    public function inspections(Request $request): JsonResponse
    {
        $params = $request->validate(['limit' => ['sometimes', 'integer', 'min:1', 'max:50']]);
        return TxapiResponse::success($request,
            $this->insights->inspectionHistory((int) ($params['limit'] ?? 20))
        )->header('Cache-Control', 'no-store');
    }

    public function runInspection(Request $request): JsonResponse
    {
        return TxapiResponse::success($request, $this->insights->runInspection('admin'))
            ->header('Cache-Control', 'no-store');
    }

    public function nodeTimeline(Request $request): JsonResponse
    {
        $id = (int) $request->route('nodeId');
        $params = $request->validate([
            'hours' => ['sometimes', 'integer', 'min:1', 'max:168'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        return TxapiResponse::success($request, $this->insights->incidentTimeline(
            $id, (int) ($params['hours'] ?? 24), (int) ($params['limit'] ?? 100)
        ))->header('Cache-Control', 'no-store');
    }

    public function actions(Request $request): JsonResponse
    {
        $params = $request->validate([
            'status' => ['sometimes', 'in:pending,running,succeeded,failed,rejected,timed_out,unknown'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $items = AgentAction::query()->with([
            'node:id,name,type', 'admin:id,email', 'approver:id,email',
        ])->when(isset($params['status']), static fn ($query) =>
            $query->where('status', $params['status']))
            ->orderByDesc('id')->limit((int) ($params['limit'] ?? 50))
            ->get()->map(fn (AgentAction $action) => $this->actions->serialize($action))->all();

        return TxapiResponse::success($request, $items)
            ->header('Cache-Control', 'no-store');
    }

    public function approveAction(Request $request): JsonResponse
    {
        $id = $this->requestId($request);
        try {
            $action = $this->actions->approve($id, $request->user());
        } catch (\InvalidArgumentException $e) {
            return TxapiResponse::error($request, 'AGENT_ACTION_NOT_FOUND',
                'Agent action not found or cannot be approved', 404);
        }
        return TxapiResponse::success($request, $this->actions->serialize($action))
            ->header('Cache-Control', 'no-store');
    }

    public function rejectAction(Request $request): JsonResponse
    {
        $id = $this->requestId($request);
        $params = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        try {
            $action = $this->actions->reject($id, $request->user(), $params['reason'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return TxapiResponse::error($request, 'AGENT_ACTION_NOT_FOUND',
                'Agent action not found or cannot be rejected', 404);
        }
        return TxapiResponse::success($request, $this->actions->serialize($action))
            ->header('Cache-Control', 'no-store');
    }

    private function requestId(Request $request): string
    {
        $data = $request->validate([
            'request_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);
        return $data['request_id'];
    }
}
