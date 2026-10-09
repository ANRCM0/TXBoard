<?php

namespace App\Http\Controllers\Txapi;

use App\Core\Http\TxapiResponse;
use App\Models\CommissionLog;
use App\Models\InviteCode;
use App\Models\Order;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class InviteController
{
    public function index(Request $request): JsonResponse
    {
        $user = User::query()->with(['codes' => fn ($q) => $q->where('status', 0)])
            ->findOrFail(Auth::guard('sanctum')->id());
        $pending = (int) Order::query()->where('status', 3)
            ->where('commission_status', 0)->where('invite_user_id', $user->id)
            ->sum('commission_balance');
        if (admin_setting('commission_distribution_enable', 0)) {
            $pending = (int) ($pending * admin_setting('commission_distribution_l1') / 100);
        }
        return TxapiResponse::success($request, [
            'codes' => $user->codes->map(static fn (InviteCode $code) => [
                'code' => $code->code, 'pv' => (int) $code->pv,
                'status' => (int) $code->status, 'created_at' => $code->created_at,
            ])->all(),
            'stat' => [
                (int) User::query()->where('invite_user_id', $user->id)->count(),
                (int) CommissionLog::query()->where('invite_user_id', $user->id)->sum('get_amount'),
                $pending,
                (int) ($user->commission_rate ?: admin_setting('invite_commission', 10)),
                (int) $user->commission_balance,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $created = DB::transaction(function () use ($request): bool {
            $user = User::query()->lockForUpdate()->findOrFail(Auth::guard('sanctum')->id());
            if (InviteCode::query()->where('user_id', $user->id)
                ->where('status', 0)->count() >= (int) admin_setting('invite_gen_limit', 5)) {
                return false;
            }
            $code = new InviteCode();
            $code->user_id = $user->id;
            $code->code = Helper::randomChar(8);
            return $code->save();
        });
        if (!$created) {
            return TxapiResponse::error($request, 'INVITE_LIMIT_REACHED', 'Invitation code limit reached', 409);
        }
        return TxapiResponse::success($request, true, status: 201);
    }
}
