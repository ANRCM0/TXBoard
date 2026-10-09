<?php

namespace App\Http\Controllers\Txapi;

use App\Core\Http\TxapiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CommissionController
{
    public function transfer(Request $request): JsonResponse
    {
        $input = $request->validate([
            'transfer_amount' => ['required', 'integer', 'min:1'],
        ]);
        $amount = (int) $input['transfer_amount'];
        $result = DB::transaction(function () use ($request, $amount): string {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $minimum = admin_transfer_minimum();
            if ($minimum > 0 && $amount < (int) round($minimum * 100)) {
                return 'MINIMUM_NOT_MET';
            }
            if ($amount > (int) $user->commission_balance) {
                return 'INSUFFICIENT_COMMISSION';
            }
            $user->commission_balance -= $amount;
            $user->balance += $amount;
            $user->saveOrFail();
            return 'OK';
        });
        if ($result !== 'OK') {
            return TxapiResponse::error($request, $result, 'Commission transfer rejected', 422);
        }
        return TxapiResponse::success($request, true);
    }
}
