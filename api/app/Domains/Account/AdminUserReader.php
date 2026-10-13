<?php

namespace App\Domains\Account;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

/** Shared native Admin queries. Authorization stays at each existing entry point. */
final class AdminUserReader
{
    public function paginate(Request $request): LengthAwarePaginator
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
        $direction = !$request->has('descending') || $request->boolean('descending') ? 'desc' : 'asc';
        if ($sort === 'total_used') {
            $query->orderByRaw('(u + d) ' . $direction);
        } else {
            $query->orderBy($sort, $direction);
        }
        if ($sort !== 'id') $query->orderByDesc('id');
        return $query->paginate((int) ($params['per_page'] ?? 20),
            ['id','email','plan_id','group_id','balance','commission_balance',
             'commission_rate','commission_type','discount','expired_at','created_at',
             'banned','is_admin','is_staff','transfer_enable','u','d','remarks',
             'speed_limit','device_limit','next_reset_at','last_reset_at','reset_count',
             'last_login_at'], 'page', (int) ($params['page'] ?? 1));
    }

    public function find(int $id): User
    {
        return User::query()->with(['plan:id,name', 'group:id,name', 'invite_user:id,email'])->findOrFail($id);
    }
}
