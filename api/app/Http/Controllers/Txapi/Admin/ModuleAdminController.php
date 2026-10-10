<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Services\Module\ModuleLifecycle;
use App\Services\Module\ModuleLifecycleErrorCode;
use App\Services\Module\ModuleLifecycleOperation;
use App\Services\Module\ModuleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ModuleAdminController
{
    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly ModuleLifecycle $lifecycle,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return TxapiResponse::success($request, $this->modules->snapshot()->toArray())
            ->header('Cache-Control', 'no-store');
    }

    public function show(Request $request): JsonResponse
    {
        $module = $this->modules->find((string) $request->route('id'));
        return $module
            ? TxapiResponse::success($request, $module->toArray())->header('Cache-Control', 'no-store')
            : $this->missing($request);
    }

    public function operations(Request $request): JsonResponse
    {
        $id = (string) $request->route('id');
        $operations = $this->lifecycle->supportedOperations($id);
        if ($operations === null) return $this->missing($request);

        return TxapiResponse::success($request, [
            'module_id' => $id,
            'operations' => array_map(static fn (ModuleLifecycleOperation $op): string => $op->value, $operations),
        ])->header('Cache-Control', 'no-store');
    }

    public function execute(Request $request): JsonResponse
    {
        $id = (string) $request->route('id');
        $operation = ModuleLifecycleOperation::tryFrom((string) $request->route('operation'));
        if (!$operation) {
            return TxapiResponse::error($request, 'MODULE_OPERATION_INVALID',
                'Invalid module lifecycle operation', 422);
        }

        $result = $this->lifecycle->execute($id, $operation);
        if ($result->success) {
            return TxapiResponse::success($request, $result->toArray())
                ->header('Cache-Control', 'no-store');
        }

        $code = $result->error?->code;
        return match ($code) {
            ModuleLifecycleErrorCode::MODULE_NOT_FOUND => $this->missing($request),
            ModuleLifecycleErrorCode::UNSUPPORTED_MODULE_TYPE,
            ModuleLifecycleErrorCode::UNSUPPORTED_OPERATION =>
                TxapiResponse::error($request, 'MODULE_OPERATION_CONFLICT',
                    'Module operation is not supported', 409),
            default => TxapiResponse::error($request, 'MODULE_OPERATION_FAILED',
                'Module operation failed', 503),
        };
    }

    private function missing(Request $request): JsonResponse
    {
        return TxapiResponse::error($request, 'MODULE_NOT_FOUND', 'Module not found', 404);
    }
}
