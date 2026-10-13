<?php

namespace App\Domains\InternalReadOnly;

use App\Core\Http\TxapiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class InternalAdminIdentityDispatcher
{
    public function execute(Request $request, \stdClass $input)
    {
        return app(InternalAdminAccess::class)->run($request, $input, function (Request $request) {
            $actor = Auth::guard('sanctum')->user();
            return TxapiResponse::success($request, [
                'id' => (string) $actor->id,
                'email' => (string) $actor->email,
                'is_admin' => true,
            ], ['current_only' => true, 'slice' => 'internal-admin-identity/1']);
        });
    }
}
