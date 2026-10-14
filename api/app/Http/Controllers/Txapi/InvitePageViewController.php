<?php

namespace App\Http\Controllers\Txapi;

use App\Core\Http\TxapiResponse;
use App\Domains\AuthorityMigration\AuthorityIdentitySqlPhase;
use App\Models\InviteCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InvitePageViewController
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['invite_code' => ['required', 'string', 'max:64']]);
        $phase = static fn () => InviteCode::query()->where('code', $data['invite_code'])->increment('pv');
        if (AuthorityIdentitySqlPhase::enabled()) {
            app(AuthorityIdentitySqlPhase::class)->run(AuthorityIdentitySqlPhase::INVITE_PAGE_VIEW, $phase);
        } else {
            $phase();
        }
        return TxapiResponse::success($request, true);
    }
}
