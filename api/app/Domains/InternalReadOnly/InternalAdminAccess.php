<?php

namespace App\Domains\InternalReadOnly;

use App\Core\Http\TxapiErrorRenderer;
use App\Core\Http\TxapiResponse;
use App\Http\Middleware\Admin;
use App\Http\Middleware\AdminPath;
use Illuminate\Http\Request;

final class InternalAdminAccess
{
    public function run(Request $request, \stdClass $input, \Closure $read)
    {
        try {
            // A service certificate grants no business role. Reuse the native
            // policies after Internal middleware validates current Sanctum/ban.
            $request->route()->setParameter('admin_path', $input->admin_path);
            $response = app(AdminPath::class)->handle($request, fn (Request $request) =>
                app(Admin::class)->handle($request, $read));
            if ($response->getStatusCode() !== 200) {
                return TxapiResponse::error($request, 'ADMIN_ACCESS_DENIED', 'Internal operation rejected', 403);
            }
            return $response;
        } catch (SliceFailure $exception) {
            return TxapiResponse::error($request, $exception->errorCode, 'Internal operation rejected', $exception->status);
        } catch (\Throwable $exception) {
            // Render before Laravel's routing pipeline can report raw SQL/input.
            return TxapiErrorRenderer::render($request, $exception);
        }
    }
}
