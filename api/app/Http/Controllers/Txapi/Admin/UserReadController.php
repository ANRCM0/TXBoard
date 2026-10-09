<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class UserReadController
{
    public function index(Request $request): JsonResponse
    {
        $params = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'email' => ['sometimes', 'string', 'max:254'],
            'plan_id' => ['sometimes', 'integer', 'min:1'],
            'banned' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'in:id,email,balance,total_used,expired_at,created_at'],
            'descending' => ['sometimes', 'boolean'],
        ]);
        $query = User::query()->with(['plan:id,name', 'group:id,name']);
        if (!empty($params['email'])) {
            $query->where('email', 'like', '%' . $params['email'] . '%');
        }
        if (isset($params['plan_id'])) $query->where('plan_id', (int) $params['plan_id']);
        if (isset($params['banned'])) $query->where('banned', (int) $params['banned']);
        $sort = $params['sort'] ?? 'id';
        $direction = !isset($params['descending']) || $params['descending'] ? 'desc' : 'asc';
        if ($sort === 'total_used') {
            $query->orderByRaw('(u + d) ' . $direction);
        } else {
            $query->orderBy($sort, $direction);
        }
        if ($sort !== 'id') $query->orderByDesc('id');
        $page = $query->paginate((int) ($params['per_page'] ?? 20),
            ['id','email','plan_id','group_id','balance','commission_balance',
             'commission_rate','commission_type','discount','expired_at','created_at',
             'banned','is_admin','is_staff','transfer_enable','u','d','remarks',
             'speed_limit','device_limit','next_reset_at','last_reset_at','reset_count',
             'last_login_at'], 'page', (int) ($params['page'] ?? 1));
        return TxapiResponse::success($request, $page->getCollection()
            ->map(static fn (User $user): array => self::dto($user))->all(), self::meta($page));
    }

    public function show(Request $request): JsonResponse
    {
        $id = (int) $request->route('id');
        $user = User::query()->with([
            'plan:id,name', 'group:id,name', 'invite_user:id,email',
        ])->findOrFail($id);
        return TxapiResponse::success($request, self::dto($user, true));
    }

    public function subscriptionLink(Request $request): JsonResponse
    {
        $id = (int) $request->route('id');
        // Only a privileged explicit operation can retrieve a subscription URL;
        // never fan out private per-user credentials through the list response.
        $user = User::query()->findOrFail($id, ['id', 'token']);
        return TxapiResponse::success($request, [
            'subscribe_url' => Helper::getSubscribeUrl((string) $user->token),
        ]);
    }

    private static function dto(User $user, bool $detail = false): array
    {
        $output = [
            'id' => (int) $user->id,
            'email' => (string) $user->email,
            'plan_id' => $user->plan_id === null ? null : (int) $user->plan_id,
            'group_id' => $user->group_id === null ? null : (int) $user->group_id,
            'plan' => $user->plan ? ['id' => (int) $user->plan->id, 'name' => (string) $user->plan->name] : null,
            'group' => $user->group ? ['id' => (int) $user->group->id, 'name' => (string) $user->group->name] : null,
            // Admin editor historically expects major currency units, not cents.
            'balance' => ((int) $user->balance) / 100,
            'commission_balance' => ((int) $user->commission_balance) / 100,
            'commission_rate' => $user->commission_rate === null ? null : (int) $user->commission_rate,
            'commission_type' => $user->commission_type === null ? null : (int) $user->commission_type,
            'discount' => $user->discount === null ? null : (int) $user->discount,
            'expired_at' => $user->expired_at === null ? null : (int) $user->expired_at,
            'created_at' => (int) $user->created_at,
            'banned' => (bool) $user->banned,
            'is_admin' => (bool) $user->is_admin,
            'is_staff' => (bool) $user->is_staff,
            'transfer_enable' => (int) ($user->transfer_enable ?? 0),
            'u' => (int) ($user->u ?? 0), 'd' => (int) ($user->d ?? 0),
            'total_used' => (int) ($user->u ?? 0) + (int) ($user->d ?? 0),
            'speed_limit' => $user->speed_limit === null ? null : (float) $user->speed_limit,
            'device_limit' => $user->device_limit === null ? null : (int) $user->device_limit,
            'remarks' => $user->remarks,
            'next_reset_at' => $user->next_reset_at === null ? null : (int) $user->next_reset_at,
            'last_reset_at' => $user->last_reset_at === null ? null : (int) $user->last_reset_at,
            'reset_count' => (int) ($user->reset_count ?? 0),
            'last_login_at' => $user->last_login_at === null ? null : (int) $user->last_login_at,
        ];
        if ($detail) {
            $output['invite_user'] = $user->invite_user ? [
                'id' => (int) $user->invite_user->id, 'email' => (string) $user->invite_user->email,
            ] : null;
        }
        return $output;
    }

    private static function meta($page): array
    {
        return ['page' => $page->currentPage(), 'per_page' => $page->perPage(),
            'total' => $page->total(), 'last_page' => $page->lastPage()];
    }
}
