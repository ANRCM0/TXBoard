<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\AgentAction;
use App\Services\AgentOps\AgentAbility;
use App\Services\AgentOps\AgentActionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AgentOpsController extends Controller
{
    public function __construct(private readonly AgentActionService $actions)
    {
    }

    public function abilities()
    {
        return $this->success([
            'default_read' => AgentAbility::DEFAULT_READ,
            'all' => AgentAbility::ALL,
        ]);
    }

    public function tokens(Request $request)
    {
        $admin = Auth::guard('sanctum')->user();
        $tokens = $admin->tokens()
            ->where('name', 'like', 'agent:%')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($token) => [
                'id' => $token->id,
                'client_name' => preg_replace('/^agent:/', '', (string) $token->name),
                'abilities' => $token->abilities ?? [],
                'last_used_at' => $token->last_used_at,
                'expires_at' => $token->expires_at,
                'created_at' => $token->created_at,
            ]);

        return $this->success($tokens);
    }

    public function createToken(Request $request)
    {
        $params = $request->validate([
            'client_name' => ['required', 'string', 'min:2', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'abilities' => 'nullable|array|min:1',
            'abilities.*' => 'string|max:64',
            'expires_in_days' => 'nullable|integer|min:1|max:90',
        ]);

        try {
            $abilities = AgentAbility::validate($params['abilities'] ?? AgentAbility::DEFAULT_READ);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['abilities' => $e->getMessage()]);
        }

        $admin = Auth::guard('sanctum')->user();
        $expiresAt = now()->addDays((int) ($params['expires_in_days'] ?? 30));
        $newToken = $admin->createToken(
            'agent:' . $params['client_name'],
            $abilities,
            $expiresAt,
        );

        return $this->success([
            'id' => $newToken->accessToken->id,
            'client_name' => $params['client_name'],
            'abilities' => $abilities,
            'expires_at' => $expiresAt->toIso8601String(),
            'plain_text_token' => $newToken->plainTextToken,
        ]);
    }

    public function revokeToken(Request $request)
    {
        $params = $request->validate(['id' => 'required|integer']);
        $admin = Auth::guard('sanctum')->user();
        $token = $admin->tokens()->where('id', $params['id'])->first();

        if (!$token || !str_starts_with((string) $token->name, 'agent:')) {
            return $this->fail([404000, 'Agent token not found']);
        }

        $token->delete();
        return $this->success(true);
    }

    public function actionList(Request $request)
    {
        $params = $request->validate([
            'status' => 'nullable|string|max:24',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $query = AgentAction::with(['node:id,name,type', 'admin:id,email', 'approver:id,email'])
            ->orderByDesc('id');

        if (!empty($params['status'])) {
            $query->where('status', $params['status']);
        }

        $items = $query->limit((int) ($params['limit'] ?? 50))->get()
            ->map(fn (AgentAction $action) => $this->actions->serialize($action));

        return $this->success($items);
    }

    public function approveAction(Request $request)
    {
        $params = $request->validate(['request_id' => 'required|string|max:64']);
        $action = $this->actions->approve($params['request_id'], Auth::guard('sanctum')->user());
        return $this->success($this->actions->serialize($action));
    }

    public function rejectAction(Request $request)
    {
        $params = $request->validate([
            'request_id' => 'required|string|max:64',
            'reason' => 'nullable|string|max:500',
        ]);
        $action = $this->actions->reject(
            $params['request_id'],
            Auth::guard('sanctum')->user(),
            $params['reason'] ?? null,
        );
        return $this->success($this->actions->serialize($action));
    }
}
