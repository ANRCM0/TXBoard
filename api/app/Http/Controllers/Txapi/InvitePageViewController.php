<?php

namespace App\Http\Controllers\Txapi;

use App\Core\Http\TxapiResponse;
use App\Models\InviteCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InvitePageViewController
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['invite_code' => ['required', 'string', 'max:64']]);
        InviteCode::query()->where('code', $data['invite_code'])->increment('pv');
        return TxapiResponse::success($request, true);
    }
}
