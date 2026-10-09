<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Models\Order;
use App\Models\Plan;
use App\Services\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CommerceReadController
{
    public function plans(Request $request): JsonResponse
    {
        $params = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $page = Plan::query()->with('group:id,name')
            ->withCount(['users', 'users as active_users_count' => static function ($query): void {
                $query->where(static function ($q): void {
                    $q->where('expired_at', '>', time())->orWhereNull('expired_at');
                });
            }])
            ->orderBy('sort')->orderBy('id')
            ->paginate((int) ($params['per_page'] ?? 100),
                ['id','name','content','group_id','transfer_enable','speed_limit',
                 'device_limit','capacity_limit','reset_traffic_method','prices',
                 'tags','show','sell','renew','sort'], 'page', (int) ($params['page'] ?? 1));
        return TxapiResponse::success($request, $page->getCollection()
            ->map(static fn (Plan $p): array => [
                'id' => (int) $p->id,
                'name' => (string) $p->name,
                'content' => $p->content,
                'group_id' => $p->group_id === null ? null : (int) $p->group_id,
                'group' => $p->group ? ['id' => (int) $p->group->id, 'name' => (string) $p->group->name] : null,
                'transfer_enable' => (int) $p->transfer_enable,
                'speed_limit' => $p->speed_limit === null ? null : (int) $p->speed_limit,
                'device_limit' => $p->device_limit === null ? null : (int) $p->device_limit,
                'capacity_limit' => $p->capacity_limit === null ? null : (int) $p->capacity_limit,
                'reset_traffic_method' => $p->reset_traffic_method,
                'prices' => $p->prices ?? [],
                'tags' => $p->tags ?? [],
                'show' => (bool) $p->show, 'sell' => (bool) $p->sell, 'renew' => (bool) $p->renew,
                'sort' => (int) $p->sort,
                'users_count' => (int) $p->users_count,
                'active_users_count' => (int) $p->active_users_count,
            ])->all(), self::meta($page));
    }

    public function orders(Request $request): JsonResponse
    {
        $params = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'trade_no' => ['sometimes', 'string', 'max:128'],
            'email' => ['sometimes', 'string', 'max:254'],
            'user_id' => ['sometimes', 'integer', 'min:1'],
            'callback_no' => ['sometimes', 'string', 'max:128'],
            'status' => ['sometimes', 'integer', 'in:0,1,2,3,4'],
            'commission_status' => ['sometimes', 'integer', 'in:0,1,2,3'],
            'is_commission' => ['sometimes', 'boolean'],
        ]);
        $query = Order::query()->with(['plan:id,name', 'user:id,email']);
        foreach (['trade_no', 'callback_no'] as $key) {
            if (isset($params[$key]) && $params[$key] !== '') {
                $query->where($key, 'like', '%' . $params[$key] . '%');
            }
        }
        if (!empty($params['email'])) {
            $query->whereHas('user', static function ($users) use ($params): void {
                $users->where('email', 'like', '%' . $params['email'] . '%');
            });
        }
        foreach (['user_id', 'status', 'commission_status'] as $key) {
            if (isset($params[$key])) $query->where($key, (int) $params[$key]);
        }
        if (!empty($params['is_commission'])) {
            $query->whereNotNull('invite_user_id')
                ->whereNotIn('status', [Order::STATUS_PENDING, Order::STATUS_CANCELLED])
                ->where('commission_balance', '>', 0);
        }
        $page = $query->orderByDesc('created_at')->orderByDesc('id')
            ->paginate((int) ($params['per_page'] ?? 20),
                ['id','trade_no','user_id','plan_id','period','type','status','total_amount',
                 'handling_amount','balance_amount','discount_amount','paid_at','created_at',
                 'updated_at','commission_status','commission_balance','actual_commission_balance',
                 'invite_user_id','callback_no'], 'page', (int) ($params['page'] ?? 1));
        return TxapiResponse::success($request,
            $page->getCollection()->map(static fn (Order $o): array => [
                'id' => (int) $o->id, 'trade_no' => (string) $o->trade_no,
                'user_id' => (int) $o->user_id, 'plan_id' => (int) $o->plan_id,
                'plan' => $o->plan ? ['id' => (int) $o->plan->id, 'name' => (string) $o->plan->name] : null,
                'user' => $o->user ? ['id' => (int) $o->user->id, 'email' => (string) $o->user->email] : null,
                'period' => PlanService::getLegacyPeriod((string) $o->period),
                'status' => (int) $o->status, 'type' => (int) $o->type,
                'total_amount' => (int) $o->total_amount,
                'handling_amount' => (int) ($o->handling_amount ?? 0),
                'balance_amount' => (int) ($o->balance_amount ?? 0),
                'discount_amount' => (int) ($o->discount_amount ?? 0),
                'paid_at' => $o->paid_at === null ? null : (int) $o->paid_at,
                'created_at' => (int) $o->created_at,
                'updated_at' => (int) $o->updated_at,
                'commission_status' => $o->commission_status === null ? null : (int) $o->commission_status,
                'commission_balance' => (int) ($o->commission_balance ?? 0),
                'actual_commission_balance' => (int) ($o->actual_commission_balance ?? 0),
                'invite_user_id' => $o->invite_user_id === null ? null : (int) $o->invite_user_id,
                'callback_no' => $o->callback_no,
            ])->all(), self::meta($page));
    }

    private static function meta($page): array
    {
        return ['page' => $page->currentPage(), 'per_page' => $page->perPage(),
            'total' => $page->total(), 'last_page' => $page->lastPage()];
    }
}
