<?php

namespace App\Http\Controllers\Txapi;

use App\Core\Http\TxapiResponse;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Services\TicketService;
use App\Utils\Dict;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class WithdrawalController
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'withdraw_method' => ['required', 'string', 'max:100'],
            'withdraw_account' => ['required', 'string', 'max:255'],
        ]);
        if ((int) admin_setting('withdraw_close_enable', 0)) {
            return TxapiResponse::error($request, 'WITHDRAW_DISABLED', 'Withdrawals disabled', 403);
        }
        if (!in_array($data['withdraw_method'],
            admin_setting('commission_withdraw_method', Dict::WITHDRAW_METHOD_WHITELIST_DEFAULT), true)) {
            return TxapiResponse::error($request, 'WITHDRAW_METHOD_INVALID', 'Unsupported withdrawal method', 422);
        }
        $user = User::query()->findOrFail(Auth::guard('sanctum')->id());
        if ((float) admin_setting('commission_withdraw_limit', 100) > ((int) $user->commission_balance / 100)) {
            return TxapiResponse::error($request, 'WITHDRAW_MINIMUM_NOT_MET', 'Withdrawal minimum not met', 422);
        }
        $ticket = (new TicketService())->createTicket($user->id,
            __('[Commission Withdrawal Request] This ticket is opened by the system'),
            2,
            __('Withdrawal method') . '：' . $data['withdraw_method'] . "\r\n"
                . __('Withdrawal account') . '：' . $data['withdraw_account']);
        HookManager::call('ticket.create.after', $ticket);
        return TxapiResponse::success($request, ['ok' => true], status: 201);
    }
}
