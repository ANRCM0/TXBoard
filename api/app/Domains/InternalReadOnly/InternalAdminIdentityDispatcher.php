<?php

namespace App\Domains\InternalReadOnly;

use App\Core\Http\TxapiResponse;
use App\Core\Http\TxapiErrorRenderer;
use App\Http\Middleware\Admin;
use App\Http\Middleware\AdminPath;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class InternalAdminIdentityDispatcher
{
    public function execute(Request $request, \stdClass $input)
    {
        try {
            return $this->read($request, $input);
        } catch (\Throwable $exception) {
            // Render before Laravel's routing pipeline can report raw SQL/input.
            return TxapiErrorRenderer::render($request, $exception);
        }
    }

    private function read(Request $request, \stdClass $input)
    {
        // The service proof supplies no business role. Reuse the native current
        // secure-path and administrator policies after Sanctum/ban validation.
        $request->route()->setParameter('admin_path', $input->admin_path);
        $response = app(AdminPath::class)->handle($request, fn (Request $request) =>
            app(Admin::class)->handle($request, function (Request $request) {
                $actor = Auth::guard('sanctum')->user();
                return TxapiResponse::success($request, [
                    'id' => (string) $actor->id,
                    'email' => (string) $actor->email,
                    'is_admin' => true,
                ], ['current_only' => true, 'slice' => 'internal-admin-identity/1']);
            }));
        if ($response->getStatusCode() !== 200) {
            return TxapiResponse::error($request, 'ADMIN_ACCESS_DENIED', 'Internal operation rejected', 403);
        }
        return $response;
    }
}
