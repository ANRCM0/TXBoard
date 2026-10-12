<?php

namespace App\Domains\InternalReadOnly;

use App\Core\Http\TxapiResponse;
use App\Core\Http\TxapiErrorRenderer;
use App\Http\Controllers\Txapi\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class InternalSessionDispatcher
{
    public function execute(Request $request, array $operation)
    {
        try {
            return $this->dispatch($request, $operation);
        } catch (SliceFailure $failure) {
            return TxapiResponse::error($request, $failure->errorCode, 'Internal operation rejected', $failure->status);
        } catch (\Throwable $exception) {
            // Render before the routing pipeline reports exceptions with SQL bindings.
            return TxapiErrorRenderer::render($request, $exception);
        }
    }

    private function dispatch(Request $request, array $operation)
    {
        $actor = Auth::guard('sanctum')->user();
        if (!str_ends_with($actor->email, '@example.test')) {
            throw new SliceFailure('ACTOR_NOT_ALLOWED', 403);
        }
        $controller = app(AuthController::class);
        $native = match ($operation['operation']) {
            'user.session.list' => $controller->sessions($request, 101),
            'user.session.revoke' => $controller->revoke($request, (int) $operation['body']->input->session_id),
            'user.session.logout' => $controller->logout($request),
        };
        if ($native->getStatusCode() !== 200) return $native;
        $data = $native->getData(true)['data'];
        if ($operation['operation'] === 'user.session.list') {
            if (count($data) > 100) throw new SliceFailure('SESSION_LIST_TOO_LARGE', 409);
            foreach ($data as &$item) $item['id'] = (string) $item['id'];
            unset($item);
            $data = ['items' => $data];
        }
        return TxapiResponse::success($request, $data,
            ['current_only' => true, 'slice' => 'internal-isolated-sessions/1']);
    }
}
